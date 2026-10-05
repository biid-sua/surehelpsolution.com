<?php

namespace App\Services\Scheduling;

use App\Models\AgentDutySchedule;
use App\Models\ShiftRequest;
use App\Models\User;
use App\Notifications\ShiftRequestActivity;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shift swap and leave requests (task.md AGT-11) and the coverage view (SUP-03).
 *
 * Whoever may edit the duty schedule (`users.update`) decides. Approving a swap moves the shift
 * to the colleague; approving leave takes the agent off their shifts in those days and marks the
 * days as leave, so the coverage view shows the gaps left to fill.
 */
class ShiftRequests
{
    public function __construct(private readonly Audit $audit) {}

    public function requestSwap(User $agent, int $shiftId, ?int $colleagueId, ?string $reason): ShiftRequest
    {
        $shift = AgentDutySchedule::active()->forAgent($agent->id)->where('shift_type', '!=', 'off')
            ->where('start_datetime', '>', now())->find($shiftId);
        if (! $shift) {
            throw ValidationException::withMessages(['request.shift_id' => 'Choose one of your upcoming shifts.']);
        }
        if (ShiftRequest::pending()->where('shift_id', $shift->id)->exists()) {
            throw ValidationException::withMessages(['request.shift_id' => 'You already asked about this shift.']);
        }
        if ($colleagueId !== null) {
            $this->colleague($colleagueId, $agent, 'request.swap_with_id');
        }

        return $this->submit($agent, [
            'type' => ShiftRequest::SWAP,
            'shift_id' => $shift->id,
            'swap_with_id' => $colleagueId,
            'reason' => $reason,
        ]);
    }

    public function requestLeave(User $agent, string $from, string $until, ?string $reason): ShiftRequest
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($until);
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['request.leave_until' => 'The last day can\'t be before the first.']);
        }
        if ($start->diffInDays($end) > 30) {
            throw ValidationException::withMessages(['request.leave_until' => 'Ask for at most 31 days at a time.']);
        }

        return $this->submit($agent, [
            'type' => ShiftRequest::LEAVE,
            'leave_from' => $start->toDateString(),
            'leave_until' => $end->toDateString(),
            'reason' => $reason,
        ]);
    }

    public function cancel(ShiftRequest $request, User $agent): void
    {
        abort_unless($request->agent_id === $agent->id && $request->isPending(), 403);
        $request->update(['status' => ShiftRequest::CANCELLED]);
        $this->audit->record('shift_request.cancelled', $request, new: ['type' => $request->type]);
    }

    public function approve(ShiftRequest $request, User $decider, ?int $colleagueId = null, ?string $note = null): void
    {
        $this->assertDecidable($request, $decider);

        DB::transaction(function () use ($request, $decider, $colleagueId, $note) {
            if ($request->type === ShiftRequest::SWAP) {
                $this->handOver($request, $colleagueId ?? $request->swap_with_id);
            } else {
                $this->takeLeave($request);
            }

            $this->decide($request, $decider, ShiftRequest::APPROVED, $note);
        });
    }

    public function decline(ShiftRequest $request, User $decider, ?string $note): void
    {
        $this->assertDecidable($request, $decider);
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Tell the agent why.']);
        }

        $this->decide($request, $decider, ShiftRequest::DECLINED, $note);
    }

    /**
     * Agents on shift for each hour of the week: [Y-m-d => [0..23 => count]], in the operations timezone.
     *
     * @return array<string, array<int, int>>
     */
    public function coverage(CarbonImmutable $weekStart): array
    {
        $weekEnd = $weekStart->addWeek();
        $shifts = AgentDutySchedule::active()->where('shift_type', '!=', 'off')
            ->where('start_datetime', '<', $weekEnd)->where('end_datetime', '>', $weekStart)
            ->get(['agent_id', 'start_datetime', 'end_datetime']);

        $grid = [];
        for ($day = $weekStart; $day->lt($weekEnd); $day = $day->addDay()) {
            foreach (range(0, 23) as $hour) {
                $from = $day->setTime($hour, 0);
                $to = $from->addHour();
                $grid[$day->toDateString()][$hour] = $shifts
                    ->filter(fn (AgentDutySchedule $s) => $s->start_datetime < $to && $s->end_datetime > $from)
                    ->unique('agent_id')->count();
            }
        }

        return $grid;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function submit(User $agent, array $attributes): ShiftRequest
    {
        $attributes['reason'] = filled($attributes['reason'] ?? null) ? Str::limit(trim((string) $attributes['reason']), 1000, '') : null;
        $request = ShiftRequest::create(['agent_id' => $agent->id] + $attributes);
        $this->audit->record('shift_request.created', $request, new: ['type' => $request->type]);

        Notification::send($this->deciders()->reject(fn (User $u) => $u->id === $agent->id), new ShiftRequestActivity($request, 'submitted'));

        return $request;
    }

    private function handOver(ShiftRequest $request, ?int $colleagueId): void
    {
        $shift = $request->shift;
        if (! $shift || ! $shift->is_active || $shift->agent_id !== $request->agent_id) {
            throw ValidationException::withMessages(['colleague' => 'That shift has changed since the request. Decline it and plan the change by hand.']);
        }
        if ($colleagueId === null) {
            throw ValidationException::withMessages(['colleague' => 'Choose who takes the shift.']);
        }

        $colleague = $this->colleague($colleagueId, $request->agent, 'colleague');
        $clash = AgentDutySchedule::getConflictingSchedules($colleague->id, $shift->start_datetime, $shift->end_datetime)->first();
        if ($clash) {
            throw ValidationException::withMessages(['colleague' => "{$colleague->name} already has \"{$clash->title}\" then."]);
        }

        $shift->update(['agent_id' => $colleague->id]);
        $request->swap_with_id = $colleague->id;
        $this->audit->record('duty_schedule.handed_over', $shift, ['agent_id' => $request->agent_id], ['agent_id' => $colleague->id]);
    }

    private function takeLeave(ShiftRequest $request): void
    {
        $from = CarbonImmutable::parse($request->leave_from)->startOfDay();
        $until = CarbonImmutable::parse($request->leave_until)->addDay()->startOfDay();

        $removed = AgentDutySchedule::active()->forAgent($request->agent_id)
            ->where('start_datetime', '<', $until)->where('end_datetime', '>', $from)
            ->update(['is_active' => false]);

        for ($day = $from; $day->lt($until); $day = $day->addDay()) {
            AgentDutySchedule::create([
                'agent_id' => $request->agent_id, 'title' => 'Leave', 'shift_type' => 'off', 'is_active' => true,
                'start_datetime' => $day, 'end_datetime' => $day->addDay(),
                'description' => $request->reason ? Str::limit($request->reason, 200) : null,
            ]);
        }

        $this->audit->record('duty_schedule.leave_applied', $request, new: ['shifts_removed' => $removed, 'from' => $from->toDateString(), 'until' => $request->leave_until?->toDateString()]);
    }

    private function decide(ShiftRequest $request, User $decider, string $status, ?string $note): void
    {
        $request->fill([
            'status' => $status,
            'decided_by' => $decider->id,
            'decided_at' => now(),
            'decision_note' => filled($note) ? Str::limit(trim((string) $note), 1000, '') : null,
        ])->save();
        $this->audit->record("shift_request.{$status}", $request, new: ['type' => $request->type]);

        if ($request->agent?->is_active) {
            $request->agent->notify(new ShiftRequestActivity($request, $status));
        }
    }

    private function assertDecidable(ShiftRequest $request, User $decider): void
    {
        abort_unless($decider->isAdmin() && $decider->hasPermissionIn('users.update'), 403);
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['note' => 'This request was already '.strtolower(ShiftRequest::STATUS_LABELS[$request->status] ?? $request->status).'.']);
        }
    }

    private function colleague(int $id, User $agent, string $field): User
    {
        $colleague = User::query()->where('role', 'agent')->where('is_active', true)->whereKeyNot($agent->id)->find($id);
        if (! $colleague) {
            throw ValidationException::withMessages([$field => 'Choose an active colleague.']);
        }

        return $colleague;
    }

    /**
     * People who plan the duty schedule.
     *
     * @return Collection<int, User>
     */
    private function deciders(): Collection
    {
        return User::query()->where('role', 'admin')->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermissionIn('users.update'))->values();
    }
}
