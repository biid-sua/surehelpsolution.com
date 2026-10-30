<?php

namespace App\Livewire\Admin\Billing;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Addon;
use App\Models\Organization;
use App\Models\Plan;
use App\Services\Billing\FeatureAccess;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Which paid features are open to everyone, which need a plan or add-on that includes them (D46), and
 * which only the businesses staff pick get, hidden from the rest (D107). A tab of Admin › Billing.
 */
class Features extends Component
{
    use PlatformAdminOnly;

    /** @var array<string, string> feature => everyone | plan | selected */
    public array $modes = [];

    /** The feature whose businesses are being picked. */
    public ?string $picking = null;

    public string $search = '';

    public function mount(FeatureAccess $features): void
    {
        $this->authorize('billing.view');
        $this->modes = $features->modes();
    }

    public function save(FeatureAccess $features, Audit $audit): void
    {
        $this->authorize('billing.manage');
        $before = $features->modes();
        $features->saveModes($this->modes);
        $after = $features->modes();
        $audit->record('features.modes_changed', null, $before, $after);
        $this->modes = $after;

        $this->dispatch('toast', type: 'success', message: 'Feature access saved.');
    }

    public function pickFor(?string $feature, FeatureAccess $features): void
    {
        $this->authorize('billing.view');
        $this->picking = $feature !== null && isset($features->catalog()[$feature]) ? $feature : null;
        $this->search = '';
    }

    /** Give one business the feature, or take it back (to following its plan). */
    public function togglePick(int $organizationId, FeatureAccess $features, Audit $audit): void
    {
        $this->authorize('billing.manage');
        abort_unless($this->picking !== null && isset($features->catalog()[$this->picking]), 404);
        $organization = Organization::query()->external()->findOrFail($organizationId);
        $on = ! in_array($organization->id, $features->switchedOn($this->picking), true);
        $features->pick($organization, $this->picking, $on, auth()->id());
        $audit->record($on ? 'features.business_picked' : 'features.business_unpicked', $organization,
            new: ['feature' => $this->picking], organization: $organization, label: $features->label($this->picking).': '.$organization->name);

        $this->dispatch('toast', type: 'success', message: $on
            ? $organization->name.' now has '.$features->label($this->picking).'.'
            : $features->label($this->picking).' taken away from '.$organization->name.'.');
    }

    public function render(FeatureAccess $features): View
    {
        $plans = Plan::query()->where('is_active', true)->ordered()->get(['id', 'name', 'features']);
        $addons = Addon::query()->where('is_active', true)->ordered()->get(['id', 'name', 'slug']);
        $includedBy = [];
        foreach (array_keys($features->catalog()) as $key) {
            $includedBy[$key] = [
                ...$plans->filter(fn (Plan $p) => in_array($key, $p->features ?? [], true))->pluck('name')->all(),
                ...$addons->where('slug', $key)->map(fn (Addon $a) => $a->name.' (add-on)')->all(),
            ];
        }

        $picked = collect(array_keys($features->catalog()))->mapWithKeys(fn ($key) => [$key => $features->switchedOn($key)])->all();
        $pickedNames = Organization::query()->external()->whereIn('id', collect($picked)->flatten()->unique()->all())->pluck('name', 'id');

        return view('livewire.admin.billing.features', [
            'catalog' => $features->catalog(),
            'includedBy' => $includedBy,
            'picked' => array_map(fn (array $ids) => array_values(array_filter(array_map(fn ($id) => $pickedNames[$id] ?? null, $ids))), $picked),
            'pickedIds' => $this->picking ? $picked[$this->picking] : [],
            'candidates' => $this->picking === null ? collect() : Organization::query()->external()
                ->when(trim($this->search) !== '', fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%'))
                ->orderByRaw('id in ('.(implode(',', $picked[$this->picking]) ?: '0').') desc')->orderBy('name')->limit(25)->get(['id', 'name', 'status']),
            'canManage' => auth()->user()->hasPermissionIn('billing.manage'),
        ]);
    }
}
