<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\AgentDutySchedule;
use App\Models\ShiftRequest;
use App\Models\User;
use App\Services\Scheduling\ShiftRequests;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The agent's own shifts for the next four weeks, planned by admins under Duty schedule.
 * Agents ask here to hand a shift to a colleague or for time off (AGT-11).
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('My schedule')]
class Schedule extends Component
{
    use AgentWorkspaceOnly;

    public bool $asking = false;

    /** @var array{type: string, shift_id: string, swap_with_id: string, leave_from: string, leave_until: string, reason: string} */
    public array $request = ['type' => 'swap', 'shift_id' => '', 'swap_with_id' => '', 'leave_from' => '', 'leave_until' => '', 'reason' => ''];

    public function ask(string $type = 'swap', ?int $shiftId = null): void
    {
        $this->resetValidation();
        $this->reset('request');
        $this->request['type'] = $type === 'leave' ? 'leave' : 'swap';
        $this->request['shift_id'] = $shiftId ? (string) $shiftId : '';
        $this->asking = true;
    }

    public function cancelAsking(): void
    {
        $this->asking = false;
        $this->resetValidation();
    }

    public function submit(ShiftRequests $requests): void
    {
        $agent = $this->agent();
        $this->validate([
            'request.type' => ['required', 'in:swap,leave'],
            'request.reason' => ['nullable', 'string', 'max:1000'],
        ] + ($this->request['type'] === 'swap'
            ? ['request.shift_id' => ['required', 'integer'], 'request.swap_with_id' => ['nullable', 'integer']]
            : ['request.leave_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'request.leave_until' => ['required', 'date_format:Y-m-d']]),
            attributes: ['request.shift_id' => 'shift', 'request.leave_from' => 'first day', 'request.leave_until' => 'last day']);

        if ($this->request['type'] === 'swap') {
            $requests->requestSwap($agent, (int) $this->request['shift_id'], $this->request['swap_with_id'] !== '' ? (int) $this->request['swap_with_id'] : null, $this->request['reason']);
        } else {
            $requests->requestLeave($agent, $this->request['leave_from'], $this->request['leave_until'], $this->request['reason']);
        }

        $this->asking = false;
        $this->dispatch('toast', type: 'success', message: 'Request sent. You\'ll hear back here and by email.');
    }

    public function withdraw(string $ulid, ShiftRequests $requests): void
    {
        $requests->cancel(ShiftRequest::where('ulid', $ulid)->firstOrFail(), $this->agent());
        $this->dispatch('toast', type: 'success', message: 'Request withdrawn.');
    }

    private function agent(): User
    {
        /** @var User */
        return auth()->user();
    }

    public function render(): View
    {
        $now = CarbonImmutable::now();
        $agent = $this->agent();
        $shifts = AgentDutySchedule::active()->forAgent($agent->id)
            ->where('end_datetime', '>', $now)->where('start_datetime', '<', $now->addWeeks(4))
            ->orderBy('start_datetime')->get();
        $requests = ShiftRequest::query()->where('agent_id', $agent->id)
            ->where(fn ($q) => $q->where('status', ShiftRequest::PENDING)->orWhere('updated_at', '>=', $now->subDays(30)))
            ->with(['shift', 'swapWith:id,name'])->latest()->limit(20)->get();

        return view('livewire.agent.schedule', [
            'current' => $shifts->first(fn (AgentDutySchedule $s) => $s->shift_type !== 'off' && $s->start_datetime <= $now),
            'days' => $shifts->groupBy(fn (AgentDutySchedule $s) => $s->start_datetime->toDateString()),
            'hoursThisWeek' => round($shifts->where('shift_type', '!=', 'off')
                ->filter(fn (AgentDutySchedule $s) => $s->start_datetime < $now->endOfWeek())
                ->sum(fn (AgentDutySchedule $s) => $s->start_datetime->diffInMinutes($s->end_datetime)) / 60, 1),
            'timezone' => config('app.timezone'),
            'swappable' => $shifts->filter(fn (AgentDutySchedule $s) => $s->shift_type !== 'off' && $s->start_datetime > $now),
            'pendingShiftIds' => $requests->where('status', ShiftRequest::PENDING)->pluck('shift_id')->filter()->all(),
            'colleagues' => User::query()->where('role', 'agent')->where('is_active', true)->whereKeyNot($agent->id)->orderBy('name')->get(['id', 'name']),
            'requests' => $requests,
            'canAsk' => $agent->isAgent(),
        ]);
    }
}
