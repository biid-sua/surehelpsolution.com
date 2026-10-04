<?php

namespace App\Console\Commands;

use App\Support\Authorization\RoleCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Write the permission catalogue (config/authorization.php) to the database';

    public function handle(RoleCatalog $catalog): int
    {
        $catalog->sync();

        $this->info(sprintf(
            'Synced %d permissions and %d global roles.',
            count($catalog->permissions()),
            count(config('authorization.roles')),
        ));

        return self::SUCCESS;
    }
}
