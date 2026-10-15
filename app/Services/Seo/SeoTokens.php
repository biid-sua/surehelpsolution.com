<?php

namespace App\Services\Seo;

use App\Models\Organization;
use App\Models\SeoTask;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;

/**
 * The AI token allowance for SEO work (D57): per calendar month in the business's timezone. Staff can
 * set an amount for one business; otherwise the plan's `seo_ai_tokens` limit; otherwise the default.
 */
class SeoTokens
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function allowance(Organization $organization): int
    {
        return $organization->seo_token_allowance
            ?? $this->entitlements->limit($organization, 'seo_ai_tokens')
            ?? (int) config('seo.monthly_tokens', 250000);
    }

    public function monthStart(Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::now($organization->timezoneOrDefault())->startOfMonth();
    }

    public function used(Organization $organization): int
    {
        return (int) SeoTask::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('created_at', '>=', $this->monthStart($organization)->utc())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total')->value('total');
    }

    public function remaining(Organization $organization): int
    {
        return max(0, $this->allowance($organization) - $this->used($organization));
    }

    public function canStart(Organization $organization): bool
    {
        return $this->remaining($organization) >= (int) config('seo.min_tokens_to_start', 5000);
    }
}
