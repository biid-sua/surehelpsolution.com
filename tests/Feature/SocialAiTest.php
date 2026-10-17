<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\KnowledgeVisibility;
use App\Enums\SocialNetwork;
use App\Enums\SocialPostStatus;
use App\Livewire\Client\Social\Accounts;
use App\Livewire\Client\Social\Compose;
use App\Livewire\Client\Social\Index;
use App\Models\BusinessProfile;
use App\Models\KnowledgeItem;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialAiRun;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Social\SocialPublishing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * G-2 — the AI content studio for social posts (D66): writing per network, rewriting, fact checks,
 * brand voice, the monthly allowance, and month plans that land as drafts needing an owner.
 */
class SocialAiTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private ScriptedAiProvider $ai;

    private User $owner;

    private Organization $business;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 10:00', self::TZ));
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);

        $this->owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business = app(ProvisionUserTenancy::class)->handle($this->owner);
        $this->business->update(['name' => 'Rivera Plumbing', 'timezone' => self::TZ]);
        BusinessProfile::create(['organization_id' => $this->business->id, 'display_name' => 'Rivera Plumbing', 'phone' => '(512) 555-0100',
            'brand_voice' => 'Warm and down-to-earth. Never say "cheap".']);
        KnowledgeItem::create(['organization_id' => $this->business->id, 'type' => 'faq', 'title' => 'Drain cleaning price', 'content' => 'Drain cleaning from $99.', 'visibility' => KnowledgeVisibility::Public]);
        KnowledgeItem::create(['organization_id' => $this->business->id, 'type' => 'faq', 'title' => 'Staff only', 'content' => 'Gate code 4417.', 'visibility' => KnowledgeVisibility::Internal]);
        $this->business = $this->business->fresh();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function account(SocialNetwork $network): SocialAccount
    {
        return SocialAccount::create([
            'organization_id' => $this->business->id, 'network' => $network, 'external_id' => $network->value.'-1',
            'name' => $network->label().' account', 'access_token' => 'token', 'is_enabled' => true,
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $this->business->members()->attach($user->id, ['role' => 'manager', 'status' => 'active']);

        return $user;
    }

    public function test_the_ai_writes_a_version_for_each_network_and_flags_what_it_cannot_back_up(): void
    {
        $facebook = $this->account(SocialNetwork::Facebook);
        $instagram = $this->account(SocialNetwork::Instagram);
        $this->ai->reply(json_encode([
            'posts' => [
                ['network' => 'facebook', 'text' => 'Slow drain? Our team clears it from $99. Call (512) 555-0100.'],
                ['network' => 'instagram', 'text' => "Slow drain? We've got you. 20% off this week!\n\n#plumbing #austin"],
            ],
            'unsupported_claims' => ['Same-day service'],
        ]));

        $this->actingAs($this->owner);
        $component = Livewire::test(Compose::class)
            ->set('aiBrief', 'Drain cleaning, same-day service')
            ->call('writeWithAi')
            ->assertHasNoErrors()
            ->assertSet('body', 'Slow drain? Our team clears it from $99. Call (512) 555-0100.')
            ->assertSet("customOn.{$instagram->ulid}", true)
            ->assertSet("customOn.{$facebook->ulid}", false)
            ->assertSet('aiAssisted', true);

        $flags = $component->get('aiFlags');
        $this->assertContains('The AI couldn\'t confirm: Same-day service', $flags);
        $this->assertTrue(collect($flags)->contains(fn ($f) => str_contains($f, '20%')));
        $this->assertFalse(collect($flags)->contains(fn ($f) => str_contains($f, '$99') || str_contains($f, '555-0100')));

        // Public facts and the brand voice go to the AI; internal knowledge never does.
        $request = $this->ai->requests[0];
        $this->assertStringContainsString('Never say "cheap"', $request->instructions);
        $this->assertStringContainsString('Drain cleaning from $99', $request->instructions);
        $this->assertStringNotContainsString('4417', $request->instructions);
        $this->assertNotNull($request->outputSchema);

        $run = SocialAiRun::query()->where('organization_id', $this->business->id)->sole();
        $this->assertSame(['write', 'done', 1900, 50], [$run->kind, $run->status, $run->input_tokens, $run->output_tokens]);

        $component->call('save', 'draft');
        $this->assertTrue(SocialPost::query()->where('organization_id', $this->business->id)->sole()->ai_assisted);
    }

    public function test_a_rewrite_replaces_the_text_and_fits_the_strictest_network(): void
    {
        $this->account(SocialNetwork::GoogleBusiness);
        $this->ai->reply(json_encode(['text' => str_repeat('Clean drains, happy homes. ', 80), 'unsupported_claims' => []]));

        $this->actingAs($this->owner);
        $component = Livewire::test(Compose::class)->set('body', 'A long post about drains.')->call('rewriteWithAi', 'shorter')->assertHasNoErrors();

        $this->assertLessThanOrEqual(1500, mb_strlen($component->get('body')));
        $this->assertStringStartsWith('Clean drains, happy homes.', $component->get('body'));
        $this->assertStringContainsString('half as long', $this->ai->requests[0]->messages[0]['content']);
    }

    public function test_the_monthly_allowance_and_an_unavailable_ai_stop_it_without_using_tokens(): void
    {
        $this->account(SocialNetwork::Facebook);
        config(['social.ai.monthly_tokens' => 4000]);
        SocialAiRun::create(['organization_id' => $this->business->id, 'kind' => 'write', 'status' => 'done', 'input_tokens' => 1500, 'output_tokens' => 0]);

        $this->actingAs($this->owner);
        Livewire::test(Compose::class)->set('aiBrief', 'Winter pipe tips')->call('writeWithAi')
            ->assertHasErrors('aiBrief')->assertSee('used this month');
        $this->assertSame([], $this->ai->requests);

        config(['social.ai.monthly_tokens' => 150000]);
        $this->ai->fail();
        Livewire::test(Compose::class)->set('aiBrief', 'Winter pipe tips')->call('writeWithAi')->assertHasErrors('aiBrief');
        $failed = SocialAiRun::query()->where('status', 'failed')->sole();
        $this->assertSame(0, $failed->input_tokens + $failed->output_tokens);
    }

    public function test_a_month_plan_becomes_ai_drafts_that_need_an_owner(): void
    {
        $facebook = $this->account(SocialNetwork::Facebook);
        $this->account(SocialNetwork::GoogleBusiness);
        $this->ai->reply(json_encode([
            'posts' => [
                ['date' => '2026-11-12', 'time' => '09:30', 'theme' => 'Winter prep', 'text' => 'Freezing nights are coming. Here is how to protect your pipes.', 'photo_idea' => 'A wrapped outdoor tap'],
                ['date' => '2026-11-18', 'time' => '12:00', 'theme' => 'Services', 'text' => 'Water heater acting up? Since 1990 we have fixed them all.', 'photo_idea' => 'The team with a new heater'],
                ['date' => '2026-11-05', 'time' => '12:00', 'theme' => 'Too early', 'text' => 'This day has passed.', 'photo_idea' => ''],
                ['date' => '2026-12-02', 'time' => '12:00', 'theme' => 'Next month', 'text' => 'Not this month.', 'photo_idea' => ''],
            ],
            'unsupported_claims' => [],
        ]));

        $this->actingAs($this->owner);
        Livewire::test(Index::class)->call('openPlan')->assertSet('planMonth', '2026-11')
            ->set('planPerWeek', 2)->set('planFocus', 'frozen pipes')
            ->call('planWithAi')->assertHasNoErrors()->assertSee('2 AI drafts added for November 2026');

        $this->assertStringContainsString('frozen pipes', $this->ai->requests[0]->messages[0]['content']);
        $this->assertStringContainsString('between 2026-11-11 and 2026-11-30', $this->ai->requests[0]->messages[0]['content']);
        $posts = SocialPost::query()->where('organization_id', $this->business->id)->orderBy('scheduled_at')->get();
        $this->assertCount(2, $posts);
        $first = $posts[0];
        $this->assertSame(['ai', SocialPostStatus::Draft, true], [$first->source, $first->status, $first->ai_assisted]);
        $this->assertSame('2026-11-12 09:30', $first->scheduled_at->setTimezone(self::TZ)->format('Y-m-d H:i'));
        $this->assertCount(2, $first->targets);
        $this->assertStringContainsString('Photo: A wrapped outdoor tap', (string) $first->ai_note);
        $this->assertStringContainsString('since 1990', mb_strtolower((string) $posts[1]->ai_note));
        $this->assertSame('AI draft', $first->aiLabel());

        // Drafts are never published, even on their day.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 10:00', self::TZ));
        app(SocialPublishing::class)->dispatchDue();
        $this->assertSame(SocialPostStatus::Draft, $first->fresh()->status);

        // A manager scheduling an AI draft sends it to the owner; the owner scheduling one approves it.
        $this->actingAs($this->manager());
        Livewire::test(Compose::class, ['post' => $posts[1]->ulid])->set('date', '2026-11-18')->set('time', '12:00')->call('save', 'schedule')->assertHasNoErrors();
        $this->assertSame(SocialPostStatus::InReview, $posts[1]->fresh()->status);

        $this->actingAs($this->owner);
        Livewire::test(Compose::class, ['post' => $first->ulid])->set('date', '2026-11-13')->set('time', '09:30')->call('save', 'schedule')->assertHasNoErrors();
        $this->assertSame(SocialPostStatus::Scheduled, $first->fresh()->status);
        $this->assertSame('Written by AI', $first->fresh()->aiLabel());
        $this->assertSame([$facebook->id], [$first->fresh()->targets()->orderBy('id')->first()->social_account_id]);
    }

    public function test_only_one_plan_at_a_time_and_only_for_coming_months(): void
    {
        $this->actingAs($this->owner);
        SocialAiRun::create(['organization_id' => $this->business->id, 'kind' => 'plan', 'status' => 'running', 'input' => ['month' => '2026-11']]);
        Livewire::test(Index::class)->set('planMonth', '2026-12')->call('planWithAi')->assertHasErrors('planMonth');

        SocialAiRun::query()->update(['status' => 'done']);
        Livewire::test(Index::class)->set('planMonth', '2026-10')->call('planWithAi')->assertHasErrors('planMonth');
        Livewire::test(Index::class)->set('planMonth', '2027-04')->call('planWithAi')->assertHasErrors('planMonth');
        $this->assertSame([], $this->ai->requests);
    }

    public function test_the_brand_voice_is_saved_by_people_who_manage_posts(): void
    {
        $this->actingAs($this->manager());
        Livewire::test(Accounts::class)->assertSet('brandVoice', 'Warm and down-to-earth. Never say "cheap".')
            ->set('brandVoice', 'Short and cheerful.')->call('saveVoice')->assertHasNoErrors();

        $this->assertSame('Short and cheerful.', BusinessProfile::query()->where('organization_id', $this->business->id)->value('brand_voice'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.brand_voice_changed', 'organization_id' => $this->business->id]);
    }
}
