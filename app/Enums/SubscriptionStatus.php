<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Free trial',
            self::Active => 'Active',
            self::PastDue => 'Payment overdue',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Trialing => 'info',
            self::Active => 'success',
            self::PastDue => 'danger',
            self::Cancelled => 'neutral',
        };
    }

    /** Still a customer: features stay on (a past-due account keeps service while we collect). */
    public function isCurrent(): bool
    {
        return $this !== self::Cancelled;
    }
}
