<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationEngine;
use Illuminate\Console\Command;

/**
 * Runs automation steps that are due (D50). Scheduled every minute.
 */
class RunAutomations extends Command
{
    protected $signature = 'automations:run {--limit=200}';

    protected $description = 'Run businesses\' automation steps that are due';

    public function handle(AutomationEngine $engine): int
    {
        $counts = $engine->runDue((int) $this->option('limit'));
        $this->info("Done {$counts['done']}, skipped {$counts['skipped']}, failed {$counts['failed']}.");

        return self::SUCCESS;
    }
}
