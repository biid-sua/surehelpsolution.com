<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A holiday (closed all day) or special hours for one date (spec §10).
 *
 * @property Carbon $date
 * @property bool $is_closed
 * @property string|null $opens_at
 * @property string|null $closes_at
 */
class BusinessHoliday extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'date', 'name', 'is_closed', 'opens_at', 'closes_at'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_closed' => 'boolean'];
    }
}
