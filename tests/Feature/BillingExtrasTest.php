<?php

namespace Tests\Feature;

use App\Actions\Billing\ManageAddons;
use App\Actions\Billing\ManageSubscription;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Actions\Team\InviteMember;
use App\Livewire\Admin\Billing\Addons as AdminAddons;
use App\Livewire\Admin\Billing\Index as AdminBilling;
use App\Livewire\Client\Billing\Index as ClientBilling;
use App\Models\Addon;
use App\Models\CallLog;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationAddon;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Models\User;
use App\Notifications\UsageAlert;
use App\Services\Billing\BillingRun;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Billing extras (task.md BIL-03, BIL-05, BIL-11, ADD-01..04): usage and extra calls, usage
 * alerts, add-ons, and plan limits on seats and calendars.
 */
class BillingExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: User, 1: Organization, 2: Subscription} */
    private function subscribed(array $limits = ['calls' => 10, 'extra_call_cents' => 150]): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->update(['name' => 'Rivera Plumbing']);
        $plan = Plan::create(['slug' => 'receptionist', 'name' => 'Virtual Receptionist', 'price_cents' => 29900, 'interval' => 'month', 'trial_days' => 0, 'limits' => $limits]);
        $subscription = app(ManageSubscription::class)->subscribe($org->fresh(), $plan, $this->admin, CarbonImmutable::parse('2026-10-01'), false);

        return [$owner, $org->fresh(), $subscription];
    }

    private function calls(Organization $org, int $count, string $at = '2026-10-15 10:00', string $status = 'new'): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        foreach (range(1, $count) as $i) {
            $call = CallLog::create(['user_id' => $agent->id, 'organization_id' => $org->id, 'call_id' => CallLog::generateCallId(), 'call_date' => substr($at, 0, 10),
                'call_time' => '10:00', 'reason_for_call' => 'service-request', 'call_outcome' => 'other', 'agent_name' => 'A', 'status' => $status]);
            $call->forceFill(['created_at' => CarbonImmutable::parse($at)])->save();
        }
    }

    private function runOn(string $date): array
    {
        Carbon::setTestNow($date.' 06:05:00');

        return app(BillingRun::class)->run();
    }

    public function test_calls_beyond_the_plan_are_billed_once_on_the_next_invoice(): void
    {
        [$owner, $org, $subscription] = $this->subscribed();
        $this->calls($org, 13);
        $this->calls($org, 4, status: 'spam');          // never counted
        $this->calls($org, 2, '2026-11-02 10:00');       // next period

        $this->actingAs($owner);
        Carbon::setTestNow('2026-10-20 12:00');
        Livewire::test(ClientBilling::class)->assertSee('13 / 10')->assertSee('3 extra so far')->assertSee('$4.50');

        $this->runOn('2026-11-01');
        $invoice = Invoice::withoutGlobalScopes()->where('subscription_id', $subscription->id)->whereDate('period_start', '2026-11-01')->sole();
        $extra = $invoice->items->firstWhere(fn ($i) => str_starts_with($i->description, 'Extra calls'));
        $this->assertNotNull($extra);
        $this->assertSame(3, $extra->quantity);
        $this->assertSame(150, $extra->unit_cents);
        $this->assertSame(29900 + 450, $invoice->total_cents);

        $record = UsageRecord::withoutGlobalScopes()->sole();
        $this->assertSame([13, 10, 3], [$record->quantity, $record->included, $record->overage]);
        $this->assertSame($invoice->id, $record->invoice_id);

        $this->runOn('2026-11-01');
        $this->assertSame(1, UsageRecord::withoutGlobalScopes()->count(), 'billed once');
        $this->assertSame(2, Invoice::withoutGlobalScopes()->count());

        Livewire::test(ClientBilling::class)->assertSee('Usage by period')->assertSee('Oct 1 – Oct 31, 2026');
    }

    public function test_a_subscription_that_ends_still_bills_its_last_extra_calls(): void
    {
        [, $org, $subscription] = $this->subscribed();
        $this->calls($org, 12);
        app(ManageSubscription::class)->cancel($subscription, $this->admin);

        $this->runOn('2026-11-01');
        $last = Invoice::withoutGlobalScopes()->whereNull('subscription_id')->sole();
        $this->assertSame(300, $last->total_cents);
        $this->assertSame('cancelled', $subscription->fresh()->status->value);
    }

    public function test_usage_alerts_at_80_and_100_percent_once_per_period(): void
    {
        [$owner, $org] = $this->subscribed();

        $this->calls($org, 7);
        $this->assertSame(0, $this->runOn('2026-10-15')['usage_alerts']);
        $this->calls($org, 1);
        $this->assertSame(1, $this->runOn('2026-10-16')['usage_alerts']);
        $this->assertSame(0, $this->runOn('2026-10-17')['usage_alerts']);
        Notification::assertSentTo($owner, UsageAlert::class, fn (UsageAlert $n) => $n->level === 80);

        $this->calls($org, 5);
        $this->assertSame(1, $this->runOn('2026-10-18')['usage_alerts']);
        Notification::assertSentTo($owner, UsageAlert::class, fn (UsageAlert $n) => $n->level === 100 && str_contains($n->toArray($owner)['body'], '13 of 10'));

        // A new period starts again from zero.
        $this->calls($org, 9, '2026-11-03 10:00');
        $this->assertSame(1, $this->runOn('2026-11-04')['usage_alerts']);
    }

    public function test_add_ons_are_invoiced_pro_rata_then_with_each_renewal_and_unlock_their_feature(): void
    {
        [$owner, $org] = $this->subscribed(['calls' => 1000]);
        $addon = Addon::create(['slug' => 'bilingual', 'name' => 'Bilingual answering', 'price_cents' => 3100]);
        $entitlements = app(Entitlements::class);
        $this->assertFalse($entitlements->allows($org, 'bilingual'));

        Carbon::setTestNow('2026-10-21 10:00');   // 11 of 31 days left
        $this->actingAs($owner);
        Livewire::test(ClientBilling::class)->assertSee('Bilingual answering')->call('activateAddon', $addon->id);

        $prorated = Invoice::withoutGlobalScopes()->whereNull('subscription_id')->sole();
        $this->assertSame(1100, $prorated->total_cents);
        $this->assertTrue(app(Entitlements::class)->allows($org, 'bilingual'));

        // Price changes don't reach businesses that already have it.
        $addon->update(['price_cents' => 5000]);
        $this->runOn('2026-11-01');
        $renewal = Invoice::withoutGlobalScopes()->whereDate('period_start', '2026-11-01')->sole();
        $this->assertSame(29900 + 3100, $renewal->total_cents);

        // Off at the end of the period, and "keep it" undoes that.
        $active = OrganizationAddon::withoutGlobalScopes()->sole();
        Livewire::test(ClientBilling::class)->call('deactivateAddon', $active->id)->assertSee('Keep it');
        Livewire::test(ClientBilling::class)->call('activateAddon', $addon->id);
        $this->assertFalse($active->fresh()->cancel_at_period_end);
        Livewire::test(ClientBilling::class)->call('deactivateAddon', $active->id);

        $this->runOn('2026-12-01');
        $december = Invoice::withoutGlobalScopes()->whereDate('period_start', '2026-12-01')->sole();
        $this->assertSame(29900, $december->total_cents);
        $this->assertSame(OrganizationAddon::ENDED, $active->fresh()->status);
        app(Entitlements::class)->forget($org);
        $this->assertFalse(app(Entitlements::class)->allows($org, 'bilingual'));

        // Managers see add-ons but can't change them.
        $manager = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org->members()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);
        $this->actingAs($manager);
        Livewire::test(ClientBilling::class)->assertSee('Bilingual answering')->assertDontSee('Turn on')->call('activateAddon', $addon->id)->assertForbidden();
    }

    public function test_add_ons_need_a_plan_and_are_free_during_a_trial(): void
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $addon = Addon::create(['slug' => 'bilingual', 'name' => 'Bilingual answering', 'price_cents' => 3100]);

        try {
            app(ManageAddons::class)->activate($org, $addon, $owner);
            $this->fail('needs a plan');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Choose a plan first', $e->getMessage());
        }

        $plan = Plan::create(['slug' => 'trial', 'name' => 'Starter', 'price_cents' => 9900, 'interval' => 'month', 'trial_days' => 14]);
        app(ManageSubscription::class)->subscribe($org, $plan, $this->admin, CarbonImmutable::parse('2026-10-01'));
        app(Entitlements::class)->forget($org);
        app(ManageAddons::class)->activate($org, $addon, $owner);
        $this->assertSame(0, Invoice::withoutGlobalScopes()->count(), 'nothing to pay during the trial');

        $this->runOn('2026-10-15');
        $first = Invoice::withoutGlobalScopes()->sole();
        $this->assertSame(9900 + 3100, $first->total_cents);
    }

    public function test_plan_limits_on_team_members_and_calendars(): void
    {
        [$owner, $org] = $this->subscribed(['team_members' => 2, 'calendars' => 0]);
        app(InviteMember::class)->handle($org, 'sam@rivera.test', 'staff', $owner);
        app(InviteMember::class)->handle($org, 'sam@rivera.test', 'manager', $owner);   // re-inviting the same person is fine

        $this->expectException(ValidationException::class);
        try {
            app(InviteMember::class)->handle($org, 'lee@rivera.test', 'staff', $owner);
        } finally {
            $this->enableTwoFactor($owner);
            config(['calendar.providers.google.client_id' => 'id', 'calendar.providers.google.client_secret' => 'secret']);
            $this->actingAs($owner)->get(route('app.integrations.calendar.connect', 'google'))
                ->assertRedirect(route('app.business.calendars'))->assertSessionHas('error', fn (string $m) => str_contains($m, 'Your plan includes 0 connected calendars'));
        }
    }

    public function test_admins_set_plan_limits_and_manage_the_add_on_catalogue(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(AdminBilling::class)->set('tab', 'plans')
            ->set('plan.name', 'Starter')->set('plan.price', '199')->set('plan.calls', '300')->set('plan.extra_call', 'abc')->call('savePlan')->assertHasErrors('plan.extra_call')
            ->set('plan.extra_call', '1.25')->set('plan.team_members', '3')->call('savePlan')->assertHasNoErrors();
        $this->assertSame(['calls' => 300, 'extra_call_cents' => 125, 'team_members' => 3], Plan::sole()->limits);

        $this->get(route('admin.billing', ['tab' => 'addons']))->assertOk()->assertSee('No add-ons yet');
        Livewire::test(AdminAddons::class)->set('form.name', 'Bilingual answering')->set('form.price', '0')->call('save')->assertHasErrors('form.price')
            ->set('form.price', '49')->call('save')->assertHasNoErrors();
        $this->assertSame(['bilingual_answering', 4900], [Addon::sole()->slug, Addon::sole()->price_cents]);
        Livewire::test(AdminAddons::class)->set('form.name', 'Other')->set('form.slug', 'bilingual_answering')->set('form.price', '10')->call('save')->assertHasErrors('form.slug');

        // Support staff can look, not change.
        $support = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $support->syncRoles(['support_agent']);
        $this->actingAs($support);
        Livewire::test(AdminAddons::class)->assertDontSee('Save add-on')->set('form.name', 'X')->set('form.price', '5')->call('save')->assertForbidden();
    }
}
