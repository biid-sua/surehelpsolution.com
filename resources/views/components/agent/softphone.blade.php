{{-- The agent's browser phone (D60): readiness in the top bar; ringing and live calls in a card. --}}
@php
    $agent = auth()->user();
    $provider = app(\App\Services\Telephony\TelephonyProvider::class);
    $presence = app(\App\Services\Telephony\AgentPresence::class);
    $lines = \App\Models\PhoneNumber::withoutGlobalScopes()->active()->select('organization_id');
    // The agent's businesses that have a line: for calling out, and for demo calls.
    $lineBusinesses = $agent->isAdmin()
        ? \App\Models\Organization::query()->whereIn('id', $lines)->orderBy('name')->limit(25)->get(['ulid', 'name'])
        : $agent->assignedOrganizations()->whereIn('organizations.id', $lines)->orderBy('name')->get(['organizations.ulid', 'organizations.name']);
    $canCallOut = ! $provider->usesBrowserSdk() || (string) config('telephony.twilio.twiml_app_sid') !== '';
    $config = [
        'driver' => $provider->usesBrowserSdk() ? 'browser' : 'demo',
        'ready' => $provider->isConfigured(),
        'status' => $presence->status($agent),
        'urls' => [
            'token' => route('agent.phone.token'),
            'presence' => route('agent.phone.presence'),
            'checkIn' => route('agent.phone.check-in'),
            'simulate' => route('agent.phone.simulate'),
            'dial' => route('agent.phone.dial'),
            'call' => url('agent/phone/calls/__ID__'),
        ],
        'businesses' => $lineBusinesses->map(fn ($o) => ['id' => $o->ulid, 'name' => $o->name])->values(),
        // Pages that show the phone too, so it stays on during a call when moving between them (D106).
        'keepOn' => array_values(array_filter(['/agent', $agent->isAgent() ? '/account' : null])),
    ];
@endphp
<div x-data="softphone(@js($config))" class="relative" x-on:keydown.escape="open = false" x-on:click.outside="open = false"
    x-on:softphone-dial.window="dial($event.detail.number, $event.detail.business)">
    <button type="button" x-on:click="open = !open" class="flex items-center gap-2 rounded-full border border-line-strong px-3 py-1.5 text-xs font-medium text-ink hover:bg-surface-2"
        :aria-expanded="open.toString()" aria-haspopup="true" title="Your phone">
        <span class="size-2 rounded-full" :class="{ 'bg-success': status === 'available', 'bg-warning': status === 'away' || status === 'wrap_up', 'bg-danger': status === 'busy', 'bg-subtle': status === 'offline' }" aria-hidden="true"></span>
        <x-ui.icon name="phone" class="size-4" />
        <span class="hidden md:inline" x-text="labels[status] ?? status">Offline</span>
    </button>

    <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 z-50 mt-2 w-72 rounded-2xl border border-line-strong bg-surface p-3 shadow-card">
        <p class="px-1 text-xs font-semibold uppercase tracking-wide text-subtle">Your phone</p>
        <div class="mt-2 space-y-1" role="radiogroup" aria-label="Phone status">
            @foreach (['available' => 'Ready for calls', 'away' => 'Away', 'offline' => 'Offline'] as $key => $label)
                <button type="button" role="radio" :aria-checked="(status === '{{ $key }}').toString()" x-on:click="setStatus('{{ $key }}')" :disabled="!!active"
                    class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-ink hover:bg-surface-2 disabled:opacity-50"
                    :class="status === '{{ $key }}' && 'bg-surface-2 font-medium'">
                    <span class="size-2 rounded-full {{ ['available' => 'bg-success', 'away' => 'bg-warning', 'offline' => 'bg-subtle'][$key] }}" aria-hidden="true"></span>{{ $label }}
                </button>
            @endforeach
        </div>
        <p class="mt-2 px-1 text-xs text-muted" x-show="status === 'wrap_up'">Finishing the last call. Calls ring again in a moment, or choose "Ready for calls".</p>
        <p class="mt-2 px-1 text-xs text-muted" x-show="passive">Your phone is open in another tab. Calls ring there.</p>
        <p class="mt-2 px-1 text-xs text-danger" x-show="error" x-text="error"></p>
        @if (! $config['ready'])
            <p class="mt-2 px-1 text-xs text-warning">The phone service isn't set up yet, so calls can't ring here.</p>
        @endif

        @if ($canCallOut && $config['businesses']->isNotEmpty())
            <form class="mt-3 space-y-2 border-t border-line pt-3" x-on:submit.prevent="dial(dialNumber, dialBusiness)">
                <p class="px-1 text-xs font-semibold uppercase tracking-wide text-subtle">Call a customer</p>
                <label class="sr-only" for="sp-business">Call as</label>
                <select id="sp-business" x-model="dialBusiness" class="sh-input py-1.5 text-sm">
                    @foreach ($config['businesses'] as $business)<option value="{{ $business['id'] }}">{{ $business['name'] }}</option>@endforeach
                </select>
                <div class="flex gap-2">
                    <label class="sr-only" for="sp-number">Number</label>
                    <input id="sp-number" type="tel" x-model="dialNumber" class="sh-input py-1.5 text-sm" placeholder="(512) 555-0100" autocomplete="off">
                    <button type="submit" class="rounded-lg bg-emerald-600 px-3 text-sm font-semibold text-white hover:bg-emerald-500" :disabled="!!active">Call</button>
                </div>
                <p class="px-1 text-xs text-subtle">The customer sees the business's number.</p>
            </form>
        @endif

        @if ($config['driver'] === 'demo')
            <div class="mt-3 border-t border-line pt-3">
                <p class="px-1 text-xs text-muted">Demo phone: no real calls. Try a call to a business's line:</p>
                @forelse ($config['businesses'] as $business)
                    <button type="button" x-on:click="simulate(@js($business['id']))" class="mt-1 flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-ink hover:bg-surface-2">
                        <x-ui.icon name="phone" class="size-4 text-brand-400" />{{ $business['name'] }}
                    </button>
                @empty
                    <p class="mt-1 px-1 text-xs text-subtle">None of your businesses has a phone line yet.</p>
                @endforelse
            </div>
        @endif
    </div>

    {{-- Ringing and live calls (moved to <body>: the blurred top bar would otherwise hold the fixed card) --}}
    <template x-teleport="body">
        <div class="fixed bottom-4 right-4 z-50 w-80 max-w-[calc(100vw-2rem)] space-y-3" aria-live="assertive">
            <template x-for="call in (passive ? [] : ringing)" :key="call.id">
                <div class="rounded-2xl border border-brand-400 bg-surface p-4 shadow-card ring-2 ring-brand-400/40" role="alertdialog" :aria-label="'Incoming call for ' + call.business">
                    <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-brand-300"><span class="size-2 animate-ping rounded-full bg-brand-400"></span> Incoming call</p>
                    <p class="mt-2 text-sm text-muted" x-text="call.business"></p>
                    <p class="text-lg font-semibold text-ink" x-text="call.caller"></p>
                    <p class="text-xs text-subtle" x-show="call.known" x-text="call.number + ' · existing customer'"></p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" x-on:click="answer(call)" class="sh-btn flex-1 rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500">Answer</button>
                        <button type="button" x-on:click="decline(call)" class="flex-1 rounded-xl border border-line-strong px-3 py-2 text-sm font-medium text-ink hover:bg-surface-2">Not now</button>
                    </div>
                </div>
            </template>

            <template x-if="active">
                <div class="rounded-2xl border border-line-strong bg-surface p-4 shadow-card" role="region" aria-label="Current call">
                    <p class="flex items-center justify-between text-xs font-semibold uppercase tracking-wide text-success"><span x-text="active.consult_for ? 'Talking to the owner' : (active.held ? 'Caller on hold' : (active.outbound ? (active.answered_at ? 'Calling out' : 'Calling…') : 'On a call'))"></span><span class="tabular-nums text-ink" x-text="elapsed"></span></p>
                    <p class="mt-2 text-sm text-muted" x-text="active.business"></p>
                    <p class="text-lg font-semibold text-ink" x-text="active.caller"></p>
                    <p class="text-xs text-subtle" x-show="active.known" x-text="active.number"></p>
                    <p class="mt-1 text-xs text-warning" x-show="active.consult_for" x-text="'Waiting on hold: ' + (active.consult_for?.caller ?? 'the caller')"></p>
                <div class="mt-3 grid grid-cols-2 gap-2" x-show="!passive && (active.consult_for || active.held)">
                    <button type="button" x-on:click="connectCaller()" x-show="active.consult_for" :disabled="!active.answered_at" class="rounded-xl bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">Connect caller</button>
                    <button type="button" x-on:click="backToCaller()" class="rounded-xl border border-line-strong px-3 py-2 text-sm text-ink hover:bg-surface-2">Back to caller</button>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2" x-show="!passive && !active.consult_for && !active.held">
                        <button type="button" x-on:click="toggleMute()" x-show="driver === 'browser'" class="rounded-xl border border-line-strong px-3 py-2 text-sm text-ink hover:bg-surface-2" x-text="muted ? 'Unmute' : 'Mute'"></button>
                        <button type="button" x-on:click="warmTransfer()" x-show="active.can_transfer" class="rounded-xl border border-line-strong px-3 py-2 text-sm text-ink hover:bg-surface-2" title="Put the caller on hold and talk to the owner first">Talk to owner first</button>
                        <button type="button" x-on:click="transfer()" x-show="active.can_transfer" class="rounded-xl border border-line-strong px-3 py-2 text-sm text-ink hover:bg-surface-2" title="Send the caller straight to the owner">Transfer to owner</button>
                        <a :href="active.workspace_url" target="surehelp-call-screen" class="rounded-xl border border-line-strong px-3 py-2 text-center text-sm text-ink hover:bg-surface-2">Call screen</a>
                        <button type="button" x-on:click="hangUp()" class="rounded-xl bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500">Hang up</button>
                    </div>
                    <p class="mt-2 text-xs text-muted" x-show="passive">This call is in your other tab.</p>
                </div>
            </template>
        </div>
    </template>
</div>
