<?php

namespace App\Services\Metrics;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Counts pages viewed with the SureHelp snippet (D68): the snippet asks for its settings once per page,
 * so that request is the count. A number per business per day, in the business's timezone; nothing
 * about the visitor is kept.
 */
class WebsiteViews
{
    public function record(Organization $organization): void
    {
        $day = CarbonImmutable::now($organization->timezoneOrDefault())->toDateString();

        DB::table('website_daily_views')->insertOrIgnore(['organization_id' => $organization->id, 'day' => $day, 'views' => 0]);
        DB::table('website_daily_views')->where('organization_id', $organization->id)->where('day', $day)->increment('views');
    }

    /** Pages viewed between two local dates (inclusive), or null when the snippet never reported a view. */
    public function between(Organization $organization, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $query = DB::table('website_daily_views')->where('organization_id', $organization->id);
        if (! $query->clone()->exists()) {
            return null;
        }

        return (int) $query->whereBetween('day', [$start->toDateString(), $end->toDateString()])->sum('views');
    }
}
