<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A paid extra a business can turn on (task.md ADD-01). Its slug is also the feature key it
 * unlocks through `Entitlements`. Billed monthly with the subscription.
 */
class Addon extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'price_cents', 'currency', 'is_active', 'sort_order'];

    protected $attributes = ['currency' => 'USD', 'is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function priceLabel(): string
    {
        return Money::format($this->price_cents, $this->currency).'/month';
    }

    /**
     * @param  Builder<Addon>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
