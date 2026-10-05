<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's own wording for an email to its customers (spec §27).
 */
class MessageTemplate extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'key', 'subject', 'body', 'is_active', 'lead_hours'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'lead_hours' => 'integer'];
    }
}
