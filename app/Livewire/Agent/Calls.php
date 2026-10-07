<?php

namespace App\Livewire\Agent;

use App\Actions\Calls\AddCallNote;
use App\Livewire\Concerns\AgentWorkspaceOnly;
use App\Models\CallLog;
use App\Services\CallStatsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The agent's own numbers and call history, across every business they answer for. A recent call
 * can get an added note (AGT-14).
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

    /** Id of the call getting a note. */
    public ?int $noting = null;

    public string $note = '';

    public function startNote(int $id): void
    {
        $this->authorize('update', $this->call($id));
        $this->reset('note');
        $this->resetValidation();
        $this->noting = $id;
    }

    public function cancelNote(): void
    {
        $this->reset('noting', 'note');
    }

    public function saveNote(AddCallNote $add): void
    {
        $call = $this->call((int) $this->noting);
        $this->authorize('update', $call);
        $this->validate(['note' => ['required', 'string', 'max:'.AddCallNote::MAX_LENGTH]]);

        try {
            $add->handle($call, $this->note, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('note', collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('noting', 'note');
        $this->dispatch('toast', type: 'success', message: 'Note added to the call.');
    }

    private function call(int $id): CallLog
    {
        return CallLog::withoutGlobalScopes()->where('user_id', auth()->id())->visibleToAgent(auth()->user())->findOrFail($id);
    }

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
