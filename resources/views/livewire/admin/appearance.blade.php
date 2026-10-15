<div>
    <x-ui.page-header title="Appearance" description="The colour theme everyone sees in the portals, unless they picked their own." />

    <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <x-ui.card title="Default theme" description="Used by everyone who hasn't chosen a theme, and on the sign-in pages. People who chose their own keep it.">
            <form wire:submit="saveDefault" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Default theme">
                    @foreach ($themes as $key => $theme)
                        <label wire:key="t-{{ $key }}" @class([
                            'flex cursor-pointer flex-col rounded-xl border p-3 transition-colors hover:border-brand-400',
                            'border-brand-400 ring-1 ring-brand-400' => $default === $key,
                            'border-line-strong' => $default !== $key,
                        ])>
                            <input type="radio" name="default" value="{{ $key }}" wire:model.live="default" class="sr-only" @disabled(! $canManage)>
                            <span class="flex h-12 overflow-hidden rounded-lg ring-1 ring-line" aria-hidden="true">
                                @foreach ($theme['swatch'] as $i => $color)<span class="block h-full" style="background: {{ $color }}; flex: {{ $i < 2 ? 3 : 1 }}"></span>@endforeach
                            </span>
                            <span class="mt-2 flex items-center justify-between gap-2">
                                <span class="text-sm font-medium text-ink">{{ $theme['label'] }}</span>
                                @if ($default === $key)<x-ui.badge tone="brand">Default</x-ui.badge>@endif
                            </span>
                            <span class="mt-0.5 text-xs text-subtle">{{ $theme['description'] }}</span>
                            <span class="mt-1 text-xs text-muted tabular-nums">{{ ($chosen[$key] ?? 0) === 0 ? 'Nobody chose it yet' : ($chosen[$key].' '.str('person')->plural($chosen[$key]).' chose it') }}</span>
                        </label>
                    @endforeach
                </div>
                @if ($canManage)
                    <x-ui.button type="submit">Save default</x-ui.button>
                @else
                    <p class="text-sm text-muted">Only administrators with settings access can change the default.</p>
                @endif
            </form>
        </x-ui.card>

        <x-ui.card title="Personal choices" description="A theme someone chose is theirs. Changing the default doesn't affect it.">
            @php $total = $chosen->sum(); @endphp
            <p class="text-sm text-ink"><span class="text-2xl font-semibold tabular-nums">{{ $total }}</span> {{ str('person')->plural($total) }} chose their own theme.</p>

            @if ($canManage)
                <form wire:submit="resetChoices" class="mt-6 space-y-4 border-t border-line pt-5">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Reset a personal choice</h3>
                        <p class="mt-1 text-sm text-muted">Only for a real reason, for example a theme someone can't read or a request from them. They're told what happened and why, and the reset is recorded in the audit log.</p>
                    </div>
                    <fieldset class="flex flex-wrap gap-4 text-sm text-ink">
                        <legend class="sr-only">Who to reset</legend>
                        <label class="flex items-center gap-2"><input type="radio" value="one" wire:model.live="resetScope" class="text-brand-500"> One person</label>
                        <label class="flex items-center gap-2"><input type="radio" value="everyone" wire:model.live="resetScope" class="text-brand-500"> Everyone ({{ $total }})</label>
                    </fieldset>
                    @if ($resetScope === 'one')
                        <div>
                            <label for="reset-email" class="sh-label">Their email address</label>
                            <input id="reset-email" type="email" wire:model="resetEmail" class="sh-input" autocomplete="off">
                            @error('resetEmail')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                    @endif
                    <div>
                        <label for="reset-reason" class="sh-label">Reason (shown to them)</label>
                        <textarea id="reset-reason" wire:model="resetReason" rows="2" maxlength="300" class="sh-input" placeholder="e.g. You asked us to reset it because the text was hard to read."></textarea>
                        @error('resetReason')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="reset-confirm" class="sh-label">Type RESET to confirm</label>
                        <input id="reset-confirm" type="text" wire:model="resetConfirm" class="sh-input w-40" autocomplete="off">
                        @error('resetConfirm')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                    <x-ui.button type="submit" variant="danger">{{ $resetScope === 'everyone' ? 'Reset everyone to the default' : 'Reset this person to the default' }}</x-ui.button>
                </form>
            @endif
        </x-ui.card>
    </div>
</div>
