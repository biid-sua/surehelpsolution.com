<?php

namespace App\Livewire\Admin\Billing;

use App\Livewire\Concerns\PlatformAdminOnly;
use App\Models\Addon;
use App\Models\OrganizationAddon;
use App\Support\Audit\Audit;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The add-on catalogue (task.md ADD-01): what businesses can turn on, at what monthly price.
 * Shown as a tab of Admin › Billing. A new price applies to businesses that turn it on afterwards.
 */
class Addons extends Component
{
    use PlatformAdminOnly;

    public ?int $editing = null;

    /** @var array{name: string, slug: string, price: string, description: string, is_active: bool} */
    public array $form = ['name' => '', 'slug' => '', 'price' => '', 'description' => '', 'is_active' => true];

    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    public function edit(int $id): void
    {
        $addon = Addon::findOrFail($id);
        $this->editing = $addon->id;
        $this->form = ['name' => $addon->name, 'slug' => $addon->slug, 'price' => Money::toInput($addon->price_cents), 'description' => (string) $addon->description, 'is_active' => $addon->is_active];
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->reset('editing', 'form');
        $this->resetValidation();
    }

    public function save(Audit $audit): void
    {
        $this->authorize('billing.manage');
        $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'form.price' => ['required', 'string', 'max:20'],
            'form.description' => ['nullable', 'string', 'max:2000'],
        ], ['form.slug.regex' => 'Use lower-case letters, numbers, dots, dashes or underscores.'], ['form.name' => 'name', 'form.slug' => 'feature key', 'form.price' => 'price']);

        try {
            $cents = Money::parse((string) $this->form['price']);
        } catch (\InvalidArgumentException) {
            $this->addError('form.price', 'Enter a price like 49 or 49.00.');

            return;
        }
        if ($cents <= 0) {
            $this->addError('form.price', 'An add-on needs a price above zero.');

            return;
        }

        $slug = filled($this->form['slug']) ? $this->form['slug'] : Str::slug($this->form['name'], '_');
        if (Addon::where('slug', $slug)->whereKeyNot($this->editing)->exists()) {
            $this->addError('form.slug', 'Another add-on already uses this key.');

            return;
        }

        $values = ['name' => trim($this->form['name']), 'price_cents' => $cents, 'description' => $this->form['description'] ?: null, 'is_active' => (bool) $this->form['is_active']];
        if ($this->editing) {
            $addon = Addon::findOrFail($this->editing);
            // The key is what features check; changing it after businesses use it would switch features off.
            $addon->fill($values)->save();
            $audit->changes('addon.updated', $addon, ['name', 'price_cents', 'is_active']);
        } else {
            $addon = Addon::create($values + ['slug' => $slug]);
            $audit->record('addon.created', $addon, new: ['slug' => $slug, 'price_cents' => $cents], label: $addon->name);
        }

        $this->cancel();
        $this->dispatch('toast', type: 'success', message: 'Add-on saved.');
    }

    public function render(): View
    {
        return view('livewire.admin.billing.addons', [
            'addons' => Addon::query()->ordered()->get(),
            'counts' => OrganizationAddon::withoutGlobalScopes()->active()->selectRaw('addon_id, COUNT(*) as aggregate')->groupBy('addon_id')->pluck('aggregate', 'addon_id'),
            'canManage' => auth()->user()->hasPermissionIn('billing.manage'),
        ]);
    }
}
