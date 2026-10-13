<?php

namespace App\Support\Geo;

/**
 * A postal address entered as separate fields (D56): country-aware labels, validation and the one-line form
 * that emails, calendars, rules and reports read.
 */
final class Address
{
    public const TYPES = ['residential' => 'Residential', 'commercial' => 'Commercial', 'other' => 'Other'];

    /** The parts, in the order they are stored. */
    public const FIELDS = ['line1', 'line2', 'city', 'state', 'postal_code', 'country'];

    private const US_STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
        'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
        'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts',
        'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana',
        'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
        'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        'AS' => 'American Samoa', 'GU' => 'Guam', 'MP' => 'Northern Mariana Islands', 'PR' => 'Puerto Rico', 'VI' => 'U.S. Virgin Islands',
        'AA' => 'Armed Forces Americas', 'AE' => 'Armed Forces Europe', 'AP' => 'Armed Forces Pacific',
    ];

    private const CA_PROVINCES = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador',
        'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island',
        'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon',
    ];

    /** @return array<string, string> code => name; the US and Canada first, then the rest alphabetically */
    public static function countries(): array
    {
        $all = (array) config('countries', []);

        return array_filter(['US' => $all['US'] ?? null, 'CA' => $all['CA'] ?? null]) + $all;
    }

    /** @return array<string, string> code => name, or empty when the country's regions are typed freely */
    public static function regions(string $country): array
    {
        return match (strtoupper($country)) {
            'US' => self::US_STATES,
            'CA' => self::CA_PROVINCES,
            default => [],
        };
    }

    public static function regionLabel(string $country): string
    {
        return match (strtoupper($country)) {
            'US' => 'State',
            'CA' => 'Province',
            default => 'State / region',
        };
    }

    public static function postalLabel(string $country): string
    {
        return strtoupper($country) === 'US' ? 'ZIP code' : 'Postal code';
    }

    /** @return array{line1: string, line2: string, city: string, state: string, postal_code: string, country: string} */
    public static function blank(string $country = 'US'): array
    {
        return ['line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'country' => strtoupper($country)];
    }

    /**
     * Rules for the parts under $prefix (e.g. "entry.location"). Street, city and, where the country has them, state
     * and postal code are required: a visit needs an address someone can drive to.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix, string $country): array
    {
        $country = strtoupper($country);
        $regions = self::regions($country);

        return [
            "$prefix.country" => ['required', 'string', 'size:2', 'in:'.implode(',', array_keys(self::countries()))],
            "$prefix.line1" => ['required', 'string', 'max:255'],
            "$prefix.line2" => ['nullable', 'string', 'max:255'],
            "$prefix.city" => ['required', 'string', 'max:100'],
            "$prefix.state" => $regions !== [] ? ['required', 'in:'.implode(',', array_keys($regions))] : ['nullable', 'string', 'max:100'],
            "$prefix.postal_code" => match ($country) {
                'US' => ['required', 'regex:/^\d{5}(-\d{4})?$/'],
                'CA' => ['required', 'regex:/^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$/'],
                default => ['nullable', 'string', 'max:20'],
            },
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix, string $country): array
    {
        return [
            "$prefix.postal_code.regex" => strtoupper($country) === 'US' ? 'Enter a 5-digit ZIP code, e.g. 78701.' : 'Enter a postal code like K1A 0B1.',
            "$prefix.postal_code.required" => 'Enter the '.mb_strtolower(self::postalLabel($country)).'.',
            "$prefix.state.required" => 'Choose the '.mb_strtolower(self::regionLabel($country)).'.',
            "$prefix.line1.required" => 'Enter the street address.',
            "$prefix.city.required" => 'Enter the city.',
        ];
    }

    /**
     * Trimmed parts with consistent formatting (upper-case state codes and Canadian postal codes).
     *
     * @param  array<string, mixed>  $parts
     * @return array{line1: ?string, line2: ?string, city: ?string, state: ?string, postal_code: ?string, country: string}
     */
    public static function normalize(array $parts): array
    {
        $clean = fn (string $key) => ($v = trim(preg_replace('/\s+/', ' ', (string) ($parts[$key] ?? '')))) !== '' ? $v : null;
        $country = strtoupper($clean('country') ?? 'US');
        $state = $clean('state');
        $postal = $clean('postal_code');

        return [
            'line1' => $clean('line1'),
            'line2' => $clean('line2'),
            'city' => $clean('city'),
            'state' => $state !== null && self::regions($country) !== [] ? strtoupper($state) : $state,
            'postal_code' => $postal !== null && $country === 'CA' ? strtoupper(substr(str_replace(' ', '', $postal), 0, 3).' '.substr(str_replace(' ', '', $postal), 3)) : $postal,
            'country' => $country,
        ];
    }

    /**
     * "12 Main St, Unit 4, Austin, TX 78701", with the country added when it isn't $homeCountry.
     *
     * @param  array<string, mixed>  $parts
     */
    public static function singleLine(array $parts, string $homeCountry = 'US'): ?string
    {
        $p = self::normalize($parts);
        $line = collect([$p['line1'], $p['line2'], $p['city'], trim(($p['state'] ?? '').' '.($p['postal_code'] ?? ''))])
            ->push($p['country'] !== strtoupper($homeCountry) ? (self::countries()[$p['country']] ?? $p['country']) : null)
            ->filter(fn ($part) => filled($part))
            ->implode(', ');

        return $line !== '' ? mb_substr($line, 0, 255) : null;
    }
}
