@php
    $labels = [
        'auth.login' => 'Signed in',
        'auth.login_failed' => 'Failed sign-in attempt',
        'auth.login_blocked' => 'Sign-in blocked (account switched off)',
        'auth.password_reset' => 'Password reset by email',
        'auth.signed_out_everywhere' => 'Signed out of all devices',
    ];
    $methods = ['password' => 'password', 'two_factor' => 'password + app code', 'recovery_code' => 'password + recovery code'];
@endphp
<div>
    <x-ui.page-header title="Your account" description="Your own details, password and security. Only you can see this page." />
    @include('livewire.account._tabs')

    @if ($forced)
        <x-ui.alert tone="warning" class="mb-6" title="Set up two-step sign-in to continue">
            Staff and agents protect their accounts with a code from an authenticator app, as well as a password. It takes about a minute.
        </x-ui.alert>
    @endif

    @if ($freshCodes)
        <x-ui.alert tone="success" class="mb-6" title="Save your recovery codes">
            <p>If you lose your phone, each of these codes lets you sign in once. Store them somewhere safe, like a password manager. You won't see them again.</p>
            <ul class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm text-ink sm:grid-cols-4" data-testid="recovery-codes">
                @foreach ($freshCodes as $code)<li>{{ $code }}</li>@endforeach
            </ul>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-ui.button size="sm" variant="secondary" x-data data-codes="{{ implode(PHP_EOL, $freshCodes) }}" x-on:click="navigator.clipboard.writeText($el.dataset.codes); $dispatch('toast', { type: 'success', message: 'Copied.' })">Copy codes</x-ui.button>
                <x-ui.button size="sm" variant="secondary" wire:click="dismissCodes">I've saved them</x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Two-step sign-in" :description="$required ? 'Required for your account.' : 'Recommended: a stolen password alone can\'t get into your account.'">
            @if ($user->hasTwoFactor())
                <div class="flex items-center gap-2">
                    <x-ui.badge tone="success">On</x-ui.badge>
                    <span class="text-sm text-muted">since {{ $user->two_factor_confirmed_at->format('M j, Y') }} · {{ $codesLeft }} recovery {{ \Illuminate\Support\Str::plural('code', $codesLeft) }} left</span>
                </div>
                <div class="mt-5 space-y-3 border-t border-line pt-5">
                    <label for="s-pass" class="sh-label">Current password (to make new codes{{ $required ? '' : ' or turn this off' }})</label>
                    <input id="s-pass" type="password" wire:model="password" class="sh-input" autocomplete="current-password">
                    @error('password') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button variant="secondary" size="sm" wire:click="regenerateCodes">Make new recovery codes</x-ui.button>
                        @unless ($required)
                            <x-ui.button variant="ghost" size="sm" wire:click="disable">Turn off</x-ui.button>
                        @endunless
                    </div>
                    <p class="text-xs text-subtle">New codes replace the old ones. Changed phone? Ask {{ $user->isClient() ? 'SureHelp support' : 'a Super Admin' }} to reset your two-step sign-in, then set it up again.</p>
                </div>
            @elseif ($qr)
                <ol class="space-y-4 text-sm text-ink">
                    <li>
                        <p class="font-medium">1. Scan this with an authenticator app</p>
                        <p class="text-muted">Google Authenticator, Microsoft Authenticator, 1Password, Authy… any of them works.</p>
                        <div class="mt-3 inline-block rounded-xl bg-white p-3" aria-hidden="true">{!! $qr !!}</div>
                        <p class="mt-2 text-xs text-subtle">Can't scan? Enter this key instead: <span class="select-all font-mono text-muted" data-testid="setup-key">{{ $secret }}</span></p>
                    </li>
                    <li>
                        <form wire:submit="confirmSetup" class="space-y-2">
                            <label for="s-code" class="font-medium">2. Enter the 6-digit code the app shows</label>
                            <div class="flex gap-2">
                                <input id="s-code" type="text" inputmode="numeric" maxlength="7" wire:model="code" class="sh-input w-40 text-center font-mono tracking-[0.3em]" autocomplete="one-time-code">
                                <x-ui.button type="submit">Turn on</x-ui.button>
                            </div>
                            @error('code') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                        </form>
                    </li>
                </ol>
                <x-ui.button variant="ghost" size="sm" class="mt-4" wire:click="cancelSetup">Cancel</x-ui.button>
            @else
                <div class="flex items-center gap-2"><x-ui.badge :tone="$required ? 'danger' : 'neutral'">Off</x-ui.badge></div>
                <p class="mt-3 text-sm text-muted">Each time you sign in, you'll enter a code from an app on your phone as well as your password.</p>
                <x-ui.button class="mt-4" wire:click="startSetup" icon="shield">Set up two-step sign-in</x-ui.button>
            @endif
        </x-ui.card>

        <x-ui.card title="Where you're signed in" :padding="false">
            <ul class="divide-y divide-line" role="list">
                @foreach ($sessions as $s)
                    <li class="flex items-center justify-between gap-3 px-5 py-3">
                        <div>
                            <p class="text-sm font-medium text-ink">{{ $s['device'] }} @if ($s['current'])<x-ui.badge tone="success">This device</x-ui.badge>@endif</p>
                            <p class="text-xs text-subtle">{{ $s['ip'] ?? 'Unknown IP' }} · active {{ $s['last_active']->diffForHumans() }}</p>
                        </div>
                    </li>
                @endforeach
                @foreach ($tokens as $token)
                    <li class="flex items-center justify-between gap-3 px-5 py-3" wire:key="token-{{ $token->id }}">
                        <div>
                            <p class="text-sm font-medium text-ink">{{ $token->name }} <x-ui.badge tone="info">Mobile app</x-ui.badge></p>
                            <p class="text-xs text-subtle">{{ $token->last_used_at ? 'used '.$token->last_used_at->diffForHumans() : 'signed in '.$token->created_at->diffForHumans() }}</p>
                        </div>
                        <x-ui.button size="sm" variant="ghost" wire:click="revokeToken({{ $token->id }})">Sign out</x-ui.button>
                    </li>
                @endforeach
                @if ($sessions->isEmpty() && $tokens->isEmpty())
                    <li class="px-5 py-4 text-sm text-muted">Only this browser.</li>
                @endif
            </ul>
            <div class="space-y-2 border-t border-line px-5 py-4">
                <p class="text-sm text-muted">Lost a device or used a shared computer? Sign out everywhere except here.</p>
                @unless ($user->hasTwoFactor())
                    <label for="o-pass" class="sh-label">Current password</label>
                    <input id="o-pass" type="password" wire:model="password" class="sh-input" autocomplete="current-password">
                    @error('password') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                @endunless
                <x-ui.button variant="secondary" size="sm" wire:click="signOutOthers">Sign out other devices</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card class="lg:col-span-2" title="Recent sign-in activity" description="If something here wasn't you, change your password and sign out other devices." :padding="false">
            @if ($history->isEmpty())
                <p class="px-5 py-4 text-sm text-muted">No sign-ins recorded yet.</p>
            @else
                <x-ui.table>
                    <thead><tr><th scope="col">What</th><th scope="col">Device</th><th scope="col">IP</th><th scope="col" class="text-right">When</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($history as $entry)
                            <tr>
                                <td>
                                    <span @class(['text-danger' => $entry->action === 'auth.login_failed', 'text-ink' => $entry->action !== 'auth.login_failed'])>{{ $labels[$entry->action] ?? $entry->action }}</span>
                                    @if ($method = $methods[$entry->new_values['method'] ?? ''] ?? null)<span class="text-xs text-subtle"> · {{ $method }}</span>@endif
                                    @if (($entry->new_values['channel'] ?? null) === 'api')<span class="text-xs text-subtle"> · mobile app</span>@endif
                                </td>
                                <td class="text-muted">{{ \App\Support\Account\DeviceName::from($entry->user_agent) }}</td>
                                <td class="text-muted">{{ $entry->ip_address ?? '—' }}</td>
                                <td class="whitespace-nowrap text-right text-muted" title="{{ $entry->created_at }}">{{ $entry->created_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>
    </div>
</div>
