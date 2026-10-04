<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingRun;
use Illuminate\Console\Command;

class RunBilling extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Renew subscriptions, issue invoices and send overdue reminders (safe to re-run)';

    public function handle(BillingRun $billing): int
    {
        $report = $billing->run();
        $this->table(['Step', 'Count'], collect($report)->map(fn ($v, $k) => [$k, $v])->values()->all());

        return self::SUCCESS;
    }
}
