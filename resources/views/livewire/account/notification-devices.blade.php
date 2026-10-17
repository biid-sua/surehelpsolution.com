<div class="mb-6 grid gap-6 lg:grid-cols-2">
    <x-ui.card title="Texts to your mobile" description="Tick “Text message” below for the notifications you want by text.">
        @if (! $smsReady)
            <p class="text-sm text-muted">Notification texts aren't available yet.</p>
        @elseif ($verified)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink"><x-ui.icon name="check-circle" class="inline size-4 text-success" /> Texts go to <span class="font-medium">{{ $verified }}</span></p>
                <x-ui.button size="sm" variant="ghost" wire:click="removePhone" wire:confirm="Stop sending notification texts to this number?">Remove</x-ui.button>
            </div>
        @else
            <form wire:submit="{{ $codeSent ? 'verify' : 'sendCode' }}" class="space-y-3">
                @if (! $codeSent)
                    <div>
                        <label for="nd-phone" class="sh-label">Mobile number</label>
                        <div class="flex gap-2">
                            <input id="nd-phone" type="tel" wire:model="phone" class="sh-input" placeholder="(512) 555-0100" autocomplete="tel">
                            <x-ui.button type="submit" variant="secondary">Text me a code</x-ui.button>
                        </div>
                        @error('phone')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        <p class="mt-1 text-xs text-subtle">We text a 6-digit code to check it's yours. Message and data rates may apply. Reply STOP to stop texts.</p>
                    </div>
                @else
                    <div>
                        <label for="nd-code" class="sh-label">Code we texted you</label>
                        <div class="flex gap-2">
                            <input id="nd-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="10" wire:model="code" class="sh-input w-40 font-mono tracking-widest">
                            <x-ui.button type="submit">Verify</x-ui.button>
                            <x-ui.button variant="ghost" wire:click="$set('codeSent', false)">Change number</x-ui.button>
                        </div>
                        @error('code')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                @endif
            </form>
        @endif
    </x-ui.card>

    <x-ui.card title="Phone app notifications" description="Push notifications go to phones signed in to the SureHelp app.">
        @if (! $pushReady)
            <p class="text-sm text-muted">Push notifications aren't available yet.</p>
        @elseif ($devices->isEmpty())
            <p class="text-sm text-muted">No phones yet. Install the SureHelp app and sign in to get push notifications.</p>
        @else
            <ul class="divide-y divide-line text-sm">
                @foreach ($devices as $device)
                    <li wire:key="dev-{{ $device->id }}" class="flex items-center justify-between gap-3 py-2">
                        <span class="text-ink">{{ $device->device_name ?: ucfirst((string) $device->platform ?: 'Phone') }}<span class="block text-xs text-subtle">Last used {{ $device->last_used_at?->diffForHumans() ?? 'never' }}</span></span>
                        <x-ui.button size="sm" variant="ghost" wire:click="forgetDevice({{ $device->id }})">Remove</x-ui.button>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
