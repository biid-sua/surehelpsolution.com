<?php

namespace App\Actions\Appointments;

use App\Actions\Customers\RecordTimelineEvent;
use App\Actions\Notifications\NotifyOrganization;
use App\Enums\AppointmentStatus;
use App\Enums\CustomerStatus;
use App\Enums\NotificationEvent;
use App\Enums\TimelineEventType;
use App\Exceptions\SlotUnavailable;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentActivity;
use App\Support\Audit\Audit;
use Illuminate\Validation\ValidationException;

/**
 * Confirm, complete, mark as no-show, cancel, or undo a completion (spec §16).
 */
class ChangeAppointmentStatus
{
    public function __construct(
        private readonly BookingGuard $guard,
        private readonly Audit $audit,
        private readonly RecordTimelineEvent $timeline,
        private readonly NotifyOrganization $notify,
    ) {}

    /**
     * @throws ValidationException when the move isn't allowed
     * @throws SlotUnavailable when re-confirming a time someone else has since taken
     */
    public function handle(Appointment $appointment, AppointmentStatus $status, ?User $actor, ?string $reason = null): Appointment
    {
        $old = $appointment->status;
        if ($old === $status) {
            return $appointment;
        }
        if (! in_array($status, $old->transitions(), true)) {
            throw ValidationException::withMessages(['status' => ["A {$old->label()} appointment can't be marked {$status->label()}."]]);
        }

        $changes = ['status' => $status];
        match ($status) {
            AppointmentStatus::Confirmed => $changes += ['confirmed_at' => $appointment->confirmed_at ?? now(), 'completed_at' => null],
            AppointmentStatus::Completed => $changes += ['completed_at' => now()],
            AppointmentStatus::Cancelled => $changes += ['cancelled_at' => now(), 'cancellation_reason' => filled($reason) ? mb_substr(trim((string) $reason), 0, 250) : null],
            default => null,
        };

        // Back to a slot-holding status from one that freed it: the time must still be free.
        if ($status->blocksTime() && ! $old->blocksTime()) {
            $this->guard->claim($appointment->organization, $appointment->starts_at->toImmutable(), $appointment->blocked_until->toImmutable(),
                $appointment->location_id, $appointment->id, fn () => $appointment->forceFill($changes)->save());
        } else {
            $appointment->forceFill($changes)->save();
        }

        $this->audit->record('appointment.status_changed', $appointment, old: ['status' => $old->value], new: array_filter(['status' => $status->value, 'reason' => $reason]), actor: $actor, label: $appointment->title);

        if ($status === AppointmentStatus::Completed && $appointment->customer && in_array($appointment->customer->status, [CustomerStatus::Lead, CustomerStatus::Prospect], true)) {
            // A finished job makes a lead a customer.
            $appointment->customer->forceFill(['status' => CustomerStatus::Customer])->save();
        }

        if ($appointment->customer) {
            $type = $status === AppointmentStatus::Cancelled ? TimelineEventType::AppointmentCancelled : TimelineEventType::AppointmentUpdated;
            $this->timeline->handle($appointment->customer, $type, 'Appointment '.mb_strtolower($status->label()).': '.$appointment->title, $reason ?: $appointment->whenLabel(), $appointment, ['appointment' => $appointment->ulid, 'status' => $status->value], $actor?->id);
        }

        if ($status === AppointmentStatus::Cancelled) {
            $this->notify->handle($appointment->organization, new AppointmentActivity($appointment, NotificationEvent::AppointmentCancelled, $reason ? 'Reason: '.$reason : null), 'appointments.view', $actor);
        }

        return $appointment;
    }
}
