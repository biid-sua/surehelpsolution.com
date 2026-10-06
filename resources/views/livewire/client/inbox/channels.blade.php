<div>
    <x-ui.page-header title="Inbox" description="Choose where customers can message you. Everything arrives in one inbox, and your AI assistant can answer on any of them." />
    @include('livewire.client.inbox._tabs')

    <div class="grid gap-6 xl:grid-cols-2">
        <x-ui.card title="Website chat" description="A chat button on your website. Works on any site: WordPress, Wix, Squarespace or your own.">
            <div class="mb-4">
                <p class="sh-label">Add this line to your website, just before &lt;/body&gt;</p>
                <div x-data="{ copied: false }" class="flex gap-2">
                    <input type="text" readonly value="{{ $chat->snippet() }}" class="sh-input font-mono text-xs" aria-label="Website snippet" onclick="this.select()">
                    <x-ui.button variant="secondary" x-on:click="navigator.clipboard.writeText(@js($chat->snippet())); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </x-ui.button>
                </div>
            </div>

            <form wire:submit="saveWidget" class="space-y-4">
                <fieldset @disabled(! $canManage) class="space-y-4">
                    <label class="flex items-center gap-3 text-sm font-medium text-ink">
                        <input type="checkbox" wire:model="widget.is_enabled" class="size-5 rounded border-line-strong bg-surface-2 text-brand-500">
                        Show the chat on my website
                    </label>
                    <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                        <div>
                            <label for="w-title" class="sh-label">Title</label>
                            <input id="w-title" type="text" wire:model="widget.title" class="sh-input" maxlength="60">
                            @error('widget.title') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="w-color" class="sh-label">Colour</label>
                            <input id="w-color" type="color" wire:model="widget.color" class="h-10 w-full cursor-pointer rounded-lg border border-line-strong bg-surface-2">
                            @error('widget.color') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label for="w-greeting" class="sh-label">Greeting</label>
                        <input id="w-greeting" type="text" wire:model="widget.greeting" class="sh-input" maxlength="300" placeholder="Hi! How can we help? We'll reply right here.">
                    </div>
                    <div>
                        <label for="w-origins" class="sh-label">Only on these websites <span class="font-normal text-subtle">(one per line; empty = any)</span></label>
                        <textarea id="w-origins" wire:model="widget.allowed_origins" rows="2" class="sh-input font-mono text-sm" placeholder="brightplumbing.com"></textarea>
                        <p class="mt-1 text-xs text-subtle">Subdomains are included. Stops other sites from showing your chat.</p>
                    </div>
                </fieldset>
                @if ($canManage)<x-ui.button type="submit">Save</x-ui.button>@endif
            </form>
        </x-ui.card>

        <x-ui.card title="Messenger and Instagram" description="Direct messages to your Facebook Page and Instagram account.">
            @if (! $meta->isConfigured())
                <p class="rounded-lg bg-surface-2 px-3 py-2 text-sm text-muted ring-1 ring-line">Coming soon: we're finishing Meta's approval for messages.</p>
            @elseif ($accounts->isEmpty())
                <p class="text-sm text-muted">Connect Facebook &amp; Instagram first, then come back to switch messages on.</p>
                @can('social.manage', app(\App\Support\Tenancy\CurrentOrganization::class)->get())
                    <x-ui.button class="mt-3" size="sm" :href="route('app.social.connect', 'meta')">Connect Facebook &amp; Instagram</x-ui.button>
                @endcan
            @else
                <ul class="divide-y divide-line">
                    @foreach ($accounts as $account)
                        <li class="flex flex-wrap items-center gap-3 py-3" wire:key="ma-{{ $account->ulid }}">
                            <span class="size-2.5 rounded-full" style="background: {{ $account->network->color() }}"></span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink">{{ $account->displayName() }}</p>
                                <p class="text-xs text-subtle">{{ $account->network->label() }}@if ($account->needsReconnect()) · <span class="text-danger">needs reconnecting</span>@endif</p>
                            </div>
                            @if ($canManage)
                                <label class="flex items-center gap-2 text-sm text-ink">
                                    <input type="checkbox" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" @checked($account->messaging_enabled) wire:click="toggleMessaging('{{ $account->ulid }}')" @disabled($account->needsReconnect())>
                                    Messages in my inbox
                                </label>
                            @else
                                <x-ui.badge :tone="$account->messaging_enabled ? 'success' : 'neutral'">{{ $account->messaging_enabled ? 'On' : 'Off' }}</x-ui.badge>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-subtle">Meta lets businesses reply within 24 hours of a customer's message, and a person can reply for up to 7 days. Accounts connected before messages were added need reconnecting once.</p>
            @endif
        </x-ui.card>
    </div>
</div>
