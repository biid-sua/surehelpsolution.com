<?php

namespace App\Console\Commands;

use App\Jobs\PullQuickBooks;
use App\Models\AppConnection;
use Illuminate\Console\Command;

/** Hourly: customers added or changed in each connected QuickBooks come into SureHelp (D110). */
class QuickBooksPull extends Command
{
    protected $signature = 'apps:quickbooks-pull';

    protected $description = 'Bring in customers added or changed in connected QuickBooks companies';

    public function handle(): int
    {
        $ids = AppConnection::withoutGlobalScopes()->where('provider', AppConnection::QUICKBOOKS)->where('status', AppConnection::ACTIVE)->pluck('id');
        $ids->each(fn (int $id) => PullQuickBooks::dispatch($id));
        $this->info('Queued '.$ids->count().' QuickBooks pull(s).');

        return self::SUCCESS;
    }
}
