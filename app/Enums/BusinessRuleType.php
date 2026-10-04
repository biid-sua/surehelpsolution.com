<?php

namespace App\Enums;

/**
 * Configurable business rules (spec §23). Most are enforced by the system; `Instruction` is guidance
 * agents (and later the AI) must follow, shown prominently on every call.
 */
enum BusinessRuleType: string
{
    case Instruction = 'instruction';
    case BookingCutoff = 'booking_cutoff';
    case BookingWindow = 'booking_window';
    case ServiceArea = 'service_area';
    case RequireDetail = 'require_detail';
    case AutoEscalate = 'auto_escalate';

    public function label(): string
    {
        return match ($this) {
            self::Instruction => 'Instruction for agents',
            self::BookingCutoff => 'Latest booking time',
            self::BookingWindow => 'How far ahead people can book',
            self::ServiceArea => 'Service area (ZIP codes)',
            self::RequireDetail => 'Details to collect before booking',
            self::AutoEscalate => 'Escalate certain calls automatically',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Instruction => 'e.g. "Never give final prices for custom jobs; offer a free estimate."',
            self::BookingCutoff => 'e.g. "Don\'t book emergency visits starting after 5 PM."',
            self::BookingWindow => 'Minimum notice and how many days ahead bookings may be made.',
            self::ServiceArea => 'Agents only book visits at addresses in these ZIP codes.',
            self::RequireDetail => 'e.g. "Always ask for the property address."',
            self::AutoEscalate => 'e.g. "Escalate complaints to the owner."',
        };
    }

    /** Checked by the system, not just shown to agents. */
    public function isEnforced(): bool
    {
        return $this !== self::Instruction;
    }
}
