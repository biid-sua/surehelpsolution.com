<?php

namespace App\Console\Commands;

use App\Services\Tenancy\TenancyBackfill;
use Illuminate\Console\Command;

class BackfillTenancy extends Command
{
    protected $signature = 'tenancy:backfill {--dry-run : Show what would change without saving anything}';

    protected $description = 'Create organizations for clients, assign agents, and attribute calls (idempotent)';

    public function handle(TenancyBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $backfill->run($dryRun);

        $this->info($dryRun ? 'Dry run: nothing was saved.' : 'Backfill complete.');
        $this->table(['Step', 'Count'], collect($report)->map(fn ($count, $step) => [$step, $count])->values()->all());

        if ($report['calls_by_email_match'] + $report['calls_unassigned'] > 0) {
            $this->warn('Some calls need admin review (email matches or unassigned). See docs/decisions.md D2.');
        }

        return self::SUCCESS;
    }
}
