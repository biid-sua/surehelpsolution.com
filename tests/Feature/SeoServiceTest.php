<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Seo\Index as AdminSeo;
use App\Livewire\Client\Seo as SeoPage;
use App\Models\Addon;
use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Models\OrganizationAddon;
use App\Models\Plan;
use App\Models\SearchConsoleConnection;
use App\Models\SeoDailyMetric;
use App\Models\SeoTask;
use App\Models\User;
use App\Models\Website;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Billing\FeatureAccess;
use App\Services\Seo\SearchConsole;
use App\Services\Seo\SeoDesk;
use App\Services\Seo\SeoStats;
use App\Services\Seo\SeoTaskCatalog;
use App\Services\Seo\SeoTokens;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ScriptedAiProvider;
use Tests\TestCase;

/**
 * The SEO service (D57): a paid add-on; AI does the SEO work within a monthly token allowance;
 * the business reviews it; Search Console and website history show the difference.
 */
class SeoServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Chicago';

    private ScriptedAiProvider $ai;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-15 09:00', self::TZ));
        $this->ai = new ScriptedAiProvider;
        $this->app->instance(AiProvider::class, $this->ai);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization} */
    private function business(bool $seo = true): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => self::TZ, 'setup_completed_at' => now()])->save();
        BusinessProfile::create(['organization_id' => $org->id, 'display_name' => 'Rivera Plumbing', 'phone' => '(512) 555-0100']);
        $plan = Plan::create(['slug' => 'pro-'.$org->id, 'name' => 'Pro', 'price_cents' => 29900, 'interval' => 'month', 'trial_days' => 0]);
        app(ManageSubscription::class)->subscribe($org->fresh(), $plan, $this->admin, CarbonImmutable::today(), false);
        if ($seo) {
            $addon = Addon::firstOrCreate(['slug' => 'seo_service'], ['name' => 'SEO service', 'price_cents' => 19900]);
            OrganizationAddon::create(['organization_id' => $org->id, 'addon_id' => $addon->id, 'status' => 'active', 'price_cents' => 19900, 'started_on' => now()->toDateString()]);
        }
        app()->forgetScopedInstances();

        return [$owner, $org->fresh()];
    }

    private function keywordPlan(): string
    {
        return json_encode([
            'summary' => 'Emergency searches in Austin are the opening.',
            'keywords' => [['keyword' => 'emergency plumber austin', 'intent' => 'urgent', 'priority' => 'high', 'target_page' => 'https://rivera.test/', 'why' => 'High intent.']],
            'content_ideas' => [['title' => 'What to do when a pipe bursts', 'why' => 'Common question.']],
        ]);
    }

    public function test_it_is_a_paid_add_on_offered_until_the_business_takes_it(): void
    {
        [$owner, $org] = $this->business(seo: false);
        $this->assertSame(FeatureAccess::PLAN, app(FeatureAccess::class)->mode('seo_service'), 'closed by default');
        Addon::create(['slug' => 'seo_service', 'name' => 'SEO service', 'price_cents' => 19900]);

        $this->actingAs($owner)->get(route('app.seo'))->assertOk()->assertSee('SEO service')->assertSee('$199');
        $this->get(route('app.seo.search-console.connect'))->assertRedirect(route('app.billing'));
        $this->expectsNoAiWhenOff($org);

        $this->ai->reply($this->keywordPlan());
        Livewire::test(SeoPage::class)->call('turnOn')->assertSet('tab', 'work');

        $this->assertTrue(OrganizationAddon::query()->where('organization_id', $org->id)->active()->exists());
        $task = SeoTask::query()->where('organization_id', $org->id)->sole();
        $this->assertSame(['keyword_plan', 'done', 'autopilot'], [$task->type, $task->status, $task->source]);
        $this->assertSame('emergency plumber austin', $task->output['keywords'][0]['keyword']);
    }

    private function expectsNoAiWhenOff(Organization $org): void
    {
        try {
            app(SeoDesk::class)->request($org, 'keyword_plan', [], null);
            $this->fail('A business without the service must not start SEO work.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('isn\'t switched on', $e->getMessage());
        }
        $this->assertSame([], $this->ai->requests);
    }

    public function test_tasks_use_structured_output_and_count_against_the_monthly_allowance(): void
    {
        [$owner, $org] = $this->business();
        $org->forceFill(['seo_token_allowance' => 7000])->save();

        $this->ai->reply($this->keywordPlan());
        $this->actingAs($owner);
        Livewire::test(SeoPage::class)->set('input.focus', 'drain cleaning')->call('run', 'keyword_plan');

        $request = $this->ai->requests[0];
        $this->assertSame('object', $request->outputSchema['type']);
        $this->assertFalse($request->outputSchema['additionalProperties']);
        $this->assertArrayHasKey('keywords', $request->outputSchema['properties']);
        $this->assertSame('high', $request->effort);
        $this->assertStringContainsString('Rivera Plumbing', $request->instructions);
        $this->assertStringContainsString('drain cleaning', $request->messages[0]['content']);

        $task = SeoTask::query()->where('organization_id', $org->id)->sole();
        $this->assertSame(1950, $task->tokens(), 'input + cached input + output');
        $this->assertSame(1950, app(SeoTokens::class)->used($org));

        // 3,900 of 7,000 used leaves less than the 5,000 a task needs to start.
        $this->ai->reply($this->keywordPlan());
        Livewire::test(SeoPage::class)->call('run', 'faq')->assertHasNoErrors();
        Livewire::test(SeoPage::class)->call('run', 'keyword_plan')->assertDispatched('toast', type: 'warning');
        $this->assertSame(2, SeoTask::query()->where('organization_id', $org->id)->count());

        // A new month starts a new allowance.
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 09:00', self::TZ));
        $this->assertSame(7000, app(SeoTokens::class)->remaining($org));
    }

    public function test_cut_off_refused_or_unreachable_answers_fail_clearly(): void
    {
        [, $org] = $this->business();
        $desk = app(SeoDesk::class);

        $this->ai->reply('{"summary": "half', 'max_tokens');
        $task = $desk->request($org, 'keyword_plan', [], null)->fresh();
        $this->assertSame('failed', $task->status);
        $this->assertStringContainsString('cut off', $task->error);
        $this->assertSame(1950, $task->tokens(), 'tokens the AI used still count');

        $this->ai->fail();
        $task = $desk->request($org, 'faq', [], null)->fresh();
        $this->assertSame('failed', $task->status);
        $this->assertSame(0, $task->tokens());
    }

    public function test_page_titles_need_the_checked_pages_of_a_verified_website(): void
    {
        [, $org] = $this->business();
        $desk = app(SeoDesk::class);
        $this->assertStringContainsString('website', (string) $desk->blocker($org, 'page_meta'));

        Website::create(['organization_id' => $org->id, 'url' => 'https://rivera.test/', 'host' => 'rivera.test', 'verified_at' => now(),
            'health' => ['pages' => [['url' => 'https://rivera.test/', 'title' => 'Home', 'description' => null, 'h1' => null]]]]);
        $this->assertNull($desk->blocker($org, 'page_meta'));

        $this->ai->reply(json_encode(['pages' => [['url' => 'https://rivera.test/', 'title' => str_repeat('T', 90), 'description' => 'Fast plumbing.', 'h1' => 'Plumbers in Austin', 'why' => 'Clearer.']]]));
        $task = $desk->request($org, 'page_meta', [], null)->fresh();

        $this->assertSame('https://rivera.test/', $task->input['pages'][0]['url']);
        $this->assertStringContainsString('"Home"', $this->ai->requests[0]->messages[0]['content']);
        $this->assertSame(70, mb_strlen($task->output['pages'][0]['title']), 'lengths are enforced in code');
    }

    public function test_the_business_reviews_and_marks_work_live_and_staff_cannot(): void
    {
        [$owner, $org] = $this->business();
        $this->ai->reply($this->keywordPlan());
        $task = app(SeoDesk::class)->request($org, 'keyword_plan', [], $owner);

        $this->actingAs($owner);
        $page = Livewire::test(SeoPage::class)->set('tab', 'work')->set('selected', $task->ulid)->assertSee('emergency plumber austin');
        $page->call('review', 'approved');
        $this->assertSame('approved', $task->fresh()->review);
        $page->call('markLive');
        $this->assertNotNull($task->fresh()->applied_at);

        // Approved but not live shows in the staff queue.
        $task->update(['applied_at' => null]);
        $this->actingAs($this->admin);
        Livewire::test(AdminSeo::class)->assertSee('Rivera Plumbing')->set('selected', $task->ulid)->call('markLive');
        $this->assertNotNull($task->fresh()->applied_at);

        // Team members without SEO permission can't open it.
        $staff = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org->members()->attach($staff->id, ['role' => 'staff', 'status' => 'active']);
        $this->actingAs($staff)->get(route('app.seo'))->assertForbidden();
    }

    public function test_one_business_never_sees_another_business_seo_work(): void
    {
        [, $other] = $this->business();
        $this->ai->reply($this->keywordPlan());
        $theirs = app(SeoDesk::class)->request($other, 'keyword_plan', [], null);

        [$owner] = $this->business();
        $this->actingAs($owner);
        $page = Livewire::test(SeoPage::class)->set('tab', 'work')->set('selected', $theirs->ulid)->assertDontSee('emergency plumber austin');
        try {
            $page->call('markLive');
            $this->fail('Another business\'s task must not be found.');
        } catch (ModelNotFoundException) {
        }
        $this->assertNull($theirs->fresh()->applied_at);
    }

    public function test_statistics_compare_before_the_service_with_the_last_28_days(): void
    {
        [, $org] = $this->business();

        // 60 days of Google data: 2 clicks a day before the service, 5 a day after; position 18 → 9.
        $end = CarbonImmutable::today(self::TZ)->subDays(2);
        for ($day = 59; $day >= 0; $day--) {
            $date = $end->subDays($day);
            $after = $day < 28;
            SeoDailyMetric::create(['organization_id' => $org->id, 'date' => $date->toDateString(), 'clicks' => $after ? 5 : 2, 'impressions' => 100, 'position' => $after ? 9 : 18]);
        }
        Carbon::setTestNow($end->subDays(27)->setTime(9, 0));
        $this->ai->reply($this->keywordPlan());
        $task = app(SeoDesk::class)->request($org, 'keyword_plan', [], null);
        $task->update(['applied_at' => now()]);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-15 09:00', self::TZ));

        $stats = app(SeoStats::class)->for($org);
        $this->assertTrue($stats['has_search_data']);
        $this->assertSame(['now' => 140, 'before' => 56], array_intersect_key($stats['tiles']['clicks'], ['now' => 1, 'before' => 1]));
        $this->assertSame(9.0, $stats['tiles']['position']['now']);
        $this->assertSame(18.0, $stats['tiles']['position']['before']);
        $this->assertSame('down', $stats['tiles']['position']['better']);
        $this->assertCount(SeoStats::WEEKS, $stats['weekly']['labels']);
        $this->assertSame([['index' => $stats['weekly']['markers'][0]['index'], 'label' => 'Keyword plan']], $stats['weekly']['markers']);
    }

    public function test_search_console_statistics_are_pulled_daily(): void
    {
        [, $org] = $this->business();
        $connection = SearchConsoleConnection::create(['organization_id' => $org->id, 'access_token' => 'tok', 'refresh_token' => 'ref',
            'token_expires_at' => now()->addHour(), 'site_url' => 'sc-domain:rivera.test', 'status' => 'active']);
        Http::fake([
            '*/searchAnalytics/query' => Http::sequence()
                ->push(['rows' => [['keys' => ['2026-10-10'], 'clicks' => 4, 'impressions' => 120, 'position' => 11.26]]])
                ->push(['rows' => [['keys' => ['plumber austin'], 'clicks' => 3, 'impressions' => 80, 'position' => 7.4]]])
                ->push([], 401),
        ]);

        $this->artisan('seo:daily')->assertSuccessful();

        $this->assertDatabaseHas('seo_daily_metrics', ['organization_id' => $org->id, 'clicks' => 4, 'impressions' => 120]);
        $this->assertSame('plumber austin', $connection->fresh()->top_queries[0]['query']);
        $this->assertNotNull($connection->fresh()->last_synced_at);
        Http::assertSent(fn ($r) => str_contains($r->url(), rawurlencode('sc-domain:rivera.test')) && $r->hasHeader('Authorization', 'Bearer tok'));

        // Lost access asks the business to reconnect instead of retrying forever.
        app(SearchConsole::class)->pull($connection->fresh());
        $this->assertSame(SearchConsoleConnection::STATUS_NEEDS_REAUTH, $connection->fresh()->status);
    }

    public function test_autopilot_does_the_monthly_work_unless_switched_off(): void
    {
        [, $org] = $this->business();
        [, $paused] = $this->business();
        $paused->forceFill(['seo_settings' => ['autopilot' => false]])->save();
        [, $without] = $this->business(seo: false);

        $this->artisan('seo:daily')->assertSuccessful();
        $this->assertSame(0, SeoTask::query()->count(), 'only on the 1st');

        $this->ai->reply($this->keywordPlan())->reply(json_encode(['posts' => [['title' => 'Winter pipes', 'body' => 'Keep them warm.', 'button' => 'CALL']]]));
        $this->artisan('seo:daily', ['--autopilot' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['keyword_plan', 'gbp_posts'], SeoTask::query()->where('organization_id', $org->id)->pluck('type')->all());
        $this->assertSame(0, SeoTask::query()->whereIn('organization_id', [$paused->id, $without->id])->count());

        // Not repeated while the last month's work is recent.
        $this->artisan('seo:daily', ['--autopilot' => true])->assertSuccessful();
        $this->assertSame(2, SeoTask::query()->count());
    }

    public function test_staff_set_a_business_allowance_and_run_work_for_it(): void
    {
        [, $org] = $this->business();
        $this->actingAs($this->admin);

        Livewire::test(AdminSeo::class)->set("allowance.{$org->id}", 'lots')->call('saveAllowance', $org->id)->assertHasErrors();
        Livewire::test(AdminSeo::class)->set("allowance.{$org->id}", '900000')->call('saveAllowance', $org->id)->assertHasNoErrors();
        $this->assertSame(900000, app(SeoTokens::class)->allowance($org->fresh()));

        $this->ai->reply($this->keywordPlan());
        Livewire::test(AdminSeo::class)->call('runFor', $org->id, 'keyword_plan');
        $this->assertSame('staff', SeoTask::query()->where('organization_id', $org->id)->sole()->source);

        $this->get(route('admin.seo'))->assertOk()->assertSee('Rivera Plumbing');
    }

    public function test_every_kind_of_result_renders(): void
    {
        [$owner, $org] = $this->business();
        $outputs = [
            'keyword_plan' => json_decode($this->keywordPlan(), true),
            'page_meta' => ['pages' => [['url' => 'https://rivera.test/', 'title' => 'Plumber in Austin | Rivera', 'description' => 'Fast "same-day" plumbing.', 'h1' => 'Austin plumbers', 'why' => 'Clearer.']]],
            'local_page' => ['slug' => 'water-heaters-round-rock', 'title' => 'Water heaters in Round Rock', 'description' => 'Repairs.', 'h1' => 'Water heater repair', 'intro' => 'We fix them.',
                'sections' => [['heading' => 'Repairs', 'body' => 'Same day.']], 'faq' => [['question' => 'How fast?', 'answer' => 'Today.']], 'call_to_action' => 'Call now.'],
            'faq' => ['faq' => [['question' => 'Do you do weekends?', 'answer' => 'Yes, it\'s "no problem".']]],
            'gbp_posts' => ['posts' => [['title' => 'Winter pipes', 'body' => "Keep them warm.\nCall us.", 'button' => 'CALL']]],
        ];
        $expect = ['keyword_plan' => 'emergency plumber austin', 'page_meta' => 'Plumber in Austin | Rivera', 'local_page' => 'Copy the page as HTML',
            'faq' => 'Copy the code', 'gbp_posts' => 'Winter pipes'];
        $this->actingAs($owner);
        foreach ($outputs as $type => $output) {
            $task = SeoTask::create(['organization_id' => $org->id, 'type' => $type, 'status' => 'done', 'output' => $output, 'input_tokens' => 10, 'output_tokens' => 5]);
            Livewire::test(SeoPage::class)->set('tab', 'work')->set('selected', $task->ulid)->assertOk()->assertSee($expect[$type]);
        }
        $this->assertStringContainsString('"@type": "FAQPage"', SeoTaskCatalog::faqJsonLd($outputs['faq']['faq']));
    }
}
