<?php

namespace Tests\Feature;

use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\TaskType;
use App\Livewire\Client\Appointments\Index as Appointments;
use App\Livewire\Client\Customers\Index as Customers;
use App\Livewire\Client\Tasks\Index as Tasks;
use App\Models\Appointment;
use App\Models\BusinessService;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Extra list filters: appointments by text, dates and service (and the CSV follows them),
 * tasks by type and assignee, customers by where they came from.
 */
class ListFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_appointments_tasks_and_customers_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'UTC'));
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['timezone' => 'UTC'])->save();
        $this->actingAs($owner);

        $ana = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'last_name' => 'Lopez', 'source' => 'website']);
        $bob = Customer::create(['organization_id' => $org->id, 'first_name' => 'Bob', 'source' => 'call']);
        $drain = BusinessService::create(['organization_id' => $org->id, 'name' => 'Drain cleaning', 'duration_minutes' => 60]);
        $make = fn (string $title, string $at, ?int $customer, ?int $service = null) => Appointment::create(['organization_id' => $org->id, 'title' => $title, 'customer_id' => $customer,
            'service_id' => $service, 'timezone' => 'UTC', 'starts_at' => $at, 'ends_at' => CarbonImmutable::parse($at)->addHour(), 'blocked_until' => CarbonImmutable::parse($at)->addHour()]);
        $make('Leak repair', '2026-10-13 10:00', $ana->id);
        $make('Drain job', '2026-10-20 10:00', $bob->id, $drain->id);

        Livewire::test(Appointments::class)->assertSee('Leak repair')->assertSee('Drain job')
            ->set('search', 'Lopez')->assertSee('Leak repair')->assertDontSee('Drain job')
            ->call('clearFilters')->set('serviceFilter', (string) $drain->id)->assertDontSee('Leak repair')->assertSee('Drain job')
            ->call('clearFilters')->set('from', '2026-10-15')->assertDontSee('Leak repair');
        $csv = $this->get(route('app.appointments.export', ['search' => 'Lopez']))->streamedContent();
        $this->assertStringContainsString('Leak repair', $csv);
        $this->assertStringNotContainsString('Drain job', $csv);

        Task::create(['organization_id' => $org->id, 'title' => 'Call Bob back', 'type' => TaskType::Callback]);
        Task::create(['organization_id' => $org->id, 'title' => 'Order parts', 'type' => TaskType::Todo, 'assigned_to_user_id' => $owner->id]);
        Livewire::test(Tasks::class)->set('type', 'callback')->assertSee('Call Bob back')->assertDontSee('Order parts')
            ->set('type', '')->set('assignee', 'none')->assertSee('Call Bob back')->assertDontSee('Order parts')
            ->set('assignee', (string) $owner->id)->assertDontSee('Call Bob back')->assertSee('Order parts');

        Livewire::test(Customers::class)->set('source', 'website')->assertSee('Lopez')->assertDontSee('Bob');
    }
}
