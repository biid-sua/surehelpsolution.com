<?php

namespace App\Livewire\Agent;

use App\Enums\TaskStatus;
use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Escalation;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Business\BusinessHours;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The agent's start screen (spec §20): which businesses are open right now, and what across them
 * needs attention: urgent escalations, call-backs due, today's appointments. Built for speed: a few
 * grouped queries, no per-business round trips.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('Agent workspace')]
class Home extends Component
{
    use AgentWorkspaceOnly;

    #[Url(except: '')]
    public string $search = '';

    public function render(BusinessHours $hours): View
    {
        $user = auth()->user();
        /** @var Collection<int, Organization> $organizations */
        $organizations = $user->workableOrganizations()->orderBy('name')->get(['id', 'ulid', 'name', 'timezone', 'status']);
        $ids = $organizations->pluck('id');

        $count = fn ($query) => $query->whereIn('organization_id', $ids)->selectRaw('organization_id, COUNT(*) as aggregate')->groupBy('organization_id')->pluck('aggregate', 'organization_id');
        $followUps = $count(Task::withoutGlobalScopes()->open()->followUps());
        $escalationCounts = $count(Escalation::withoutGlobalScopes()->active());

        $businesses = $organizations
            ->filter(fn (Organization $o) => $this->search === '' || str_contains(mb_strtolower($o->name), mb_strtolower(trim($this->search))))
            ->map(fn (Organization $o) => [
                'organization' => $o,
                'status' => $hours->status($o),
                'local_time' => now($o->timezoneOrDefault())->format('g:i A'),
                'follow_ups' => (int) ($followUps[$o->id] ?? 0),
                'escalations' => (int) ($escalationCounts[$o->id] ?? 0),
            ])
            ->sortByDesc(fn (array $b) => [$b['escalations'] > 0, $b['status']['open']])
            ->values();

        return view('livewire.agent.home', [
            'businesses' => $businesses,
            'escalations' => Escalation::withoutGlobalScopes()->whereIn('organization_id', $ids)->active()
                ->with(['organization:id,ulid,name', 'customer:id,first_name,last_name,company'])->byUrgency()->limit(6)->get(),
            'callbacks' => Task::withoutGlobalScopes()->whereIn('organization_id', $ids)->open()->followUps()
                ->with('organization:id,ulid,name,timezone')->byUrgency()->limit(8)->get(),
            'appointments' => Appointment::withoutGlobalScopes()->whereIn('organization_id', $ids)->blocking()
                ->whereBetween('starts_at', [now()->subHour(), now()->addDay()])
                ->with('organization:id,ulid,name,timezone')->orderBy('starts_at')->limit(8)->get(),
            'myCallsToday' => CallLog::withoutGlobalScopes()->where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count(),
            'recentCalls' => CallLog::withoutGlobalScopes()->where('user_id', $user->id)->with('organization:id,ulid,name')->latest('created_at')->limit(5)->get(),
            'openStatus' => TaskStatus::Open,
        ]);
    }
}
