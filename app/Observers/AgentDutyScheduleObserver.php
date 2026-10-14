<?php

namespace App\Observers;

use App\Jobs\SyncAgentCalendar;
use App\Models\AgentCalendarConnection;
use App\Models\AgentDutySchedule;
use Illuminate\Support\Facades\Bus;

/**
 * When a shift is planned, changed, moved to another agent or removed, the affected agents' own
 * calendars are brought up to date (D54).
 */
class AgentDutyScheduleObserver
{
    public function saved(AgentDutySchedule $shift): void
    {
        $agents = array_filter([$shift->agent_id, $shift->wasChanged('agent_id') ? $shift->getOriginal('agent_id') : null]);
        $this->sync($agents);
    }

    public function deleted(AgentDutySchedule $shift): void
    {
        $this->sync([$shift->agent_id]);
    }

    /** @param array<int, mixed> $agentIds */
    private function sync(array $agentIds): void
    {
        AgentCalendarConnection::query()->whereIn('user_id', $agentIds)->where('status', AgentCalendarConnection::STATUS_ACTIVE)
            ->where('push_shifts', true)->pluck('id')
            // From a web page: right after the response (not through the job's unique lock, which would
            // drop a second change made within the minute). Elsewhere: the queue.
            ->each(fn (int $id) => app()->runningInConsole() ? SyncAgentCalendar::dispatch($id) : Bus::dispatchAfterResponse(new SyncAgentCalendar($id)));
    }
}
