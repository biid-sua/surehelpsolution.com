<?php

namespace App\Models;

use App\Enums\OutcomeCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call outcome: a platform default (no organization) or a business's own row (spec §14).
 *
 * Not tenant-scoped on purpose: a business always needs the platform defaults too.
 * Read outcomes through App\Services\Calls\CallOutcomes, never directly.
 *
 * @property OutcomeCategory $category
 */
class CallOutcome extends Model
{
    protected $fillable = ['organization_id', 'key', 'label', 'category', 'is_active', 'sort_order'];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'category' => OutcomeCategory::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
