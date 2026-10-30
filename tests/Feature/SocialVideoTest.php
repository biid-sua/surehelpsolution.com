<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Actions\Social\SavePost;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Livewire\Client\Social\Media;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Services\Social\Connectors\XConnector;
use App\Services\Social\PostValidator;
use App\Services\Social\SocialManager;
use App\Services\Social\SocialPublishing;
use App\Support\Media\VideoLength;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Video posts, YouTube and X (D104): uploads, each network's rules, and publishing a video to
 * Facebook, Instagram (Reels), LinkedIn, YouTube and X.
 */
class SocialVideoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        config(['social.poll_ms' => 0, 'social.video_poll_attempts' => 2,
            'social.connectors.x.client_id' => 'x-client', 'social.connectors.x.client_secret' => 'x-secret',
            'social.connectors.youtube.client_id' => 'yt-client', 'social.connectors.youtube.client_secret' => 'yt-secret']);
    }

    /** A tiny MP4 header saying the video lasts 15 seconds. */
    private static function mp4(int $seconds = 15): string
    {
        return pack('N', 20).'ftypisom'.str_repeat("\0", 8)
            .pack('N', 108).'mvhd'.chr(0)."\0\0\0".pack('N', 0).pack('N', 0).pack('N', 1000).pack('N', $seconds * 1000).str_repeat("\0", 80);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);

        return [$owner, app(ProvisionUserTenancy::class)->handle($owner)->fresh()];
    }

    private function account(Organization $org, SocialNetwork $network, string $externalId, array $extra = []): SocialAccount
    {
        return SocialAccount::create(array_merge(['organization_id' => $org->id, 'network' => $network, 'external_id' => $externalId,
            'name' => $network->label().' account', 'access_token' => 'token-'.$network->value, 'is_enabled' => true], $extra));
    }

    private function video(Organization $org, int $seconds = 15): MediaAsset
    {
        Storage::disk('local')->put('social/clip.mp4', self::mp4($seconds));

        return MediaAsset::create(['organization_id' => $org->id, 'path' => 'social/clip.mp4', 'original_name' => 'clip.mp4',
            'mime' => 'video/mp4', 'size_bytes' => strlen(self::mp4($seconds)), 'duration_seconds' => $seconds]);
    }

    private function postNow(Organization $org, User $owner, SocialAccount $account, string $body, MediaAsset $video): void
    {
        $this->actingAs($owner);
        app(SavePost::class)->handle($org, null, ['body' => $body, 'accounts' => [$account->ulid], 'media' => [$video->ulid]], $owner, 'now');
        app(SocialPublishing::class)->dispatchDue();
    }

    public function test_videos_are_uploaded_to_the_library_with_their_length(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner);
        $file = UploadedFile::fake()->createWithContent('van-tour.mp4', self::mp4(42));

        Livewire::test(Media::class)->set('uploads', [$file])->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $asset = MediaAsset::query()->sole();
        $this->assertTrue($asset->isVideo());
        $this->assertSame(42, $asset->duration_seconds);
        $this->assertSame(42, VideoLength::of(Storage::disk('local')->path($asset->path)));
        Livewire::test(Media::class)->assertSeeHtml('<video');

        Livewire::test(Media::class)->set('uploads', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertHasErrors('uploads.0');
    }

    public function test_each_network_has_its_own_video_rules(): void
    {
        $v = app(PostValidator::class);
        $this->assertSame([], $v->check(SocialNetwork::Facebook, 'Tour', null, 0, [30])['errors']);
        $this->assertContains('YouTube posts need a video.', $v->check(SocialNetwork::YouTube, "Title\nAbout", null, 1)['errors']);
        $this->assertSame([], $v->check(SocialNetwork::YouTube, "Our new van\nA quick tour.", null, 0, [30])['errors']);
        $this->assertNotEmpty($v->check(SocialNetwork::GoogleBusiness, 'Tour', null, 0, [30])['errors'], 'no video through the Google API');
        $this->assertNotEmpty($v->check(SocialNetwork::Facebook, 'Tour', null, 1, [30])['errors'], 'photos or a video, not both');
        $this->assertContains('X videos can be up to 2 min 20 s; this one is 3 min.', $v->check(SocialNetwork::X, 'Tour', null, 0, [180])['errors']);
        $this->assertNotEmpty($v->check(SocialNetwork::X, str_repeat('a', 281), null, 0)['errors']);
        $this->assertSame([], $v->check(SocialNetwork::Instagram, 'Reel', null, 0, [null])['errors'], 'a video counts as Instagram media');

        // Scheduling refuses a video for Google.
        [$owner, $org] = $this->business();
        $google = $this->account($org, SocialNetwork::GoogleBusiness, 'accounts/1/locations/2');
        $this->actingAs($owner);
        $this->expectException(ValidationException::class);
        app(SavePost::class)->handle($org, null, ['body' => 'Tour', 'accounts' => [$google->ulid], 'media' => [$this->video($org)->ulid],
            'scheduled_at' => CarbonImmutable::now()->addHour()], $owner, 'schedule');
    }

    public function test_facebook_gets_the_video_from_a_signed_link(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook, '111');
        Http::fake(['graph.facebook.com/*/111/videos' => Http::response(['id' => 'v9'])]);

        $this->postNow($org, $owner, $fb, 'Take a tour of our van', $this->video($org));

        $this->assertSame(SocialPostStatus::Published, SocialPost::sole()->status);
        $this->assertSame('https://www.facebook.com/111/videos/v9', SocialPostTarget::sole()->external_url);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/111/videos') && str_contains($r['file_url'], 'signature=') && $r['description'] === 'Take a tour of our van');
    }

    public function test_an_instagram_reel_still_processing_is_finished_on_the_retry_without_uploading_again(): void
    {
        [$owner, $org] = $this->business();
        $ig = $this->account($org, SocialNetwork::Instagram, '999');
        Http::fake([
            'graph.facebook.com/*/999/media_publish' => Http::response(['id' => 'm1']),
            'graph.facebook.com/*/999/media' => Http::response(['id' => 'c1']),
            'graph.facebook.com/*/c1*' => Http::sequence()->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'IN_PROGRESS'])->push(['status_code' => 'FINISHED']),
            'graph.facebook.com/*/m1*' => Http::response(['permalink' => 'https://instagram.com/reel/abc']),
        ]);

        $this->postNow($org, $owner, $ig, 'Reel time', $this->video($org));
        $this->assertNotSame(SocialPostTarget::PUBLISHED, SocialPostTarget::sole()->status, 'still processing: tried again later');

        $this->travel(10)->minutes();
        app(SocialPublishing::class)->dispatchDue();
        $this->assertSame('https://instagram.com/reel/abc', SocialPostTarget::sole()->external_url);

        $created = 0;
        Http::assertSent(function (HttpRequest $r) use (&$created) {
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/999/media')) {
                $created++;
                $this->assertSame('REELS', $r['media_type']);
            }

            return true;
        });
        $this->assertSame(1, $created, 'the container was created once');
    }

    public function test_linkedin_uploads_the_video_in_parts_then_posts_it(): void
    {
        [$owner, $org] = $this->business();
        $li = $this->account($org, SocialNetwork::LinkedIn, 'urn:li:organization:777');
        Http::fake([
            'api.linkedin.com/rest/videos?action=initializeUpload' => Http::response(['value' => [
                'video' => 'urn:li:video:V1', 'uploadToken' => '',
                'uploadInstructions' => [['uploadUrl' => 'https://upload.linkedin.test/part1', 'firstByte' => 0, 'lastByte' => strlen(self::mp4()) - 1]],
            ]]),
            'upload.linkedin.test/*' => Http::response('', 200, ['ETag' => '"etag-1"']),
            'api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response([]),
            'api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:9']),
        ]);

        $this->postNow($org, $owner, $li, "Behind the scenes\nOur crew at work.", $this->video($org));

        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:9', SocialPostTarget::sole()->external_url);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'finalizeUpload') && $r['finalizeUploadRequest']['uploadedPartIds'] === ['etag-1']);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/rest/posts') && $r['content']['media']['id'] === 'urn:li:video:V1');
    }

    public function test_youtube_uploads_the_video_with_the_first_line_as_title(): void
    {
        [$owner, $org] = $this->business();
        $yt = $this->account($org, SocialNetwork::YouTube, 'UC123');
        Http::fake([
            'www.googleapis.com/upload/youtube/v3/videos?*' => Http::response([], 200, ['Location' => 'https://upload.youtube.test/session-1']),
            'upload.youtube.test/*' => Http::response(['id' => 'abc123']),
        ]);

        $this->postNow($org, $owner, $yt, "A day with our crew\nHow we fix a burst pipe in under an hour.", $this->video($org));

        $this->assertSame('https://www.youtube.com/watch?v=abc123', SocialPostTarget::sole()->external_url);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'uploadType=resumable') && $r['snippet']['title'] === 'A day with our crew'
            && $r['snippet']['description'] === 'How we fix a burst pipe in under an hour.' && $r['status']['privacyStatus'] === 'public');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://upload.youtube.test/session-1' && $r->method() === 'PUT');
    }

    public function test_x_signs_in_with_pkce_and_posts_a_video(): void
    {
        $x = app(SocialManager::class)->connector('x');
        $url = $x->authorizationUrl('https://surehelp.test/cb', 'state-123');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', XConnector::verifier('state-123'), true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertStringContainsString('media.write', $query['scope']);

        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 7200])]);
        $this->get('/?state=state-123');   // the callback's query carries the state
        $this->app['request']->query->set('state', 'state-123');
        $x->exchangeCode('code-1', 'https://surehelp.test/cb');
        Http::assertSent(fn (HttpRequest $r) => $r['code_verifier'] === XConnector::verifier('state-123') && $r->hasHeader('Authorization', 'Basic '.base64_encode('x-client:x-secret')));

        [$owner, $org] = $this->business();
        $account = $this->account($org, SocialNetwork::X, '42', ['handle' => 'surehelp']);
        Http::fake([
            'api.x.com/2/media/upload/initialize' => Http::response(['data' => ['id' => 'mv1']]),
            'api.x.com/2/media/upload/mv1/append' => Http::response([], 204),
            'api.x.com/2/media/upload/mv1/finalize' => Http::response(['data' => ['id' => 'mv1', 'processing_info' => ['state' => 'pending', 'check_after_secs' => 1]]]),
            'api.x.com/2/media/upload?*' => Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => 't77']], 201),
        ]);

        $this->postNow($org, $owner, $account, 'A quick tour of our van', $this->video($org));

        $this->assertSame('https://x.com/surehelp/status/t77', SocialPostTarget::sole()->external_url);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/2/tweets') && $r['media']['media_ids'] === ['mv1'] && $r['text'] === 'A quick tour of our van');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/initialize') && $r['media_category'] === 'tweet_video');
    }
}
