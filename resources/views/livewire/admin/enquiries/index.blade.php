<div>
    <x-ui.page-header title="Website enquiries" description="Messages sent through the contact form on the website. Each one is also emailed to your inbox." />

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Show">
            @foreach (['open' => 'Open ('.$openCount.')', 'handled' => 'Handled', 'all' => 'All'] as $value => $label)
                <button type="button" wire:click="$set('show', '{{ $value }}')" @class([
                    'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-brand-600 text-white' => $show === $value,
                    'bg-surface-2 text-muted hover:text-ink' => $show !== $value,
                ]) aria-pressed="{{ $show === $value ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="ml-auto grid w-full gap-3 sm:w-auto sm:grid-cols-[16rem_12rem]">
            <div>
                <label for="enq-search" class="sh-label">Search</label>
                <input id="enq-search" type="search" wire:model.live.debounce.350ms="search" class="sh-input" placeholder="Name, email, company…" autocomplete="off">
            </div>
            <div>
                <label for="enq-kind" class="sh-label">Type</label>
                <select id="enq-kind" wire:model.live="kind" class="sh-input">
                    <option value="">All types</option>
                    @foreach ($kinds as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
        <x-ui.card :padding="false">
            @if ($enquiries->isEmpty())
                <x-ui.empty-state icon="inbox" title="{{ $show === 'open' ? 'All caught up' : 'No enquiries' }}"
                    description="{{ $show === 'open' ? 'Every website enquiry has been handled.' : 'Nothing matches this filter.' }}" />
            @else
                <ul class="divide-y divide-line" role="list">
                    @foreach ($enquiries as $enquiry)
                        <li wire:key="enq-{{ $enquiry->id }}">
                            <button type="button" wire:click="select({{ $enquiry->id }})" @class([
                                'flex w-full items-start gap-3 px-5 py-4 text-left transition-colors hover:bg-surface-2',
                                'bg-surface-2' => $current?->id === $enquiry->id,
                            ])>
                                <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-brand-400' => ! $enquiry->handled_at, 'bg-transparent' => $enquiry->handled_at]) aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-medium text-ink">{{ $enquiry->name }}@if ($enquiry->company)<span class="font-normal text-muted"> · {{ $enquiry->company }}</span>@endif</span>
                                        <span class="text-xs text-subtle">{{ $enquiry->created_at->diffForHumans() }}</span>
                                    </span>
                                    <span class="mt-1 flex items-center gap-2">
                                        <x-ui.badge :tone="$enquiry->inquiry_type === 'support' ? 'warning' : 'brand'">{{ $enquiry->inquiryLabel() }}</x-ui.badge>
                                        @if ($enquiry->handled_at)<x-ui.badge tone="success">Handled</x-ui.badge>@endif
                                    </span>
                                    <span class="mt-1 line-clamp-2 block text-sm text-muted">{{ $enquiry->message }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :paginator="$enquiries" />
            @endif
        </x-ui.card>

        <div>
            @if ($current)
                <x-ui.card class="lg:sticky lg:top-24" :title="$current->name" :description="$current->inquiryLabel().' · '.$current->created_at->format('M j, Y g:i A')">
                    <dl class="grid grid-cols-[6rem_1fr] gap-y-2 text-sm">
                        <dt class="text-subtle">Email</dt><dd><a href="mailto:{{ $current->email }}" class="text-brand-300 hover:underline">{{ $current->email }}</a></dd>
                        <dt class="text-subtle">Phone</dt><dd>@if ($current->phone)<a href="tel:{{ $current->phone }}" class="text-brand-300 hover:underline">{{ $current->phone }}</a>@else<span class="text-subtle">—</span>@endif</dd>
                        <dt class="text-subtle">Company</dt><dd class="text-ink">{{ $current->company ?: '—' }}</dd>
                        <dt class="text-subtle">SMS consent</dt><dd class="text-ink">{{ $current->sms_consent ? 'Yes' : 'No' }}</dd>
                    </dl>
                    <p class="mt-4 whitespace-pre-line rounded-xl bg-surface-2 p-4 text-sm text-ink ring-1 ring-line">{{ $current->message }}</p>
                    @if ($current->handled_at)
                        <p class="mt-3 text-xs text-subtle">Handled {{ $current->handled_at->diffForHumans() }}{{ $current->handler ? ' by '.$current->handler->name : '' }}.</p>
                    @endif
                    <div class="mt-5 flex flex-wrap gap-2">
                        <x-ui.button :href="'mailto:'.$current->email.'?subject='.rawurlencode('Re: your SureHelp enquiry')" icon="chat">Reply by email</x-ui.button>
                        @if ($canManage)
                            <x-ui.button variant="secondary" wire:click="toggleHandled">{{ $current->handled_at ? 'Reopen' : 'Mark handled' }}</x-ui.button>
                        @endif
                    </div>
                </x-ui.card>
            @else
                <x-ui.card>
                    <p class="text-sm text-muted">Select an enquiry to read it and reply.</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
