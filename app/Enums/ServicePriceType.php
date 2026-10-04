<?php

namespace App\Enums;

use App\Support\Money;

/**
 * How a service's price is presented to callers (spec §11).
 */
enum ServicePriceType: string
{
    case Fixed = 'fixed';
    case StartingFrom = 'starting_from';
    case QuoteRequired = 'quote_required';
    case Hidden = 'hidden';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed price',
            self::StartingFrom => 'Starting from',
            self::QuoteRequired => 'Quote required',
            self::Hidden => 'Don\'t share the price',
        };
    }

    public function needsAmount(): bool
    {
        return in_array($this, [self::Fixed, self::StartingFrom], true);
    }

    /**
     * What an agent may say to a caller about the price.
     */
    public function display(?int $cents, string $currency): string
    {
        return match ($this) {
            self::Fixed => $cents !== null ? Money::format($cents, $currency) : 'Price on request',
            self::StartingFrom => $cents !== null ? 'From '.Money::format($cents, $currency) : 'Price on request',
            self::QuoteRequired => 'Quote required',
            self::Hidden => 'Ask our team for pricing',
        };
    }
}
