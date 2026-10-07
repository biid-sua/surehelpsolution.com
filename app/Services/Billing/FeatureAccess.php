<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a business may use a paid feature (spec §30–31, FND-18, D46). In order:
 *
 *  1. A staff override for this business (on or off) wins.
 *  2. A feature open to everyone (the default) is allowed.
 *  3. Otherwise the business's plan or one of its active add-ons must include the feature key.
 *
 * Checked on the server, where the work happens: pages, background jobs and public endpoints.
 * Request-scoped and memoised.
 */
class FeatureAccess
{
    public const EVERYONE = 'everyone';

    public const PLAN = 'plan';

    /** @var array<string, string>|null */
    private ?array $modes = null;

    /** @var array<int, array<string, bool>> */
    private array $overrides = [];

    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public function catalog(): array
    {
        return config('features');
    }

    public function allows(Organization $organization, string $feature): bool
    {
        $override = $this->overrides($organization)[$feature] ?? null;
        if ($override !== null) {
            return $override;
        }
        if ($this->mode($feature) === self::EVERYONE) {
            return true;
        }

        return $this->entitlements->allows($organization, $feature);
    }

    public function label(string $feature): string
    {
        return $this->catalog()[$feature]['label'] ?? $feature;
    }

    public function mode(string $feature): string
    {
        return $this->modes()[$feature] ?? self::EVERYONE;
    }

    /**
     * @return array<string, string> feature => everyone | plan
     */
    public function modes(): array
    {
        if ($this->modes === null) {
            $saved = Schema::hasTable('platform_settings') ? (PlatformSetting::query()->where('key', 'features.modes')->value('value') ?? []) : [];
            $saved = is_string($saved) ? (json_decode($saved, true) ?: []) : (array) $saved;
            $this->modes = [];
            foreach (array_keys($this->catalog()) as $key) {
                $this->modes[$key] = ($saved[$key] ?? self::EVERYONE) === self::PLAN ? self::PLAN : self::EVERYONE;
            }
        }

        return $this->modes;
    }

    /**
     * @param  array<string, string>  $modes
     */
    public function saveModes(array $modes): void
    {
        $clean = [];
        foreach (array_keys($this->catalog()) as $key) {
            $clean[$key] = ($modes[$key] ?? self::EVERYONE) === self::PLAN ? self::PLAN : self::EVERYONE;
        }
        PlatformSetting::put('features.modes', $clean);
        $this->modes = null;
    }

    /**
     * Staff overrides for one business: feature => on/off (missing means "follow the plan").
     *
     * @return array<string, bool>
     */
    public function overrides(Organization $organization): array
    {
        return $this->overrides[$organization->id] ??= DB::table('organization_features')->where('organization_id', $organization->id)
            ->pluck('enabled', 'feature')->map(fn ($v) => (bool) $v)->all();
    }

    public function forget(Organization $organization): void
    {
        unset($this->overrides[$organization->id]);
        $this->entitlements->forget($organization);
    }
}
