<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Livewire\Admin\Appointments\Index as AdminAppointments;
use App\Livewire\Admin\Calls\Index as AdminCalls;
use App\Livewire\Admin\Customers\Index as AdminCustomers;
use App\Livewire\Admin\Usage\Index as AdminUsage;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin console platform-wide lists (spec §3.5, Super Admin navigation): calls, appointments,
 * customers and usage across every business, for staff only.
 */
class AdminRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $a;

    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'must_change_password' => false]);
        $this->admin->syncRoles(['super_admin']);
        $this->a = $this->business('ABC Plumbing');
        $this->b = $this->business('XYZ Cleaning');
    }

    private function business(string $name): Organization
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => $name, 'timezone' => 'UTC'])->save();

        return $org->fresh();
    }

    private function logCall(Organization $org, string $caller, string $at, string $status = 'new'): CallLog
    {
        $call = CallLog::withoutGlobalScopes()->create([
            'call_id' => CallLog::generateCallId(), 'organization_id' => $org->id, 'call_date' => substr($at, 0, 10), 'call_time' => '10:00',
            'caller_name' => $caller, 'reason_for_call' => 'general-inquiry', 'call_outcome' => 'resolved-by-agent', 'agent_name' => 'Agent', 'status' => $status, 'user_id' => $this->admin->id,
        ]);
        $call->forceFill(['created_at' => $at])->save();

        return $call;
    }

    public function test_calls_across_businesses_with_filters(): void
    {
        $this->logCall($this->a, 'Alice', '2026-10-14 10:00');
        $this->logCall($this->b, 'Bob', '2026-10-01 10:00');
        $this->logCall($this->b, 'Spammy', '2026-10-14 11:00', 'spam');

        $this->actingAs($this->admin)->get(route('admin.calls'))->assertOk()->assertSee('Alice')->assertSee('Bob')->assertSee('XYZ Cleaning');

        Livewire::test(AdminCalls::class)
            ->set('business', $this->b->ulid)->assertDontSee('Alice')->assertSee('Bob')
            ->set('from', '2026-10-10')->assertDontSee('Bob')->assertSee('Spammy')
            ->set('status', 'spam')->assertSee('Spammy')
            ->call('clearFilters')->set('search', 'ali')->assertSee('Alice')->assertDontSee('Bob');
    }

    public function test_appointments_and_customers_across_businesses(): void
    {
        $ana = Customer::withoutGlobalScopes()->create(['organization_id' => $this->a->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'phone' => '+15125550101']);
        Customer::withoutGlobalScopes()->create(['organization_id' => $this->b->id, 'first_name' => 'Zed', 'phone' => '+15125550102']);
        $make = fn (Organization $o, string $title, string $at, ?int $customer = null) => Appointment::withoutGlobalScopes()->create([
            'organization_id' => $o->id, 'customer_id' => $customer, 'title' => $title, 'timezone' => 'UTC',
            'starts_at' => $at, 'ends_at' => CarbonImmutable::parse($at)->addHour(), 'blocked_until' => CarbonImmutable::parse($at)->addHour()]);
        $make($this->a, 'Boiler service', '2026-10-20 10:00', $ana->id);
        $make($this->b, 'Deep clean', '2026-10-21 10:00');
        $make($this->b, 'Last month job', '2026-09-10 10:00');

        $this->actingAs($this->admin);
        Livewire::test(AdminAppointments::class)->assertSee('Boiler service')->assertSee('Deep clean')->assertDontSee('Last month job')
            ->set('from', '2026-09-01')->set('to', '2026-09-30')->assertSee('Last month job')->assertDontSee('Deep clean')
            ->call('clearFilters')->set('search', 'Lopez')->assertSee('Boiler service')->assertDontSee('Deep clean');

        Livewire::test(AdminCustomers::class)->assertSee('Ana Lopez')->assertSee('Zed')
            ->set('business', $this->a->ulid)->assertSee('Ana Lopez')->assertDontSee('Zed');
    }

    public function test_usage_counts_answered_calls_per_business_for_the_month(): void
    {
        $this->logCall($this->a, 'One', '2026-10-02 10:00');
        $this->logCall($this->a, 'Two', '2026-10-03 10:00');
        $this->logCall($this->a, 'Spam', '2026-10-03 11:00', 'spam');
        $this->logCall($this->a, 'September', '2026-09-03 11:00');
        $this->logCall($this->b, 'Three', '2026-10-04 10:00');

        $this->actingAs($this->admin);
        $page = Livewire::test(AdminUsage::class);
        $rows = $page->viewData('organizations')->getCollection()->pluck('calls', 'name')->all();
        $this->assertSame(['ABC Plumbing' => 2, 'XYZ Cleaning' => 1], $rows);
        $this->assertSame(3, $page->viewData('totals')['calls']);

        $page->set('month', '2026-09');
        $this->assertSame(1, $page->viewData('organizations')->getCollection()->firstWhere('name', 'ABC Plumbing')->calls);
    }

    public function test_only_platform_staff_reach_the_lists(): void
    {
        $owner = $this->a->owner;
        foreach (['admin.calls', 'admin.appointments', 'admin.customers', 'admin.usage'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertForbidden();
        }
    }
}
