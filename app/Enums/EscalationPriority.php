<?php

namespace App\Enums;

/**
 * Escalation priority (spec §25). Urgent ones always reach people (NTF-03) and are re-sent if nobody responds.
 */
enum EscalationPriority: string
{
    case Urgent = 'urgent';
    case High = 'high';
    case Normal = 'normal';

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
        };
    }
}
