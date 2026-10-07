<?php

namespace App\Livewire\Admin\Billing;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Addon;
use App\Models\Plan;
use App\Services\Billing\FeatureAccess;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Which paid features are open to everyone and which need a plan or add-on that includes them (D46).
 * A tab of Admin › Billing.
 */
class Features extends Component
{
    use PlatformAdminOnly;

    /** @var array<string, string> feature => everyone | plan */
    public array $modes = [];

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

        return view('livewire.admin.billing.features', [
            'catalog' => $features->catalog(),
            'includedBy' => $includedBy,
            'canManage' => auth()->user()->hasPermissionIn('billing.manage'),
        ]);
    }
}
