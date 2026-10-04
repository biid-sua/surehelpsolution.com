<?php

namespace App\Console\Commands;

use App\Jobs\SyncCalendarConnection;
use App\Models\CalendarConnection;
use App\Services\Calendar\CalendarSync;
use Illuminate\Console\Command;

class SyncCalendars extends Command
{
    protected $signature = 'calendar:sync {--renew-push : Also renew provider push notifications}';

    protected $description = 'Refresh busy times from connected Google/Microsoft calendars (polling safety net)';

    public function handle(CalendarSync $sync): int
    {
        $count = 0;

        CalendarConnection::withoutGlobalScopes()->where('status', CalendarConnection::STATUS_ACTIVE)
            ->each(function (CalendarConnection $connection) use ($sync, &$count) {
                SyncCalendarConnection::dispatch($connection->id);
                if ($this->option('renew-push')) {
                    $sync->ensurePush($connection);
                }
                $count++;
            });

        $this->info("{$count} calendar connection(s) queued for sync.");

        return self::SUCCESS;
    }
}
