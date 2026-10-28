<?php

namespace App\Console\Commands;

use App\Services\Sales\DemoFollowUp;
use Illuminate\Console\Command;

/** Demo call reminders, sign-up spotting and nudges (D96); runs every five minutes. */
class DemoFollowUps extends Command
{
    protected $signature = 'sales:demo-follow-ups';

    protected $description = 'Send demo call reminders and follow-ups, and spot demos that became sign-ups';

    public function handle(DemoFollowUp $demo): int
    {
        $done = $demo->run();
        $this->info("{$done['reminders']} reminders ({$done['texts']} by text), {$done['signed_up']} sign-ups spotted, {$done['nudges']} nudges.");

        return self::SUCCESS;
    }
}
