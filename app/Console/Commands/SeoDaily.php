<?php

namespace App\Console\Commands;

use App\Jobs\PullSearchConsole;
use App\Models\Organization;
use App\Models\SearchConsoleConnection;
use App\Models\SeoTask;
use App\Services\Billing\FeatureAccess;
use App\Services\Seo\SeoDesk;
use App\Services\Seo\SeoTokens;
use Illuminate\Console\Command;

/**
 * Daily SEO upkeep (D57): fetch Search Console numbers, and on the 1st run each business's monthly
 * autopilot (unless switched off): a keyword plan every three months, new titles and descriptions
 * when the website check finds problems with them, and the month's Google Business Profile posts.
 */
class SeoDaily extends Command
{
    protected $signature = 'seo:daily {--autopilot : Run the monthly autopilot now, whatever the date}';

    protected $description = 'Fetch Search Console statistics and run the monthly SEO autopilot';

    public function handle(FeatureAccess $features, SeoDesk $desk, SeoTokens $tokens): int
    {
        SearchConsoleConnection::withoutGlobalScopes()->where('status', SearchConsoleConnection::STATUS_ACTIVE)->whereNotNull('site_url')
            ->pluck('id')->each(fn (int $id) => PullSearchConsole::dispatch($id));

        if (! $this->option('autopilot') && now()->day !== 1) {
            return self::SUCCESS;
        }

        $started = 0;
        Organization::query()->whereIn('status', ['active', 'onboarding'])->each(function (Organization $org) use ($features, $desk, $tokens, &$started) {
            if (! $features->allows($org, 'seo_service') || (($org->seo_settings ?? [])['autopilot'] ?? true) === false) {
                return;
            }
            $done = fn (string $type, int $days) => SeoTask::withoutGlobalScopes()->where('organization_id', $org->id)->where('type', $type)
                ->whereIn('status', ['queued', 'running', 'done'])->where('created_at', '>=', now()->subDays($days))->exists();

            $plan = array_filter([
                'keyword_plan' => ! $done('keyword_plan', 85),
                'page_meta' => ! $done('page_meta', 25) && $this->metaNeedsWork($desk, $org),
                'gbp_posts' => ! $done('gbp_posts', 25),
            ]);
            foreach (array_keys($plan) as $type) {
                if ($desk->blocker($org, $type) === null && $tokens->canStart($org)) {
                    $desk->request($org, $type, [], null, 'autopilot');
                    $started++;
                }
            }
        });

        $this->info("Autopilot started {$started} SEO task(s).");

        return self::SUCCESS;
    }

    private function metaNeedsWork(SeoDesk $desk, Organization $org): bool
    {
        $findings = collect($desk->website($org)?->health['findings'] ?? []);

        return $findings->contains(fn (array $f) => in_array($f['key'], ['titles', 'title_length', 'descriptions', 'description_length', 'h1_missing', 'h1_many'], true)
            && in_array($f['status'], ['problem', 'warning'], true));
    }
}
