<?php

namespace App\Livewire\Agent;

use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\CallLog;
use App\Services\CallStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The agent's own numbers and call history, across every business they answer for.
 */
#[Layout('layouts.portal', ['portal' => 'agent'])]
#[Title('My calls')]
class Calls extends Component
{
    use AgentWorkspaceOnly;
    use WithPagination;

    public const PERIODS = ['today' => 'Today', 'weekly' => 'This week', 'monthly' => 'This month'];

    #[Url(except: 'today')]
    public string $period = 'today';

    #[Url(except: '')]
    public string $search = '';

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    public function render(CallStatsService $stats): View
    {
        $period = array_key_exists($this->period, self::PERIODS) ? $this->period : 'today';
        $userId = auth()->id();

        return view('livewire.agent.calls', [
            'kpis' => $stats->kpis($userId, $period),
            'periods' => self::PERIODS,
            'calls' => CallLog::withoutGlobalScopes()->where('user_id', $userId)->visibleToAgent(auth()->user())->with('organization:id,ulid,name')
                ->when($this->search !== '', function (Builder $query) {
                    $term = '%'.addcslashes(trim($this->search), '%_\\').'%';
                    $query->where(fn (Builder $q) => $q->where('caller_name', 'like', $term)->orWhere('caller_phone', 'like', $term)
                        ->orWhere('call_id', 'like', $term)->orWhereHas('organization', fn (Builder $o) => $o->where('name', 'like', $term)));
                })
                ->latest('created_at')->latest('id')->paginate(20),
        ]);
    }
}
