<?php

namespace App\Console\Commands;

use App\Services\Social\House\HouseAutopilot;
use Illuminate\Console\Command;

/**
 * Prepares SureHelp's own scheduled social posts for the next 24 hours (D104).
 */
class RunHouseSocial extends Command
{
    protected $signature = 'social:house-schedule';

    protected $description = 'Prepare SureHelp\'s own scheduled social posts for the next 24 hours';

    public function handle(HouseAutopilot $autopilot): int
    {
        $result = $autopilot->run();
        $this->info("Prepared {$result['prepared']} post(s).");
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
