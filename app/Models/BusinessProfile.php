<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Public-facing details of a business (spec §9). One per organization.
 *
 * @property Carbon|null $closed_until
 * @property bool $emergency_available
 */
class BusinessProfile extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'display_name', 'legal_name', 'logo_path', 'description', 'business_type', 'industry',
        'website', 'phone', 'email', 'service_area', 'emergency_available', 'emergency_instructions',
        'closed_until', 'closure_message',
    ];

    protected function casts(): array
    {
        return [
            'emergency_available' => 'boolean',
            'closed_until' => 'date',
        ];
    }
}
