<?php

namespace App\Enums;

/**
 * Customer lifecycle (spec §12).
 */
enum CustomerStatus: string
{
    case Lead = 'lead';
    case Prospect = 'prospect';
    case Customer = 'customer';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Lead => 'info',
            self::Prospect => 'warning',
            self::Customer => 'success',
            self::Inactive, self::Archived => 'neutral',
        };
    }
}
