<?php

namespace App\Console\Commands;

use App\Services\Tasks\TaskBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillTasks extends Command
{
    protected $signature = 'tasks:backfill {--dry-run : Show what would change without saving anything}';

    protected $description = 'Create call-back tasks for calls still waiting for a call back (idempotent)';

    public function handle(TaskBackfill $backfill): int
    {
        if (! Schema::hasTable('tasks')) {
            $this->error('The tasks table does not exist yet. Run php artisan migrate (it runs this backfill).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = $backfill->run($dryRun);

        $this->info($dryRun ? 'Dry run: nothing was saved.' : 'Backfill complete.');
        $this->table(['Step', 'Count'], collect($report)->map(fn ($count, $step) => [$step, $count])->values()->all());

        return self::SUCCESS;
    }
}
