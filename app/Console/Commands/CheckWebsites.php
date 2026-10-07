<?php

namespace App\Console\Commands;

use App\Jobs\CheckWebsite;
use App\Models\Website;
use Illuminate\Console\Command;

/**
 * Re-checks verified websites once a month (spec §41B).
 */
class CheckWebsites extends Command
{
    protected $signature = 'websites:check';

    protected $description = 'Queue the monthly health and SEO check for verified websites that are due';

    public function handle(): int
    {
        $count = 0;
        Website::withoutGlobalScopes()->whereNotNull('verified_at')
            ->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subDays(Website::RECHECK_DAYS)))
            ->orderBy('id')->each(function (Website $website) use (&$count) {
                CheckWebsite::dispatch($website->id);
                $count++;
            });

        $this->info("Queued {$count} website ".str('check')->plural($count).'.');

        return self::SUCCESS;
    }
}
