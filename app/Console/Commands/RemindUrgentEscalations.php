<?php

namespace App\Console\Commands;

use App\Services\Escalations\EscalationReminderSweep;
use Illuminate\Console\Command;

class RemindUrgentEscalations extends Command
{
    protected $signature = 'escalations:remind';

    protected $description = 'Re-send urgent escalations nobody has acknowledged yet (once each)';

    public function handle(EscalationReminderSweep $sweep): int
    {
        $this->info($sweep->run().' escalation reminder(s) sent.');

        return self::SUCCESS;
    }
}
