<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Actions\Social\PostWorkflow;
use App\Actions\Social\SavePost;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Livewire\Client\Social\Accounts;
use App\Livewire\Client\Social\Compose;
use App\Livewire\Client\Social\Media;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Notifications\SocialAccountDisconnected;
use App\Notifications\SocialApprovalRequested;
use App\Notifications\SocialPostFailed;
use App\Services\Account\Impersonation;
use App\Services\Social\Connectors\LinkedInApi;
use App\Services\Social\PostValidator;
use App\Services\Social\SocialPublishing;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * G-1 — social publishing (spec §41, D33).
 */
class SocialTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        config([
            'social.poll_ms' => 0,
            'social.connectors.meta.client_id' => 'meta-app', 'social.connectors.meta.client_secret' => 'meta-secret',
        ]);
    }

    /** @return array{0: User, 1: Organization} */
    private function business(string $timezone = 'America/Chicago'): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization = app(ProvisionUserTenancy::class)->handle($owner);
        $organization->update(['timezone' => $timezone]);

        return [$owner, $organization->fresh()];
    }

    private function member(Organization $organization, string $role): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $organization->members()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    private function account(Organization $organization, SocialNetwork $network, array $attributes = []): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'organization_id' => $organization->id,
            'network' => $network,
            'external_id' => match ($network) {
                SocialNetwork::LinkedIn => 'urn:li:organization:777',
                SocialNetwork::GoogleBusiness => 'accounts/1/locations/2',
                default => (string) random_int(1000, 9999),
            },
            'name' => $network->label().' account',
            'access_token' => 'token-'.$network->value,
            'is_enabled' => true,
        ], $attributes));
    }

    private function photo(Organization $organization): MediaAsset
    {
        Storage::disk('local')->put('social/test.png', base64_decode(self::PNG));

        return MediaAsset::create([
            'organization_id' => $organization->id, 'path' => 'social/test.png', 'original_name' => 'van.png',
            'mime' => 'image/png', 'size_bytes' => 68, 'width' => 1, 'height' => 1, 'alt_text' => 'Our van',
        ]);
    }

    /**
     * @param  list<SocialAccount>  $accounts
     */
    private function schedule(Organization $organization, User $author, array $accounts, string $body, array $extra = [], string $intent = 'schedule'): SocialPost
    {
        $this->actingAs($author);

        return app(SavePost::class)->handle($organization, null, array_merge([
            'body' => $body,
            'accounts' => array_map(fn (SocialAccount $a) => $a->ulid, $accounts),
            'scheduled_at' => CarbonImmutable::now()->addHour(),
        ], $extra), $author, $intent);
    }

    private function runQueue(): void
    {
        $this->travel(2)->hours();
        app(SocialPublishing::class)->dispatchDue();
    }

    public function test_each_network_checks_its_own_rules(): void
    {
        $v = app(PostValidator::class);

        $this->assertContains('Instagram posts need at least one photo.', $v->check(SocialNetwork::Instagram, 'Hello', null, 0)['errors']);
        $this->assertSame([], $v->check(SocialNetwork::Instagram, 'Hello', null, 1)['errors']);
        $this->assertNotEmpty($v->check(SocialNetwork::Instagram, str_repeat('#tag ', 31), null, 1)['errors']);
        $this->assertNotEmpty($v->check(SocialNetwork::Instagram, 'Hi', 'https://x.test', 1)['warnings'], 'links are not clickable on Instagram');
        $this->assertNotEmpty($v->check(SocialNetwork::LinkedIn, str_repeat('a', 3001), null, 0)['errors']);
        $this->assertNotEmpty($v->check(SocialNetwork::GoogleBusiness, 'Call us on (512) 555-0147 today', null, 0)['errors'], 'Google rejects phone numbers');
        $this->assertSame([], $v->check(SocialNetwork::GoogleBusiness, 'Spring tune-ups are back', null, 0)['errors']);
        $this->assertSame([], $v->check(SocialNetwork::LinkedIn, 'One photo only', null, 3)['errors'], 'extra photos are a warning, not an error');
    }

    public function test_linkedin_text_is_escaped_and_hashtags_kept(): void
    {
        $this->assertSame('Tune-up \(20% off\) {hashtag|\#|HVAC} \@ us \# 1', LinkedInApi::commentary('Tune-up (20% off) #HVAC @ us # 1'));
    }

    public function test_connecting_meta_finds_pages_and_instagram_with_encrypted_tokens(): void
    {
        [$owner, $org] = $this->business();
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::sequence()
                ->push(['access_token' => 'short', 'expires_in' => 3600])
                ->push(['access_token' => 'long-user', 'expires_in' => 5184000]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [[
                'id' => '111', 'name' => 'Bright Plumbing', 'access_token' => 'page-token',
                'instagram_business_account' => ['id' => '999', 'username' => 'brightplumbing', 'name' => 'Bright Plumbing'],
            ]]]),
        ]);

        $this->actingAs($owner)->get(route('app.social.connect', 'meta'))->assertRedirectContains('facebook.com');
        $state = session('social_oauth')['state'];
        $this->get(route('app.social.connect.callback', ['connector' => 'meta', 'code' => 'abc', 'state' => $state]))->assertRedirect(route('app.social.accounts'));

        $accounts = SocialAccount::query()->forOrganization($org)->orderBy('network')->get();
        $this->assertSame(['facebook', 'instagram'], $accounts->pluck('network')->map->value->all());
        $this->assertSame('999', $accounts[1]->external_id);
        $this->assertSame('111', $accounts[1]->meta['page_id']);
        $this->assertSame('page-token', $accounts[0]->access_token);
        $this->assertNotSame('page-token', DB::table('social_accounts')->where('id', $accounts[0]->id)->value('access_token'), 'stored encrypted');
        $this->assertFalse($accounts[0]->is_enabled, 'two accounts found: the person chooses');

        Livewire::test(Accounts::class)->call('toggle', $accounts[0]->ulid);
        $this->assertTrue($accounts[0]->fresh()->is_enabled);
        $this->assertStringNotContainsString('page-token', $this->get(route('app.social.accounts'))->getContent());
    }

    public function test_a_forged_callback_is_refused(): void
    {
        [$owner] = $this->business();
        Http::fake();

        $this->actingAs($owner)->get(route('app.social.connect.callback', ['connector' => 'meta', 'code' => 'abc', 'state' => 'guess']))
            ->assertRedirect(route('app.social.accounts'));
        Http::assertNothingSent();
        $this->assertSame(0, SocialAccount::withoutGlobalScopes()->count());
    }

    public function test_owner_schedules_in_business_time_and_it_publishes_to_facebook(): void
    {
        [$owner, $org] = $this->business('America/Chicago');
        $fb = $this->account($org, SocialNetwork::Facebook, ['external_id' => '111']);
        Http::fake(['graph.facebook.com/*/111/feed' => Http::response(['id' => '111_222'])]);

        $this->actingAs($owner);
        Livewire::test(Compose::class)
            ->set('selected', [$fb->ulid])
            ->set('body', 'Spring AC tune-ups: book before May 1st.')
            ->set('link', 'https://bright.test/book')
            ->set('date', '2026-11-03')->set('time', '09:00')
            ->call('save', 'schedule')
            ->assertHasNoErrors();

        $post = SocialPost::sole();
        $this->assertSame(SocialPostStatus::Scheduled, $post->status, 'an owner needs no approval');
        $this->assertSame('2026-11-03 15:00:00', $post->scheduled_at->utc()->format('Y-m-d H:i:s'), '9:00 in Chicago (CST) is 15:00 UTC');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:59:00', 'UTC'));
        app(SocialPublishing::class)->dispatchDue();
        $this->assertSame(SocialPostStatus::Scheduled, $post->fresh()->status, 'not before its time');

        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:00:30', 'UTC'));
        app(SocialPublishing::class)->dispatchDue();

        $post->refresh();
        $this->assertSame(SocialPostStatus::Published, $post->status);
        $this->assertSame('https://www.facebook.com/111_222', $post->targets->first()->external_url);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/111/feed') && $r['message'] === 'Spring AC tune-ups: book before May 1st.' && $r['link'] === 'https://bright.test/book' && $r['access_token'] === 'token-facebook');
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.published', 'organization_id' => $org->id]);
    }

    public function test_instagram_builds_a_container_then_publishes_with_a_signed_image_link(): void
    {
        [$owner, $org] = $this->business();
        $ig = $this->account($org, SocialNetwork::Instagram, ['external_id' => '999']);
        $photo = $this->photo($org);
        Http::fake([
            'graph.facebook.com/*/999/media_publish' => Http::response(['id' => 'm1']),
            'graph.facebook.com/*/999/media' => Http::response(['id' => 'c1']),
            'graph.facebook.com/*/c1*' => Http::response(['status_code' => 'FINISHED']),
            'graph.facebook.com/*/m1*' => Http::response(['permalink' => 'https://instagram.com/p/abc']),
        ]);

        $this->schedule($org, $owner, [$ig], 'Fresh install today', ['media' => [$photo->ulid]], 'now');
        app(SocialPublishing::class)->dispatchDue();

        $this->assertSame(SocialPostStatus::Published, SocialPost::sole()->status);
        $this->assertSame('https://instagram.com/p/abc', SocialPostTarget::sole()->external_url);

        $imageUrl = null;
        Http::assertSent(function (HttpRequest $r) use (&$imageUrl) {
            if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/999/media')) {
                $imageUrl = $r['image_url'];
            }

            return true;
        });
        $this->assertStringContainsString('signature=', (string) $imageUrl);

        // The network can download it; a link without a valid signature can't.
        auth()->logout();
        $this->get($imageUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('media.public', $photo->ulid))->assertForbidden();
    }

    public function test_linkedin_and_google_posts_use_their_own_formats(): void
    {
        [$owner, $org] = $this->business();
        config(['social.connectors.google.enabled' => true]);
        $li = $this->account($org, SocialNetwork::LinkedIn);
        $google = $this->account($org, SocialNetwork::GoogleBusiness);
        Http::fake([
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:42']),
            'mybusiness.googleapis.com/*' => Http::response(['name' => 'accounts/1/locations/2/localPosts/9', 'searchUrl' => 'https://g.test/post']),
        ]);

        $this->schedule($org, $owner, [$li, $google], 'Spring tune-ups are back #HVAC', [
            'link_url' => 'https://bright.test/book',
            'options' => [$google->ulid => ['topic' => 'OFFER', 'title' => '20% off tune-ups', 'button' => 'BOOK', 'coupon' => 'SPRING']],
        ], 'now');
        app(SocialPublishing::class)->dispatchDue();

        $this->assertSame(SocialPostStatus::Published, SocialPost::sole()->status);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.linkedin.com/rest/posts'
            && $r->hasHeader('LinkedIn-Version') && $r['author'] === 'urn:li:organization:777'
            && $r['commentary'] === 'Spring tune-ups are back {hashtag|\#|HVAC}'
            && $r['content']['article']['source'] === 'https://bright.test/book');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'v4/accounts/1/locations/2/localPosts')
            && $r['topicType'] === 'OFFER' && $r['callToAction'] === ['actionType' => 'BOOK', 'url' => 'https://bright.test/book']
            && $r['offer']['couponCode'] === 'SPRING' && $r['event']['title'] === '20% off tune-ups');
        $this->assertSame('https://www.linkedin.com/feed/update/urn:li:share:42', SocialPostTarget::where('social_account_id', $li->id)->value('external_url'));
    }

    public function test_content_that_breaks_a_networks_rules_cannot_be_scheduled(): void
    {
        [$owner, $org] = $this->business();
        $ig = $this->account($org, SocialNetwork::Instagram);

        try {
            $this->schedule($org, $owner, [$ig], 'No photo here');
            $this->fail('Instagram without a photo must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('need at least one photo', implode(' ', $e->errors()['body']));
        }

        // A draft can be saved anyway.
        $this->schedule($org, $owner, [$ig], 'No photo yet', intent: 'draft');
        $this->assertSame(SocialPostStatus::Draft, SocialPost::sole()->status);
    }

    public function test_managers_and_our_team_need_an_owners_approval(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook);
        $manager = $this->member($org, 'manager');

        $post = $this->schedule($org, $manager, [$fb], 'Written by the manager');
        $this->assertSame(SocialPostStatus::InReview, $post->status);
        Notification::assertSentTo($owner, SocialApprovalRequested::class);
        Notification::assertNotSentTo($manager, SocialApprovalRequested::class);

        // Nothing goes out while it waits.
        Http::fake();
        $this->runQueue();
        Http::assertNothingSent();

        // The manager can't approve; the owner can.
        $this->expectsAuthorization(fn () => app(PostWorkflow::class)->approve($post, $manager));
        app(PostWorkflow::class)->approve($post, $owner);
        $this->assertSame(SocialPostStatus::Scheduled, $post->fresh()->status);
        $this->assertSame($owner->id, $post->fresh()->approved_by_user_id);

        // SureHelp staff viewing as the owner are not the owner.
        $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        request()->setLaravelSession(session()->driver()); // as on a web request, where "view as client" lives
        session([Impersonation::KEY => $staff->id]);
        $teamPost = $this->schedule($org, $owner, [$fb], 'Written by SureHelp for you');
        $this->assertSame(SocialPostStatus::InReview, $teamPost->status);
        $this->assertSame('team', $teamPost->source);
        $this->assertSame($staff->id, $teamPost->created_by_impersonator_id);
        $this->expectsAuthorization(fn () => app(PostWorkflow::class)->approve($teamPost, $owner));
        session()->forget(Impersonation::KEY);

        // Requesting changes sends it back as a draft with the note.
        app(PostWorkflow::class)->requestChanges($teamPost, $owner, 'Mention the 10-year warranty');
        $this->assertSame(SocialPostStatus::Draft, $teamPost->fresh()->status);
        $this->assertSame('Mention the 10-year warranty', $teamPost->fresh()->review_note);
    }

    public function test_with_approval_off_managers_publish_directly(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook);
        $this->actingAs($owner);
        Livewire::test(Accounts::class)->set('approvalMode', 'off')->call('saveApproval')->assertHasNoErrors();

        $post = $this->schedule($org, $this->member($org, 'manager'), [$fb], 'Straight out');
        $this->assertSame(SocialPostStatus::Scheduled, $post->status);
    }

    public function test_busy_networks_are_retried_then_the_post_fails_with_a_notice(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook);
        $busy = fn () => Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 503);
        // The first try and three retries fail; after a person retries, it works.
        Http::fake(['graph.facebook.com/*' => Http::sequence()->pushResponse($busy())->pushResponse($busy())->pushResponse($busy())->pushResponse($busy())->push(['id' => '1_2'])]);

        $this->schedule($org, $owner, [$fb], 'Try me', intent: 'now');
        app(SocialPublishing::class)->dispatchDue();

        $target = SocialPostTarget::sole();
        $this->assertSame('pending', $target->status);
        $this->assertSame(1, $target->attempts);
        $this->assertSame(SocialPostStatus::Publishing, SocialPost::sole()->status);

        foreach ([6, 16, 61] as $minutes) {
            $this->travel($minutes)->minutes();
            app(SocialPublishing::class)->dispatchDue();
        }

        $this->assertSame('failed', $target->fresh()->status);
        $this->assertSame(SocialPostStatus::Failed, SocialPost::sole()->status);
        Notification::assertSentTo($owner, SocialPostFailed::class);

        // Retry puts only the failed accounts back in the queue.
        app(PostWorkflow::class)->retry(SocialPost::sole(), $owner);
        app(SocialPublishing::class)->dispatchDue();
        $this->assertSame(SocialPostStatus::Published, SocialPost::sole()->status);
    }

    public function test_lost_access_flags_the_account_once_and_partly_publishes(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook, ['external_id' => '111']);
        $fb2 = $this->account($org, SocialNetwork::Facebook, ['external_id' => '222']);
        Http::fake([
            'graph.facebook.com/*/111/feed' => Http::response(['id' => '111_1']),
            'graph.facebook.com/*/222/feed' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 400),
        ]);

        $this->schedule($org, $owner, [$fb, $fb2], 'Hello both', intent: 'now');
        app(SocialPublishing::class)->dispatchDue();

        $this->assertSame(SocialPostStatus::PartlyPublished, SocialPost::sole()->status);
        $this->assertTrue($fb2->fresh()->needsReconnect());
        $this->assertFalse($fb->fresh()->needsReconnect());
        Notification::assertSentToTimes($owner, SocialAccountDisconnected::class, 1);
        Notification::assertSentTo($owner, SocialPostFailed::class);

        // It can't be chosen for new posts until reconnected.
        $this->expectException(ValidationException::class);
        $this->schedule($org, $owner, [$fb2], 'Again');
    }

    public function test_content_refused_by_the_network_fails_without_retrying(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 506, 'message' => 'Duplicate status message']], 400)]);

        $this->schedule($org, $owner, [$fb], 'Same again', intent: 'now');
        app(SocialPublishing::class)->dispatchDue();

        $target = SocialPostTarget::sole();
        $this->assertSame('failed', $target->status);
        $this->assertSame(0, $target->attempts);
        $this->assertSame('Duplicate status message', $target->last_error);
    }

    public function test_an_interrupted_publish_is_never_reposted_automatically(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook);
        $this->schedule($org, $owner, [$fb], 'Stuck', intent: 'now');
        SocialPostTarget::query()->update(['status' => 'publishing']);
        SocialPost::query()->update(['status' => 'publishing']);

        Http::fake();
        $this->travel(20)->minutes();
        app(SocialPublishing::class)->dispatchDue();

        Http::assertNothingSent();
        $this->assertSame('failed', SocialPostTarget::sole()->status);
        $this->assertStringContainsString('Check the account', SocialPostTarget::sole()->last_error);
    }

    public function test_cancelled_posts_never_go_out(): void
    {
        [$owner, $org] = $this->business();
        $post = $this->schedule($org, $owner, [$this->account($org, SocialNetwork::Facebook)], 'Never mind');
        app(PostWorkflow::class)->cancel($post, $owner);

        Http::fake();
        $this->runQueue();
        Http::assertNothingSent();
        $this->assertSame('cancelled', SocialPostTarget::sole()->status);
    }

    public function test_media_upload_and_isolation_between_businesses(): void
    {
        [$owner, $org] = $this->business();
        [$other, $otherOrg] = $this->business();
        $theirPhoto = $this->photo($otherOrg);
        $theirAccount = $this->account($otherOrg, SocialNetwork::Facebook);
        $theirs = $this->schedule($otherOrg, $other, [$theirAccount], 'Theirs');

        $this->actingAs($owner);
        Livewire::test(Media::class)->set('uploads', [UploadedFile::fake()->createWithContent('van.png', base64_decode(self::PNG))])->assertHasNoErrors();
        $mine = MediaAsset::query()->forOrganization($org)->sole();
        $this->assertSame(1, $mine->width);
        $this->get(route('app.social.media.show', $mine->ulid))->assertOk();
        $this->get(route('app.social.media.show', $theirPhoto->ulid))->assertNotFound();

        Livewire::test(Media::class)->set('uploads', [UploadedFile::fake()->createWithContent('notes.txt', 'hello')])->assertHasErrors('uploads.0');

        // Another business's accounts, media and posts are out of reach.
        $fb = $this->account($org, SocialNetwork::Facebook);
        try {
            $this->schedule($org, $owner, [$theirAccount], 'Hijack');
            $this->fail('another business\'s account must be refused');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->schedule($org, $owner, [$fb], 'Hijack', ['media' => [$theirPhoto->ulid]]);
            $this->fail('another business\'s photo must be refused');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->actingAs($owner)->get(route('app.social.posts.edit', $theirs))->assertNotFound();
    }

    public function test_pages_and_permissions(): void
    {
        [$owner, $org] = $this->business();
        $this->account($org, SocialNetwork::Facebook);
        $staff = $this->member($org, 'staff');

        foreach (['app.social.index', 'app.social.accounts', 'app.social.media', 'app.social.posts.create'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
            $this->actingAs($staff)->get(route($route))->assertForbidden();
        }
        $this->actingAs($owner)->get(route('app.social.index', ['view' => 'list']))->assertOk();
        $this->actingAs($owner)->get(route('app.dashboard'))->assertSee(route('app.social.index'));
    }

    public function test_mobile_api_lists_and_approves(): void
    {
        [$owner, $org] = $this->business();
        $fb = $this->account($org, SocialNetwork::Facebook);
        $post = $this->schedule($org, $this->member($org, 'manager'), [$fb], 'Please approve');

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/client/social/posts?status=awaiting_approval')->assertOk()
            ->assertJsonPath('data.posts.0.id', $post->ulid)
            ->assertJsonPath('data.posts.0.accounts.0.network', 'facebook')
            ->assertJsonMissingPath('data.posts.0.accounts.0.access_token');
        $this->postJson("/api/v1/client/social/posts/{$post->ulid}/approve")->assertOk()->assertJsonPath('data.post.status', 'scheduled');

        [$stranger] = $this->business();
        Sanctum::actingAs($stranger);
        $this->postJson("/api/v1/client/social/posts/{$post->ulid}/approve")->assertNotFound();
    }

    private function expectsAuthorization(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected the action to be refused.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
