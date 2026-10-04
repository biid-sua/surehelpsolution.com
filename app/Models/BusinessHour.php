<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * One opening interval on a weekday. Several per day = split shift (spec §10).
 */
class BusinessHour extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'location_id', 'day_of_week', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer'];
    }
}
