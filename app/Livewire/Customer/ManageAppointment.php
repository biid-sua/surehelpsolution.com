<?php

namespace App\Livewire\Customer;

use App\Actions\Appointments\ChangeAppointmentStatus;
use App\Actions\Appointments\RescheduleAppointment;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailable;
use App\Models\Appointment;
use App\Models\BusinessProfile;
use App\Services\Appointments\CustomerSelfService;
use App\Services\Scheduling\Availability;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A customer changes or cancels their appointment from the link in their email (CAL-09, D48).
 * The route is signed and expires at the appointment's start; Livewire's signed component state
 * keeps the appointment fixed afterwards. Every action re-checks the business's cut-off.
 */
#[Layout('layouts.customer')]
class ManageAppointment extends Component
{
    #[Locked]
    public string $ulid;

    public string $mode = '';   // '' | move | cancel

    public string $date = '';

    public string $time = '';

    public string $reason = '';

    public bool $done = false;

    public function mount(string $appointment): void
    {
        $this->ulid = $appointment;
        $this->appointment(); // 404 if it doesn't exist
    }

    private function appointment(): Appointment
    {
        return Appointment::withoutGlobalScopes()->where('ulid', $this->ulid)->with(['organization', 'service', 'customer'])->firstOrFail();
    }

    public function startMove(): void
    {
        $this->mode = 'move';
        $this->date = $this->appointment()->localStart()->toDateString();
        $this->time = '';
        $this->resetValidation();
    }

    public function updatedDate(): void
    {
        $this->time = '';
    }

    public function move(RescheduleAppointment $reschedule, CustomerSelfService $selfService): void
    {
        if (! $appointment = $this->guard($selfService)) {
            return;
        }
        $this->validate(['date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i']], ['time.required' => 'Pick a time.']);

        try {
            $reschedule->handle($appointment, CarbonImmutable::parse($this->date.' '.$this->time, $appointment->organization->timezoneOrDefault()), null, null, true);
        } catch (SlotUnavailable $e) {
            $this->addError('time', 'That time was just taken. Please pick another.');

            return;
        } catch (ValidationException $e) {
            $this->addError('time', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->mode = '';
        $this->dispatch('toast', type: 'success', message: 'Your appointment has moved. We\'ve emailed you the new time.');
    }

    public function cancel(ChangeAppointmentStatus $change, CustomerSelfService $selfService): void
    {
        if (! $appointment = $this->guard($selfService)) {
            return;
        }
        $this->validate(['reason' => ['nullable', 'string', 'max:250']]);

        $change->handle($appointment, AppointmentStatus::Cancelled, null, 'Cancelled by the customer online'.(filled($this->reason) ? ': '.trim($this->reason) : ''));
        $this->mode = '';
        $this->done = true;
    }

    /** Still changeable, and not hammered from one address. */
    private function guard(CustomerSelfService $selfService): ?Appointment
    {
        $appointment = $this->appointment();
        if (! $selfService->canChange($appointment)) {
            $this->mode = '';

            return null;   // the page now says to call the business
        }
        abort_if(RateLimiter::tooManyAttempts('self-service:'.$this->ulid, 10), 429);
        RateLimiter::hit('self-service:'.$this->ulid, 3600);

        return $appointment;
    }

    public function render(CustomerSelfService $selfService, Availability $availability): View
    {
        $appointment = $this->appointment();
        $organization = $appointment->organization;
        $timezone = $organization->timezoneOrDefault();
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();

        $slots = [];
        if ($this->mode === 'move' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) && $selfService->canChange($appointment)) {
            $slots = array_values(array_filter(
                $availability->slots($organization, $this->date, $appointment->durationMinutes(),
                    (int) $appointment->blocked_until->diffInMinutes($appointment->ends_at, true), $appointment->location_id, $appointment->id, null, $appointment->service_id),
                // Not the time it already has, and never in the past.
                fn (CarbonImmutable $s) => $s->greaterThan(now()) && ! $s->equalTo($appointment->starts_at),
            ));
        }

        return view('livewire.customer.manage-appointment', [
            'appointment' => $appointment,
            'business' => $profile?->display_name ?: $organization->name,
            'logoUrl' => $profile?->logoUrl(),
            'phone' => $profile?->phone ? (Phone::display(Phone::normalize($profile->phone)) ?? $profile->phone) : null,
            'timezone' => $timezone,
            'canChange' => $selfService->canChange($appointment),
            'deadline' => $selfService->deadline($appointment)?->setTimezone($timezone),
            'slots' => $slots,
            'today' => now($timezone)->toDateString(),
        ])->title('Your appointment with '.($profile?->display_name ?: $organization->name));
    }
}
