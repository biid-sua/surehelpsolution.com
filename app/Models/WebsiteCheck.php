<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One run of a website's health check, kept so the SEO page can show how the site changed (D57).
 *
 * @property Carbon $checked_at
 */
class WebsiteCheck extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $fillable = ['website_id', 'organization_id', 'checked_at', 'health_score', 'problems', 'warnings', 'pages_checked', 'error'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'health_score' => 'integer', 'problems' => 'integer', 'warnings' => 'integer', 'pages_checked' => 'integer'];
    }
}
