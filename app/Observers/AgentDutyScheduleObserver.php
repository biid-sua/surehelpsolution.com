<?php

namespace App\Observers;

use App\Jobs\SyncAgentCalendar;
use App\Models\AgentCalendarConnection;
use App\Models\AgentDutySchedule;

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
            ->each(fn (int $id) => SyncAgentCalendar::dispatch($id));
    }
}
