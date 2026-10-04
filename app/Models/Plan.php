<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price list entry (spec §28). Plans are data: adding or repricing one needs no code change.
 *
 * @property list<string>|null $features
 * @property array<string, int>|null $limits
 */
class Plan extends Model
{
    public const INTERVALS = ['month' => 'Monthly', 'year' => 'Yearly'];

    protected $fillable = ['slug', 'name', 'description', 'price_cents', 'currency', 'interval', 'trial_days', 'features', 'limits', 'is_public', 'is_active', 'sort_order'];

    /** Mirror the column defaults so a freshly created plan is usable without a refresh. */
    protected $attributes = ['currency' => 'USD', 'interval' => 'month', 'trial_days' => 0, 'is_public' => true, 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

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
}
