@php
    $usZones = ['America/New_York' => 'Eastern', 'America/Chicago' => 'Central', 'America/Denver' => 'Mountain', 'America/Phoenix' => 'Arizona', 'America/Los_Angeles' => 'Pacific', 'America/Anchorage' => 'Alaska', 'Pacific/Honolulu' => 'Hawaii'];
@endphp
<div>
    <x-ui.page-header title="Your account" description="Your own details, password and security. Only you can see this page." />
    @include('livewire.account._tabs')

    @if (session('status'))
        <x-ui.alert tone="success" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Details">
            <form wire:submit="saveDetails" class="space-y-4">
                <div>
                    <label for="p-name" class="sh-label">Full name</label>
                    <input id="p-name" type="text" wire:model="name" class="sh-input" autocomplete="name">
                    @error('name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <p class="sh-label">Email</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-ink">{{ $user->email }}</span>
                        @if ($user->hasVerifiedEmail())
                            <x-ui.badge tone="success">Confirmed</x-ui.badge>
                        @else
                            <x-ui.badge tone="warning">Not confirmed</x-ui.badge>
                        @endif
                    </div>
                    <p class="mt-1 text-xs text-subtle">To change your sign-in email, ask {{ $user->isClient() ? 'SureHelp support' : 'a Super Admin' }}.</p>
                </div>
                <div>
                    <label for="p-phone" class="sh-label">Phone (optional)</label>
                    <input id="p-phone" type="tel" wire:model="phone" class="sh-input" autocomplete="tel">
                    @error('phone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-tz" class="sh-label">Your timezone</label>
                    <select id="p-tz" wire:model="timezone" class="sh-input">
                        <option value="">Same as the business / SureHelp</option>
                        <optgroup label="United States">
                            @foreach ($usZones as $zone => $label)
                                <option value="{{ $zone }}">{{ $label }} ({{ $zone }})</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="All timezones">
                            @foreach ($allTimezones as $zone)
                                <option value="{{ $zone }}">{{ $zone }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    @error('timezone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end"><x-ui.button type="submit">Save details</x-ui.button></div>
            </form>
            @unless ($user->hasVerifiedEmail())
                <form method="POST" action="{{ route('account.verification.send') }}" class="mt-4 border-t border-line pt-4">
                    @csrf
                    <p class="text-sm text-muted">Confirm your email so we can reach you and you can reset your password if you forget it.</p>
                    <x-ui.button type="submit" variant="secondary" size="sm" class="mt-2">Send confirmation email</x-ui.button>
                </form>
            @endunless
        </x-ui.card>

        <x-ui.card title="Password" description="Changing it signs you out on every other device, including the mobile app.">
            <form wire:submit="changePassword" class="space-y-4">
                <div>
                    <label for="p-current" class="sh-label">Current password</label>
                    <input id="p-current" type="password" wire:model="currentPassword" class="sh-input" autocomplete="current-password">
                    @error('currentPassword') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-new" class="sh-label">New password</label>
                    <input id="p-new" type="password" wire:model="newPassword" class="sh-input" autocomplete="new-password">
                    @error('newPassword') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-confirm" class="sh-label">Repeat new password</label>
                    <input id="p-confirm" type="password" wire:model="newPassword_confirmation" class="sh-input" autocomplete="new-password">
                </div>
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('account.security') }}" class="text-sm text-brand-300 hover:underline">Two-step sign-in and devices →</a>
                    <x-ui.button type="submit">Change password</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
