<div @if ($pending) wire:poll.3s @endif>
    <x-ui.page-header title="Website builder" description="A website we host for you, made from your business details. Ask for changes in plain words, check the preview, then publish." />

    @if (! $site)
        {{-- Start from a template (D99) --}}
        <form wire:submit="create" class="space-y-6">
            <x-ui.card title="1. Choose a look" description="You can change words, pages and colours later by asking.">
                <div class="grid gap-4 md:grid-cols-3" role="radiogroup" aria-label="Templates">
                    @foreach ($templates as $key => $t)
                        <label wire:key="tpl-{{ $key }}" @class(['cursor-pointer rounded-xl border p-4 transition', 'border-brand-500 bg-brand-500/10' => $template === $key, 'border-line bg-surface-2 hover:border-line-strong' => $template !== $key])>
                            <input type="radio" class="sr-only" wire:model.live="template" value="{{ $key }}">
                            <span class="flex items-center gap-2"><span class="size-4 rounded-full" style="background: {{ $t['accent'] }}"></span><span class="font-semibold text-ink">{{ $t['label'] }}</span></span>
                            <span class="mt-2 block text-sm text-muted">{{ $t['description'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error('template') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
            </x-ui.card>

            <x-ui.card title="2. Pick your address" description="Free with SureHelp. You'll be able to connect your own domain later.">
                <label for="sb-sub" class="sh-label">Address</label>
                <div class="flex max-w-xl items-center gap-2">
                    @if (! $domain)<span class="shrink-0 text-sm text-muted">{{ preg_replace('#^https?://#', '', url('/site')) }}/</span>@endif
                    <input id="sb-sub" type="text" wire:model="subdomain" class="sh-input" autocomplete="off" spellcheck="false" maxlength="40">
                    @if ($domain)<span class="shrink-0 text-sm text-muted">.{{ $domain }}</span>@endif
                </div>
                @error('subdomain') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-subtle">Letters, numbers and dashes. Your services, hours, area and reviews are filled in for you.</p>
            </x-ui.card>

            @if ($canManage)
                <div class="flex justify-end"><x-ui.button type="submit" icon="sparkles">Create my website</x-ui.button></div>
            @else
                <x-ui.alert tone="info">Ask the business owner to create the website.</x-ui.alert>
            @endif
        </form>
    @else
        @php
            $live = $site->published !== null;
            $changes = $site->published_version_id !== $draft->id;
            $percent = $tokens['allowance'] > 0 ? min(100, (int) round($tokens['used'] / $tokens['allowance'] * 100)) : 100;
        @endphp

        <x-ui.card class="mb-6">
            <div class="flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2 text-sm text-muted">
                        @if ($live)<x-ui.badge tone="success">Live</x-ui.badge>@else<x-ui.badge tone="neutral">Not published</x-ui.badge>@endif
                        @if ($changes && $live)<x-ui.badge tone="warning">Unpublished changes</x-ui.badge>@endif
                        <span class="truncate">{{ preg_replace('#^https?://#', '', $site->url()) }}</span>
                    </p>
                    <p class="mt-1 text-xs text-subtle">Draft: version {{ $draft->number }}@if ($live) · live: version {{ $site->published->number }}, published {{ $site->published_at?->diffForHumans() }}@endif</p>
                </div>
                @if ($live)<x-ui.button variant="ghost" size="sm" :href="$site->url()" target="_blank" rel="noopener">View live site</x-ui.button>@endif
                @if ($canManage)
                    @if ($changes && $live)<x-ui.button variant="ghost" size="sm" wire:click="discard" wire:confirm="Drop the changes that aren't published yet?">Drop changes</x-ui.button>@endif
                    @if ($changes || ! $live)<x-ui.button size="sm" wire:click="publish" wire:confirm="Publish version {{ $draft->number }}? Visitors will see it straight away.">{{ $live ? 'Publish changes' : 'Publish website' }}</x-ui.button>@endif
                    @if ($live && ! $changes)<x-ui.button variant="ghost" size="sm" wire:click="unpublish" wire:confirm="Take your website offline? Visitors will see a 'not found' page until you publish again.">Take offline</x-ui.button>@endif
                @endif
            </div>
        </x-ui.card>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="min-w-0 xl:col-span-2">
                <x-ui.card :padding="false">
                    <div class="flex flex-wrap items-center gap-1 border-b border-line px-3 py-2" role="tablist" aria-label="Pages">
                        @foreach ($pages as $p)
                            <button type="button" role="tab" wire:key="pg-{{ $p['slug'] ?: 'home' }}" wire:click="$set('page', '{{ $p['slug'] }}')" aria-selected="{{ $page === $p['slug'] ? 'true' : 'false' }}"
                                @class(['rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-brand-600 text-white' => $page === $p['slug'], 'text-muted hover:text-ink' => $page !== $p['slug']])>{{ $p['title'] }}</button>
                        @endforeach
                        <span class="ml-auto text-xs text-subtle">Preview of your draft</span>
                    </div>
                    <iframe wire:key="preview-{{ $draft->id }}-{{ $page ?: 'home' }}" src="{{ route('app.site.preview', array_filter(['page' => $page])) }}" title="Preview of your website"
                        class="block h-[720px] w-full rounded-b-2xl bg-white" loading="lazy"></iframe>
                </x-ui.card>
            </div>

            <div class="space-y-6">
                @if ($canManage)
                    <x-ui.card title="Ask for a change" description="Say what you'd like, like you'd tell a designer. Nothing goes live until you publish.">
                        <form wire:submit="ask" class="space-y-3">
                            <label for="sb-prompt" class="sr-only">What should change?</label>
                            <textarea id="sb-prompt" wire:model="prompt" rows="4" maxlength="2000" class="sh-input" placeholder="e.g. Make the headline about 24/7 emergency repairs" @disabled($pending)></textarea>
                            @error('prompt') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2">
                                @foreach (['Make the headline about 24/7 emergency repairs', 'Add a page about our drain cleaning service', 'Use a dark green colour', 'Add an FAQ about pricing and estimates', 'Make the text friendlier and shorter'] as $example)
                                    <button type="button" wire:click="$set('prompt', @js($example))" class="rounded-full border border-line px-3 py-1 text-xs text-muted hover:border-brand-400 hover:text-ink">{{ $example }}</button>
                                @endforeach
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-xs text-subtle">{{ $page === '' ? 'About the home page, or the whole site' : 'About the page you\'re looking at' }}</span>
                                <x-ui.button type="submit" size="sm" icon="sparkles" :disabled="$pending">{{ $pending ? 'Working…' : 'Make the change' }}</x-ui.button>
                            </div>
                        </form>
                        <div class="mt-4 border-t border-line pt-3">
                            <div class="flex items-baseline justify-between text-xs"><span class="text-muted">AI allowance this month</span><span class="tabular-nums text-ink">{{ $percent }}% used</span></div>
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="AI allowance used">
                                <div @class(['h-full rounded-full', 'bg-brand-500' => $percent < 80, 'bg-warning' => $percent >= 80]) style="width: {{ $percent }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-subtle">Most changes use 1–3% of it. It resets on the 1st.</p>
                            <p class="mt-2 flex items-baseline justify-between gap-2 text-xs">
                                <span class="text-muted">AI credits: <span class="tabular-nums text-ink">{{ number_format($credits) }}</span>@if ($percent >= 100) <span class="text-subtle">(used after the allowance)</span>@endif</span>
                                @can('billing.view', $site->organization)<a href="{{ route('app.billing') }}#credits" class="text-brand-300 hover:underline">Buy credits</a>@endcan
                            </p>
                        </div>
                    </x-ui.card>
                @endif

                @if ($edits->isNotEmpty())
                    <x-ui.card title="Recent requests" :padding="false">
                        <ul class="divide-y divide-line" role="list">
                            @foreach ($edits as $edit)
                                <li class="px-5 py-3" wire:key="edit-{{ $edit->id }}">
                                    <p class="text-sm text-ink">“{{ \Illuminate\Support\Str::limit($edit->prompt, 120) }}”</p>
                                    <p class="mt-1 text-xs">
                                        @switch($edit->status)
                                            @case('queued') @case('running') <span class="text-brand-300">Working on it…</span> @break
                                            @case('done') <span class="text-success">Done.</span> <span class="text-muted">{{ $edit->summary }}</span> @break
                                            @case('nothing') <span class="text-muted">No change made. {{ $edit->summary }}</span> @break
                                            @default <span class="text-danger">{{ $edit->error }}</span>
                                        @endswitch
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif

                {{-- The business's own domain (D101) --}}
                @if ($ownDomains)
                    <x-ui.card title="Your own domain" description="Use your business's web address, like www.yourbusiness.com. Your free address keeps working and sends visitors there.">
                        @if (! $site->custom_domain)
                            @if ($canManage)
                                <form wire:submit="connectDomain" class="space-y-2">
                                    <label for="sb-domain" class="sr-only">Your domain</label>
                                    <input id="sb-domain" type="text" wire:model="ownDomain" class="sh-input" placeholder="www.yourbusiness.com" autocomplete="off" spellcheck="false">
                                    @error('ownDomain') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                                    <div class="flex justify-end"><x-ui.button type="submit" size="sm">Connect domain</x-ui.button></div>
                                </form>
                            @else
                                <p class="text-sm text-muted">Ask the business owner to connect your domain.</p>
                            @endif
                        @else
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                                {{ $site->custom_domain }}
                                @if ($site->domain_status === 'active')<x-ui.badge tone="success">Connected</x-ui.badge>@else<x-ui.badge tone="warning">Waiting</x-ui.badge>@endif
                            </p>
                            @if ($site->domain_status !== 'active' && $domainRecord)
                                <p class="mt-3 text-sm text-muted">At the company you bought the domain from (GoDaddy, Namecheap, Google, …), add this DNS record:</p>
                                <div class="mt-2 overflow-x-auto rounded-lg border border-line">
                                    <table class="w-full text-left text-sm">
                                        <thead class="bg-surface-2 text-xs text-subtle"><tr><th class="px-3 py-2 font-medium">Type</th><th class="px-3 py-2 font-medium">Name / host</th><th class="px-3 py-2 font-medium">Value / points to</th></tr></thead>
                                        <tbody><tr class="font-mono text-ink"><td class="px-3 py-2">{{ $domainRecord['type'] }}</td><td class="px-3 py-2">{{ $domainRecord['name'] }}</td><td class="px-3 py-2 break-all">{{ $domainRecord['value'] }}</td></tr></tbody>
                                    </table>
                                </div>
                                @if ($site->domain_error)<p class="mt-2 text-xs text-muted">{{ $site->domain_error }}</p>@endif
                                <p class="mt-1 text-xs text-subtle">We check every few minutes{{ $site->domain_checked_at ? '; last checked '.$site->domain_checked_at->diffForHumans() : '' }}.</p>
                            @elseif ($site->domain_status === 'active')
                                <p class="mt-1 text-xs text-subtle">Visitors at {{ $site->custom_domain }} see your website, with a secure (https) connection.</p>
                            @endif
                            @if ($canManage)
                                <div class="mt-3 flex justify-end gap-2">
                                    @if ($site->domain_status !== 'active')<x-ui.button variant="ghost" size="sm" wire:click="checkDomain">Check now</x-ui.button>@endif
                                    <x-ui.button variant="ghost" size="sm" wire:click="removeDomain" wire:confirm="Remove {{ $site->custom_domain }}? Your website goes back to its free address.">Remove</x-ui.button>
                                </div>
                            @endif
                        @endif
                    </x-ui.card>
                @endif

                <x-ui.card title="Versions" description="Every change is saved. Go back to any version, then publish it." :padding="false">
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($versions as $v)
                            <li class="flex items-start gap-3 px-5 py-3" wire:key="ver-{{ $v->id }}">
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2 text-sm font-medium text-ink">Version {{ $v->number }}
                                        @if ($v->id === $site->published_version_id)<x-ui.badge tone="success">Live</x-ui.badge>@endif
                                        @if ($v->id === $draft->id)<x-ui.badge tone="info">Draft</x-ui.badge>@endif
                                    </span>
                                    <span class="block truncate text-xs text-muted">{{ $v->note }}</span>
                                    <span class="block text-xs text-subtle">{{ $v->created_at->diffForHumans() }}@if ($v->author) · {{ $v->author->name }}@endif</span>
                                </span>
                                <a href="{{ route('app.site.preview', ['version' => $v->number]) }}" target="_blank" rel="noopener" class="text-xs text-brand-300 hover:underline">Preview</a>
                                @if ($canManage && $v->id !== $draft->id)
                                    <button type="button" wire:click="restore({{ $v->number }})" wire:confirm="Go back to version {{ $v->number }}? It becomes your draft; publish it to make it live." class="text-xs text-muted hover:text-ink">Restore</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            </div>
        </div>
    @endif
</div>
