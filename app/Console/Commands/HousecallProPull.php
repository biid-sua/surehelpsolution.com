<?php

namespace App\Console\Commands;

use App\Jobs\PullHousecallPro;
use App\Models\AppConnection;
use Illuminate\Console\Command;

/** Hourly: customers from each connected Housecall Pro company come into SureHelp (D112). */
class HousecallProPull extends Command
{
    protected $signature = 'apps:housecall-pro-pull';

    protected $description = 'Bring in customers from connected Housecall Pro companies';

    public function handle(): int
    {
        $ids = AppConnection::withoutGlobalScopes()->where('provider', AppConnection::HOUSECALL_PRO)->where('status', AppConnection::ACTIVE)->pluck('id');
        $ids->each(fn (int $id) => PullHousecallPro::dispatch($id));
        $this->info('Queued '.$ids->count().' Housecall Pro pull(s).');

        return self::SUCCESS;
    }
}
