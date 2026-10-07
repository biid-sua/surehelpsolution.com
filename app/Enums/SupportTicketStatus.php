<?php

namespace App\Enums;

/** Spec §78. */
enum SupportTicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::WaitingForCustomer => 'Waiting for you',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** As SureHelp staff see it. */
    public function staffLabel(): string
    {
        return $this === self::WaitingForCustomer ? 'Waiting for customer' : $this->label();
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'brand',
            self::WaitingForCustomer => 'info',
            self::Resolved => 'success',
            self::Closed => 'neutral',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::InProgress, self::WaitingForCustomer], true);
    }

    /** @return list<string> */
    public static function activeValues(): array
    {
        return [self::Open->value, self::InProgress->value, self::WaitingForCustomer->value];
    }
}
