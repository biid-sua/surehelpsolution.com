<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of Google Search Console numbers for a business's site (D57).
 *
 * @property string $date Y-m-d, kept as a plain date string so day ranges compare the same on every database
 */
class SeoDailyMetric extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = ['organization_id', 'date', 'clicks', 'impressions', 'position'];

    protected function casts(): array
    {
        return ['clicks' => 'integer', 'impressions' => 'integer', 'position' => 'float'];
    }
}
