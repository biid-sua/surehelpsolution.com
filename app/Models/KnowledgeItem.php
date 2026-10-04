<?php

namespace App\Models;

use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One piece of the business's knowledge base (spec §22).
 *
 * @property KnowledgeType $type
 * @property KnowledgeVisibility $visibility
 */
class KnowledgeItem extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'type', 'title', 'content', 'category', 'service_id', 'visibility', 'is_pinned',
        'is_active', 'sort_order', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected $attributes = [
        'visibility' => 'internal',
        'is_pinned' => false,
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => KnowledgeType::class,
            'visibility' => KnowledgeVisibility::class,
            'is_pinned' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (KnowledgeItem $item) {
            $item->ulid ??= (string) Str::ulid();
        });
    }

    /** @return BelongsTo<BusinessService, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'service_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * What our agents (and later the AI) may read: active and not team-only.
     *
     * @param  Builder<KnowledgeItem>  $query
     */
    public function scopeForAgents(Builder $query): void
    {
        $query->where('is_active', true)->where('visibility', '!=', KnowledgeVisibility::TeamOnly->value);
    }

    /**
     * Pinned first, then emergency guidance, then by the business's order.
     *
     * @param  Builder<KnowledgeItem>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByDesc('is_pinned')
            ->orderByRaw("CASE type WHEN 'emergency_instruction' THEN 0 WHEN 'agent_instruction' THEN 1 WHEN 'escalation_rule' THEN 2 ELSE 3 END")
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    /**
     * @param  Builder<KnowledgeItem>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('content', 'like', $like)->orWhere('category', 'like', $like));
    }
}
