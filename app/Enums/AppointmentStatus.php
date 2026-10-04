<?php

namespace App\Enums;

/**
 * Appointment lifecycle (spec §16). Pending, tentative and confirmed hold the slot; the others free it.
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Tentative = 'tentative';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Needs confirming',
            self::Tentative => 'Tentative',
            self::Confirmed => 'Confirmed',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending, self::Tentative => 'warning',
            self::Confirmed => 'scheduled',
            self::Completed => 'success',
            self::Cancelled => 'neutral',
            self::NoShow => 'danger',
        };
    }

    /** Holds its time slot: other bookings may not overlap it. */
    public function blocksTime(): bool
    {
        return in_array($this, [self::Pending, self::Tentative, self::Confirmed], true);
    }

    /**
     * @return list<string>
     */
    public static function blockingValues(): array
    {
        return [self::Pending->value, self::Tentative->value, self::Confirmed->value];
    }

    /**
     * Statuses a person can move an appointment to from this one.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending, self::Tentative => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Completed, self::NoShow, self::Cancelled],
            self::Completed, self::NoShow => [self::Confirmed],
            self::Cancelled => [],
        };
    }
}
