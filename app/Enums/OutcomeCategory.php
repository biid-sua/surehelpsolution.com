<?php

namespace App\Enums;

/**
 * What a call outcome means (spec §14). Every outcome, platform or custom, has one,
 * and KPIs, filters and notifications are driven by the category, never by outcome keys.
 */
enum OutcomeCategory: string
{
    case Booked = 'booked';
    case Information = 'information';
    case Callback = 'callback';
    case Escalated = 'escalated';
    case Missed = 'missed';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked a job or appointment',
            self::Information => 'Helped or informed the caller',
            self::Callback => 'Caller needs a call back',
            self::Escalated => 'Passed to the business urgently',
            self::Missed => 'Missed or dropped call',
            self::Spam => 'Spam or wrong number',
            self::Other => 'Other',
        };
    }

    /** Short status badge for call lists. */
    public function badge(): string
    {
        return match ($this) {
            self::Booked => 'Scheduled',
            self::Information => 'Handled',
            self::Callback => 'Callback Requested',
            self::Escalated => 'Escalated',
            self::Missed => 'Missed',
            self::Spam => 'Spam',
            self::Other => 'Handled',
        };
    }

    /** Badge colour group (see CallLog::statusTone). */
    public function tone(): string
    {
        return match ($this) {
            self::Booked => 'scheduled',
            self::Callback, self::Escalated => 'progress',
            self::Missed, self::Spam => 'danger',
            self::Information, self::Other => 'neutral',
        };
    }

    /** Counts towards the success rate. */
    public function isSuccess(): bool
    {
        return $this === self::Booked || $this === self::Information;
    }

    /** Which notification a call with this outcome triggers (spec §27). */
    public function notificationEvent(): NotificationEvent
    {
        return match ($this) {
            self::Missed => NotificationEvent::CallMissed,
            self::Callback => NotificationEvent::FollowUpCreated,
            default => NotificationEvent::CallLogged,
        };
    }
}
