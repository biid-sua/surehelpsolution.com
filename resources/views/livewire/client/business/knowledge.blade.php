<div>
    <x-ui.page-header title="Business" description="What our agents need to know to answer like you would. The AI assistant will use the same knowledge later.">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button icon="info" wire:click="create">Add knowledge</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('livewire.client.business._tabs')

    <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_16rem] sm:items-end">
        <div>
            <label for="kb-search" class="sh-label">Search</label>
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                <input id="kb-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input pl-9" placeholder="Questions, policies, prices…" autocomplete="off">
            </div>
        </div>
        <div>
            <label for="kb-type" class="sh-label">Type</label>
            <select id="kb-type" wire:model.live="type" class="sh-input">
                <option value="">Everything</option>
                @foreach ($types as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </select>
        </div>
    </div>

    @if ($items->isEmpty())
        <x-ui.card>
            @if ($filtered)
                <x-ui.empty-state icon="search" title="Nothing matches" description="Try other words or another type." />
            @else
                <x-ui.empty-state icon="info" title="Teach our agents about your business"
                    description="Add the questions callers ask, your policies, pricing guidance and anything agents must never say. Pin what matters most.">
                    @if ($canManage)<x-ui.button variant="secondary" size="sm" wire:click="create">Add the first item</x-ui.button>@endif
                </x-ui.empty-state>
            @endif
        </x-ui.card>
    @else
        <ul class="space-y-3" role="list">
            @foreach ($items as $item)
                <li wire:key="kb-{{ $item->ulid }}" @class(['rounded-2xl border border-line bg-surface p-5', 'opacity-60' => ! $item->is_active])>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-ui.badge :tone="in_array($item->type, [\App\Enums\KnowledgeType::EmergencyInstruction, \App\Enums\KnowledgeType::EscalationRule], true) ? 'danger' : 'brand'">
                                    <x-ui.icon :name="$item->type->icon()" class="size-3" />{{ $item->type->label() }}
                                </x-ui.badge>
                                <x-ui.badge :tone="$item->visibility->tone()">{{ $item->visibility->label() }}</x-ui.badge>
                                @if ($item->is_pinned)<x-ui.badge tone="warning">Pinned</x-ui.badge>@endif
                                @unless ($item->is_active)<x-ui.badge>Off</x-ui.badge>@endunless
                            </div>
                            <h3 class="mt-2 font-medium text-ink">{{ $item->title }}</h3>
                            <p class="mt-1 line-clamp-3 whitespace-pre-line text-sm text-muted">{{ $item->content }}</p>
                            <p class="mt-2 text-xs text-subtle">
                                {{ collect([$item->category, $item->service?->name])->filter()->join(' · ') }}{{ $item->category || $item->service ? ' · ' : '' }}Updated {{ $item->updated_at->diffForHumans() }}{{ $item->updatedBy ? ' by '.$item->updatedBy->name : '' }}
                            </p>
                        </div>
                        @if ($canManage)
                            <div class="flex shrink-0 gap-1">
                                <x-ui.button variant="ghost" size="sm" wire:click="edit('{{ $item->ulid }}')">Edit</x-ui.button>
                                <x-ui.button variant="ghost" size="sm" wire:click="toggle('{{ $item->ulid }}')">{{ $item->is_active ? 'Switch off' : 'Switch on' }}</x-ui.button>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="mt-4"><x-ui.pagination :paginator="$items" /></div>
    @endif

    @if ($canManage)
        <div x-data="{ open: $wire.entangle('editing') }" x-show="open" x-cloak x-on:keydown.escape.window="open = false"
            class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="kb-editor-title">
            <div class="fixed inset-0 bg-black/60" x-on:click="open = false"></div>
            <form wire:submit="save" x-trap.noscroll="open" class="relative max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <h2 id="kb-editor-title" class="text-lg font-semibold text-ink">{{ $editingId ? 'Edit knowledge' : 'Add knowledge' }}</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="kb-f-type" class="sh-label">Type</label>
                        <select id="kb-f-type" wire:model="form.type" class="sh-input">
                            @foreach ($types as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="kb-f-visibility" class="sh-label">Who can see it</label>
                        <select id="kb-f-visibility" wire:model="form.visibility" class="sh-input">
                            @foreach ($visibilities as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="kb-f-title" class="sh-label">{{ ($form['type'] ?? '') === 'faq' ? 'Question' : 'Title' }}</label>
                        <input id="kb-f-title" type="text" wire:model="form.title" class="sh-input" maxlength="200">
                        @error('form.title') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="kb-f-content" class="sh-label">{{ ($form['type'] ?? '') === 'faq' ? 'Answer' : 'Details' }}</label>
                        <textarea id="kb-f-content" wire:model="form.content" rows="6" class="sh-input"></textarea>
                        @error('form.content') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="kb-f-category" class="sh-label">Category <span class="font-normal text-subtle">(optional)</span></label>
                        <input id="kb-f-category" type="text" wire:model="form.category" class="sh-input" maxlength="100" placeholder="e.g. Billing">
                    </div>
                    <div>
                        <label for="kb-f-service" class="sh-label">About a service <span class="font-normal text-subtle">(optional)</span></label>
                        <select id="kb-f-service" wire:model="form.service_id" class="sh-input">
                            <option value="">Any</option>
                            @foreach ($services as $service)<option value="{{ $service->id }}">{{ $service->name }}</option>@endforeach
                        </select>
                        @error('form.service_id') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="form.is_pinned" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Pin to the top for agents</label>
                    <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="form.is_active" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> In use</label>
                </div>
                <div class="mt-6 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        @if ($editingId)
                            <x-ui.button variant="ghost" size="sm" wire:click="delete('{{ $editingId }}')" wire:confirm="Delete this item?">Delete</x-ui.button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button variant="secondary" x-on:click="open = false">Close</x-ui.button>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
                    </div>
                </div>
            </form>
        </div>
    @endif
</div>
