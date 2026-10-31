<div>
    <x-ui.page-header title="Estimates & invoices" description="Send estimates customers accept online, turn them into invoices, and get paid by card or mark them paid.">
        @if ($canWrite)
            <x-slot:actions>
                <x-ui.button :href="route('app.documents.create', ['type' => $type])" wire:navigate icon="list">New {{ $type === 'estimate' ? 'estimate' : 'invoice' }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-ui.stat label="Estimates waiting on customers" icon="clock" :value="\App\Support\Money::format((int) $stats['waiting'])" />
        <x-ui.stat label="Estimates won this month" icon="check-circle" :value="\App\Support\Money::format((int) $stats['won'])" />
        <x-ui.stat label="Invoices not paid yet" icon="card" :value="\App\Support\Money::format((int) $stats['unpaid'])" />
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="flex rounded-lg border border-line p-0.5" role="tablist">
            @foreach (['estimate' => 'Estimates', 'invoice' => 'Invoices'] as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $type === $key ? 'true' : 'false' }}" wire:click="$set('type', '{{ $key }}')"
                    @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-surface-3 text-ink' => $type === $key, 'text-muted hover:text-ink' => $type !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <select wire:model.live="status" aria-label="Status" class="sh-input w-auto">
            <option value="">All statuses</option>
            @foreach ($statuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by number, title or customer" aria-label="Search" class="sh-input min-w-[12rem] flex-1">
    </div>

    <x-ui.card :padding="false">
        <ul class="divide-y divide-line" role="list">
            @forelse ($documents as $document)
                <li wire:key="doc-{{ $document->ulid }}">
                    <a href="{{ route('app.documents.edit', $document) }}" wire:navigate class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm hover:bg-surface-2">
                        <span class="w-20 shrink-0 font-mono text-xs text-subtle">{{ $document->number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-ink">{{ $document->title }}</span>
                            <span class="block truncate text-xs text-muted">{{ $document->customer?->fullName() }}</span>
                        </span>
                        <x-ui.badge :tone="\App\Models\JobDocument::STATUS_TONES[$document->status] ?? 'neutral'">{{ \App\Models\JobDocument::STATUS_LABELS[$document->status] ?? $document->status }}</x-ui.badge>
                        <span class="w-24 text-right font-medium text-ink">{{ $document->total() }}</span>
                    </a>
                </li>
            @empty
                <li class="px-5 py-6"><x-ui.empty-state icon="list" title="Nothing here yet" description="{{ $type === 'estimate' ? 'Write an estimate after a call, send it by text or email, and the customer accepts it online.' : 'Turn an accepted estimate into an invoice, or write one from scratch.' }}" /></li>
            @endforelse
        </ul>
        @if ($documents->hasPages())<div class="border-t border-line px-5 py-3">{{ $documents->links() }}</div>@endif
    </x-ui.card>
</div>
