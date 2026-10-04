<?php

namespace App\Models;

use App\Enums\OutcomeCategory;
use Illuminate\Database\Eloquent\Model;

/**
 * A call outcome: a platform default (organization_id NULL) or a business's
 * override / own outcome. Read through App\Services\Calls\CallOutcomes.
 *
 * Deliberately not tenant-scoped: platform defaults have no organization.
 *
 * @property OutcomeCategory $category
 */
class CallOutcome extends Model
{
    protected $fillable = ['organization_id', 'key', 'label', 'category', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'category' => OutcomeCategory::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
