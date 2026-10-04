<?php

namespace App\Enums;

/**
 * What a call outcome *means*, independent of its wording (spec §14).
 * Metrics, filters, notifications, tasks and escalations key off the category,
 * so a business can rename or add outcomes without breaking any of them.
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
            self::Booked => 'Appointment booked',
            self::Information => 'Handled / information',
            self::Callback => 'Needs a call back',
            self::Escalated => 'Escalated to the business',
            self::Missed => 'Missed / dropped',
            self::Spam => 'Spam / wrong number',
            self::Other => 'Other',
        };
    }

    /** Short badge text used in call lists. */
    public function badge(): ?string
    {
        return match ($this) {
            self::Booked => 'Scheduled',
            self::Callback => 'Callback Requested',
            self::Escalated => 'Escalated',
            self::Missed => 'Missed',
            self::Spam => 'Spam',
            default => null, // fall back to the call status
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Booked => 'scheduled',
            self::Callback, self::Escalated => 'progress',
            self::Missed, self::Spam => 'danger',
            default => 'neutral',
        };
    }

    /** Counts as a successful call in success-rate metrics. */
    public function isSuccess(): bool
    {
        return in_array($this, [self::Booked, self::Information], true);
    }

    public function notificationEvent(): NotificationEvent
    {
        return match ($this) {
            self::Missed => NotificationEvent::CallMissed,
            self::Callback => NotificationEvent::FollowUpCreated,
            default => NotificationEvent::CallLogged,
        };
    }
}
