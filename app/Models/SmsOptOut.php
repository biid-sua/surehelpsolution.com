<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A number that texted STOP to one of a business's lines (D61). Nothing is texted to it from that
 * business until it texts START again.
 */
class SmsOptOut extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'phone', 'opted_out_at'];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }
}
