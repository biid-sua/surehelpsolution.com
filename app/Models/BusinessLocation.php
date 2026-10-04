<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A place the business operates from. The data model supports many; the UI exposes the primary one (spec §9).
 */
class BusinessLocation extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'name', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country', 'phone', 'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function singleLine(): string
    {
        return collect([$this->address_line1, $this->address_line2, $this->city, trim($this->state.' '.$this->postal_code)])
            ->filter(fn ($part) => filled(trim((string) $part)))
            ->implode(', ');
    }
}
