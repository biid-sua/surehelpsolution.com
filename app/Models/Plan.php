<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price list entry (spec §28). Plans are data: adding or repricing one needs no code change. The
 * same plans fill the website's pricing section, the sign-up page, Billing and the sales chat (D98).
 *
 * @property list<string>|null $features
 * @property array<string, int>|null $limits
 */
class Plan extends Model
{
    public const INTERVALS = ['month' => 'Monthly', 'year' => 'Yearly'];

    protected $fillable = ['slug', 'name', 'description', 'price_cents', 'currency', 'interval', 'trial_days', 'features', 'limits', 'is_public', 'is_active', 'sort_order',
        'tagline', 'annual_monthly_cents', 'is_featured', 'contact_only'];

    /** Mirror the column defaults so a freshly created plan is usable without a refresh. */
    protected $attributes = ['currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'is_active' => true, 'sort_order' => 0,
        'is_featured' => false, 'contact_only' => false];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'annual_monthly_cents' => 'integer',
            'is_featured' => 'boolean',
            'contact_only' => 'boolean',
        ];
    }

    /** Limits a plan can set (all optional; missing means unlimited). */
    public const LIMITS = [
        'calls' => 'Calls included per billing period',
        'extra_call_cents' => 'Price per extra call (cents)',
        'team_members' => 'Team members (people who can sign in)',
        'calendars' => 'Connected calendars',
    ];

    /** "$299/month" */
    public function priceLabel(): string
    {
        return Money::format($this->price_cents, $this->currency).'/'.$this->interval;
    }

    /** Price per month, for MRR. */
    public function monthlyCents(): int
    {
        return $this->interval === 'year' ? intdiv($this->price_cents, 12) : $this->price_cents;
    }

    /** The "What's included" lines, one per line, for the website and the plan cards. @return list<string> */
    public function highlights(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $this->description) ?: [])));
    }

    /** Ever used by a subscription (current, past or scheduled): such a plan is retired, never deleted. */
    public function inUse(): bool
    {
        return Subscription::withoutGlobalScopes()->where('plan_id', $this->id)->orWhere('next_plan_id', $this->id)->exists();
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('price_cents');
    }

    /**
     * Shown on the website: public and available, including plans priced on request.
     *
     * @param  Builder<Plan>  $query
     */
    public function scopeOnWebsite(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true);
    }

    /**
     * What a business can choose itself, at sign-up or in Billing: not plans priced on request.
     *
     * @param  Builder<Plan>  $query
     */
    public function scopeSelfServe(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true)->where('contact_only', false);
    }
}
