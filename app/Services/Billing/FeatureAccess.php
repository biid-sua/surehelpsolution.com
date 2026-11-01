<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\PlatformSetting;
use App\Services\Compliance\Hipaa;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a business may use a paid feature (spec §30–31, FND-18, D46). In order:
 *
 *  0. A feature switched off for everyone (D119) is off, whatever else is set: it isn't offered yet.
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

    /** Switched off for everyone and hidden: not offered yet, or taken out for a while (D119). */
    public const OFF = 'off';

    public const MODES = [self::EVERYONE, self::PLAN, self::SELECTED, self::OFF];

    /** Who gets a feature while it's on; "off" isn't one of them. */
    public const AUDIENCES = [self::EVERYONE, self::PLAN, self::SELECTED];

    /** How long a feature that was just switched on shows as "New" to businesses (D119). */
    public const NEW_FOR_DAYS = 30;

    /** @var array<string, string>|null */
    private ?array $modes = null;

    /** @var array<int, array<string, bool>> */
    private array $overrides = [];

    /** @var array<string, string>|null */
    private ?array $launches = null;

    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @return array<string, array{label: string, description: string, default_mode?: string, audience?: string, group?: string, route?: string}>
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
        if ($this->mode($feature) === self::OFF) {
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

    /**
     * Hidden, not offered as an upgrade: a feature switched off for everyone, or one for selected
     * businesses that this one doesn't have.
     */
    public function hidden(Organization $organization, string $feature): bool
    {
        if ($organization->is_internal) {
            return false;
        }

        return $this->mode($feature) === self::OFF
            || ($this->mode($feature) === self::SELECTED && ! $this->allows($organization, $feature));
    }

    public function isOn(string $feature): bool
    {
        return $this->mode($feature) !== self::OFF;
    }

    /**
     * Who gets each feature while it's on. For a switched-off feature, who got it before (or its
     * default), so switching it back on picks up where it was.
     *
     * @return array<string, string> feature => everyone | plan | selected
     */
    public function audiences(): array
    {
        $saved = $this->setting('features.audience');
        $audiences = [];
        foreach ($this->modes() as $key => $mode) {
            $default = $this->catalog()[$key]['audience'] ?? $this->catalog()[$key]['default_mode'] ?? self::EVERYONE;
            $audience = $mode !== self::OFF ? $mode : ($saved[$key] ?? $default);
            $audiences[$key] = in_array($audience, self::AUDIENCES, true) ? $audience : self::EVERYONE;
        }

        return $audiences;
    }

    /**
     * Switch features on or off and choose who gets them (D119). A feature switched on from off is
     * announced to the businesses that get it as "New", unless $announce says not to.
     *
     * @param  array<string, bool>  $on
     * @param  array<string, string>  $audiences
     * @param  array<string, bool>  $announce
     * @return list<string> features newly announced
     */
    public function configure(array $on, array $audiences, array $announce = []): array
    {
        $before = $this->modes();
        $current = $this->audiences();
        $modes = [];
        $remembered = [];
        foreach (array_keys($this->catalog()) as $key) {
            $audience = in_array($audiences[$key] ?? null, self::AUDIENCES, true) ? $audiences[$key] : $current[$key];
            $remembered[$key] = $audience;
            $modes[$key] = ($on[$key] ?? ($before[$key] !== self::OFF)) ? $audience : self::OFF;
        }

        $launched = $this->launches();
        $announced = [];
        foreach ($modes as $key => $mode) {
            if ($before[$key] === self::OFF && $mode !== self::OFF && ($announce[$key] ?? true)) {
                $launched[$key] = now()->toIso8601String();
                $announced[] = $key;
            }
        }
        PlatformSetting::put('features.audience', $remembered);
        PlatformSetting::put('features.launched', $launched);
        $this->launches = null;
        $this->saveModes($modes);

        return $announced;
    }

    /**
     * When each feature was last switched on and announced.
     *
     * @return array<string, string> feature => ISO 8601 time
     */
    public function launches(): array
    {
        return $this->launches ??= array_filter($this->setting('features.launched'), 'is_string');
    }

    /** Shown as "New" (in the menu and on the dashboard) for a while after it was switched on. */
    public function isNew(string $feature): bool
    {
        $at = $this->launches()[$feature] ?? null;

        return $at !== null && $this->isOn($feature) && Carbon::parse($at)->gt(now()->subDays(self::NEW_FOR_DAYS));
    }

    /**
     * New features this business has, launched after $since (when the person last closed the card).
     *
     * @return list<string>
     */
    public function newFor(Organization $organization, ?CarbonInterface $since = null): array
    {
        return array_values(array_filter(array_keys($this->launches()), fn (string $key) => isset($this->catalog()[$key])
            && $this->isNew($key) && $this->allows($organization, $key)
            && ($since === null || Carbon::parse($this->launches()[$key])->gt($since))));
    }

    /** @return array<string, mixed> */
    private function setting(string $key): array
    {
        if (! Schema::hasTable('platform_settings')) {
            return [];
        }
        $value = PlatformSetting::query()->where('key', $key)->value('value') ?? [];

        return is_string($value) ? (json_decode($value, true) ?: []) : (array) $value;
    }

    /**
     * @return array<string, string> feature => everyone | plan | selected
     */
    public function modes(): array
    {
        if ($this->modes === null) {
            $saved = $this->setting('features.modes');
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
