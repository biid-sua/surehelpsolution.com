<div>
    <x-ui.page-header title="Social media" description="Connect the pages and profiles you post to. You choose which ones SureHelp may publish on, and you can remove them any time." />
    @include('livewire.client.social._tabs')

    @error('accounts') <x-ui.alert tone="warning" class="mb-6">{{ $message }}</x-ui.alert> @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        @foreach ($connectors as $key => $connector)
            @php($mine = $accounts->filter(fn ($a) => $a->network->connector() === $key))
            <x-ui.card wire:key="con-{{ $key }}">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="text-base font-semibold text-ink">{{ $connector->label() }}</h2>
                    @if ($mine->contains(fn ($a) => $a->needsReconnect()))
                        <x-ui.badge tone="danger">Needs reconnecting</x-ui.badge>
                    @elseif ($mine->isNotEmpty())
                        <x-ui.badge tone="success">Connected</x-ui.badge>
                    @else
                        <x-ui.badge>Not connected</x-ui.badge>
                    @endif
                </div>

                <p class="mt-2 text-sm text-muted">
                    @switch($key)
                        @case('meta') Facebook Pages and the Instagram business or creator accounts linked to them. @break
                        @case('linkedin') Your LinkedIn profile and the Company Pages you administer. @break
                        @case('youtube') Your YouTube channel, for video posts and Shorts. @break
                        @case('x') Your X (Twitter) account. @break
                        @default Posts, offers and events on your Google Business Profile, shown in Search and Maps.
                    @endswitch
                </p>

                @if (! $connector->isConfigured())
                    <p class="mt-4 rounded-lg bg-surface-2 px-3 py-2 text-sm text-muted ring-1 ring-line">@if ($this->socialPortal() === 'admin')Add this network's app keys under <a href="{{ route('admin.connections') }}" class="text-brand-300 hover:underline">Admin › Connections</a> first.@else Coming soon: we're finishing the {{ $connector->label() }} approval.@endif</p>
                @elseif ($canManage)
                    <x-ui.button class="mt-4" size="sm" :variant="$mine->isEmpty() ? 'primary' : 'secondary'" :href="$this->socialRoute('connect', $key)">
                        {{ $mine->isEmpty() ? 'Connect' : ($mine->contains(fn ($a) => $a->needsReconnect()) ? 'Reconnect' : 'Connect more / refresh') }}
                    </x-ui.button>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.card class="mt-6" :padding="false">
        <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-4">
            <h2 class="text-base font-semibold text-ink">Your accounts</h2>
            @if ($limit !== null)
                <span class="text-sm text-muted">{{ $accounts->where('is_enabled', true)->count() }} of {{ $limit }} in use on your plan</span>
            @endif
        </div>
        @if ($accounts->isEmpty())
            <x-ui.empty-state icon="megaphone" title="No accounts connected yet" description="Connect Facebook, Instagram, LinkedIn or Google above. Then plan and schedule posts to all of them from one place." />
        @else
            <ul class="divide-y divide-line">
                @foreach ($accounts as $account)
                    <li wire:key="acc-{{ $account->ulid }}" class="flex flex-wrap items-center gap-4 px-5 py-3">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-full text-xs font-bold text-white" style="background: {{ $account->network->color() }}">
                            @if ($account->avatar_url)<img src="{{ $account->avatar_url }}" alt="" class="size-9 object-cover" referrerpolicy="no-referrer">@else{{ mb_substr($account->network->shortLabel(), 0, 1) }}@endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ $account->displayName() }}</p>
                            <p class="text-xs text-subtle">{{ $account->network->label() }}{{ ($account->meta['kind'] ?? null) === 'person' ? ' · personal profile' : '' }}</p>
                            @if ($account->needsReconnect())
                                <p class="mt-1 text-xs text-danger">We lost access. Reconnect {{ $account->network->connector() === 'meta' ? 'Facebook & Instagram' : $account->network->label() }} to keep posting.</p>
                            @endif
                        </div>
                        @if ($canManage)
                            <label class="flex items-center gap-2 text-sm text-ink">
                                <input type="checkbox" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @checked($account->is_enabled) wire:click="toggle('{{ $account->ulid }}')" @disabled($account->needsReconnect())>
                                Post here
                            </label>
                            <x-ui.button size="sm" variant="ghost" wire:click="remove('{{ $account->ulid }}')" wire:confirm="Remove {{ $account->displayName() }} from SureHelp? Posts already published stay on {{ $account->network->label() }}.">Remove</x-ui.button>
                        @else
                            <x-ui.badge :tone="$account->is_enabled ? 'success' : 'neutral'">{{ $account->is_enabled ? 'In use' : 'Not used' }}</x-ui.badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- Approval, link page and brand voice belong to a business; SureHelp's own accounts use Admin › Social › Schedule (D104). --}}
    @if ($this->socialPortal() === 'app')
    <x-ui.card class="mt-6">
        <h2 class="text-base font-semibold text-ink">Approval</h2>
        <p class="mt-1 text-sm text-muted">Nothing is ever published without a person deciding. Choose whether posts by your managers, by SureHelp's content team or written with AI need an owner's approval first.</p>
        <form wire:submit="saveApproval" class="mt-4 space-y-2">
            @foreach ($modes as $value => $label)
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="radio" value="{{ $value }}" wire:model="approvalMode" class="mt-0.5 size-4 border-line-strong bg-surface-2 text-brand-500" @disabled(! $canSetApproval)>
                    <span>{{ $label }}@if ($value === 'owner') <span class="text-subtle">(recommended)</span>@endif</span>
                </label>
            @endforeach
            <p class="text-xs text-subtle">Drafts planned by AI always need an owner's approval, unless an owner schedules them.</p>
            @if ($canSetApproval)
                <x-ui.button type="submit" size="sm" class="mt-2">Save</x-ui.button>
            @else
                <p class="text-xs text-subtle">Only the business owner can change this.</p>
            @endif
        </form>
    </x-ui.card>

    <x-ui.card class="mt-6" id="link-page">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-ink">Link in bio</h2>
                <p class="mt-1 text-sm text-muted">One page for your Instagram, Facebook or TikTok bio: book online, call, send a message, your website and your own links.</p>
            </div>
            @if ($linkPageEnabled)<x-ui.badge tone="success">Live</x-ui.badge>@else<x-ui.badge>Off</x-ui.badge>@endif
        </div>
        <form wire:submit="saveLinkPage" class="mt-4 space-y-4">
            <label class="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" wire:model="linkPageEnabled" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                Show my link page
            </label>
            <div class="flex flex-wrap items-center gap-2" x-data="{ copied: false, url: @js($linkPageUrl) }">
                <input type="text" readonly value="{{ $linkPageUrl }}" class="sh-input max-w-md font-mono text-xs" aria-label="Link page address" x-on:click="$el.select()">
                <x-ui.button type="button" size="sm" variant="secondary" x-on:click="navigator.clipboard.writeText(url); copied = true; setTimeout(() => copied = false, 2000)"><span x-text="copied ? 'Copied' : 'Copy'">Copy</span></x-ui.button>
                @if ($linkPageEnabled)<a href="{{ $linkPageUrl }}" target="_blank" rel="noopener" class="text-sm text-brand-300 hover:underline">Open</a>@endif
            </div>
            <div>
                <p class="sh-label">Your own links <span class="font-normal text-subtle">(optional, up to {{ \App\Services\Growth\LinkPage::MAX_LINKS }})</span></p>
                <div class="space-y-2">
                    @foreach ($links as $i => $link)
                        <div wire:key="link-{{ $i }}" class="flex flex-wrap gap-2">
                            <input type="text" wire:model="links.{{ $i }}.label" maxlength="60" class="sh-input w-48" placeholder="Button text, e.g. Reviews" aria-label="Button text" @disabled(! $canManage)>
                            <input type="url" wire:model="links.{{ $i }}.url" maxlength="500" class="sh-input min-w-56 flex-1" placeholder="https://" aria-label="Link" @disabled(! $canManage)>
                            @if ($canManage)<x-ui.button type="button" size="sm" variant="ghost" wire:click="removeLink({{ $i }})">Remove</x-ui.button>@endif
                        </div>
                        @error("links.$i.label") <p class="text-sm text-danger">{{ $message }}</p> @enderror
                        @error("links.$i.url") <p class="text-sm text-danger">{{ $message }}</p> @enderror
                    @endforeach
                </div>
                @if ($canManage && count($links) < \App\Services\Growth\LinkPage::MAX_LINKS)
                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="addLink" class="mt-2">Add a link</x-ui.button>
                @endif
            </div>
            <p class="text-xs text-subtle">"Book online" and "Send us a message" appear when they're switched on in your website tools (Website page). Your phone and website come from your business profile.</p>
            @if ($canManage)<x-ui.button type="submit" size="sm">Save</x-ui.button>@endif
        </form>
    </x-ui.card>

    <x-ui.card class="mt-6" id="brand-voice">
        <h2 class="text-base font-semibold text-ink">Brand voice</h2>
        <p class="mt-1 text-sm text-muted">How your posts should sound when you write them with AI: your tone, words you like or avoid, emojis or not, how you sign off.</p>
        <form wire:submit="saveVoice" class="mt-4 space-y-2">
            <label for="brand-voice-text" class="sr-only">Brand voice</label>
            <textarea id="brand-voice-text" wire:model="brandVoice" rows="4" maxlength="1000" class="sh-input" placeholder="e.g. Warm and down-to-earth, like a family business. Say &quot;our team&quot;, not &quot;our technicians&quot;. One emoji at most. Never use the word &quot;cheap&quot;." @disabled(! $canManage)></textarea>
            @error('brandVoice') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            @if ($canManage)<x-ui.button type="submit" size="sm">Save</x-ui.button>@endif
        </form>
    </x-ui.card>
    @endif
</div>
