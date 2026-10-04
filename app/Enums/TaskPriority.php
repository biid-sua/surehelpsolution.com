<?php

namespace App\Enums;

/**
 * Task priority (spec §24). `rank` orders lists: most urgent first.
 */
enum TaskPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Urgent => 'danger',
            self::High => 'warning',
            self::Normal => 'info',
            self::Low => 'neutral',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
        };
    }
}
