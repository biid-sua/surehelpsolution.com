<div>
    <x-ui.page-header title="Website" description="Connect your website: one snippet adds chat, booking, click-to-call and a contact form, and we check the site so people and search engines find you." />

    <div class="grid gap-6 lg:grid-cols-5">
        <div class="space-y-6 lg:col-span-3">
            <x-ui.card :padding="false" title="Your websites">
                @if ($sites->isEmpty())
                    <x-ui.empty-state icon="globe" title="No website yet" description="Add your website to check its health and get your snippet working there." />
                @else
                    <ul class="divide-y divide-line" role="list">
                        @foreach ($sites as $site)
                            @php($isOpen = $open === $site->ulid)
                            <li wire:key="site-{{ $site->id }}">
                                <button type="button" wire:click="$set('open', '{{ $isOpen ? '' : $site->ulid }}')" class="flex w-full items-center gap-3 px-5 py-4 text-left hover:bg-surface-2" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium text-ink">{{ $site->host }}</span>
                                        <span class="block text-xs text-subtle">
                                            @if (! $site->isVerified()) Not verified yet
                                            @elseif ($site->last_checked_at) Checked {{ $site->last_checked_at->diffForHumans() }}
                                            @else Verified · first check in progress
                                            @endif
                                        </span>
                                    </span>
                                    @if (! $site->isVerified())
                                        <x-ui.badge tone="warning">Verify</x-ui.badge>
                                    @elseif ($site->health_score !== null)
                                        <x-ui.badge :tone="$site->health_score >= 80 ? 'success' : ($site->health_score >= 60 ? 'warning' : 'danger')">{{ $site->health_score }}/100</x-ui.badge>
                                    @endif
                                </button>

                                @if ($isOpen)
                                    <div class="space-y-4 border-t border-line bg-surface-2/40 px-5 py-4">
                                        @if (! $site->isVerified())
                                            <div>
                                                <p class="text-sm text-ink">Prove this site is yours in one of two ways, then press <span class="font-medium">Verify</span>.</p>
                                                <p class="mt-3 text-sm font-medium text-ink">Option 1: add this tag to your home page, inside &lt;head&gt;</p>
                                                <code class="mt-1 block overflow-x-auto rounded-lg bg-canvas p-3 text-xs text-muted">{{ $site->metaTag() }}</code>
                                                <p class="mt-3 text-sm font-medium text-ink">Option 2: add a TXT record to your domain's DNS</p>
                                                <code class="mt-1 block overflow-x-auto rounded-lg bg-canvas p-3 text-xs text-muted">{{ $site->dnsRecord() }}</code>
                                                <p class="mt-1 text-xs text-subtle">Most site builders (Wix, Squarespace, WordPress) have a "verification code" or "header code" setting for option 1.</p>
                                                @if ($site->last_error)<p class="mt-2 text-sm text-danger">{{ $site->last_error }}</p>@endif
                                            </div>
                                        @elseif ($site->last_error && ! $site->health)
                                            <x-ui.alert tone="warning">{{ $site->last_error }}</x-ui.alert>
                                        @elseif ($site->health)
                                            @php($findings = collect($site->health['findings'] ?? []))
                                            @if ($site->last_error)<x-ui.alert tone="warning">Last check failed: {{ $site->last_error }} These are the results from the check before.</x-ui.alert>@endif
                                            @foreach (['problem' => ['Fix soon', 'danger'], 'warning' => ['Worth fixing', 'warning']] as $status => [$heading, $tone])
                                                @if ($findings->where('status', $status)->isNotEmpty())
                                                    <div>
                                                        <h3 class="text-sm font-semibold text-ink">{{ $heading }}</h3>
                                                        <ul class="mt-2 space-y-3" role="list">
                                                            @foreach ($findings->where('status', $status) as $f)
                                                                <li class="rounded-xl border border-line bg-surface p-3">
                                                                    <p class="flex items-start gap-2 text-sm font-medium text-ink"><x-ui.badge :tone="$tone">{{ $status === 'problem' ? 'Problem' : 'Tip' }}</x-ui.badge> {{ $f['title'] }}</p>
                                                                    <p class="mt-1 text-sm text-muted">{{ $f['detail'] }}</p>
                                                                    @if (! empty($f['pages']))
                                                                        <details class="mt-1 text-xs text-subtle"><summary class="cursor-pointer">Where ({{ count($f['pages']) }})</summary>
                                                                            <ul class="mt-1 space-y-0.5">@foreach ($f['pages'] as $p)<li class="truncate">{{ $p }}</li>@endforeach</ul>
                                                                        </details>
                                                                    @endif
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    </div>
                                                @endif
                                            @endforeach
                                            @if ($findings->where('status', 'good')->isNotEmpty())
                                                <div>
                                                    <h3 class="text-sm font-semibold text-ink">Looking good</h3>
                                                    <ul class="mt-2 flex flex-wrap gap-2" role="list">
                                                        @foreach ($findings->where('status', 'good') as $f)<li><x-ui.badge tone="success">✓ {{ $f['title'] }}</x-ui.badge></li>@endforeach
                                                    </ul>
                                                </div>
                                            @endif
                                            <p class="text-xs text-subtle">We read {{ count($site->health['checked_pages'] ?? []) }} {{ str('page')->plural(count($site->health['checked_pages'] ?? [])) }} and check again every month. These are tips from what we can see on your pages; nobody can promise a search ranking.</p>
                                        @else
                                            <p class="text-sm text-muted">Your first check is running. Refresh in a minute or two.</p>
                                        @endif

                                        @if ($canManage)
                                            <div class="flex flex-wrap gap-2">
                                                @if (! $site->isVerified())
                                                    <x-ui.button size="sm" wire:click="verify('{{ $site->ulid }}')">Verify</x-ui.button>
                                                @else
                                                    <x-ui.button size="sm" variant="secondary" icon="refresh" wire:click="check('{{ $site->ulid }}')">Check now</x-ui.button>
                                                @endif
                                                <x-ui.confirm id="remove-site-{{ $site->id }}" title="Remove this website?" confirm-label="Remove" :action="'remove('.$site->id.')'">
                                                    <x-slot:trigger><x-ui.button size="sm" variant="ghost">Remove</x-ui.button></x-slot:trigger>
                                                    Its checks are deleted. Your snippet keeps working on the site until you take it off.
                                                </x-ui.confirm>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <form wire:submit="add" class="flex flex-wrap items-start gap-3 border-t border-line px-5 py-4">
                        <div class="min-w-56 flex-1">
                            <label for="w-url" class="sr-only">Website address</label>
                            <input id="w-url" type="text" wire:model="newUrl" class="sh-input" placeholder="riverplumbing.com" autocomplete="url">
                            @error('newUrl') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <x-ui.button type="submit" icon="globe">Add website</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Your snippet" description="Paste it once into your site's footer or header code. It works on every page.">
                <code class="block overflow-x-auto rounded-lg bg-canvas p-3 text-xs text-muted" x-data x-ref="snippet">{{ $widget->snippet() }}</code>
                <div class="mt-2 flex items-center gap-3" x-data="{ copied: false }">
                    <x-ui.button size="sm" variant="secondary" x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($widget->snippet()) }}); copied = true; setTimeout(() => copied = false, 2000)">Copy</x-ui.button>
                    <span class="text-xs text-emerald-300" x-show="copied" x-cloak>Copied</span>
                </div>
                @if (! $widget->is_enabled)<p class="mt-3 text-sm text-amber-300">The snippet is switched off under Inbox › Channels.</p>@endif
            </x-ui.card>

            <x-ui.card title="What the snippet shows" description="Visitors see one button; it opens the parts you switch on.">
                <form wire:submit="saveFeatures" class="space-y-3">
                    @foreach ($featureLabels as $key => $label)
                        <label class="flex items-start gap-3 text-sm">
                            <input type="checkbox" wire:model="features.{{ $key }}" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                            <span>
                                <span class="font-medium text-ink">{{ $label }}</span>
                                <span class="block text-xs text-subtle">{{ [
                                    'chat' => 'Visitors message you; replies come from your Inbox (or the AI assistant, if on).',
                                    'booking' => 'Visitors pick a service and an open time, using your hours, services and calendar.',
                                    'call' => 'A tap-to-call button with your business phone number.',
                                    'lead' => 'Name, phone or email and a message. It becomes a follow-up task and a customer record.',
                                ][$key] }}</span>
                            </span>
                        </label>
                    @endforeach
                    <label class="flex items-start gap-3 rounded-lg bg-surface-2 p-3 text-sm">
                        <input type="checkbox" wire:model="needsConfirmation" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                        <span><span class="font-medium text-ink">I confirm website bookings myself</span>
                            <span class="block text-xs text-subtle">On: bookings arrive as requests you confirm. Off: they're confirmed straight away.</span></span>
                    </label>
                    @if ($canManage)<x-ui.button type="submit" size="sm">Save</x-ui.button>@endif
                </form>
            </x-ui.card>
        </div>
    </div>
</div>
