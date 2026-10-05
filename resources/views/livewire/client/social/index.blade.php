<div>
    <x-ui.page-header title="Social media" description="Plan, write and schedule posts for every account in one place. Times are in {{ $timezone }}.">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button icon="megaphone" :href="route('app.social.posts.create')">New post</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('livewire.client.social._tabs')

    @unless ($hasAccounts)
        <x-ui.alert tone="info" class="mb-6" title="Connect your accounts to start posting">
            You can plan drafts now. <a href="{{ route('app.social.accounts') }}" class="font-semibold underline underline-offset-2">Connect Facebook, Instagram, LinkedIn or Google</a> to publish them.
        </x-ui.alert>
    @endunless

    <div class="mb-4 flex flex-wrap items-end gap-3">
        <div class="inline-flex rounded-lg bg-surface-2 p-1 ring-1 ring-line" role="tablist" aria-label="View">
            @foreach (['calendar' => 'Calendar', 'list' => 'List', 'approval' => 'Needs approval'] as $value => $label)
                <button type="button" role="tab" aria-selected="{{ $view === $value ? 'true' : 'false' }}" wire:click="$set('view', '{{ $value }}')"
                    @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-surface-3 text-ink shadow-sm' => $view === $value, 'text-muted hover:text-ink' => $view !== $value])>
                    {{ $label }}@if ($value === 'approval' && $awaiting > 0) <span class="ml-1 rounded-full bg-amber-500/20 px-1.5 text-xs text-amber-200">{{ $awaiting }}</span>@endif
                </button>
            @endforeach
        </div>
        <div>
            <label for="f-network" class="sr-only">Network</label>
            <select id="f-network" wire:model.live="network" class="sh-input py-1.5">
                <option value="">All networks</option>
                @foreach ($networks as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
            </select>
        </div>
        @if ($view === 'list')
            <div>
                <label for="f-status" class="sr-only">Status</label>
                <select id="f-status" wire:model.live="status" class="sh-input py-1.5">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $option)<option value="{{ $option->value }}">{{ $option->label() }}</option>@endforeach
                </select>
            </div>
        @endif
    </div>

    @if ($view === 'calendar')
        <x-ui.card :padding="false">
            <div class="flex items-center justify-between border-b border-line px-4 py-3">
                <x-ui.button size="sm" variant="ghost" icon="chevron-left" wire:click="shiftMonth(-1)" aria-label="Previous month" />
                <h2 class="font-semibold text-ink">{{ $monthStart->format('F Y') }}</h2>
                <x-ui.button size="sm" variant="ghost" icon="chevron-right" wire:click="shiftMonth(1)" aria-label="Next month" />
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[48rem] table-fixed text-sm">
                    <thead>
                        <tr class="text-xs text-subtle">
                            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<th scope="col" class="px-2 py-2 text-left font-medium">{{ $d }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($weeks as $week)
                            <tr class="border-t border-line align-top">
                                @foreach ($week as $day)
                                    @php($key = $day->format('Y-m-d'))
                                    @php($items = $byDay->get($key, collect()))
                                    <td @class(['h-28 border-l border-line p-1.5 first:border-l-0', 'bg-surface-2/40' => $day->month !== $monthStart->month])>
                                        <div class="flex items-center justify-between">
                                            <span @class(['inline-flex size-6 items-center justify-center rounded-full text-xs', 'bg-brand-600 font-semibold text-white' => $key === $today, 'text-muted' => $key !== $today])>{{ $day->day }}</span>
                                            @if ($canManage && $key >= $today)
                                                <a href="{{ route('app.social.posts.create', ['date' => $key]) }}" class="rounded px-1 text-subtle hover:text-ink" aria-label="New post on {{ $day->format('j F') }}">+</a>
                                            @endif
                                        </div>
                                        <ul class="mt-1 space-y-1">
                                            @foreach ($items->take(3) as $p)
                                                <li>
                                                    <a href="{{ route('app.social.posts.edit', $p) }}" class="block truncate rounded-md bg-surface-2 px-1.5 py-1 text-xs text-ink ring-1 ring-line hover:ring-brand-400" title="{{ $p->status->label() }}: {{ $p->excerpt(200) }}">
                                                        @foreach ($p->targets->pluck('account.network')->filter()->unique() as $n)<span class="mr-0.5 inline-block size-2 rounded-full" style="background: {{ $n->color() }}"></span>@endforeach
                                                        <span class="text-subtle">{{ ($p->scheduled_at ?? $p->published_at)?->setTimezone($timezone)->format('g:ia') }}</span>
                                                        {{ $p->excerpt(40) }}
                                                    </a>
                                                </li>
                                            @endforeach
                                            @if ($items->count() > 3)
                                                <li class="px-1.5 text-xs text-subtle">+{{ $items->count() - 3 }} more</li>
                                            @endif
                                        </ul>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @else
        <x-ui.card :padding="false">
            @if ($posts->isEmpty())
                <x-ui.empty-state icon="megaphone" :title="$view === 'approval' ? 'Nothing waiting for approval' : 'No posts yet'"
                    :description="$view === 'approval' ? 'Posts written by your managers, our team or AI appear here for an owner to approve.' : 'Write your first post: a tip, an offer, a finished job or a thank-you to a customer.'" />
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">When</th>
                            <th scope="col">Post</th>
                            <th scope="col">Accounts</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($posts as $p)
                            <tr wire:key="p-{{ $p->ulid }}">
                                <td class="whitespace-nowrap text-muted">{{ ($p->scheduled_at ?? $p->published_at)?->setTimezone($timezone)->format('D j M, g:i A') ?? 'Not scheduled' }}</td>
                                <td><a href="{{ route('app.social.posts.edit', $p) }}" class="font-medium text-ink hover:text-brand-300">{{ $p->excerpt(90) ?: '(photo only)' }}</a></td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($p->targets as $t)
                                            @if ($t->account)<span class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted"><span class="size-2 rounded-full" style="background: {{ $t->account->network->color() }}"></span>{{ $t->account->network->shortLabel() }}</span>@endif
                                        @endforeach
                                    </div>
                                </td>
                                <td><x-ui.badge :tone="$p->status->tone()">{{ $p->status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <x-ui.pagination :paginator="$posts" />
            @endif
        </x-ui.card>
    @endif
</div>
