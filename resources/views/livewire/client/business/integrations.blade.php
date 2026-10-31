<div>
    <x-ui.page-header title="Business" description="API & webhooks: connect SureHelp to n8n, Zapier, Make or your own software." />
    @include('livewire.client.business._tabs')

    <div class="space-y-6">
        <x-ui.card title="API keys" description="For tools that read or add your customers, appointments, calls and tasks. A key acts as you, with your permissions.">
            @if ($newKey)
                <x-ui.alert tone="success" class="mb-4" title="Copy your key now">
                    <span class="block">You won't see it again. Keep it secret, like a password.</span>
                    <span class="mt-2 flex items-center gap-2" x-data="{ copied: false }">
                        <code class="break-all rounded bg-surface-2 px-2 py-1 font-mono text-sm text-ink">{{ $newKey }}</code>
                        <button type="button" class="text-sm font-medium underline" x-on:click="navigator.clipboard.writeText(@js($newKey)); copied = true"><span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span></button>
                    </span>
                </x-ui.alert>
            @endif
            <form wire:submit="createKey" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[14rem] flex-1">
                    <label for="k-name" class="sh-label">Name</label>
                    <input id="k-name" type="text" wire:model="keyName" maxlength="80" class="sh-input" placeholder="n8n, Zapier, our CRM…">
                    @error('keyName') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm text-ink"><input type="checkbox" wire:model="keyWrite" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Can add and change things</label>
                <x-ui.button type="submit">Create key</x-ui.button>
            </form>
            @if ($keys->isNotEmpty())
                <ul class="mt-4 divide-y divide-line rounded-xl border border-line" role="list">
                    @foreach ($keys as $key)
                        <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" wire:key="key-{{ $key->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-ink">{{ $key->name }} <code class="ml-1 text-xs text-subtle">{{ $key->prefix }}…</code></p>
                                <p class="text-xs text-subtle">{{ $key->can_write ? 'Read and write' : 'Read only' }} · made by {{ $key->user?->name }} · {{ $key->last_used_at ? 'last used '.$key->last_used_at->diffForHumans() : 'never used' }}</p>
                            </div>
                            @if ($key->revoked_at)
                                <x-ui.badge tone="neutral">Revoked</x-ui.badge>
                            @else
                                <button type="button" wire:click="revokeKey('{{ $key->ulid }}')" wire:confirm="Revoke this key? Anything using it stops working." class="text-muted hover:text-danger">Revoke</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="mt-3 text-xs text-subtle">Send <code class="rounded bg-surface-2 px-1">Authorization: Bearer shk_…</code> to <code class="rounded bg-surface-2 px-1">{{ $apiBase }}/…</code>, for example <code class="rounded bg-surface-2 px-1">GET /customers</code>. The full list is in the API guide.</p>
        </x-ui.card>

        <x-ui.card title="Webhooks" description="We send events to your address the moment they happen, signed so you can trust them.">
            <form wire:submit="addEndpoint" class="space-y-3">
                <div>
                    <label for="w-url" class="sh-label">Address (https)</label>
                    <input id="w-url" type="url" wire:model="url" maxlength="500" class="sh-input" placeholder="https://your-n8n.example.com/webhook/surehelp">
                    @error('url') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <fieldset>
                    <legend class="sh-label">Events</legend>
                    <div class="grid gap-x-5 gap-y-1.5 text-sm text-ink sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($allEvents as $k => $label)
                            <label class="flex items-center gap-2"><input type="checkbox" value="{{ $k }}" wire:model="events" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> {{ $label }}</label>
                        @endforeach
                    </div>
                    @error('events') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </fieldset>
                <x-ui.button type="submit">Add webhook</x-ui.button>
            </form>

            @foreach ($endpoints as $endpoint)
                <div class="mt-4 rounded-xl border border-line p-4" wire:key="wh-{{ $endpoint->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="min-w-0 flex-1 truncate font-mono text-sm text-ink">{{ $endpoint->url }}</p>
                        @if ($endpoint->source === 'zapier')<x-ui.badge tone="info">Added by a Zap</x-ui.badge>@endif
                        <x-ui.badge :tone="$endpoint->is_active ? 'success' : 'warning'">{{ $endpoint->is_active ? 'On' : 'Off' }}</x-ui.badge>
                    </div>
                    @if ($endpoint->disabled_reason)<p class="mt-1 text-xs text-danger">{{ $endpoint->disabled_reason }}</p>@endif
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($allEvents as $k => $label)
                            <button type="button" wire:click="toggleEvent('{{ $endpoint->ulid }}', '{{ $k }}')"
                                @class(['rounded-full px-2 py-0.5 text-xs ring-1', 'bg-brand-600/20 text-ink ring-brand-400/50' => in_array($k, $endpoint->events, true), 'text-subtle ring-line' => ! in_array($k, $endpoint->events, true)])>{{ $k }}</button>
                        @endforeach
                    </div>
                    @if ($revealed === $endpoint->ulid)
                        <p class="mt-3 text-xs text-subtle">Signing secret: <code class="break-all rounded bg-surface-2 px-1 text-ink">{{ $endpoint->secret }}</code></p>
                    @endif
                    <div class="mt-3 flex flex-wrap gap-3 text-sm">
                        <button type="button" wire:click="test('{{ $endpoint->ulid }}')" class="font-medium text-brand-300 hover:underline">Send a test</button>
                        <button type="button" wire:click="show('{{ $endpoint->ulid }}')" class="text-muted hover:text-ink">{{ $editing === $endpoint->ulid ? 'Hide' : 'Recent deliveries' }}</button>
                        <button type="button" wire:click="$set('revealed', '{{ $endpoint->ulid }}')" class="text-muted hover:text-ink">Show secret</button>
                        <button type="button" wire:click="rotate('{{ $endpoint->ulid }}')" wire:confirm="Make a new signing secret? Update it in your tool straight away." class="text-muted hover:text-ink">New secret</button>
                        <button type="button" wire:click="toggleActive('{{ $endpoint->ulid }}')" class="text-muted hover:text-ink">{{ $endpoint->is_active ? 'Turn off' : 'Turn on' }}</button>
                        <button type="button" wire:click="remove('{{ $endpoint->ulid }}')" wire:confirm="Remove this webhook?" class="text-muted hover:text-danger">Remove</button>
                    </div>
                    @if ($editing === $endpoint->ulid)
                        <ul class="mt-3 divide-y divide-line text-xs" role="list">
                            @forelse ($endpoint->deliveries as $d)
                                <li class="flex flex-wrap gap-2 py-1.5">
                                    <x-ui.badge :tone="['delivered' => 'success', 'failed' => 'danger'][$d->status] ?? 'warning'">{{ ucfirst($d->status) }}</x-ui.badge>
                                    <span class="text-ink">{{ $d->event }}</span>
                                    <span class="text-subtle">{{ $d->created_at->diffForHumans() }} · {{ $d->attempts }} {{ \Illuminate\Support\Str::plural('try', $d->attempts) }}{{ $d->response_code ? ' · HTTP '.$d->response_code : '' }}{{ $d->error ? ' · '.$d->error : '' }}</span>
                                </li>
                            @empty
                                <li class="py-1.5 text-subtle">Nothing sent yet.</li>
                            @endforelse
                        </ul>
                    @endif
                </div>
            @endforeach
            <p class="mt-4 text-xs text-subtle">Each request carries <code class="rounded bg-surface-2 px-1">X-SureHelp-Signature: t=…,v1=…</code>: an HMAC-SHA256 of "t.body" with the signing secret. Answer with any 2xx; otherwise we try again 5 more times over about 8 hours.</p>
        </x-ui.card>
    </div>
</div>
