<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentDutySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The agent's own shifts for the next four weeks. Planned by admins under Duty schedule.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('My schedule')]
class Schedule extends Component
{
    use AgentWorkspaceOnly;

    public function render(): View
    {
        $now = CarbonImmutable::now();
        $shifts = AgentDutySchedule::active()->forAgent(auth()->id())
            ->where('end_datetime', '>', $now)->where('start_datetime', '<', $now->addWeeks(4))
            ->orderBy('start_datetime')->get();

        return view('livewire.agent.schedule', [
            'current' => $shifts->first(fn (AgentDutySchedule $s) => $s->shift_type !== 'off' && $s->start_datetime <= $now),
            'days' => $shifts->groupBy(fn (AgentDutySchedule $s) => $s->start_datetime->toDateString()),
            'hoursThisWeek' => round($shifts->where('shift_type', '!=', 'off')
                ->filter(fn (AgentDutySchedule $s) => $s->start_datetime < $now->endOfWeek())
                ->sum(fn (AgentDutySchedule $s) => $s->start_datetime->diffInMinutes($s->end_datetime)) / 60, 1),
            'timezone' => config('app.timezone'),
        ]);
    }
}
