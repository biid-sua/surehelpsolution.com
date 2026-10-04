<?php

namespace App\Enums;

/**
 * Escalation lifecycle (spec §25): raised, someone has seen it, dealt with.
 */
enum EscalationStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Waiting',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Acknowledged => 'warning',
            self::Resolved => 'success',
        };
    }

    public function isActive(): bool
    {
        return $this !== self::Resolved;
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::Open->value, self::Acknowledged->value];
    }
}
