<?php

namespace App\Livewire\Client\Business;

use App\Enums\KnowledgeType;
use App\Enums\KnowledgeVisibility;
use App\Livewire\Concerns\ScopedToOrganization;
use App\Models\BusinessService;
use App\Models\KnowledgeItem;
use App\Support\Audit\Audit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The business's knowledge base (spec §22): FAQs, policies, pricing guidance and instructions our agents
 * read on every call, and the AI will use later.
 */
#[Layout('layouts.portal', ['portal' => 'client'])]
#[Title('Knowledge base')]
class Knowledge extends Component
{
    use ScopedToOrganization;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    public bool $editing = false;

    public ?string $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        $this->authorize('knowledge_base.view', $this->organization());
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('knowledge_base.manage', $this->organization());
        $this->resetValidation();
        $this->editingId = null;
        $this->form = [
            'type' => KnowledgeType::Faq->value, 'title' => '', 'content' => '', 'category' => '', 'service_id' => '',
            'visibility' => KnowledgeVisibility::Internal->value, 'is_pinned' => false, 'is_active' => true,
        ];
        $this->editing = true;
    }

    public function edit(string $ulid): void
    {
        $this->authorize('knowledge_base.manage', $this->organization());
        $item = $this->find($ulid);
        $this->resetValidation();
        $this->editingId = $item->ulid;
        $this->form = [
            'type' => $item->type->value, 'title' => $item->title, 'content' => $item->content, 'category' => (string) $item->category,
            'service_id' => (string) ($item->service_id ?? ''), 'visibility' => $item->visibility->value,
            'is_pinned' => $item->is_pinned, 'is_active' => $item->is_active,
        ];
        $this->editing = true;
    }

    public function save(Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('knowledge_base.manage', $organization);

        $data = $this->validate([
            'form.type' => ['required', Rule::enum(KnowledgeType::class)],
            'form.title' => ['required', 'string', 'max:200'],
            'form.content' => ['required', 'string', 'max:10000'],
            'form.category' => ['nullable', 'string', 'max:100'],
            'form.service_id' => ['nullable', Rule::exists('business_services', 'id')->where('organization_id', $organization->id)],
            'form.visibility' => ['required', Rule::enum(KnowledgeVisibility::class)],
            'form.is_pinned' => ['boolean'],
            'form.is_active' => ['boolean'],
        ], [], ['form.title' => 'title', 'form.content' => 'answer or details', 'form.service_id' => 'service'])['form'];

        $values = [
            'type' => $data['type'],
            'title' => trim($data['title']),
            'content' => trim($data['content']),
            'category' => filled($data['category']) ? trim($data['category']) : null,
            'service_id' => filled($data['service_id']) ? (int) $data['service_id'] : null,
            'visibility' => $data['visibility'],
            'is_pinned' => (bool) $data['is_pinned'],
            'is_active' => (bool) $data['is_active'],
            'updated_by_user_id' => auth()->id(),
        ];

        if ($this->editingId) {
            $item = $this->find($this->editingId);
            $item->fill($values)->save();
            $audit->changes('knowledge.updated', $item, ['type', 'title', 'content', 'visibility', 'is_pinned', 'is_active']);
        } else {
            $item = KnowledgeItem::create($values + ['organization_id' => $organization->id, 'created_by_user_id' => auth()->id()]);
            $audit->record('knowledge.created', $item, new: ['type' => $item->type->value, 'title' => $item->title, 'visibility' => $item->visibility->value], organization: $organization);
        }

        $this->editing = false;
        $this->dispatch('toast', type: 'success', message: 'Saved. Agents see it on their next call.');
    }

    public function toggle(string $ulid, Audit $audit): void
    {
        $this->authorize('knowledge_base.manage', $this->organization());
        $item = $this->find($ulid);
        $item->forceFill(['is_active' => ! $item->is_active, 'updated_by_user_id' => auth()->id()])->save();
        $audit->changes('knowledge.updated', $item, ['is_active']);
    }

    public function delete(string $ulid, Audit $audit): void
    {
        $organization = $this->organization();
        $this->authorize('knowledge_base.manage', $organization);
        $item = $this->find($ulid);
        $item->delete();
        $audit->record('knowledge.deleted', $item, old: ['type' => $item->type->value, 'title' => $item->title], organization: $organization);
        $this->editing = false;
    }

    private function find(string $ulid): KnowledgeItem
    {
        return KnowledgeItem::query()->forOrganization($this->organization())->where('ulid', $ulid)->firstOrFail();
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.client.business.knowledge', [
            'items' => KnowledgeItem::query()->forOrganization($organization)
                ->with(['updatedBy:id,name', 'service:id,name'])
                ->search($this->search)
                ->when(KnowledgeType::tryFrom($this->type), fn (Builder $q, KnowledgeType $t) => $q->where('type', $t))
                ->orderByDesc('is_active')
                ->ordered()
                ->paginate(30),
            'types' => KnowledgeType::cases(),
            'visibilities' => KnowledgeVisibility::cases(),
            'services' => $this->editing ? BusinessService::query()->forOrganization($organization)->orderBy('name')->get(['id', 'name']) : collect(),
            'canManage' => auth()->user()->can('knowledge_base.manage', $organization),
            'filtered' => $this->search !== '' || $this->type !== '',
        ]);
    }
}
