<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Livewire\Admin\Social\Compose as HouseCompose;
use App\Livewire\Admin\Social\Schedule;
use App\Models\MediaAsset;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Social\House\HouseAutopilot;
use App\Services\Social\House\HouseSocial;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * Admin › Social media (D104): SureHelp's own accounts on the business social screens, and the daily
 * schedule that has the AI write posts a day ahead, with a picture or video by the mix.
 */
class HouseSocialTest extends TestCase
{
    use RefreshDatabase;

    private ScriptedAiProvider $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);
        config(['ai.social_provider' => null, 'ai.openai.api_key' => null]);
    }

    private function staff(string $role = 'super_admin'): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        if ($role !== 'super_admin') {
            $user->syncRoles([$role]);
        }
        $this->enableTwoFactor($user);

        return $user;
    }

    private function house(): Organization
    {
        return app(HouseSocial::class)->organization();
    }

    private function account(SocialNetwork $network, string $id = '1'): SocialAccount
    {
        return SocialAccount::create(['organization_id' => $this->house()->id, 'network' => $network, 'external_id' => $network->value.$id,
            'name' => 'SureHelp on '.$network->label(), 'access_token' => 'token', 'is_enabled' => true]);
    }

    private function picture(string $name = 'office.jpg', ?string $lastUsed = null): MediaAsset
    {
        Storage::disk('local')->put('social/'.$name, 'jpeg-bytes');

        return MediaAsset::create(['organization_id' => $this->house()->id, 'path' => 'social/'.$name, 'original_name' => $name,
            'mime' => 'image/jpeg', 'size_bytes' => 10, 'last_used_at' => $lastUsed]);
    }

    /** @param  array<string, mixed>  $changes */
    private function schedule(array $changes = []): void
    {
        app(HouseSocial::class)->save(array_replace(app(HouseSocial::class)->settings(), ['enabled' => true, 'timezone' => 'America/New_York',
            'times' => ['09:00', '18:00'], 'mix' => ['text' => 0, 'image' => 100, 'video' => 0], 'pictures' => 'library'], $changes), $this->staff());
    }

    private function aiWrites(array $posts, string $picturePrompt = ''): void
    {
        $this->ai->reply(json_encode(['posts' => collect($posts)->map(fn ($text, $network) => ['network' => $network, 'text' => $text])->values()->all(),
            'picture_prompt' => $picturePrompt, 'alt_text' => 'A receptionist on a call']));
    }

    public function test_staff_with_social_access_open_the_screens_and_others_do_not(): void
    {
        $admin = $this->staff();
        foreach (['admin.social.index', 'admin.social.schedule', 'admin.social.accounts', 'admin.social.media', 'admin.social.posts.create'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
        $this->actingAs($admin)->get(route('admin.home'))->assertSee('Social media');

        // A business owner can't reach them, and an agent isn't platform staff.
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        $this->actingAs($owner)->get(route('admin.social.index'))->assertForbidden();
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true, 'must_change_password' => false]);
        $this->enableTwoFactor($agent);
        $this->actingAs($agent)->get(route('admin.social.schedule'))->assertForbidden();

        // The SureHelp business is never listed with customers.
        $this->actingAs($admin)->get(route('admin.organizations.index'))->assertDontSee('organizations/'.$this->house()->id.'"', false);
        $this->assertSame(0, Organization::query()->external()->where('id', $this->house()->id)->count());
    }

    public function test_a_business_never_sees_surehelps_posts_or_media(): void
    {
        $post = SocialPost::create(['organization_id' => $this->house()->id, 'body' => 'SureHelp only', 'status' => SocialPostStatus::Draft]);
        $media = $this->picture();
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        app(ProvisionUserTenancy::class)->handle($owner);
        $this->enableTwoFactor($owner);

        $this->actingAs($owner)->get(route('app.social.posts.edit', $post))->assertNotFound();
        $this->actingAs($owner)->get(route('app.social.media.show', $media->ulid))->assertNotFound();
        $this->actingAs($this->staff())->get(route('admin.social.media.show', $media->ulid))->assertOk();
        $this->assertStringContainsString('/admin/social/media/', $media->url());
    }

    public function test_each_time_gets_one_post_written_by_the_ai_for_every_network(): void
    {
        $facebook = $this->account(SocialNetwork::Facebook);
        $linkedin = $this->account(SocialNetwork::LinkedIn);
        $picture = $this->picture();
        $this->schedule();
        $this->aiWrites(['facebook' => 'Never miss a call again. https://surehelp.test', 'linkedin' => 'Small teams win on answered calls.']);
        $this->aiWrites(['facebook' => 'Evening post', 'linkedin' => 'Evening post on LinkedIn']);

        // 7:00 in New York: the 9:00 and 18:00 posts are written now.
        $now = CarbonImmutable::parse('2026-11-02 07:00', 'America/New_York');
        $this->travelTo($now);
        $result = app(HouseAutopilot::class)->run($now);
        $this->assertSame(2, $result['prepared'], implode(' ', $result['errors']));

        $post = SocialPost::query()->forOrganization($this->house())->where('autopilot_slot', '2026-11-02 14:00')->sole();
        $this->assertSame(SocialPostStatus::Scheduled, $post->status, 'goes out on its own when approval is off');
        $this->assertSame('autopilot', $post->source);
        $this->assertTrue($post->scheduled_at->eq(CarbonImmutable::parse('2026-11-02 14:00', 'UTC')));
        $this->assertEqualsCanonicalizing([$facebook->id, $linkedin->id], $post->targets()->pluck('social_account_id')->all());
        $this->assertSame('Small teams win on answered calls.', $post->targets()->where('social_account_id', $linkedin->id)->value('body'));
        $this->assertSame([$picture->id], $post->media()->pluck('media_assets.id')->all());
        $this->assertNotNull($picture->fresh()->last_used_at);
        $this->assertStringContainsString('Topic:', $this->ai->requests[0]->context);
        $this->assertDatabaseHas('audit_logs', ['action' => 'house_social.prepared']);

        // Running again in the same window writes nothing new: one post per time.
        $this->assertSame(0, app(HouseAutopilot::class)->run($now->addMinutes(15))['prepared']);
        $this->assertSame(2, SocialPost::query()->forOrganization($this->house())->count());
    }

    public function test_with_approval_on_posts_wait_and_staff_approve_them(): void
    {
        $this->account(SocialNetwork::LinkedIn);
        $this->schedule(['approval' => true, 'mix' => ['text' => 100, 'image' => 0, 'video' => 0], 'times' => ['09:00']]);
        $this->aiWrites(['linkedin' => 'A text post for review.']);
        $now = CarbonImmutable::parse('2026-11-02 07:00', 'America/New_York');
        $this->travelTo($now);
        app(HouseAutopilot::class)->run($now);

        $post = SocialPost::query()->forOrganization($this->house())->sole();
        $this->assertSame(SocialPostStatus::InReview, $post->status);
        $this->assertSame(0, $post->media()->count(), 'text only');

        $this->actingAs($this->staff());
        Livewire::test(HouseCompose::class, ['post' => $post->ulid])->call('approve');
        $this->assertSame(SocialPostStatus::Scheduled, $post->fresh()->status);
    }

    public function test_chatgpt_makes_a_picture_when_the_library_has_been_used(): void
    {
        config(['ai.openai.api_key' => 'sk-test', 'ai.openai.image_model' => 'gpt-image-test']);
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode('png-bytes')]]])]);
        $this->account(SocialNetwork::Facebook);
        $this->picture('old.jpg', now()->subDays(3)->toDateTimeString());
        $this->schedule(['pictures' => 'when_needed', 'times' => ['09:00']]);
        $this->aiWrites(['facebook' => 'Answered calls, happy customers.'], 'A friendly receptionist with a headset at a bright desk');
        $now = CarbonImmutable::parse('2026-11-02 07:00', 'America/New_York');
        $this->travelTo($now);

        $this->assertSame(1, app(HouseAutopilot::class)->run($now)['prepared']);
        $made = MediaAsset::query()->forOrganization($this->house())->where('source', MediaAsset::SOURCE_AI)->sole();
        $this->assertSame('A receptionist on a call', $made->alt_text);
        $this->assertSame([$made->id], SocialPost::query()->forOrganization($this->house())->sole()->media()->pluck('media_assets.id')->all());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['prompt'], 'receptionist with a headset') && $r['model'] === 'gpt-image-test');
    }

    public function test_problems_are_reported_not_hidden(): void
    {
        $this->schedule(['times' => ['09:00']]);
        $now = CarbonImmutable::parse('2026-11-02 07:00', 'America/New_York');
        $this->travelTo($now);
        $result = app(HouseAutopilot::class)->run($now);
        $this->assertSame(0, $result['prepared']);
        $this->assertStringContainsString('Connect at least one account', $result['errors'][0]);

        // Turned off: nothing runs.
        $this->schedule(['enabled' => false]);
        $this->assertSame(['prepared' => 0, 'errors' => []], app(HouseAutopilot::class)->run($now));
    }

    public function test_the_schedule_screen_saves_and_prepares_the_next_post(): void
    {
        $this->account(SocialNetwork::LinkedIn);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', 'America/New_York'));
        $this->actingAs($this->staff());

        Livewire::test(Schedule::class)
            ->set('form.enabled', true)
            ->set('newTime', '25:00')->call('addTime')->assertHasErrors('newTime')
            ->set('newTime', '12:30')->call('addTime')
            ->set('form.mix', ['text' => 100, 'image' => 0, 'video' => 0])
            ->call('save')->assertHasNoErrors()->assertDispatched('toast', type: 'success');
        $this->assertContains('12:30', app(HouseSocial::class)->settings()['times']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'house_social.schedule_saved']);

        $this->aiWrites(['linkedin' => 'Prepared on demand.']);
        Livewire::test(Schedule::class)->call('prepareNext')->assertDispatched('toast', type: 'success')->assertSee('Prepared on demand.');
        $this->assertSame('2026-11-02 14:00', SocialPost::query()->forOrganization($this->house())->sole()->autopilot_slot);

        // Support staff have no social media access at all.
        $support = $this->staff('support_agent');
        $this->actingAs($support)->get(route('admin.social.schedule'))->assertForbidden();
        Livewire::test(Schedule::class)->assertForbidden();
    }

    public function test_connecting_an_account_from_the_admin_comes_back_to_the_admin(): void
    {
        config(['social.connectors.linkedin.client_id' => 'li-client', 'social.connectors.linkedin.client_secret' => 'li-secret']);
        $admin = $this->staff();
        $response = $this->actingAs($admin)->get(route('admin.social.connect', 'linkedin'));
        $response->assertRedirect();
        $this->assertStringContainsString(urlencode(route('admin.social.connect.callback', 'linkedin')), $response->headers->get('Location'));
        $this->assertSame($this->house()->id, session('social_oauth')['organization_id']);

        // A forged or stale callback goes back to the admin screen with nothing connected.
        $this->actingAs($admin)->get(route('admin.social.connect.callback', ['linkedin', 'state' => 'wrong', 'code' => 'x']))
            ->assertRedirect(route('admin.social.accounts'));
        $this->assertSame(0, SocialAccount::query()->count());

        // Support staff can't start it.
        $this->actingAs($this->staff('support_agent'))->get(route('admin.social.connect', 'linkedin'))->assertForbidden();
    }
}
