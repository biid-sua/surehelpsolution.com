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
 * Every feature businesses can have, switched on or off for everyone (D119), and while on, who gets
 * it: every business, plans and add-ons that include it (D46), or only the businesses staff pick,
 * hidden from the rest (D107). A feature switched back on is announced to businesses as "New".
 * A tab of Admin › Billing.
 */
class Features extends Component
{
    use PlatformAdminOnly;

    /** @var array<string, bool> feature => on */
    public array $active = [];

    /** @var array<string, string> feature => everyone | plan | selected (kept while it's off) */
    public array $audiences = [];

    /** @var array<string, bool> feature => tell businesses it's new when it's switched on */
    public array $announce = [];

    /** all | on | off */
    public string $show = 'all';

    public string $find = '';

    /** The feature whose businesses are being picked. */
    public ?string $picking = null;

    public string $search = '';

    public function mount(FeatureAccess $features): void
    {
        $this->authorize('billing.view');
        $this->load($features);
    }

    private function load(FeatureAccess $features): void
    {
        $this->audiences = $features->audiences();
        foreach ($features->modes() as $key => $mode) {
            $this->active[$key] = $mode !== FeatureAccess::OFF;
            $this->announce[$key] = true;
        }
    }

    public function save(FeatureAccess $features, Audit $audit): void
    {
        $this->authorize('billing.manage');
        $this->validate([
            'active.*' => ['boolean'],
            'audiences.*' => ['in:'.implode(',', FeatureAccess::AUDIENCES)],
        ]);
        $before = $features->modes();
        $announced = $features->configure(
            array_map(fn ($v) => (bool) $v, $this->active),
            $this->audiences,
            array_map(fn ($v) => (bool) $v, $this->announce),
        );
        $after = $features->modes();
        if ($before !== $after) {
            $audit->record('features.modes_changed', null, $before, $after);
        }
        $this->load($features);

        $this->dispatch('toast', type: 'success', message: $announced === []
            ? 'Feature access saved.'
            : 'Saved. Businesses that have it will see '.implode(', ', array_map(fn ($k) => $features->label($k), $announced)).' as new.');
    }

    /** Switch every feature on or off at once (saved with Save). */
    public function setAll(bool $on): void
    {
        $this->authorize('billing.manage');
        foreach (array_keys($this->active) as $key) {
            $this->active[$key] = $on;
        }
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
        $catalog = $features->catalog();
        $saved = $features->modes();
        $includedBy = [];
        foreach (array_keys($catalog) as $key) {
            $includedBy[$key] = [
                ...$plans->filter(fn (Plan $p) => in_array($key, $p->features ?? [], true))->pluck('name')->all(),
                ...$addons->where('slug', $key)->map(fn (Addon $a) => $a->name.' (add-on)')->all(),
            ];
        }

        $picked = collect(array_keys($catalog))->mapWithKeys(fn ($key) => [$key => $features->switchedOn($key)])->all();
        $pickedNames = Organization::query()->external()->whereIn('id', collect($picked)->flatten()->unique()->all())->pluck('name', 'id');

        $find = mb_strtolower(trim($this->find));
        $listed = collect($catalog)
            ->filter(fn (array $f, string $key) => match ($this->show) {
                'on' => (bool) ($this->active[$key] ?? true),
                'off' => ! ($this->active[$key] ?? true),
                default => true,
            })
            ->filter(fn (array $f, string $key) => $find === '' || str_contains(mb_strtolower($f['label'].' '.$f['description'].' '.$key), $find))
            ->groupBy(fn (array $f) => $f['group'] ?? 'Other', true);

        return view('livewire.admin.billing.features', [
            'catalog' => $catalog,
            'groups' => $listed,
            'counts' => ['on' => count(array_filter($saved, fn ($m) => $m !== FeatureAccess::OFF)), 'off' => count(array_filter($saved, fn ($m) => $m === FeatureAccess::OFF))],
            'wasOff' => array_map(fn ($m) => $m === FeatureAccess::OFF, $saved),
            'isNew' => collect(array_keys($catalog))->mapWithKeys(fn ($k) => [$k => $features->isNew($k)])->all(),
            'launches' => $features->launches(),
            'dirty' => collect(array_keys($catalog))->contains(fn ($k) => (bool) ($this->active[$k] ?? true) !== ($saved[$k] !== FeatureAccess::OFF)
                || (($this->active[$k] ?? true) && ($this->audiences[$k] ?? null) !== $features->audiences()[$k])),
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
