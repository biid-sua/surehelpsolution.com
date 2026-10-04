<?php

namespace App\Console\Commands;

use App\Services\Tasks\OverdueTaskSweep;
use Illuminate\Console\Command;

class NotifyOverdueTasks extends Command
{
    protected $signature = 'tasks:notify-overdue';

    protected $description = 'Notify people once about tasks that have passed their due time';

    public function handle(OverdueTaskSweep $sweep): int
    {
        $this->info($sweep->run().' overdue task(s) notified.');

        return self::SUCCESS;
    }
}
