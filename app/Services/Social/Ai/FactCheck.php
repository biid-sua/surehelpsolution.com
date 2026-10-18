<?php

namespace App\Services\Social\Ai;

/**
 * Checks AI-written text against the business's own details (D66): phone numbers, prices, discounts and
 * "years in business" that don't appear in the details are flagged for a person to confirm. The AI also
 * lists claims it couldn't back up; both show next to the text, nothing is removed automatically.
 */
class FactCheck
{
    /** @return list<string> */
    public function check(string $text, string $facts): array
    {
        $flags = [];
        $factPhoneDigits = preg_replace('/\D+/', '', $facts) ?? '';
        $factsLower = mb_strtolower($facts);

        preg_match_all('/\+?\(?\d[\d\s().-]{7,}\d/', $text, $phones);
        foreach ($phones[0] as $phone) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if (strlen($digits) >= 7 && ! str_contains($factPhoneDigits, $digits)) {
                $flags[] = "The phone number {$phone} isn't in your business details.";
            }
        }

        preg_match_all('/[$€£]\s?\d[\d,]*(?:\.\d{1,2})?|\d+(?:\.\d+)?\s?%/u', $text, $amounts);
        foreach (array_unique($amounts[0]) as $amount) {
            $number = str_replace(',', '', preg_replace('/[^\d.,]/', '', $amount) ?? '');
            if (! preg_match('/(?<![\d.])'.preg_quote($number, '/').'(?!\d)/', str_replace(',', '', $facts))) {
                $flags[] = "\"{$amount}\" isn't in your business details. Check the price or discount is right.";
            }
        }

        preg_match_all('/\b(?:since|established|founded)\s+(?:in\s+)?(19|20)\d{2}\b|\b\d+\+?\s+years\b/i', $text, $years);
        foreach (array_unique($years[0]) as $claim) {
            if (! str_contains($factsLower, mb_strtolower($claim))) {
                $flags[] = "\"{$claim}\" isn't in your business details.";
            }
        }

        return array_values(array_unique($flags));
    }
}
