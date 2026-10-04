<?php

namespace App\Enums;

/**
 * Task lifecycle (spec §24). Open and in progress count as "still to do".
 */
enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::Completed => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open || $this === self::InProgress;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::Open->value, self::InProgress->value];
    }
}
