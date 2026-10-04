<?php

namespace App\Models;

use App\Enums\ServicePriceType;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A service the client's business sells (spec §11).
 *
 * @property ServicePriceType $price_type
 * @property int|null $price_cents
 * @property list<string>|null $required_fields
 */
class BusinessService extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** Customer details an agent must collect when booking this service (key => label). */
    public const REQUIRED_FIELDS = [
        'phone' => 'Phone number',
        'email' => 'Email address',
        'address' => 'Service address',
        'details' => 'Description of the problem',
    ];

    protected $fillable = [
        'organization_id', 'location_id', 'name', 'description', 'category', 'duration_minutes', 'buffer_minutes',
        'price_type', 'price_cents', 'currency', 'is_active', 'is_bookable', 'required_fields', 'agent_instructions', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_type' => ServicePriceType::class,
            'price_cents' => 'integer',
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_bookable' => 'boolean',
            'required_fields' => 'array',
        ];
    }

    public function priceLabel(): string
    {
        return $this->price_type->display($this->price_cents, $this->currency);
    }

    public function durationLabel(): string
    {
        $hours = intdiv($this->duration_minutes, 60);
        $minutes = $this->duration_minutes % 60;

        return trim(($hours ? $hours.' h ' : '').($minutes ? $minutes.' min' : ''));
    }

    /**
     * @param  Builder<BusinessService>  $query
     * @return Builder<BusinessService>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
