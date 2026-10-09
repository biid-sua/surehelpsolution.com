<?php

namespace App\Jobs;

use App\Models\AgentCalendarConnection;
use App\Services\Calendar\AgentCalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Copies an agent's shifts into their own calendar (D54). One at a time per calendar. */
class SyncAgentCalendar implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $connectionId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(AgentCalendarSync $sync): void
    {
        $connection = AgentCalendarConnection::query()->with('user')->find($this->connectionId);
        if ($connection && $connection->user?->is_active) {
            $sync->pushShifts($connection);
        }
    }
}
