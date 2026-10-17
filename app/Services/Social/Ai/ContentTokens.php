<?php

namespace App\Services\Social\Ai;

use App\Models\Organization;
use App\Models\SocialAiRun;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;

/**
 * The AI token allowance for social posts (D66): per calendar month in the business's timezone, from
 * the plan's `social_ai_tokens` limit or the default in config/social.php.
 */
class ContentTokens
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function allowance(Organization $organization): int
    {
        return $this->entitlements->limit($organization, 'social_ai_tokens') ?? (int) config('social.ai.monthly_tokens', 150000);
    }

    public function used(Organization $organization): int
    {
        return (int) SocialAiRun::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->where('created_at', '>=', CarbonImmutable::now($organization->timezoneOrDefault())->startOfMonth()->utc())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total')->value('total');
    }

    public function remaining(Organization $organization): int
    {
        return max(0, $this->allowance($organization) - $this->used($organization));
    }

    /** Share of the month's allowance used, 0–100. */
    public function percentUsed(Organization $organization): int
    {
        $allowance = $this->allowance($organization);

        return $allowance > 0 ? min(100, (int) round($this->used($organization) * 100 / $allowance)) : 100;
    }

    public function canStart(Organization $organization): bool
    {
        return $this->remaining($organization) >= (int) config('social.ai.min_tokens_to_start', 3000);
    }
}
