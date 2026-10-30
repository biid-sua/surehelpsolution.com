<div>
    <x-ui.page-header title="Social media" description="Plan, write and schedule posts for every account in one place. Times are in {{ $timezone }}.">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="sparkles" wire:click="openPlan">Plan the month with AI</x-ui.button>
                <x-ui.button icon="megaphone" :href="$this->socialRoute('posts.create')">New post</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>
    @include('livewire.client.social._tabs')

    {{-- Month plan with AI (D66) --}}
    @if ($planning && $canManage)
        <x-ui.card class="mb-6">
            <h2 class="flex items-center gap-2 text-base font-semibold text-ink"><x-ui.icon name="sparkles" class="size-4 text-brand-300" /> Plan the month with AI</h2>
            <p class="mt-1 text-sm text-muted">The AI drafts posts from your business details and brand voice, spread over the month. They appear in the calendar as <span class="text-ink">AI drafts</span>: nothing is published until you add photos where needed and schedule them. Drafts scheduled by someone other than an owner wait for an owner's approval.</p>
            @if ($aiBlocker)
                <x-ui.alert tone="info" class="mt-4">{{ $aiBlocker }}</x-ui.alert>
            @else
                <form wire:submit="planWithAi" class="mt-4 grid gap-4 sm:grid-cols-[12rem_10rem_minmax(0,1fr)]">
                    <div>
                        <label for="plan-month" class="sh-label">Month</label>
                        <select id="plan-month" wire:model="planMonth" class="sh-input">
                            @foreach ($planMonths as $m)<option value="{{ $m->format('Y-m') }}">{{ $m->format('F Y') }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="plan-per-week" class="sh-label">Posts a week</label>
                        <select id="plan-per-week" wire:model="planPerWeek" class="sh-input">
                            @foreach (range(1, (int) config('social.ai.max_posts_per_week', 5)) as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label for="plan-focus" class="sh-label">Anything to focus on? <span class="font-normal text-subtle">(optional)</span></label>
                        <input id="plan-focus" type="text" wire:model="planFocus" maxlength="1000" class="sh-input" placeholder="e.g. spring AC tune-ups, our new technician Sam, the Riverside fair on the 14th">
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-3">
                        <x-ui.button type="submit" icon="sparkles" wire:loading.attr="disabled" wire:target="planWithAi">Write the drafts</x-ui.button>
                        <x-ui.button type="button" variant="ghost" wire:click="$set('planning', false)">Cancel</x-ui.button>
                        @error('planMonth') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                </form>
            @endif
        </x-ui.card>
    @endif

    @if ($lastPlan)
        @php($planLabel = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', ($lastPlan->input['month'] ?? now()->format('Y-m')).'-01')->format('F Y'))
        @if (in_array($lastPlan->status, ['queued', 'running'], true))
            <div wire:poll.4s>
                <x-ui.alert tone="info" class="mb-6">The AI is writing your posts for {{ $planLabel }}. This takes a minute or two; they'll appear in the calendar.</x-ui.alert>
            </div>
        @elseif ($lastPlan->status === 'failed')
            <x-ui.alert tone="warning" class="mb-6">The month plan for {{ $planLabel }} didn't work: {{ $lastPlan->error }}</x-ui.alert>
        @elseif ($lastPlan->updated_at->greaterThan(now()->subMinutes(30)))
            <x-ui.alert tone="success" class="mb-6">{{ $lastPlan->output['drafts'] ?? 0 }} AI {{ str('draft')->plural($lastPlan->output['drafts'] ?? 0) }} added for {{ $planLabel }}. Open each one to check it, add a photo and schedule it.</x-ui.alert>
        @endif
    @endif

    {{-- Last-minute openings (D69) --}}
    @if ($openings !== [])
        <x-ui.card class="mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-ink">Last-minute openings</h2>
                    <p class="mt-1 text-sm text-muted">Free times in the next few days. A quick post can fill them.</p>
                    <ul class="mt-3 space-y-1 text-sm">
                        @foreach ($openings as $day)
                            <li><span class="font-medium text-ink">{{ $day['date']->isToday() ? 'Today' : ($day['date']->isTomorrow() ? 'Tomorrow' : $day['date']->format('l')) }}</span>
                                <span class="text-muted">{{ implode(', ', array_map(fn ($t) => $t->format('g:i A'), $day['times'])) }}</span></li>
                        @endforeach
                    </ul>
                </div>
                <x-ui.button icon="megaphone" :href="$this->socialRoute('posts.create', ['openings' => 1])">Post about these openings</x-ui.button>
            </div>
        </x-ui.card>
    @endif

    @unless ($hasAccounts)
        <x-ui.alert tone="info" class="mb-6" title="Connect your accounts to start posting">
            You can plan drafts now. <a href="{{ $this->socialRoute('accounts') }}" class="font-semibold underline underline-offset-2">Connect Facebook, Instagram, LinkedIn or Google</a> to publish them.
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
            {{-- Phones: a seven-column table can't fit, so a small month grid marks the days with posts
                 and the posts follow as a list, day by day. --}}
            <div class="sm:hidden">
                <div class="grid grid-cols-7 gap-1 px-3 pt-3 text-center text-[0.6875rem] text-subtle" aria-hidden="true">
                    @foreach (['M', 'T', 'W', 'T', 'F', 'S', 'S'] as $d)<span>{{ $d }}</span>@endforeach
                </div>
                <div class="grid grid-cols-7 gap-1 px-3 pt-1 pb-3">
                    @foreach ($weeks as $week)
                        @foreach ($week as $day)
                            @php($key = $day->format('Y-m-d'))
                            @php($items = $day->month === $monthStart->month ? $byDay->get($key, collect()) : collect())
                            @php($dayClasses = ['flex h-11 flex-col items-center justify-center gap-0.5 rounded-lg text-xs', 'text-subtle/50' => $day->month !== $monthStart->month, 'bg-brand-600 font-semibold text-white' => $key === $today, 'bg-surface-2 text-ink' => $items->isNotEmpty() && $key !== $today, 'text-muted' => $items->isEmpty() && $key !== $today && $day->month === $monthStart->month])
                            @if ($items->isNotEmpty())
                                <a href="#social-day-{{ $key }}" @class($dayClasses) aria-label="{{ $day->format('j F') }}: {{ $items->count() }} {{ Str::plural('post', $items->count()) }}">
                            @else
                                <span @class($dayClasses)>
                            @endif
                                <span>{{ $day->day }}</span>
                                <span class="flex h-1.5 gap-0.5" aria-hidden="true">
                                    @foreach ($items->flatMap(fn ($p) => $p->targets->pluck('account.network'))->filter()->unique()->take(3) as $n)
                                        <span class="size-1.5 rounded-full" style="background: {{ $n->color() }}"></span>
                                    @endforeach
                                </span>
                            @if ($items->isNotEmpty())</a>@else</span>@endif
                        @endforeach
                    @endforeach
                </div>

                @php($monthDays = $byDay->filter(fn ($items, $key) => str_starts_with($key, $monthStart->format('Y-m')))->sortKeys())
                @if ($monthDays->isEmpty())
                    <p class="border-t border-line px-4 py-6 text-center text-sm text-muted">No posts in {{ $monthStart->format('F') }} yet.</p>
                @else
                    <div class="divide-y divide-line border-t border-line">
                        @foreach ($monthDays as $key => $items)
                            @php($day = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $key))
                            <section id="social-day-{{ $key }}" class="scroll-mt-20 px-4 py-3">
                                <h3 class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-subtle">
                                    {{ $day->format('D j M') }}
                                    @if ($key === $today)<x-ui.badge tone="brand">Today</x-ui.badge>@endif
                                </h3>
                                <ul class="space-y-2">
                                    @foreach ($items->sortBy(fn ($p) => $p->scheduled_at ?? $p->published_at) as $p)
                                        <li>
                                            <a href="{{ $this->socialRoute('posts.edit', $p) }}" class="block rounded-lg bg-surface-2 px-3 py-2 ring-1 ring-line hover:ring-brand-400">
                                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-subtle">
                                                    @foreach ($p->targets->pluck('account.network')->filter()->unique() as $n)<span class="inline-block size-2 rounded-full" style="background: {{ $n->color() }}" title="{{ $n->label() }}"></span>@endforeach
                                                    <span>{{ ($p->scheduled_at ?? $p->published_at)?->setTimezone($timezone)->format('g:ia') }}</span>
                                                    <span>· {{ $p->status->value === 'draft' && $p->source === 'ai' ? 'AI draft' : $p->status->label() }}</span>
                                                </span>
                                                <span class="mt-1 block text-sm text-ink">{{ $p->excerpt(90) }}</span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="hidden overflow-x-auto sm:block">
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
                                                <a href="{{ $this->socialRoute('posts.create', ['date' => $key]) }}" class="rounded px-1 text-subtle hover:text-ink" aria-label="New post on {{ $day->format('j F') }}">+</a>
                                            @endif
                                        </div>
                                        <ul class="mt-1 space-y-1">
                                            @foreach ($items->take(3) as $p)
                                                <li>
                                                    <a href="{{ $this->socialRoute('posts.edit', $p) }}" class="block truncate rounded-md bg-surface-2 px-1.5 py-1 text-xs text-ink ring-1 ring-line hover:ring-brand-400" title="{{ $p->aiLabel() ? $p->aiLabel().' · ' : '' }}{{ $p->status->label() }}: {{ $p->excerpt(200) }}">
                                                        @foreach ($p->targets->pluck('account.network')->filter()->unique() as $n)<span class="mr-0.5 inline-block size-2 rounded-full" style="background: {{ $n->color() }}"></span>@endforeach
                                                        @if ($p->status->value === 'draft')<span class="font-medium text-subtle">{{ $p->source === 'ai' ? 'AI draft' : 'Draft' }}</span>@endif
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
                                <td><a href="{{ $this->socialRoute('posts.edit', $p) }}" class="font-medium text-ink hover:text-brand-300">{{ $p->excerpt(90) ?: '(photo only)' }}</a></td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($p->targets as $t)
                                            @if ($t->account)<span class="inline-flex items-center gap-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted"><span class="size-2 rounded-full" style="background: {{ $t->account->network->color() }}"></span>{{ $t->account->network->shortLabel() }}</span>@endif
                                        @endforeach
                                    </div>
                                </td>
                                <td><x-ui.badge :tone="$p->status->tone()">{{ $p->status->label() }}</x-ui.badge>@if ($p->aiLabel()) <x-ui.badge tone="info">{{ $p->aiLabel() }}</x-ui.badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <x-ui.pagination :paginator="$posts" />
            @endif
        </x-ui.card>
    @endif
</div>
