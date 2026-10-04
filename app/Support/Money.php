<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money in integer minor units (spec §75). Never uses floating point.
 */
final class Money
{
    private const SYMBOLS = ['USD' => '$', 'CAD' => 'CA$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$'];

    /**
     * 15050 USD → "$150.50"; whole amounts drop the cents: 15000 → "$150".
     */
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $negative = $cents < 0;
        $cents = abs($cents);
        $whole = number_format(intdiv($cents, 100));
        $fraction = $cents % 100;

        $amount = $fraction === 0 ? $whole : $whole.'.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
        $symbol = self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency).' ';

        return ($negative ? '-' : '').$symbol.$amount;
    }

    /**
     * Parse what a person typed ("150", "150.5", "$1,250.00") into cents, without floats.
     *
     * @throws InvalidArgumentException when the text isn't a valid non-negative amount
     */
    public static function parse(string $input): int
    {
        $clean = str_replace([',', ' ', '$'], '', trim($input));

        if (! preg_match('/^(\d{1,7})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException('Enter an amount like 150 or 149.99.');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /**
     * Cents → editable text ("150" or "149.99").
     */
    public static function toInput(?int $cents): string
    {
        if ($cents === null) {
            return '';
        }

        $fraction = $cents % 100;

        return intdiv($cents, 100).($fraction === 0 ? '' : '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT));
    }
}
