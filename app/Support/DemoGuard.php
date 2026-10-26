<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\PlatformSetting;

/**
 * Keeps demo data (D94) from reaching the outside world: background work that calls another service
 * (Google, Stripe, social networks, webhooks, the AI) skips demo businesses, and while demo data is
 * loaded, mail to reserved example addresses is dropped.
 */
class DemoGuard
{
    public const STATE_SETTING = 'demo.state';

    /** Domains reserved for examples (RFC 2606): no real person can receive mail there. */
    public static function reservedEmail(string $address): bool
    {
        $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));

        return in_array($domain, ['example.com', 'example.net', 'example.org'], true)
            || str_ends_with($domain, '.example') || $domain === 'example' || str_ends_with($domain, '.invalid');
    }

    public static function isDemo(?int $organizationId): bool
    {
        return $organizationId !== null && (bool) Organization::withoutGlobalScopes()->whereKey($organizationId)->value('is_demo');
    }

    /** Demo data is in the database (or being loaded or removed). */
    public static function present(): bool
    {
        $state = rescue(fn () => PlatformSetting::get(self::STATE_SETTING), null, report: false);

        return is_array($state) && in_array($state['status'] ?? null, ['loading', 'loaded', 'removing', 'failed_removal'], true);
    }
}
