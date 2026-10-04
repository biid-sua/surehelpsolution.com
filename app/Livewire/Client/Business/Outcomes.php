<?php

namespace App\Livewire\Client\Business;

use App\Enums\OutcomeCategory;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\CallLog;
use App\Models\CallOutcome;
use App\Services\Calls\CallOutcomes;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The outcomes agents can choose for this business's calls (spec §14).
 * Defaults can be renamed or switched off; the business can add its own.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Call outcomes')]
class Outcomes extends Component
{
    use ScopedToOrganization;

    public ?string $renaming = null;

    public string $renameLabel = '';

    public string $newLabel = '';

    public string $newCategory = 'information';

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
    }

    public function startRename(string $key): void
    {
        $this->authorize('settings.manage', $this->organization());
        $outcome = $this->outcome($key);
        $this->renaming = $key;
        $this->renameLabel = $outcome['label'];
    }

    public function saveRename(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);
        $this->validate(['renameLabel' => ['required', 'string', 'max:100']], attributes: ['renameLabel' => 'name']);

        $outcome = $this->outcome((string) $this->renaming);
        $row = $this->rowFor($outcome);
        $row->label = trim($this->renameLabel);
        $row->save();
        $audit->changes('call_outcome.updated', $row, ['label']);

        $this->renaming = null;
        app(CallOutcomes::class)->forget();
    }

    public function toggle(string $key, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);

        $outcome = $this->outcome($key);
        $row = $this->rowFor($outcome);
        $row->is_active = ! $outcome['is_active'];
        $row->save();
        $audit->changes('call_outcome.updated', $row, ['is_active']);

        app(CallOutcomes::class)->forget();
    }

    public function add(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);

        $this->validate([
            'newLabel' => ['required', 'string', 'max:100'],
            'newCategory' => ['required', Rule::enum(OutcomeCategory::class)],
        ], attributes: ['newLabel' => 'name', 'newCategory' => 'meaning']);

        $key = 'custom-'.Str::limit(Str::slug($this->newLabel), 50, '');
        if (app(CallOutcomes::class)->effective($organization)->has($key)) {
            $this->addError('newLabel', 'You already have an outcome with this name.');

            return;
        }

        $row = CallOutcome::create([
            'organization_id' => $organization->id,
            'key' => $key,
            'label' => trim($this->newLabel),
            'category' => $this->newCategory,
            'sort_order' => (int) CallOutcome::where('organization_id', $organization->id)->max('sort_order') + 1,
        ]);
        $audit->record('call_outcome.created', $row, new: ['key' => $key, 'label' => $row->label, 'category' => $row->category->value], organization: $organization);

        $this->reset('newLabel', 'newCategory');
        app(CallOutcomes::class)->forget();
        $this->dispatch('toast', type: 'success', message: 'Outcome added. Agents can choose it on the next call.');
    }

    /**
     * Custom outcomes that were never used can be deleted; used ones can only be switched off (history).
     */
    public function delete(string $key, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);

        $row = CallOutcome::where('organization_id', $organization->id)->where('key', $key)->firstOrFail();
        abort_unless(str_starts_with($row->key, 'custom-'), 403);

        if (CallLog::query()->forOrganization($organization)->where('call_outcome', $key)->exists()) {
            $this->dispatch('toast', type: 'error', message: 'Calls already use this outcome. Switch it off instead.');

            return;
        }

        $row->delete();
        $audit->record('call_outcome.deleted', $row, old: ['key' => $key, 'label' => $row->label], organization: $organization);
        app(CallOutcomes::class)->forget();
    }

    /**
     * @return array{key: string, label: string, category: OutcomeCategory, is_active: bool, sort_order: int, custom: bool, overridden: bool}
     */
    private function outcome(string $key): array
    {
        $outcome = app(CallOutcomes::class)->effective($this->organization())->get($key);
        abort_if($outcome === null, 404);

        return $outcome;
    }

    /**
     * The business's own row for an outcome: its custom outcome, or an override of a default.
     *
     * @param  array{key: string, label: string, category: OutcomeCategory, is_active: bool}  $outcome
     */
    private function rowFor(array $outcome): CallOutcome
    {
        return CallOutcome::firstOrNew(
            ['organization_id' => $this->organization()->id, 'key' => $outcome['key']],
            ['label' => $outcome['label'], 'category' => $outcome['category'], 'is_active' => $outcome['is_active']],
        );
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.business.outcomes', [
            'outcomes' => app(CallOutcomes::class)->effective($organization),
            'categories' => OutcomeCategory::cases(),
            'canManage' => auth()->user()->can('settings.manage', $organization),
        ]);
    }
}
