<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For tenant-owned models: scoped reads and server-side organization stamping.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            $current = app(CurrentOrganization::class);

            if (empty($model->organization_id) && $current->has()) {
                $model->organization_id = $current->id();
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Explicit filter for code paths that run without a tenant context (admin, agent, jobs).
     */
    public function scopeForOrganization(Builder $query, Organization|int $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query->where($this->qualifyColumn('organization_id'), $id);
    }
}
