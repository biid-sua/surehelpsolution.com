<?php

namespace App\Services\Business;

use App\Models\BusinessProfile;
use App\Models\Organization;
use App\Services\Billing\FeatureAccess;

/**
 * How a business looks to its customers (D82). Every business's emails and pages carry its name and
 * logo; with white-label in its plan, they also use its own colour and sender name and drop the
 * "handled by SureHelp" lines, and its portal can show its logo and colour. Memoised per request.
 */
class Branding
{
    public const DEFAULT_COLOR = '#4f46e5';

    /** @var array<int, array{name: string, logo: ?string, color: string, sender: string, white_label: bool, portal: bool}> */
    private array $cache = [];

    public function __construct(private readonly FeatureAccess $features) {}

    /** @return array{name: string, logo: ?string, color: string, sender: string, white_label: bool, portal: bool} */
    public function for(Organization $organization): array
    {
        if (isset($this->cache[$organization->id])) {
            return $this->cache[$organization->id];
        }
        $profile = BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
        $name = $profile?->display_name ?: $organization->name;
        $white = $this->features->allows($organization, 'white_label');
        $color = $white && self::isColor($profile?->brand_color) ? strtolower((string) $profile->brand_color) : self::DEFAULT_COLOR;

        return $this->cache[$organization->id] = [
            'name' => $name,
            'logo' => $profile?->logoUrl(),
            'color' => $color,
            'sender' => $white && $profile?->email_sender_name ? $profile->email_sender_name : $name,
            'white_label' => $white,
            'portal' => $white && ($profile === null || $profile->brand_portal),
        ];
    }

    public function forget(Organization $organization): void
    {
        unset($this->cache[$organization->id]);
    }

    public static function isColor(?string $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * CSS that recolours the portal's brand shades from one colour, or '' for SureHelp's own.
     */
    public function css(Organization $organization): string
    {
        $brand = $this->for($organization);
        if (! $brand['white_label'] || $brand['color'] === self::DEFAULT_COLOR) {
            return '';
        }

        return self::shades($brand['color']);
    }

    public static function shades(string $color): string
    {
        if (! self::isColor($color)) {
            return '';
        }
        $mix = fn (string $with, int $pct) => "color-mix(in oklab, {$color} ".(100 - $pct)."%, {$with})";

        return ':root{'
            .'--color-brand-200:'.$mix('white', 70).';'
            .'--color-brand-300:'.$mix('white', 50).';'
            .'--color-brand-400:'.$mix('white', 25).';'
            .'--color-brand-500:'.$color.';'
            .'--color-brand-600:'.$mix('black', 12).';'
            .'--color-brand-700:'.$mix('black', 25).';'
            .'}';
    }

    /** Readable text (black or white) on the colour, for buttons in emails. */
    public static function textOn(string $color): string
    {
        if (! self::isColor($color)) {
            return '#ffffff';
        }
        [$r, $g, $b] = array_map(fn ($h) => hexdec($h) / 255, str_split(substr($color, 1), 2));
        $lin = fn ($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $l = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);

        return $l > 0.4 ? '#111111' : '#ffffff';
    }
}
