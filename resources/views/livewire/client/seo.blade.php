@php
    $fmt = fn ($v, int $decimals = 0) => $v === null ? '—' : number_format((float) $v, $decimals);
    $copy = fn (string $text) => 'navigator.clipboard.writeText('.\Illuminate\Support\Js::from($text).'); $dispatch(\'toast\', { type: \'success\', message: \'Copied\' })';
@endphp
<div @if ($busy) wire:poll.5s @endif>
    <x-ui.page-header title="SEO" description="We improve how your business shows up in Google, with AI doing the heavy lifting, and show you the difference it makes." />

    @if (session('status'))<x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>@endif
    @if (session('error'))<x-ui.alert tone="danger" class="mb-6">{{ session('error') }}</x-ui.alert>@endif

    @unless ($on)
        <x-ui.card class="mb-6" title="Take our SEO service">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <ul class="space-y-2 text-sm text-ink">
                    <li>✓ <span class="font-medium">A keyword plan</span>: the searches your customers make, and which page should win each one.</li>
                    <li>✓ <span class="font-medium">Better titles and descriptions</span> for every page we check, so more searchers click.</li>
                    <li>✓ <span class="font-medium">Local service pages, FAQs with search markup, and Google Business Profile posts</span>, ready to publish.</li>
                    <li>✓ <span class="font-medium">Every month on autopilot</span>, using your monthly AI allowance, and on demand whenever you want more.</li>
                    <li>✓ <span class="font-medium">Before-and-after results</span> from Google Search Console, your website health and your website leads.</li>
                </ul>
                <div class="rounded-xl bg-surface-2 p-4">
                    @if ($addon)
                        <p class="text-2xl font-semibold text-ink">{{ \App\Support\Money::format($addon->price_cents, $addon->currency) }}<span class="text-sm font-normal text-muted"> / month</span></p>
                        <p class="mt-1 text-xs text-subtle">Added to your next invoice. Switch it off any time under Billing.</p>
                        @if ($canBill)
                            <x-ui.button class="mt-4 w-full" wire:click="turnOn" wire:loading.attr="disabled">Turn on the SEO service</x-ui.button>
                        @else
                            <p class="mt-4 text-sm text-muted">Ask your business owner to turn it on under Billing.</p>
                        @endif
                    @else
                        <p class="text-sm text-muted">Talk to us about adding SEO to your plan.</p>
                        <x-ui.button class="mt-4 w-full" variant="secondary" :href="route('app.support')">Ask SureHelp</x-ui.button>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @endunless

    <div class="mb-6 flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="SEO sections">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class([
                '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
                'border-brand-400 text-ink' => $tab === $key,
                'border-transparent text-muted hover:text-ink' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
        <span class="ml-auto hidden self-center whitespace-nowrap pb-1 text-xs text-subtle md:inline" title="AI tokens are what the AI reads and writes. A typical task uses 5,000–20,000.">
            AI allowance this month: {{ number_format($tokens['used']) }} of {{ number_format($tokens['allowance']) }} tokens · AI credits: {{ number_format($tokens['credits']) }}
            @can('billing.view', $organization)<a href="{{ route('app.billing') }}#credits" class="ml-1 text-brand-300 hover:underline">Buy credits</a>@endcan
        </span>
    </div>
    <p class="-mt-4 mb-4 text-xs text-subtle md:hidden">AI allowance this month: {{ number_format($tokens['used']) }} of {{ number_format($tokens['allowance']) }} tokens · AI credits: {{ number_format($tokens['credits']) }}</p>

    {{-- Results: before and after --}}
    @if ($tab === 'overview')
        @if (! $stats)
            <x-ui.card><x-ui.empty-state icon="chart" title="No results yet" description="Once the SEO service is on and your first changes are live, the difference shows here." /></x-ui.card>
        @else
            <p class="mb-4 text-sm text-muted">
                @if ($stats['started'])
                    <span class="font-medium text-ink">Before</span> is the {{ \App\Services\Seo\SeoStats::WINDOW }} days before SEO work started ({{ $stats['started']->format('M j, Y') }}); <span class="font-medium text-ink">now</span> is the latest {{ \App\Services\Seo\SeoStats::WINDOW }} days.
                @else
                    The latest {{ \App\Services\Seo\SeoStats::WINDOW }} days. Once SEO work starts, we compare against the 28 days before it.
                @endif
            </p>
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                @foreach ($stats['tiles'] as $key => $t)
                    @php
                        $decimals = $key === 'position' ? 1 : 0;
                        $delta = $t['now'] !== null && $t['before'] !== null ? $t['now'] - $t['before'] : null;
                        $good = $delta === null || $delta == 0 ? null : (($t['better'] === 'up') === ($delta > 0));
                    @endphp
                    <div class="rounded-[var(--radius-card)] border border-line bg-surface p-4">
                        <p class="text-xs font-medium text-muted">{{ $t['label'] }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ $fmt($t['now'], $decimals) }}{{ $key === 'health' && $t['now'] !== null ? '/100' : '' }}</p>
                        <p class="mt-1 text-xs text-subtle">
                            @if ($t['before'] !== null)
                                before: {{ $fmt($t['before'], $decimals) }}
                                @if ($good !== null)
                                    <span @class(['ml-1 font-medium', 'text-emerald-300' => $good, 'text-red-300' => ! $good])>{{ $good ? '▲ better' : '▼ worse' }}</span>
                                @endif
                            @elseif ($key === 'position')
                                lower is better (1 = top)
                            @else
                                no earlier figure
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>

            @if ($stats['has_search_data'])
                @php $w = $stats['weekly']; @endphp
                <div class="mt-6 grid gap-6 lg:grid-cols-2">
                    @foreach (['clicks' => ['Clicks from Google per week', 'People who clicked through to your site.'], 'impressions' => ['Times shown in Google per week', 'How often your site appeared in search results.']] as $key => [$title, $desc])
                        <x-ui.card :title="$title" :description="$desc">
                            <div class="h-56" wire:ignore x-data="chart({ type: 'line', labels: @js($w['labels']), series: [{ label: @js($title), data: @js($w[$key]) }], markers: @js($w['markers']) })">
                                <canvas x-ref="canvas" role="img" aria-label="{{ $title }}, last {{ \App\Services\Seo\SeoStats::WEEKS }} weeks"></canvas>
                            </div>
                        </x-ui.card>
                    @endforeach
                    <x-ui.card title="Average position in Google per week" description="Higher on the chart is better: position 1 is the top of the results.">
                        <div class="h-56" wire:ignore x-data="chart({ type: 'line', labels: @js($w['labels']), series: [{ label: 'Average position', data: @js($w['position']) }], markers: @js($w['markers']), reverse: true, precision: 1 })">
                            <canvas x-ref="canvas" role="img" aria-label="Average position per week"></canvas>
                        </div>
                    </x-ui.card>
                    @if (count($stats['health_series']) > 1)
                        <x-ui.card title="Website health over time" description="From our regular checks of your site (out of 100).">
                            <div class="h-56" wire:ignore x-data="chart({ type: 'line', labels: @js(array_column($stats['health_series'], 'label')), series: [{ label: 'Website health', data: @js(array_column($stats['health_series'], 'value')) }] })">
                                <canvas x-ref="canvas" role="img" aria-label="Website health score over time"></canvas>
                            </div>
                        </x-ui.card>
                    @endif
                </div>
                @if ($w['markers'])<p class="mt-2 text-xs text-subtle">Dashed lines mark the weeks SEO changes went live on your site. Search results usually take 2–8 weeks to respond.</p>@endif
                @if ($connection?->top_queries)
                    <x-ui.card class="mt-6" :padding="false" title="What people searched to find you" description="Last 28 days of Google Search Console data.">
                        <x-ui.table>
                            <thead><tr><th scope="col">Search</th><th scope="col" class="text-right">Clicks</th><th scope="col" class="text-right">Shown</th><th scope="col" class="text-right">Position</th></tr></thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($connection->top_queries as $q)
                                    <tr><td class="text-ink">{{ $q['query'] }}</td><td class="text-right tabular-nums">{{ number_format($q['clicks']) }}</td><td class="text-right tabular-nums text-muted">{{ number_format($q['impressions']) }}</td><td class="text-right tabular-nums text-muted">{{ number_format($q['position'], 1) }}</td></tr>
                                @endforeach
                            </tbody>
                        </x-ui.table>
                    </x-ui.card>
                @endif
            @else
                <x-ui.card class="mt-6">
                    <x-ui.empty-state icon="chart" title="Connect Google Search Console to see clicks and rankings"
                        description="It's free from Google and shows how often your site appears in searches, how often people click, and your position. We only read it.">
                        @if ($canManage)<x-ui.button variant="secondary" wire:click="$set('tab', 'settings')">Connect it</x-ui.button>@endif
                    </x-ui.empty-state>
                </x-ui.card>
            @endif
        @endif
    @endif

    {{-- SEO work --}}
    @if ($tab === 'work')
        <div class="grid gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">
            <div class="space-y-4">
                @if ($on && $canManage)
                    <x-ui.card title="Run a task" description="Each one uses some of this month's AI allowance, then AI credits once it's used up.">
                        <div class="space-y-4">
                            @foreach ($types as $type => $def)
                                <div class="rounded-xl border border-line p-3" wire:key="run-{{ $type }}">
                                    <p class="text-sm font-medium text-ink">{{ $def['label'] }}</p>
                                    <p class="text-xs text-subtle">{{ $def['description'] }}</p>
                                    @if ($type === 'keyword_plan')
                                        <label class="sr-only" for="in-focus">Focus (optional)</label>
                                        <input id="in-focus" type="text" wire:model="input.focus" class="sh-input mt-2 py-1.5 text-sm" placeholder="Focus (optional), e.g. emergency repairs">
                                    @elseif ($type === 'local_page')
                                        <div class="mt-2 grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="sr-only" for="in-service">Service</label>
                                                <input id="in-service" type="text" wire:model="input.service" list="seo-services" class="sh-input py-1.5 text-sm" placeholder="Service">
                                                <datalist id="seo-services">@foreach ($services as $s)<option value="{{ $s }}">@endforeach</datalist>
                                            </div>
                                            <div>
                                                <label class="sr-only" for="in-area">Town or area</label>
                                                <input id="in-area" type="text" wire:model="input.area" class="sh-input py-1.5 text-sm" placeholder="Town or area">
                                            </div>
                                        </div>
                                        @error('input.service') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                                        @error('input.area') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                                    @elseif ($type === 'faq')
                                        <label class="sr-only" for="in-topic">Topic (optional)</label>
                                        <input id="in-topic" type="text" wire:model="input.topic" class="sh-input mt-2 py-1.5 text-sm" placeholder="Topic (optional)">
                                    @endif
                                    @if ($blockers[$type] ?? null)
                                        <p class="mt-2 text-xs text-amber-300">{{ $blockers[$type] }}</p>
                                    @else
                                        <x-ui.button size="sm" class="mt-2" wire:click="run('{{ $type }}')" wire:loading.attr="disabled" wire:target="run">Run</x-ui.button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </x-ui.card>
                @endif

                <x-ui.card :padding="false" title="History">
                    @if ($tasks->isEmpty())
                        <p class="px-5 py-4 text-sm text-muted">Nothing yet.</p>
                    @else
                        <ul class="divide-y divide-line" role="list">
                            @foreach ($tasks as $t)
                                <li wire:key="t-{{ $t->id }}">
                                    <button type="button" wire:click="$set('selected', '{{ $t->ulid }}')" @class(['block w-full px-5 py-3 text-left hover:bg-surface-2', 'bg-surface-2' => $current?->id === $t->id])>
                                        <span class="flex items-center justify-between gap-2">
                                            <span class="truncate text-sm font-medium text-ink">{{ $types[$t->type]['label'] ?? $t->type }}{{ ! empty($t->input['area']) ? ' · '.$t->input['area'] : '' }}</span>
                                            @if (in_array($t->status, ['queued', 'running'], true))
                                                <x-ui.badge tone="info">Working…</x-ui.badge>
                                            @elseif ($t->status === 'failed')
                                                <x-ui.badge tone="danger">Didn't work</x-ui.badge>
                                            @elseif ($t->applied_at)
                                                <x-ui.badge tone="success">Live</x-ui.badge>
                                            @elseif ($t->review === 'approved')
                                                <x-ui.badge tone="brand">Approved</x-ui.badge>
                                            @elseif ($t->review === 'dismissed')
                                                <x-ui.badge tone="neutral">Set aside</x-ui.badge>
                                            @else
                                                <x-ui.badge tone="warning">To review</x-ui.badge>
                                            @endif
                                        </span>
                                        <span class="mt-0.5 block text-xs text-subtle">{{ $t->created_at->diffForHumans() }}{{ $t->source === 'autopilot' ? ' · autopilot' : '' }}{{ $t->tokens() ? ' · '.number_format($t->tokens()).' tokens' : '' }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
            </div>

            <div>
                @if (! $current)
                    <x-ui.card><x-ui.empty-state icon="sparkles" title="Pick a task" description="Run one on the left, or open one from the history." /></x-ui.card>
                @else
                    <x-ui.card :title="$types[$current->type]['label'] ?? $current->type">
                        @if (in_array($current->status, ['queued', 'running'], true))
                            <p class="text-sm text-muted">The AI is working on this. It usually takes under a minute; this page updates by itself.</p>
                        @elseif ($current->status === 'failed')
                            <x-ui.alert tone="danger">{{ $current->error }}</x-ui.alert>
                        @else
                            @include('partials.seo.result')

                            @if ($canManage)
                                <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                                    @if ($current->review !== 'approved')<x-ui.button size="sm" wire:click="review('approved')">Approve</x-ui.button>@endif
                                    @if ($current->review === null)<x-ui.button size="sm" variant="ghost" wire:click="review('dismissed')">Set aside</x-ui.button>@endif
                                    <x-ui.button size="sm" variant="secondary" wire:click="markLive">{{ $current->applied_at ? 'Not live after all' : 'Mark as live on my site' }}</x-ui.button>
                                    <p class="text-xs text-subtle">
                                        @if ($current->applied_at) Live since {{ $current->applied_at->format('M j, Y') }}: marked on your results charts.
                                        @else Approved work is put on your site by our team, or by you. Marking it live adds it to your results charts.
                                        @endif
                                    </p>
                                </div>
                            @endif
                            <p class="mt-3 text-xs text-subtle">Written by AI from your business details{{ $current->model ? ' ('.$current->model.')' : '' }}, {{ number_format($current->tokens()) }} tokens. Check facts before publishing.</p>
                        @endif
                    </x-ui.card>
                @endif
            </div>
        </div>
    @endif

    {{-- Settings --}}
    @if ($tab === 'settings')
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card title="What to focus on">
                <form wire:submit="saveSettings" class="space-y-4">
                    <div>
                        <label for="s-areas" class="sh-label">Towns and areas you want customers from</label>
                        <input id="s-areas" type="text" wire:model="areas" class="sh-input" placeholder="Austin, Round Rock, Pflugerville" @disabled(! $canManage)>
                        @error('areas') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="s-keywords" class="sh-label">Searches you want to be found for (optional)</label>
                        <input id="s-keywords" type="text" wire:model="keywords" class="sh-input" placeholder="emergency plumber, water heater repair" @disabled(! $canManage)>
                    </div>
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" wire:model="autopilot" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                        <span><span class="font-medium text-ink">Autopilot</span>
                            <span class="block text-xs text-subtle">On the 1st of each month: a fresh keyword plan every three months, new titles and descriptions when our website check finds problems, and the month's Google Business Profile posts. Uses your monthly AI allowance.</span></span>
                    </label>
                    @if ($canManage)<x-ui.button type="submit" size="sm">Save</x-ui.button>@endif
                </form>
            </x-ui.card>

            <x-ui.card title="Google Search Console" description="Free from Google. It's where your clicks, rankings and searches come from. We only read it.">
                @if ($connection)
                    <p class="text-sm text-ink">Connected as {{ $connection->account_email }}
                        @if ($connection->status !== 'active')<x-ui.badge tone="danger">Reconnect</x-ui.badge>@endif
                    </p>
                    <p class="mt-1 text-xs text-subtle">{{ $connection->last_synced_at ? 'Updated '.$connection->last_synced_at->diffForHumans() : 'Fetching your statistics…' }}{{ $connection->last_error ? ' · '.$connection->last_error : '' }}</p>
                    @if ($connection->sites)
                        <label for="s-site" class="sh-label mt-4">Site to follow</label>
                        <select id="s-site" class="sh-input" x-on:change="$wire.chooseSite($event.target.value)" @disabled(! $canManage)>
                            <option value="" @selected(! $connection->site_url)>Choose…</option>
                            @foreach ($connection->sites as $site)<option value="{{ $site['url'] }}" @selected($connection->site_url === $site['url'])>{{ $site['url'] }}</option>@endforeach
                        </select>
                    @else
                        <p class="mt-3 text-sm text-amber-300">This Google account has no sites in Search Console. Add your site at search.google.com/search-console, then reconnect.</p>
                    @endif
                    @if ($canManage)
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($connection->status !== 'active')<x-ui.button size="sm" :href="route('app.seo.search-console.connect')">Reconnect</x-ui.button>@endif
                            <form method="POST" action="{{ route('app.seo.search-console.disconnect') }}">@csrf<x-ui.button type="submit" size="sm" variant="ghost">Disconnect</x-ui.button></form>
                        </div>
                    @endif
                @elseif (! $consoleAvailable)
                    <p class="text-sm text-muted">Coming soon: we're finishing the Google setup.</p>
                @elseif ($canManage)
                    <x-ui.button :href="route('app.seo.search-console.connect')">Connect Google Search Console</x-ui.button>
                @else
                    <p class="text-sm text-muted">Not connected.</p>
                @endif
            </x-ui.card>
        </div>
    @endif
</div>
