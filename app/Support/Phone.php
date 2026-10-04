<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone numbers in E.164 for matching (spec §15: match customers by normalised phone).
 * Defaults to the US, the initial market; other countries need a leading "+".
 */
final class Phone
{
    public static function normalize(?string $raw, string $region = 'US'): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || ! preg_match('/\d/', $raw)) {
            return null;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($raw, $region);

            return $util->isValidNumber($number) ? $util->format($number, PhoneNumberFormat::E164) : null;
        } catch (NumberParseException) {
            return null;
        }
    }

    /**
     * "+15125550100" → "(512) 555-0100" for US numbers, international format otherwise.
     */
    public static function display(?string $e164, string $region = 'US'): ?string
    {
        if (! $e164) {
            return null;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($e164, $region);

            return $util->format($number, $util->getRegionCodeForNumber($number) === $region ? PhoneNumberFormat::NATIONAL : PhoneNumberFormat::INTERNATIONAL);
        } catch (NumberParseException) {
            return $e164;
        }
    }
}
