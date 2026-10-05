<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An add-on a business has turned on (task.md ADD-02, ADD-04).
 *
 * @property Carbon $started_on
 * @property Carbon|null $ended_on
 */
class OrganizationAddon extends Model
{
    use BelongsToOrganization;

    public const ACTIVE = 'active';

    public const ENDED = 'ended';

    protected $fillable = ['organization_id', 'addon_id', 'status', 'price_cents', 'started_on', 'cancel_at_period_end', 'ended_on', 'activated_by'];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'started_on' => 'date',
            'ended_on' => 'date',
            'cancel_at_period_end' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Addon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    /**
     * @param  Builder<OrganizationAddon>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    public function priceLabel(): string
    {
        return Money::format($this->price_cents, $this->addon->currency ?? 'USD').'/month';
    }
}
