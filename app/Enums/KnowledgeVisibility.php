<?php

namespace App\Enums;

/**
 * Who may see a knowledge item (spec §22).
 */
enum KnowledgeVisibility: string
{
    /** Safe to tell callers; later used by the website chatbot. */
    case Public = 'public';
    /** For our agents and the business's team, not to be read out to callers. */
    case Internal = 'internal';
    /** The business's own team only; never shown to agents or AI. */
    case TeamOnly = 'team_only';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Can be shared with callers',
            self::Internal => 'Agents and your team',
            self::TeamOnly => 'Your team only',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Public => 'success',
            self::Internal => 'info',
            self::TeamOnly => 'neutral',
        };
    }

    public function visibleToAgents(): bool
    {
        return $this !== self::TeamOnly;
    }
}
