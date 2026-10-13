<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Geo\Address;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place a customer has saved for future bookings (D56), e.g. their home and their office.
 */
class CustomerLocation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'customer_id', 'name', 'type', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return array{line1: ?string, line2: ?string, city: ?string, state: ?string, postal_code: ?string, country: string} */
    public function parts(): array
    {
        return [
            'line1' => $this->address_line1, 'line2' => $this->address_line2, 'city' => $this->city,
            'state' => $this->state, 'postal_code' => $this->postal_code, 'country' => $this->country ?: 'US',
        ];
    }

    public function singleLine(string $homeCountry = 'US'): string
    {
        return (string) Address::singleLine($this->parts(), $homeCountry);
    }

    /** "Lake house · 12 Main St, Austin, TX 78701" */
    public function label(string $homeCountry = 'US'): string
    {
        return collect([$this->name, $this->singleLine($homeCountry)])->filter()->implode(' · ');
    }
}
