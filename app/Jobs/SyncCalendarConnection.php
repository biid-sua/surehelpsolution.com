<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\Calendar\CalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mirrors a connection's busy times. Unique per connection, so a burst of webhook calls runs one sync.
 */
class SyncCalendarConnection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(CalendarSync $sync): void
    {
        $connection = CalendarConnection::withoutGlobalScopes()->find($this->connectionId);

        if ($connection && $connection->isActive()) {
            $sync->pullBusy($connection);
        }
    }
}
