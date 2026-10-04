<?php

namespace App\Enums;

/**
 * What kind of work a task is (spec §24).
 */
enum TaskType: string
{
    case Callback = 'callback';
    case FollowUp = 'follow_up';
    case Todo = 'todo';

    public function label(): string
    {
        return match ($this) {
            self::Callback => 'Call back',
            self::FollowUp => 'Follow-up',
            self::Todo => 'To-do',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Callback => 'callback',
            self::FollowUp => 'refresh',
            self::Todo => 'list',
        };
    }

    /** Callbacks and follow-ups are what the dashboard's "pending follow-ups" counts. */
    public function isFollowUp(): bool
    {
        return $this !== self::Todo;
    }
}
