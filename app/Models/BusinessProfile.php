<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Public-facing details of a business (spec §9). One per organization.
 *
 * @property Carbon|null $closed_from
 * @property Carbon|null $closed_until
 * @property bool $emergency_available
 */
class BusinessProfile extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'display_name', 'legal_name', 'logo_path', 'description', 'business_type', 'industry',
        'website', 'phone', 'email', 'service_area', 'emergency_available', 'emergency_instructions',
        'closed_from', 'closed_until', 'closure_message',
    ];

    /** Away (vacation mode) on this date: from closed_from (or straight away) up to and including closed_until. */
    public function isAwayOn(string $date): bool
    {
        return $this->closed_until !== null
            && $date <= $this->closed_until->toDateString()
            && ($this->closed_from === null || $date >= $this->closed_from->toDateString());
    }

    /** Time away that hasn't finished yet (current or planned). */
    public function hasAwayAhead(string $today): bool
    {
        return $this->closed_until !== null && $today <= $this->closed_until->toDateString();
    }

    protected function casts(): array
    {
        return [
            'emergency_available' => 'boolean',
            'closed_from' => 'date',
            'closed_until' => 'date',
        ];
    }
}
