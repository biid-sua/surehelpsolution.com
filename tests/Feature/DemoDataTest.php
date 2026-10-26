<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AgentAssignmentSource;
use App\Jobs\PullSearchConsole;
use App\Jobs\RunDemoData;
use App\Livewire\Admin\DemoData as DemoDataPage;
use App\Mail\SystemTestMail;
use App\Models\AgentAssignment;
use App\Models\AuditLog;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\SearchConsoleConnection;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Demo\DemoData;
use App\Services\Seo\SearchConsole;
use App\Services\System\LaunchReadiness;
use App\Support\DemoGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Demo data (D94): an administrator loads sample businesses into a system with real data, learns
 * with them, and removes them again, leaving the real data exactly as it was.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $real;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false, 'name' => 'Real Admin']);
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false, 'name' => 'Real Owner']);
        $this->real = app(ProvisionUserTenancy::class)->handle($owner);
        $this->real->forceFill(['name' => 'Real Biz'])->save();
        Customer::create(['organization_id' => $this->real->id, 'first_name' => 'Real', 'last_name' => 'Customer', 'email' => 'rc@realbiz.com']);
        Plan::create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 9900]);
        PlatformSetting::put('ui.site_default_theme', 'ocean');
    }

    /** Every row of every table that matters, to compare before and after. @return array<string, string> */
    private function fingerprint(): array
    {
        $skip = [...DemoData::SKIP, 'audit_logs', 'platform_settings'];
        $out = [];
        foreach (DB::connection()->getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
            if (! in_array($table, $skip, true)) {
                $out[$table] = md5(DB::table($table)->get()->map(fn ($r) => json_encode($r))->sort()->implode("\n"));
            }
        }
        $out['platform_settings'] = md5(DB::table('platform_settings')->where('key', '!=', DemoGuard::STATE_SETTING)->get()->toJson());

        return $out;
    }

    public function test_the_demo_loads_beside_real_data_and_removes_without_a_trace(): void
    {
        $demo = app(DemoData::class);
        $before = $this->fingerprint();

        $this->assertTrue($demo->begin('load', $this->admin));
        $this->assertFalse($demo->begin('load', $this->admin), 'only one at a time');
        $demo->load($this->admin);

        $this->assertSame('loaded', $demo->state()['status']);
        $demoOrgs = Organization::withoutGlobalScopes()->where('is_demo', true)->pluck('name')->sort()->values()->all();
        $this->assertSame(['Bright Smile Dental', 'Green Valley HVAC', 'Lakeside Law Group', 'Rivera Plumbing & Heating', 'Sunrise Yoga Studio'], $demoOrgs);
        $this->assertFalse($this->real->fresh()->is_demo);
        $this->assertGreaterThan(100, CallLog::withoutGlobalScopes()->whereIn('organization_id', Organization::withoutGlobalScopes()->where('is_demo', true)->pluck('id'))->count());
        // Its own accounts, which nobody can sign in to; real accounts and settings untouched.
        $this->assertSame('Real Admin', $this->admin->fresh()->name);
        $this->assertTrue(User::query()->where('email', 'alex.morgan@surehelp-demo.example')->value('is_demo'));
        $this->assertFalse(auth()->attempt(['email' => 'elena@riveraplumbing.example', 'password' => 'password']));
        $this->assertSame(['demo-pro', 'demo-receptionist', 'demo-starter', 'starter'], Plan::query()->orderBy('slug')->pluck('slug')->all());
        $this->assertFalse(Plan::query()->where('slug', 'demo-pro')->value('is_public'));
        $this->assertSame('ocean', PlatformSetting::get('ui.site_default_theme'));
        $this->assertSame(LaunchReadiness::FAIL, collect(app(LaunchReadiness::class)->checks())->firstWhere('label', 'No demo data')['status']);

        // While loaded, real activity mixes in.
        $rivera = Organization::withoutGlobalScopes()->where('name', 'Rivera Plumbing & Heating')->first();
        $demoAgent = User::query()->where('email', 'john.carter@surehelp-demo.example')->first();
        app(ManageSubscription::class)->subscribe($this->real, Plan::query()->where('slug', 'demo-pro')->first(), $this->admin);
        $this->real->assignAgent($demoAgent, AgentAssignmentSource::Manual);
        CallLog::create(['call_id' => 'REAL-1', 'organization_id' => $this->real->id, 'caller_name' => 'Real Caller', 'caller_phone' => '5125559999', 'reason_for_call' => 'Quote',
            'call_outcome' => 'message_taken', 'agent_name' => $demoAgent->name, 'user_id' => $demoAgent->id, 'status' => 'completed', 'call_date' => now(), 'call_time' => '10:00']);
        Customer::create(['organization_id' => $rivera->id, 'first_name' => 'Added', 'last_name' => 'Later']);

        $this->assertTrue($demo->begin('remove', $this->admin));
        $result = $demo->remove($this->admin);

        $this->assertSame('none', $demo->state()['status']);
        $this->assertSame(0, Organization::withoutGlobalScopes()->where('is_demo', true)->count());
        $this->assertFalse(Customer::withoutGlobalScopes()->where('first_name', 'Added')->exists(), 'added inside a demo business: removed');
        // Real data that used demo items keeps them, and says so.
        $this->assertSame(['users' => 1, 'plans' => 1], $result['kept']);
        $this->assertSame('demo-pro', Subscription::withoutGlobalScopes()->where('organization_id', $this->real->id)->first()->plan->slug);
        $this->assertSame($demoAgent->id, CallLog::withoutGlobalScopes()->where('call_id', 'REAL-1')->value('user_id'));
        $this->assertSame(0, AgentAssignment::withoutGlobalScopes()->where('organization_id', $this->real->id)->count(), 'the link to a demo agent goes');
        $this->assertSame(['demo.loaded', 'demo.removed'], AuditLog::query()->where('action', 'like', 'demo.%')->orderBy('id')->pluck('action')->all());

        // Without the real activity, everything is exactly as before.
        Subscription::withoutGlobalScopes()->where('organization_id', $this->real->id)->delete();
        CallLog::withoutGlobalScopes()->where('call_id', 'REAL-1')->delete();
        $demoAgent->delete();
        Plan::query()->where('slug', 'demo-pro')->delete();
        DB::table('invoice_sequences')->delete();
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_demo_businesses_never_reach_the_outside_world(): void
    {
        $demoOrg = Organization::factory()->create(['is_demo' => true]);
        PlatformSetting::put(DemoGuard::STATE_SETTING, ['status' => 'loaded']);

        // Mail to the demo's reserved addresses is dropped; real recipients still get theirs.
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$sent) {
            $sent[] = array_map(fn ($a) => $a->getAddress(), $e->message->getTo());
        });
        Mail::to('elena@riveraplumbing.example')->send(new SystemTestMail);
        Mail::to(['real@realbiz.com', 'kayla@example.com'])->send(new SystemTestMail);
        $this->assertSame([['real@realbiz.com']], $sent);

        // Background work that calls another service skips demo businesses.
        $connection = SearchConsoleConnection::withoutGlobalScopes()->forceCreate(['organization_id' => $demoOrg->id, 'site_url' => 'https://demo.example/', 'access_token' => 'x', 'refresh_token' => 'y', 'status' => 'active']);
        $this->mock(SearchConsole::class)->shouldNotReceive('pull');
        (new PullSearchConsole($connection->id))->handle(app(SearchConsole::class));

        $this->assertTrue(DemoGuard::isDemo($demoOrg->id));
        $this->assertFalse(DemoGuard::isDemo($this->real->id));
    }

    public function test_only_administrators_load_and_remove_it_from_the_admin_page(): void
    {
        Bus::fake();
        $this->actingAs($this->admin);
        Livewire::test(DemoDataPage::class)->assertSee('Load demo data')->call('load')->assertDispatched('toast');
        Bus::assertDispatchedAfterResponse(RunDemoData::class, fn (RunDemoData $job) => $job->action === 'load');
        $this->assertSame('loading', app(DemoData::class)->state()['status']);

        // A crashed load is recovered from what's really in the database.
        $state = PlatformSetting::get(DemoGuard::STATE_SETTING);
        PlatformSetting::put(DemoGuard::STATE_SETTING, [...$state, 'started_at' => now()->subHour()->toIso8601String()]);
        Livewire::test(DemoDataPage::class)->assertSee('This seems to have stopped')->call('recover');
        $this->assertSame('none', app(DemoData::class)->state()['status']);

        PlatformSetting::put(DemoGuard::STATE_SETTING, ['status' => 'loaded', 'loaded_at' => now()->toIso8601String(), 'loaded_by' => 'Real Admin']);
        Livewire::test(DemoDataPage::class)->assertSee('Remove the demo data')
            ->call('remove')->assertHasErrors('confirm')
            ->set('confirm', 'REMOVE')->call('remove')->assertHasNoErrors();
        Bus::assertDispatchedAfterResponse(RunDemoData::class, fn (RunDemoData $job) => $job->action === 'remove');

        // Business users can't reach the page.
        $this->actingAs($this->real->owner);
        $this->get(route('admin.demo-data'))->assertForbidden();
    }
}
