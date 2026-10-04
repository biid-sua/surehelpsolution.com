<?php

namespace App\Console\Commands;

use App\Services\Customers\CustomerBackfill;
use Illuminate\Console\Command;

class BackfillCustomers extends Command
{
    protected $signature = 'customers:backfill {--dry-run : Show what would change without saving anything}';

    protected $description = 'Create customers and timelines from existing calls (idempotent)';

    public function handle(CustomerBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $backfill->run($dryRun);

        $this->info($dryRun ? 'Dry run: nothing was saved.' : 'Backfill complete.');
        $this->table(['Step', 'Count'], collect($report)->map(fn ($count, $step) => [$step, $count])->values()->all());

        return self::SUCCESS;
    }
}
