<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Services\Compliance\Hipaa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a business may use a paid feature (spec §30–31, FND-18, D46). In order:
 *
 *  1. A staff override for this business (on or off) wins.
 *     After a self-serve trial ended without a plan, nothing else is on (D97).
 *  2. A feature open to everyone (the default) is allowed.
 *  3. A feature for selected businesses only (D107) is allowed only where staff switched it on (1).
 *  4. Otherwise the business's plan or one of its active add-ons must include the feature key.
 *
 * Checked on the server, where the work happens: pages, background jobs and public endpoints.
 * Request-scoped and memoised.
 */
class FeatureAccess
{
    public const EVERYONE = 'everyone';

    public const PLAN = 'plan';

    /** Only the businesses staff pick; everyone else doesn't see it at all (D107). */
    public const SELECTED = 'selected';

    public const MODES = [self::EVERYONE, self::PLAN, self::SELECTED];

    /** @var array<string, string>|null */
    private ?array $modes = null;

    /** @var array<int, array<string, bool>> */
    private array $overrides = [];

    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @return array<string, array{label: string, description: string, default_mode?: string}>
     */
    public function catalog(): array
    {
        return config('features');
    }

    public function allows(Organization $organization, string $feature): bool
    {
        // SureHelp's own internal organization (D104) isn't a customer: nothing is gated.
        if ($organization->is_internal) {
            return true;
        }
        // HIPAA mode (D117): no AI reads a healthcare business's calls or messages.
        if ($organization->hipaa_mode && in_array($feature, Hipaa::AI_FEATURES, true)) {
            return false;
        }
        $override = $this->overrides($organization)[$feature] ?? null;
        if ($override !== null) {
            return $override;
        }
        if (app(Trials::class)->ended($organization)) {
            return false;
        }
        if ($this->mode($feature) === self::EVERYONE) {
            return true;
        }
        if ($this->mode($feature) === self::SELECTED) {
            return false;
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

    /** A feature for selected businesses that this one doesn't have: hidden, not offered as an upgrade. */
    public function hidden(Organization $organization, string $feature): bool
    {
        return $this->mode($feature) === self::SELECTED && ! $this->allows($organization, $feature);
    }

    /**
     * @return array<string, string> feature => everyone | plan | selected
     */
    public function modes(): array
    {
        if ($this->modes === null) {
            $saved = Schema::hasTable('platform_settings') ? (PlatformSetting::query()->where('key', 'features.modes')->value('value') ?? []) : [];
            $saved = is_string($saved) ? (json_decode($saved, true) ?: []) : (array) $saved;
            $this->modes = [];
            foreach (array_keys($this->catalog()) as $key) {
                $default = $this->catalog()[$key]['default_mode'] ?? self::EVERYONE;
                $mode = $saved[$key] ?? $default;
                $this->modes[$key] = in_array($mode, self::MODES, true) ? $mode : self::EVERYONE;
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
            $mode = $modes[$key] ?? self::EVERYONE;
            $clean[$key] = in_array($mode, self::MODES, true) ? $mode : self::EVERYONE;
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

    /**
     * Businesses that have a feature switched on by staff (the picked ones, in "selected" mode).
     *
     * @return list<int>
     */
    public function switchedOn(string $feature): array
    {
        return DB::table('organization_features')->where('feature', $feature)->where('enabled', true)->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Switch a feature on for one business, or back to following its plan. */
    public function pick(Organization $organization, string $feature, bool $on, ?int $by): void
    {
        if ($on) {
            DB::table('organization_features')->updateOrInsert(
                ['organization_id' => $organization->id, 'feature' => $feature],
                ['enabled' => true, 'set_by' => $by, 'updated_at' => now(), 'created_at' => now()],
            );
        } else {
            DB::table('organization_features')->where('organization_id', $organization->id)->where('feature', $feature)->delete();
        }
        $this->forget($organization);
    }

    public function forget(Organization $organization): void
    {
        unset($this->overrides[$organization->id]);
        $this->entitlements->forget($organization);
    }
}
