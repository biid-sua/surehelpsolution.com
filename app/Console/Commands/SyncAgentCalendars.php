<?php

namespace App\Console\Commands;

use App\Jobs\SyncAgentCalendar;
use App\Models\AgentCalendarConnection;
use Illuminate\Console\Command;

/**
 * Nightly catch-up for agents' own calendars (D54): shifts entering the copy window and anything a
 * missed update left behind. Day-to-day changes are copied as they happen.
 */
class SyncAgentCalendars extends Command
{
    protected $signature = 'agent-calendars:sync';

    protected $description = 'Copy agents\' upcoming shifts into their connected calendars';

    public function handle(): int
    {
        $count = 0;
        AgentCalendarConnection::query()->where('status', AgentCalendarConnection::STATUS_ACTIVE)->where('push_shifts', true)
            ->whereNotNull('write_calendar_id')->pluck('id')
            ->each(function (int $id) use (&$count) {
                SyncAgentCalendar::dispatch($id);
                $count++;
            });
        $this->info("Queued {$count} calendar(s).");

        return self::SUCCESS;
    }
}
