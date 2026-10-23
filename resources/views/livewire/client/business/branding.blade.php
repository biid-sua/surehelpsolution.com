<div>
    <x-ui.page-header title="Business" description="Branding: your logo, colour and name on everything your customers see." />
    @include('livewire.client.business._tabs')
    @if (session('success'))<x-ui.alert tone="success" class="mb-6">{{ session('success') }}</x-ui.alert>@endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <x-ui.card title="White-label">
            @unless ($allowed)
                <x-ui.alert tone="info" class="mb-4" title="Not in your plan yet">
                    White-label is part of the Growth plan. Your logo and business name already appear on customer emails; with white-label you also get your own colour and sender name, SureHelp's name removed from booking pages and emails, and your look in this portal.
                    <a href="{{ route('app.billing') }}" class="font-medium underline">See plans</a>
                </x-ui.alert>
            @endunless
            <form wire:submit="save" class="space-y-5">
                <fieldset @disabled(! $allowed || ! $canManage) class="space-y-5">
                    <div>
                        <span class="sh-label">Logo</span>
                        <div class="flex items-center gap-3">
                            @if ($brand['logo'])<img src="{{ $brand['logo'] }}" alt="Your logo" class="size-12 rounded-lg bg-white object-contain p-1 ring-1 ring-line">@endif
                            <a href="{{ route('app.business.profile') }}" class="text-sm text-brand-300 hover:underline">{{ $brand['logo'] ? 'Change it on your profile' : 'Add a logo on your profile' }}</a>
                        </div>
                    </div>
                    <div>
                        <label for="b-color" class="sh-label">Brand colour</label>
                        <div class="flex items-center gap-2">
                            <input id="b-color-pick" type="color" wire:model.live="color" class="h-10 w-14 cursor-pointer rounded-lg border border-line bg-surface-2 p-1" aria-label="Pick a colour">
                            <input id="b-color" type="text" wire:model.live.debounce.400ms="color" maxlength="7" class="sh-input w-32 font-mono" placeholder="#4f46e5">
                        </div>
                        <p class="mt-1 text-xs text-subtle">Used for buttons in emails, your booking and link pages, and your website chat.</p>
                        @error('color') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-sender" class="sh-label">Email sender name</label>
                        <input id="b-sender" type="text" wire:model.live.debounce.400ms="sender" maxlength="80" class="sh-input" placeholder="{{ $brand['name'] }}">
                        <p class="mt-1 text-xs text-subtle">What customers see as "From", for example "Rivera Plumbing Team". Replies still go to your business email.</p>
                        @error('sender') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" wire:model="chat" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                        <span class="text-ink">Use this colour for my website chat too</span>
                    </label>
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" wire:model="portal" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                        <span><span class="text-ink">Show my logo and colour in this portal</span>
                            <span class="block text-xs text-subtle">For you and your team. SureHelp agents and staff still see the SureHelp look.</span></span>
                    </label>
                </fieldset>
                @if ($allowed && $canManage)
                    <x-ui.button type="submit">Save branding</x-ui.button>
                @endif
            </form>
        </x-ui.card>

        <x-ui.card title="Preview" description="A customer email with these settings.">
            <div class="overflow-hidden rounded-xl bg-white text-[#1f2330] shadow-sm">
                <div class="h-1.5" style="background: {{ $previewColor }}"></div>
                <div class="px-5 pb-5 pt-4 text-sm">
                    <p class="text-xs" style="color:#6b7080">From: <span class="font-medium" style="color:#1f2330">{{ $allowed && trim($sender) !== '' ? $sender : $brand['name'] }}</span></p>
                    <div class="my-4 text-center">
                        @if ($brand['logo'])<img src="{{ $brand['logo'] }}" alt="" class="mx-auto h-10 w-auto">@else<p class="text-lg font-bold" style="color:#1f2330">{{ $brand['name'] }}</p>@endif
                    </div>
                    <p style="color:#1f2330">Hi Ana,</p>
                    <p class="mt-2" style="color:#1f2330">Your appointment with {{ $brand['name'] }} is confirmed for Friday at 10:00 AM.</p>
                    <p class="mt-4 text-center"><span class="inline-block rounded-lg px-4 py-2 font-semibold" style="background: {{ $previewColor }}; color: {{ \App\Services\Business\Branding::textOn($previewColor) }}">Change or cancel</span></p>
                    <p class="mt-4 border-t pt-3 text-center text-xs" style="color:#8a8fa3;border-color:#ececf1">{{ $brand['name'] }}@unless ($allowed) · sent with SureHelp Solutions @endunless</p>
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
