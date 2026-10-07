<?php

namespace Tests\Feature;

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Organizations\ProvisionUserTenancy;
use App\Enums\AppointmentStatus;
use App\Livewire\Client\Business\Automations;
use App\Models\Appointment;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AutomationNotice;
use App\Notifications\CustomerEmail;
use App\Services\Automation\AutomationEngine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Automation foundation (spec §42–43, D50): triggers schedule runs, delays are honoured, conditions
 * are re-checked at run time, consent is respected, and every run is recorded.
 */
class AutomationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00', 'UTC'));
    }

    /** @return array{0: User, 1: Organization} */
    private function business(): array
    {
        $owner = User::factory()->create(['role' => 'client', 'is_active' => true, 'must_change_password' => false]);
        $org = app(ProvisionUserTenancy::class)->handle($owner);
        $org->forceFill(['name' => 'Rivera Plumbing', 'timezone' => 'UTC', 'status' => 'active'])->save();

        return [$owner, $org->fresh()];
    }

    private function completedJob(Organization $org, Customer $customer, User $by): Appointment
    {
        $a = Appointment::create(['organization_id' => $org->id, 'customer_id' => $customer->id, 'title' => 'Leak repair', 'timezone' => 'UTC',
            'starts_at' => now()->subHours(3), 'ends_at' => now()->subHours(2), 'blocked_until' => now()->subHours(2)]);
        app(ChangeAppointmentStatus::class)->handle($a, AppointmentStatus::Completed, $by);

        return $a;
    }

    public function test_a_review_request_goes_out_after_the_delay_only_to_customers_who_agreed(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner);
        Livewire::test(Automations::class)->call('start', 'review')
            ->call('save')->assertHasErrors('form.review_url')
            ->set('form.review_url', 'https://g.page/r/rivera/review')->call('save')->assertHasNoErrors();
        $automation = Automation::query()->sole();
        $this->assertSame(120, $automation->delay_minutes);

        $agreed = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'email' => 'ana@example.test', 'email_consent' => true]);
        $notAgreed = Customer::create(['organization_id' => $org->id, 'first_name' => 'Bo', 'email' => 'bo@example.test']);
        $this->completedJob($org, $agreed, $owner);
        $this->completedJob($org, $notAgreed, $owner);
        $this->assertSame(2, AutomationRun::query()->where('status', 'pending')->count());

        $this->artisan('automations:run')->assertSuccessful();
        Notification::assertNotSentTo(new AnonymousNotifiable, CustomerEmail::class, fn (CustomerEmail $n) => str_starts_with($n->subject, 'Thank you'));
        $this->assertSame(0, AutomationRun::query()->where('status', 'done')->count());
        $this->assertSame(2, AutomationRun::query()->where('status', 'pending')->count(), 'not before the delay');

        $this->travel(121)->minutes();
        $this->artisan('automations:run')->assertSuccessful();
        Notification::assertSentOnDemand(CustomerEmail::class, fn (CustomerEmail $n, array $c, $to) => $to->routes['mail'] === 'ana@example.test'
            && str_contains($n->body, 'https://g.page/r/rivera/review') && $n->subject === 'Thank you from Rivera Plumbing');
        $this->assertSame(['done', 'skipped'], AutomationRun::query()->orderBy('id')->pluck('status')->all());

        // Triggering again for the same appointment never doubles it.
        $this->assertSame(0, app(AutomationEngine::class)->fire('appointment_completed', Appointment::query()->first()));
        $this->get(route('app.business.automations'))->assertOk()->assertSee('Thank you and review request')->assertSee('agreed to emails');
    }

    public function test_conditions_are_checked_again_when_the_step_runs(): void
    {
        [$owner, $org] = $this->business();
        Automation::create(['organization_id' => $org->id, 'name' => 'Rebook', 'trigger' => 'appointment_cancelled', 'delay_minutes' => 60,
            'action' => 'create_task', 'action_config' => ['title' => 'Call {first_name} to rebook', 'due_hours' => 24]]);
        $customer = Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana']);
        $kept = app(BookAppointment::class)->handle($org, ['starts_at' => now()->addDays(2), 'title' => 'Visit', 'customer_id' => $customer->id], $owner);
        $rebooked = app(BookAppointment::class)->handle($org, ['starts_at' => now()->addDays(3), 'title' => 'Visit 2', 'customer_id' => $customer->id], $owner);
        app(ChangeAppointmentStatus::class)->handle($kept, AppointmentStatus::Cancelled, $owner);
        app(ChangeAppointmentStatus::class)->handle($rebooked, AppointmentStatus::Cancelled, $owner);
        $rebooked->forceFill(['status' => AppointmentStatus::Confirmed])->saveQuietly(); // reinstated before the step ran

        $this->travel(61)->minutes();
        $this->artisan('automations:run');
        $this->assertSame(['done', 'cancelled'], AutomationRun::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame('Call Ana to rebook', Task::query()->sole()->title);
        $this->assertSame('automation', Task::query()->sole()->source);
    }

    public function test_new_leads_notify_the_team_but_imports_dont_trigger(): void
    {
        [$owner, $org] = $this->business();
        $this->actingAs($owner);
        Livewire::test(Automations::class)->call('start', 'lead')->call('save')->assertHasNoErrors();

        Customer::create(['organization_id' => $org->id, 'first_name' => 'Imported', 'source' => 'import']);
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Ana', 'source' => 'website']);
        $this->artisan('automations:run');

        Notification::assertSentTo($owner, AutomationNotice::class, fn (AutomationNotice $n) => str_contains($n->text, 'New lead: Ana'));
        $this->assertSame(1, AutomationRun::query()->count());

        // Switched off: what's waiting is cancelled, not sent.
        $auto = Automation::query()->sole();
        $auto->update(['delay_minutes' => 30]);
        Customer::create(['organization_id' => $org->id, 'first_name' => 'Late', 'source' => 'manual']);
        Livewire::test(Automations::class)->call('toggle', $auto->id);
        $this->travel(31)->minutes();
        $this->artisan('automations:run');
        $this->assertSame('cancelled', AutomationRun::query()->latest('id')->value('status'));
    }

    public function test_other_businesses_and_staff_without_settings_cant_change_automations(): void
    {
        [$owner, $org] = $this->business();
        [$other] = $this->business();
        $mine = Automation::create(['organization_id' => $org->id, 'name' => 'Mine', 'trigger' => 'customer_created', 'action' => 'notify_team', 'action_config' => ['message' => 'Hi']]);

        $this->actingAs($other);
        Livewire::test(Automations::class)->assertDontSee('Mine');
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(Automations::class)->call('toggle', $mine->id);
    }
}
