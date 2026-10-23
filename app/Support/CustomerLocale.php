<?php

namespace App\Support;

use App\Models\BusinessProfile;
use App\Models\Organization;
use Illuminate\Support\Facades\App;

/**
 * The language a business's customers see (D92): English, Spanish, or "auto" (the visitor's browser
 * on pages; English in emails and texts, where there's no browser to ask).
 */
class CustomerLocale
{
    public const CHOICES = ['en' => 'English', 'es' => 'Spanish (Español)', 'auto' => 'Match each visitor\'s browser (English or Spanish)'];

    /** The business's setting. */
    public static function setting(Organization|int $organization): string
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;
        $value = (string) (BusinessProfile::withoutGlobalScopes()->where('organization_id', $id)->value('customer_locale') ?? 'en');

        return array_key_exists($value, self::CHOICES) ? $value : 'en';
    }

    /**
     * The language for this customer: the setting, or for "auto" the hint (a language code from the
     * widget) or the browser's Accept-Language.
     */
    public static function resolve(Organization|int $organization, ?string $hint = null, bool $browser = true): string
    {
        $setting = self::setting($organization);
        if ($setting !== 'auto') {
            return $setting;
        }
        $hint = strtolower(substr((string) $hint, 0, 2));
        if (in_array($hint, ['en', 'es'], true)) {
            return $hint;
        }

        return $browser ? (request()->getPreferredLanguage(['en', 'es']) ?? 'en') : 'en';
    }

    /** For emails and texts: Spanish only when the business chose Spanish. */
    public static function forMessages(Organization|int $organization): string
    {
        return self::setting($organization) === 'es' ? 'es' : 'en';
    }

    /** Switch this request (or job) to the customer's language. */
    public static function apply(Organization|int $organization, ?string $hint = null): string
    {
        $locale = self::resolve($organization, $hint);
        App::setLocale($locale);

        return $locale;
    }
}
