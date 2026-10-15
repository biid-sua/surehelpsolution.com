<?php

namespace App\Services\Seo;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\SeoDailyMetric;
use App\Models\SeoTask;
use App\Models\WebsiteCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Before-and-after numbers for the SEO page (D57). Every figure comes from a real record: Google
 * Search Console (clicks, impressions, average position), our own website checks (health score)
 * and the business's leads from its website. "Before" is the 28 days before the first SEO task;
 * "Now" is the latest 28 days. Weekly series cover the last 26 weeks, with the weeks in which SEO
 * changes went live marked.
 */
class SeoStats
{
    public const WINDOW = 28;

    public const WEEKS = 26;

    /** @return array<string, mixed> */
    public function for(Organization $organization): array
    {
        $timezone = $organization->timezoneOrDefault();
        $started = SeoTask::withoutGlobalScopes()->where('organization_id', $organization->id)->min('created_at');
        $start = $started ? CarbonImmutable::parse($started)->setTimezone($timezone)->startOfDay() : null;
        $lastData = SeoDailyMetric::withoutGlobalScopes()->where('organization_id', $organization->id)->max('date');
        $nowEnd = $lastData ? CarbonImmutable::parse($lastData, $timezone) : CarbonImmutable::now($timezone)->subDays(2)->startOfDay();

        $search = fn (CarbonImmutable $from, CarbonImmutable $to) => $this->search($organization, $from, $to);
        $now = $search($nowEnd->subDays(self::WINDOW - 1), $nowEnd);
        $before = $start ? $search($start->subDays(self::WINDOW), $start->subDay()) : null;

        $health = WebsiteCheck::withoutGlobalScopes()->where('organization_id', $organization->id)->whereNotNull('health_score');
        $healthNow = (clone $health)->latest('checked_at')->value('health_score');
        $healthBefore = $start ? ((clone $health)->where('checked_at', '<', $start->utc())->latest('checked_at')->value('health_score')
            ?? (clone $health)->oldest('checked_at')->value('health_score')) : null;

        $today = CarbonImmutable::now($timezone)->startOfDay();
        $leadsNow = $this->leads($organization, $today->subDays(self::WINDOW - 1), $today->endOfDay());
        $leadsBefore = $start ? $this->leads($organization, $start->subDays(self::WINDOW), $start->subSecond()) : null;

        return [
            'started' => $start,
            'has_search_data' => $lastData !== null,
            'tiles' => [
                'clicks' => ['label' => 'Clicks from Google', 'now' => $now['clicks'], 'before' => $before['clicks'] ?? null, 'better' => 'up'],
                'impressions' => ['label' => 'Times shown in Google', 'now' => $now['impressions'], 'before' => $before['impressions'] ?? null, 'better' => 'up'],
                'position' => ['label' => 'Average position', 'now' => $now['position'], 'before' => $before['position'] ?? null, 'better' => 'down'],
                'health' => ['label' => 'Website health', 'now' => $healthNow, 'before' => $healthBefore, 'better' => 'up'],
                'leads' => ['label' => 'Leads from your website', 'now' => $leadsNow, 'before' => $leadsBefore, 'better' => 'up'],
            ],
            'weekly' => $this->weekly($organization, $nowEnd),
            'health_series' => (clone $health)->where('checked_at', '>=', now()->subMonths(12))->get(['checked_at', 'health_score'])
                ->map(fn (WebsiteCheck $c) => ['label' => $c->checked_at->setTimezone($timezone)->format('M j'), 'value' => $c->health_score])->values()->all(),
        ];
    }

    /** @return array{clicks: int, impressions: int, position: ?float} */
    private function search(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('seo_daily_metrics')->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) as weighted')->first();
        $impressions = (int) ($row->impressions ?? 0);

        return [
            'clicks' => (int) ($row->clicks ?? 0),
            'impressions' => $impressions,
            // Google's own average position is weighted by impressions.
            'position' => $impressions > 0 ? round((float) ($row->weighted ?? 0) / $impressions, 1) : null,
        ];
    }

    private function leads(Organization $organization, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $window = [$from->utc(), $to->utc()];

        return Customer::withoutGlobalScopes()->where('organization_id', $organization->id)->where('source', 'website')->whereBetween('created_at', $window)->count()
            + Appointment::withoutGlobalScopes()->where('organization_id', $organization->id)->where('source', 'website')->whereBetween('created_at', $window)->count();
    }

    /**
     * @return array{labels: list<string>, clicks: list<int>, impressions: list<int>, position: list<?float>, markers: list<array{index: int, label: string}>}
     */
    /**
     * Seven-day blocks ending on the latest day with data, so every point is a full week (a calendar
     * week still in progress would always look like a drop).
     */
    private function weekly(Organization $organization, CarbonImmutable $end): array
    {
        $first = $end->subDays(7 * self::WEEKS - 1)->startOfDay();
        $rows = SeoDailyMetric::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->whereBetween('date', [$first->toDateString(), $end->toDateString()])->get();
        $block = fn (CarbonImmutable $day) => intdiv((int) $first->diffInDays($day->setTimezone($first->getTimezone())->startOfDay()), 7);

        $weeks = array_fill(0, self::WEEKS, ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0]);
        foreach ($rows as $r) {
            $index = $block(CarbonImmutable::parse($r->date, $end->getTimezone()));
            if (isset($weeks[$index])) {
                $weeks[$index]['clicks'] += $r->clicks;
                $weeks[$index]['impressions'] += $r->impressions;
                $weeks[$index]['weighted'] += (float) $r->position * $r->impressions;
            }
        }

        $markers = SeoTask::withoutGlobalScopes()->where('organization_id', $organization->id)->whereNotNull('applied_at')
            ->where('applied_at', '>=', $first->utc())->orderBy('applied_at')->get(['type', 'applied_at'])
            ->map(function (SeoTask $t) use ($block) {
                $index = $block(CarbonImmutable::instance($t->applied_at));

                return $index < self::WEEKS ? ['index' => $index, 'label' => SeoTaskCatalog::TYPES[$t->type]['label'] ?? 'SEO change'] : null;
            })->filter()->unique('index')->values()->all();

        return [
            'labels' => array_map(fn (int $i) => $first->addDays(7 * $i)->format('M j'), array_keys($weeks)),
            'clicks' => array_column($weeks, 'clicks'),
            'impressions' => array_column($weeks, 'impressions'),
            'position' => array_map(fn (array $w) => $w['impressions'] > 0 ? round($w['weighted'] / $w['impressions'], 1) : null, $weeks),
            'markers' => $markers,
        ];
    }
}
