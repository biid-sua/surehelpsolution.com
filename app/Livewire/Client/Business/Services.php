<?php

namespace App\Livewire\Client\Business;

use App\Enums\ServicePriceType;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessService;
use App\Support\Audit\Audit;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Services the business sells (spec §11): what agents quote, book and explain.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Services')]
class Services extends Component
{
    use ScopedToOrganization;

    public bool $editing = false;

    public ?int $serviceId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize('organization.view', $this->organization());
        $this->resetForm();
    }

    public function create(): void
    {
        $this->authorize('settings.manage', $this->organization());
        $this->resetForm();
        $this->editing = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('settings.manage', $this->organization());
        $service = $this->find($id);

        $this->serviceId = $service->id;
        $this->form = [
            'name' => $service->name,
            'description' => (string) $service->description,
            'category' => (string) $service->category,
            'duration_minutes' => (string) $service->duration_minutes,
            'buffer_minutes' => (string) $service->buffer_minutes,
            'price_type' => $service->price_type->value,
            'price' => Money::toInput($service->price_cents),
            'is_active' => $service->is_active,
            'is_bookable' => $service->is_bookable,
            'required_fields' => $service->required_fields ?? [],
            'agent_instructions' => (string) $service->agent_instructions,
        ];
        $this->resetValidation();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('settings.manage', $organization);

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:255', Rule::unique('business_services', 'name')
                ->where('organization_id', $organization->id)->whereNull('deleted_at')->ignore($this->serviceId)],
            'form.description' => ['nullable', 'string', 'max:2000'],
            'form.category' => ['nullable', 'string', 'max:100'],
            'form.duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'form.buffer_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'form.price_type' => ['required', Rule::enum(ServicePriceType::class)],
            'form.price' => ['nullable', 'string', 'max:20'],
            'form.is_active' => ['boolean'],
            'form.is_bookable' => ['boolean'],
            'form.required_fields' => ['array'],
            'form.required_fields.*' => [Rule::in(array_keys(BusinessService::REQUIRED_FIELDS))],
            'form.agent_instructions' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'form.name' => 'service name',
            'form.duration_minutes' => 'duration',
            'form.buffer_minutes' => 'buffer',
            'form.price_type' => 'price type',
        ])['form'];

        $priceType = ServicePriceType::from($data['price_type']);
        $cents = null;
        if ($priceType->needsAmount()) {
            try {
                $cents = Money::parse((string) ($data['price'] ?? ''));
            } catch (\InvalidArgumentException $e) {
                $this->addError('form.price', $e->getMessage());

                return;
            }
        }

        $service = $this->serviceId ? $this->find($this->serviceId) : new BusinessService(['organization_id' => $organization->id]);
        $service->fill([
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'category' => filled($data['category'] ?? null) ? trim($data['category']) : null,
            'duration_minutes' => (int) $data['duration_minutes'],
            'buffer_minutes' => (int) $data['buffer_minutes'],
            'price_type' => $priceType,
            'price_cents' => $cents,
            'currency' => $organization->currency,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_bookable' => (bool) ($data['is_bookable'] ?? true),
            'required_fields' => array_values($data['required_fields'] ?? []),
            'agent_instructions' => filled($data['agent_instructions'] ?? null) ? trim($data['agent_instructions']) : null,
        ]);
        if (! $service->exists) {
            $service->sort_order = (int) BusinessService::query()->forOrganization($organization)->max('sort_order') + 1;
        }
        $service->save();

        $audit->changes($service->wasRecentlyCreated ? 'service.created' : 'service.updated', $service);

        $this->editing = false;
        $this->dispatch('toast', type: 'success', message: "\"{$service->name}\" saved.");
    }

    public function toggleActive(int $id, Audit $audit): void
    {
        $this->authorize('settings.manage', $this->organization());
        $service = $this->find($id);
        $service->update(['is_active' => ! $service->is_active]);
        $audit->changes('service.updated', $service, ['is_active']);
    }

    public function delete(int $id, Audit $audit): void
    {
        $this->authorize('settings.manage', $this->organization());
        $service = $this->find($id);
        $service->delete();
        $audit->record('service.deleted', $service, old: ['name' => $service->name]);

        $this->dispatch('toast', type: 'success', message: "\"{$service->name}\" removed.");
    }

    private function find(int $id): BusinessService
    {
        return BusinessService::query()->forOrganization($this->organization())->findOrFail($id);
    }

    private function resetForm(): void
    {
        $this->serviceId = null;
        $this->form = [
            'name' => '', 'description' => '', 'category' => '', 'duration_minutes' => '60', 'buffer_minutes' => '0',
            'price_type' => ServicePriceType::QuoteRequired->value, 'price' => '', 'is_active' => true, 'is_bookable' => true,
            'required_fields' => ['phone', 'address'], 'agent_instructions' => '',
        ];
    }

    public function render(): View
    {
        $organization = $this->organization();
        $services = BusinessService::query()->forOrganization($organization)->orderBy('sort_order')->orderBy('name')->get();

        return view('livewire.client.business.services', [
            'services' => $services,
            'categories' => $services->pluck('category')->filter()->unique()->sort()->values(),
            'priceTypes' => ServicePriceType::cases(),
            'requiredFieldOptions' => BusinessService::REQUIRED_FIELDS,
            'canManage' => auth()->user()->can('settings.manage', $organization),
            'currency' => $organization->currency,
        ]);
    }
}
