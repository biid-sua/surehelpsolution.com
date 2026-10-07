<div>
    <x-agent.company-bar :company="$company" active="customers" />

    <div class="mb-4 flex flex-wrap items-start gap-3">
        <div class="min-w-0 flex-1">
        <a href="{{ route('agent.businesses.customers', $company->ulid) }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-ink"><x-ui.icon name="arrow-left" class="size-4" /> Customers</a>
        <h1 class="mt-2 text-2xl font-semibold text-ink">{{ $customer->fullName() }}</h1>
        <p class="mt-1 text-sm text-muted">
            {{ $customer->displayPhone() ?? 'No phone' }}@if ($customer->email) · {{ $customer->email }}@endif
            · <x-ui.badge :tone="$customer->status->tone()">{{ $customer->status->label() }}</x-ui.badge>
        </p>
        </div>
        @if ($canUpdate)<x-ui.button variant="secondary" wire:click="edit">Edit details</x-ui.button>@endif
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <x-ui.card title="History" :padding="false">
            @if ($canUpdate)
                <form wire:submit="addNote" class="border-b border-line px-5 py-4">
                    <label for="c-note" class="sr-only">Add a note</label>
                    <textarea id="c-note" wire:model="note" rows="2" class="sh-input" placeholder="Add a note for the business and other agents…"></textarea>
                    @error('note') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    <div class="mt-2 flex justify-end"><x-ui.button type="submit" size="sm" variant="secondary">Add note</x-ui.button></div>
                </form>
            @endif
            @if ($timeline->isEmpty())
                <x-ui.empty-state icon="clock" title="Nothing yet" description="Calls, bookings and messages appear here." />
            @else
                <ol class="divide-y divide-line">
                    @foreach ($timeline as $event)
                        <li class="flex gap-3 px-5 py-3" wire:key="te-{{ $event->id }}">
                            <x-ui.icon :name="$event->type->icon()" class="mt-0.5 size-4 shrink-0 text-subtle" />
                            <div class="min-w-0">
                                <p class="text-sm text-ink">{{ $event->title }}</p>
                                @if ($event->body)<p class="mt-0.5 whitespace-pre-line text-sm text-muted">{{ \Illuminate\Support\Str::limit($event->body, 400) }}</p>@endif
                                <p class="mt-0.5 text-xs text-subtle">{{ $event->occurred_at?->setTimezone($timezone)->format('D j M Y, g:i A') }}</p>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </x-ui.card>

        <aside class="space-y-4">
            <x-ui.card title="Upcoming appointments">
                @forelse ($appointments as $a)
                    <p class="text-sm text-ink">{{ $a->title }}</p>
                    <p class="mb-2 text-xs text-subtle">{{ $a->starts_at->setTimezone($timezone)->format('D j M, g:i A') }} · {{ $a->status->label() }}</p>
                @empty
                    <p class="text-sm text-muted">None.</p>
                @endforelse
            </x-ui.card>
            <x-ui.card title="Open tasks">
                @forelse ($tasks as $t)
                    <p class="text-sm text-ink">{{ $t->title }}</p>
                    <p class="mb-2 text-xs text-subtle">{{ $t->type->label() }}@if ($t->due_at) · due {{ $t->due_at->setTimezone($timezone)->format('D j M, g:i A') }}@endif</p>
                @empty
                    <p class="text-sm text-muted">None.</p>
                @endforelse
            </x-ui.card>
            @if ($customer->notes)
                <x-ui.card title="Notes"><p class="whitespace-pre-line text-sm text-muted">{{ $customer->notes }}</p></x-ui.card>
            @endif
        </aside>
    </div>

    @if ($canUpdate)
        @include('partials.customer-edit-dialog')
    @endif
</div>
