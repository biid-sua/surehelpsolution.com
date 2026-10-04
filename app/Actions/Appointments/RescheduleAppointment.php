<?php

namespace App\Actions\Appointments;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\NotificationEvent;
use App\Enums\TimelineEventType;
use App\Exceptions\SlotUnavailable;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentActivity;
use App\Services\Rules\BusinessRules;
use App\Services\Scheduling\Availability;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Moves an appointment to a new time, through the same double-booking guard as a new booking.
 */
class RescheduleAppointment
{
    public function __construct(
        private readonly BookingGuard $guard,
        private readonly Availability $availability,
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
        private readonly NotifyOrganization $notify,
        private readonly BusinessRules $rules,
    ) {}

    /**
     * @throws ValidationException
     * @throws SlotUnavailable
     */
    public function handle(Appointment $appointment, CarbonImmutable $startsAt, ?int $durationMinutes, User $actor, bool $strict = false): Appointment
    {
        if (! $appointment->status->blocksTime()) {
            throw ValidationException::withMessages(['starts_at' => ['Only upcoming appointments can be moved. Book a new one instead.']]);
        }

        $organization = $appointment->organization;
        $duration = $durationMinutes ?? $appointment->durationMinutes();
        $buffer = (int) $appointment->blocked_until->diffInMinutes($appointment->ends_at, true);
        $start = $startsAt->utc()->second(0);
        $end = $start->addMinutes($duration);

        BookAppointment::checkTime($this->availability, $organization, $start, $duration, $strict);
        if ($strict && $violations = array_intersect_key($this->rules->bookingViolations($organization, $start, $appointment->service, $appointment->customer, ['address' => $appointment->address]), ['starts_at' => true])) {
            throw ValidationException::withMessages(array_map(fn (string $m) => [$m], $violations));
        }

        $before = $appointment->whenLabel();
        $this->guard->claim($organization, $start, $end->addMinutes($buffer), $appointment->location_id, $appointment->id,
            fn () => $appointment->forceFill([
                'starts_at' => $start,
                'ends_at' => $end,
                'blocked_until' => $end->addMinutes($buffer),
            ])->save());

        $this->audit->changes('appointment.rescheduled', $appointment, ['starts_at', 'ends_at']);

        $change = "Moved from {$before}.";
        if ($appointment->customer) {
            $this->timeline->handle($appointment->customer, TimelineEventType::AppointmentUpdated, 'Appointment moved to '.$appointment->whenLabel(), $change, $appointment, ['appointment' => $appointment->ulid], $actor->id);
        }
        $this->notify->handle($organization, new AppointmentActivity($appointment, NotificationEvent::AppointmentUpdated, $change), 'appointments.view', $actor);

        return $appointment;
    }
}
