<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Services\Agencies\AgencyDesk;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Daily: last month's statement for each agency, made once it has ended (D118). */
class AgencyStatements extends Command
{
    protected $signature = 'agencies:statements';

    protected $description = 'Make last month\'s statement of each agency\'s share';

    public function handle(AgencyDesk $desk): int
    {
        // The dashboard counts months in Eastern time; a month has ended once it's over there.
        $lastMonth = CarbonImmutable::now('America/New_York')->startOfMonth()->subMonth();
        $made = 0;
        Agency::query()->each(function (Agency $agency) use ($desk, $lastMonth, &$made) {
            $made += $desk->statement($agency, $lastMonth)->wasRecentlyCreated ? 1 : 0;
        });
        $this->info("Made {$made} agency statement(s) for {$lastMonth->format('F Y')}.");

        return self::SUCCESS;
    }
}
