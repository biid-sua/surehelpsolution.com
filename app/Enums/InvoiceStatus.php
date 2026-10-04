<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Due',
            self::Paid => 'Paid',
            self::Void => 'Void',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Paid => 'success',
            self::Void => 'neutral',
        };
    }
}
