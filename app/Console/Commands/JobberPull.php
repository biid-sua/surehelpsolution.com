<?php

namespace App\Console\Commands;

use App\Jobs\PullJobber;
use App\Models\AppConnection;
use Illuminate\Console\Command;

/** Hourly: clients added or changed in each connected Jobber account come into SureHelp (D111). */
class JobberPull extends Command
{
    protected $signature = 'apps:jobber-pull';

    protected $description = 'Bring in clients added or changed in connected Jobber accounts';

    public function handle(): int
    {
        $ids = AppConnection::withoutGlobalScopes()->where('provider', AppConnection::JOBBER)->where('status', AppConnection::ACTIVE)->pluck('id');
        $ids->each(fn (int $id) => PullJobber::dispatch($id));
        $this->info('Queued '.$ids->count().' Jobber pull(s).');

        return self::SUCCESS;
    }
}
