<?php

namespace App\Enums;

enum OrganizationStatus: string
{
    case Onboarding = 'onboarding';
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Onboarding => 'Onboarding',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Cancelled => 'Cancelled',
        };
    }
}
