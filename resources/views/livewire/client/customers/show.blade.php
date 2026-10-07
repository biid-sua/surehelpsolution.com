<div>
    <x-ui.page-header :title="$customer->fullName()" :back="route('app.customers.index')"
        description="Customer since {{ $stats['first_seen']->setTimezone($timezone)->format('M j, Y') }} · {{ $stats['calls'] }} {{ \Illuminate\Support\Str::plural('call', $stats['calls']) }}">
        <x-slot:actions>
            <x-ui.badge :tone="$customer->status->tone()" class="text-sm">{{ $customer->status->label() }}</x-ui.badge>
            @if ($canUpdate)
                <x-ui.button variant="secondary" wire:click="edit">Edit</x-ui.button>
            @endif
            @can('customers.delete', $customer->organization)
                <x-ui.button variant="ghost" :href="route('app.customers.duplicates', ['a' => $customer->ulid])">Merge with another customer</x-ui.button>
                <x-ui.confirm id="erase-customer" title="Erase this customer's personal data?" confirm-label="Erase for good" action="erase">
                    <x-slot:trigger><x-ui.button variant="ghost">Erase personal data</x-ui.button></x-slot:trigger>
                    Use this when {{ $customer->fullName() }} asks you to delete their information. Their name, phone, email, address and notes are removed from this record and from every call, appointment and task about them, and their timeline is deleted. This can't be undone.
                </x-ui.confirm>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Details --}}
        <div class="space-y-6">
            <x-ui.card title="Contact">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-xs uppercase tracking-wide text-subtle">Phone</dt><dd class="mt-0.5 text-ink">{{ $customer->displayPhone() ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-subtle">Email</dt><dd class="mt-0.5 break-all text-ink">{{ $customer->email ?? '—' }}</dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-subtle">Address</dt><dd class="mt-0.5 text-ink">{{ $customer->singleLineAddress() ?? '—' }}</dd></div>
                    @if ($customer->company)
                        <div><dt class="text-xs uppercase tracking-wide text-subtle">Company</dt><dd class="mt-0.5 text-ink">{{ $customer->company }}</dd></div>
                    @endif
                    <div><dt class="text-xs uppercase tracking-wide text-subtle">Prefers</dt><dd class="mt-0.5 text-ink">{{ $contactMethods[$customer->preferred_contact] ?? 'No preference' }}</dd></div>
                </dl>
                @if ($customer->phone_e164)
                    <x-ui.button class="mt-4 w-full" variant="secondary" icon="phone" href="tel:{{ $customer->phone_e164 }}">Call {{ $customer->displayPhone() }}</x-ui.button>
                @endif
            </x-ui.card>

            <x-ui.card title="Communication consent">
                <ul class="space-y-2 text-sm">
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-muted">Text messages</span>
                        <x-ui.badge :tone="$customer->sms_consent ? 'success' : 'neutral'">{{ $customer->sms_consent ? 'Yes · '.$customer->sms_consent_at?->setTimezone($timezone)->format('M j, Y') : 'No' }}</x-ui.badge>
                    </li>
                    <li class="flex items-center justify-between gap-2">
                        <span class="text-muted">Email</span>
                        <x-ui.badge :tone="$customer->email_consent ? 'success' : 'neutral'">{{ $customer->email_consent ? 'Yes · '.$customer->email_consent_at?->setTimezone($timezone)->format('M j, Y') : 'No' }}</x-ui.badge>
                    </li>
                </ul>
                @if ($customer->consent_source)<p class="mt-3 text-xs text-subtle">Last change {{ $customer->consent_source }}.</p>@endif
            </x-ui.card>

            <x-ui.card title="Tags">
                <div class="flex flex-wrap gap-2">
                    @forelse ($customer->tags as $tag)
                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-500/15 py-0.5 pl-2.5 pr-1 text-xs font-medium text-brand-300 ring-1 ring-inset ring-brand-500/30">
                            {{ $tag->name }}
                            @if ($canUpdate)
                                <button type="button" wire:click="removeTag({{ $tag->id }})" class="rounded-full p-0.5 hover:bg-white/10" aria-label="Remove tag {{ $tag->name }}"><x-ui.icon name="x" class="size-3" /></button>
                            @endif
                        </span>
                    @empty
                        <p class="text-sm text-subtle">No tags yet.</p>
                    @endforelse
                </div>
                @if ($canUpdate)
                    <form wire:submit="addTag" class="mt-4 flex gap-2">
                        <label for="new-tag" class="sr-only">Add a tag</label>
                        <input id="new-tag" type="text" wire:model="newTag" class="sh-input" list="org-tags" placeholder="VIP, Repeat, …" maxlength="50">
                        <datalist id="org-tags">@foreach ($allTags as $name)<option value="{{ $name }}">@endforeach</datalist>
                        <x-ui.button type="submit" variant="secondary" size="sm">Add</x-ui.button>
                    </form>
                    @error('newTag') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                @endif
            </x-ui.card>

            @if ($appointments !== null)
                <x-ui.card title="Upcoming appointments">
                    @forelse ($appointments as $appointment)
                        <a href="{{ route('app.appointments.index', ['appointment' => $appointment->ulid]) }}" class="mb-3 block last:mb-0">
                            <span class="block text-sm font-medium text-ink hover:text-brand-300">{{ $appointment->whenLabel() }}</span>
                            <span class="block text-xs text-subtle">{{ $appointment->title }} · {{ $appointment->status->label() }}</span>
                        </a>
                    @empty
                        <p class="text-sm text-muted">Nothing booked.</p>
                    @endforelse
                    @if ($canBook)
                        <x-ui.button class="mt-4 w-full" variant="secondary" size="sm" icon="calendar" :href="route('app.appointments.index', ['book' => 1, 'customer' => $customer->ulid])">Book appointment</x-ui.button>
                    @endif
                </x-ui.card>
            @endif

            @if ($openTasks !== null)
                @include('livewire.client.tasks._card', [
                    'cardTasks' => $openTasks,
                    'emptyText' => 'No open tasks for this customer.',
                    'addUrl' => $canCreateTask ? route('app.tasks.index', ['new' => 1, 'customer' => $customer->ulid]) : null,
                ])
            @endif

            @if ($customer->notes)
                <x-ui.card title="About this customer">
                    <p class="whitespace-pre-line text-sm text-ink">{{ $customer->notes }}</p>
                </x-ui.card>
            @endif
        </div>

        {{-- Timeline --}}
        <div class="lg:col-span-2">
            <x-ui.card title="Timeline" description="Every call, note and booking, newest first.">
                @if ($canUpdate)
                    <form wire:submit="addNote" class="mb-6">
                        <label for="note" class="sh-label">Add a note</label>
                        <textarea id="note" wire:model="note" rows="2" class="sh-input" placeholder="e.g. Prefers morning visits. Has a dog."></textarea>
                        @error('note') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        <div class="mt-2 flex justify-end"><x-ui.button type="submit" size="sm" wire:loading.attr="disabled" wire:target="addNote">Add note</x-ui.button></div>
                    </form>
                @endif

                @if ($events->isEmpty())
                    <x-ui.empty-state icon="list" title="Nothing here yet" description="Calls, bookings and notes for this customer will appear here." />
                @else
                    <ol class="relative space-y-6 border-l border-line pl-6">
                        @foreach ($events as $event)
                            <li wire:key="ev-{{ $event->id }}" class="relative">
                                <span class="absolute -left-[2.15rem] grid size-7 place-items-center rounded-full bg-surface-2 text-brand-300 ring-1 ring-line">
                                    <x-ui.icon :name="$event->type->icon()" class="size-4" />
                                </span>
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="text-sm font-medium text-ink">
                                        @if ($event->subject_type === 'CallLog' && ($event->meta['call_id'] ?? null))
                                            <a href="{{ route('app.calls.show', $event->meta['call_id']) }}" class="hover:text-brand-300">{{ $event->title }}</a>
                                        @else
                                            {{ $event->title }}
                                        @endif
                                    </p>
                                    <time class="text-xs text-subtle" datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->setTimezone($timezone)->format('M j, Y · g:i A') }}</time>
                                </div>
                                @if ($event->body)<p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $event->body }}</p>@endif
                                @if ($event->actor)<p class="mt-1 text-xs text-subtle">by {{ $event->actor->name }}</p>@endif
                            </li>
                        @endforeach
                    </ol>
                    @if ($hasMore)
                        <div class="mt-6 text-center"><x-ui.button variant="ghost" size="sm" wire:click="loadMore">Show older</x-ui.button></div>
                    @endif
                @endif
            </x-ui.card>
        </div>
    </div>

    @if ($canUpdate)
        @include('partials.customer-edit-dialog')
    @endif
</div>
