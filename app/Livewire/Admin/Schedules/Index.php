<?php

namespace App\Livewire\Admin\Schedules;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\AgentDutySchedule;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Agent duty schedule as a week grid: who answers when. Shifts can run past midnight and never overlap
 * for the same agent. Times are in the operations timezone (config app.timezone).
 */
#[Layout('layouts.portal', ['portal' => 'admin'])]
#[Title('Duty schedule')]
class Index extends Component
{
    use PlatformAdminOnly;

    /** Monday of the week shown, Y-m-d. */
    #[Url(as: 'week', except: '')]
    public string $week = '';

    public ?int $editing = null;

    public bool $open = false;

    /** @var array{agent_id: string, date: string, start: string, end: string, shift_type: string, title: string, description: string} */
    public array $shift = ['agent_id' => '', 'date' => '', 'start' => '09:00', 'end' => '17:00', 'shift_type' => 'morning', 'title' => '', 'description' => ''];

    public function mount(): void
    {
        $this->authorize('users.view');
        $this->week = $this->weekStart()->toDateString();
        $this->blank();
    }

    public function previousWeek(): void
    {
        $this->week = $this->weekStart()->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->week = $this->weekStart()->addWeek()->toDateString();
    }

    public function thisWeek(): void
    {
        $this->week = CarbonImmutable::now()->startOfWeek()->toDateString();
    }

    public function add(?int $agentId = null, ?string $date = null): void
    {
        $this->authorize('users.update');
        $this->blank();
        $this->shift['agent_id'] = $agentId ? (string) $agentId : '';
        $this->shift['date'] = $date ?? CarbonImmutable::today()->max($this->weekStart())->toDateString();
        $this->editing = null;
        $this->open = true;
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $this->authorize('users.update');
        $schedule = AgentDutySchedule::findOrFail($id);
        $this->editing = $schedule->id;
        $this->shift = [
            'agent_id' => (string) $schedule->agent_id,
            'date' => $schedule->start_datetime->toDateString(),
            'start' => $schedule->start_datetime->format('H:i'),
            'end' => $schedule->end_datetime->format('H:i'),
            'shift_type' => $schedule->shift_type,
            'title' => $schedule->title,
            'description' => (string) $schedule->description,
        ];
        $this->open = true;
        $this->resetValidation();
    }

    public function close(): void
    {
        $this->open = false;
        $this->editing = null;
    }

    public function save(Audit $audit): void
    {
        $this->authorize('users.update');
        $this->validate([
            'shift.agent_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'agent')],
            'shift.date' => ['required', 'date_format:Y-m-d'],
            'shift.start' => ['required', 'date_format:H:i'],
            'shift.end' => ['required', 'date_format:H:i', 'different:shift.start'],
            'shift.shift_type' => ['required', Rule::in(array_keys(AgentDutySchedule::SHIFT_TYPES))],
            'shift.title' => ['nullable', 'string', 'max:255'],
            'shift.description' => ['nullable', 'string', 'max:2000'],
        ], ['shift.end.different' => 'The shift must end at a different time than it starts.'], [
            'shift.agent_id' => 'agent', 'shift.date' => 'date', 'shift.start' => 'start time', 'shift.end' => 'end time', 'shift.shift_type' => 'shift',
        ]);

        [$start, $end] = $this->range($this->shift['date'], $this->shift['start'], $this->shift['end']);
        $conflict = AgentDutySchedule::getConflictingSchedules((int) $this->shift['agent_id'], $start, $end, $this->editing)->first();
        if ($conflict) {
            throw ValidationException::withMessages(['shift.start' => 'This agent already works '.$conflict->start_datetime->format('D g:i A').' – '.$conflict->end_datetime->format('g:i A').' ('.$conflict->title.').']);
        }

        $schedule = $this->editing ? AgentDutySchedule::findOrFail($this->editing) : new AgentDutySchedule(['is_active' => true]);
        $schedule->fill([
            'agent_id' => (int) $this->shift['agent_id'],
            'title' => trim($this->shift['title']) ?: AgentDutySchedule::SHIFT_TYPES[$this->shift['shift_type']],
            'start_datetime' => $start,
            'end_datetime' => $end,
            'shift_type' => $this->shift['shift_type'],
            'description' => trim($this->shift['description']) ?: null,
        ])->save();
        $audit->changes($this->editing ? 'duty_schedule.updated' : 'duty_schedule.created', $schedule, ['agent_id', 'start_datetime', 'end_datetime', 'shift_type']);

        $this->close();
        $this->dispatch('toast', type: 'success', message: 'Shift saved.');
    }

    public function delete(Audit $audit): void
    {
        $this->authorize('users.update');
        $schedule = AgentDutySchedule::findOrFail($this->editing);
        $audit->record('duty_schedule.deleted', $schedule, ['agent_id' => $schedule->agent_id, 'start' => $schedule->start_datetime->toDateTimeString()], label: $schedule->title);
        $schedule->delete();
        $this->close();
        $this->dispatch('toast', type: 'success', message: 'Shift removed.');
    }

    /** Repeat last week's shifts in this week, skipping any that would overlap. */
    public function copyPreviousWeek(): void
    {
        $this->authorize('users.update');
        $from = $this->weekStart()->subWeek();
        $copied = 0;
        $skipped = 0;

        AgentDutySchedule::active()->where('start_datetime', '>=', $from)->where('start_datetime', '<', $from->addWeek())
            ->orderBy('start_datetime')->get()
            ->each(function (AgentDutySchedule $previous) use (&$copied, &$skipped) {
                $start = CarbonImmutable::parse($previous->start_datetime)->addWeek();
                $end = CarbonImmutable::parse($previous->end_datetime)->addWeek();
                if (AgentDutySchedule::hasConflictingSchedules($previous->agent_id, $start, $end)) {
                    $skipped++;

                    return;
                }
                $previous->replicate()->fill(['start_datetime' => $start, 'end_datetime' => $end])->save();
                $copied++;
            });

        app(Audit::class)->record('duty_schedule.week_copied', null, [], ['week' => $this->week, 'copied' => $copied, 'skipped' => $skipped]);
        $this->dispatch('toast', type: $copied ? 'success' : 'info', message: $copied
            ? "Copied {$copied} ".str('shift')->plural($copied).($skipped ? ", skipped {$skipped} that would overlap." : '.')
            : 'Nothing to copy from last week.');
    }

    public function render(): View
    {
        $start = $this->weekStart();
        $days = collect(range(0, 6))->map(fn (int $i) => $start->addDays($i));
        $agents = User::query()->where('role', 'agent')->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $shifts = AgentDutySchedule::active()->with('agent:id,name')
            ->where('start_datetime', '<', $start->addWeek())->where('end_datetime', '>', $start)
            ->orderBy('start_datetime')->get();

        // Agents switched off since keep their shifts visible until removed.
        $agents = $agents->concat($shifts->pluck('agent')->filter()->reject(fn (User $a) => $agents->contains('id', $a->id))->unique('id'));
        $now = CarbonImmutable::now();

        return view('livewire.admin.schedules.index', [
            'days' => $days,
            'agents' => $agents,
            'grid' => $shifts->groupBy(fn (AgentDutySchedule $s) => $s->agent_id.'|'.$s->start_datetime->toDateString()),
            'onShift' => $shifts->filter(fn (AgentDutySchedule $s) => $s->shift_type !== 'off' && $s->start_datetime <= $now && $s->end_datetime > $now)->pluck('agent')->filter()->unique('id'),
            'hours' => $shifts->where('shift_type', '!=', 'off')->groupBy('agent_id')
                ->map(fn ($items) => round($items->sum(fn (AgentDutySchedule $s) => $s->start_datetime->diffInMinutes($s->end_datetime)) / 60, 1)),
            'types' => AgentDutySchedule::SHIFT_TYPES,
            'timezone' => config('app.timezone'),
            'canEdit' => auth()->user()->hasPermissionIn('users.update'),
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} an end at or before the start means the shift ends the next day */
    private function range(string $date, string $from, string $to): array
    {
        $start = CarbonImmutable::parse("{$date} {$from}");
        $end = CarbonImmutable::parse("{$date} {$to}");

        return [$start, $end <= $start ? $end->addDay() : $end];
    }

    private function weekStart(): CarbonImmutable
    {
        try {
            $date = $this->week !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $this->week) : null;
        } catch (\Throwable) {
            $date = null;
        }

        return ($date ?: CarbonImmutable::now())->startOfWeek();
    }

    private function blank(): void
    {
        $this->shift = ['agent_id' => '', 'date' => '', 'start' => '09:00', 'end' => '17:00', 'shift_type' => 'morning', 'title' => '', 'description' => ''];
    }
}
