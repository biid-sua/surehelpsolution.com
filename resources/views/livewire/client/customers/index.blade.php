<div>
    <x-ui.page-header title="Customers" description="Everyone who has called, been booked, or been added by your team.">
        <x-slot:actions>
            @if ($duplicateCount)
                <x-ui.button variant="secondary" icon="users" :href="route('app.customers.duplicates')">{{ $duplicateCount }} possible {{ \Illuminate\Support\Str::plural('duplicate', $duplicateCount) }}</x-ui.button>
            @endif
            <x-ui.button variant="secondary" icon="download" :href="route('app.customers.export', array_filter(['search' => $search, 'status' => $status, 'tag' => $tag]))">Export CSV</x-ui.button>
            @if ($canCreate)
                <x-ui.button variant="secondary" :href="route('app.customers.import')">Import CSV</x-ui.button>
                <x-ui.button icon="users" wire:click="add">Add customer</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-[1fr_12rem_12rem] sm:items-end">
        <div>
            <label for="cust-search" class="sh-label">Search</label>
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                <input id="cust-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input pl-9" placeholder="Name, phone, email, company…" autocomplete="off">
            </div>
        </div>
        <div>
            <label for="cust-status" class="sh-label">Status</label>
            <select id="cust-status" wire:model.live="status" class="sh-input">
                <option value="">All statuses</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="cust-tag" class="sh-label">Tag</label>
            <select id="cust-tag" wire:model.live="tag" class="sh-input" @disabled($tags->isEmpty())>
                <option value="">All tags</option>
                @foreach ($tags as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <x-ui.card :padding="false">
        <div class="relative">
            <div wire:loading.flex wire:target="search,status,tag,nextPage,previousPage" class="absolute inset-0 z-10 items-start justify-center bg-surface/60 pt-16">
                <span class="rounded-full bg-surface-2 px-3 py-1 text-xs text-muted ring-1 ring-line">Loading…</span>
            </div>

            @if ($customers->isEmpty())
                @if ($filtered)
                    <x-ui.empty-state icon="search" title="No customers match" description="Try a different name, number or filter." />
                @else
                    <x-ui.empty-state icon="users" title="No customers yet"
                        description="Every caller our team speaks with becomes a customer here automatically, with their full history." />
                @endif
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Customer</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="hidden md:table-cell">Tags</th>
                            <th scope="col" class="text-right">Last activity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($customers as $customer)
                            <tr wire:key="c-{{ $customer->id }}">
                                <td>
                                    <a href="{{ route('app.customers.show', $customer) }}" class="font-medium text-ink hover:text-brand-300">{{ $customer->fullName() }}</a>
                                    @if ($customer->company && trim($customer->first_name.' '.$customer->last_name) !== '')
                                        <p class="text-xs text-subtle">{{ $customer->company }}</p>
                                    @endif
                                </td>
                                <td class="text-muted">
                                    {{ $customer->displayPhone() ?? '—' }}
                                    @if ($customer->email)<p class="text-xs text-subtle">{{ $customer->email }}</p>@endif
                                </td>
                                <td><x-ui.badge :tone="$customer->status->tone()">{{ $customer->status->label() }}</x-ui.badge></td>
                                <td class="hidden md:table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($customer->tags as $t)<x-ui.badge tone="brand">{{ $t->name }}</x-ui.badge>@endforeach
                                    </div>
                                </td>
                                <td class="whitespace-nowrap text-right text-muted" title="{{ $customer->last_activity_at?->setTimezone($timezone)->toDayDateTimeString() }}">
                                    {{ $customer->last_activity_at?->diffForHumans() ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <x-ui.pagination :paginator="$customers" />
            @endif
        </div>
    </x-ui.card>

    @if ($canCreate)
        <div x-data="{ open: $wire.entangle('adding') }" x-show="open" x-cloak x-on:keydown.escape.window="open = false"
            class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="add-customer-title">
            <div class="fixed inset-0 bg-black/60" x-on:click="open = false"></div>
            <form wire:submit="create" x-trap.noscroll="open" class="relative w-full max-w-lg rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <h2 id="add-customer-title" class="text-lg font-semibold text-ink">Add a customer</h2>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="n-first" class="sh-label">First name</label>
                        <input id="n-first" type="text" wire:model="form.first_name" class="sh-input" autocomplete="off">
                        @error('form.first_name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="n-last" class="sh-label">Last name</label>
                        <input id="n-last" type="text" wire:model="form.last_name" class="sh-input" autocomplete="off">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="n-company" class="sh-label">Company</label>
                        <input id="n-company" type="text" wire:model="form.company" class="sh-input">
                    </div>
                    <div>
                        <label for="n-phone" class="sh-label">Phone</label>
                        <input id="n-phone" type="tel" wire:model="form.phone" class="sh-input" placeholder="(512) 555-0100">
                        @error('form.phone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="n-email" class="sh-label">Email</label>
                        <input id="n-email" type="email" wire:model="form.email" class="sh-input">
                        @error('form.email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <x-ui.button variant="secondary" x-on:click="open = false">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="create">Add customer</x-ui.button>
                </div>
            </form>
        </div>
    @endif
</div>
