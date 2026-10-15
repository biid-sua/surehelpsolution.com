@props(['variant' => 'menu'])
@php
    // Colour theme picker (D59): a menu in the top bar, or a grid on the account page.
    $themes = app(\App\Support\Ui\Themes::class);
    $user = auth()->user();
    $default = $themes->default();
    $config = [
        'url' => route('account.theme'),
        'choice' => $themes->choiceFor($user),
        'own' => $themes->exists($user?->theme),
        'modes' => collect($themes->portal())->map(fn ($t) => $t['mode'])->all(),
    ];
@endphp

@if ($variant === 'menu')
    <div x-data="themePicker(@js($config))" class="relative" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
        <button type="button" x-on:click="open = ! open" class="grid size-9 place-items-center rounded-lg text-muted hover:bg-surface-2 hover:text-ink"
            :aria-expanded="open.toString()" aria-haspopup="true" aria-controls="theme-menu" title="Colour theme">
            <x-ui.icon name="swatch" class="size-5" />
            <span class="sr-only">Colour theme</span>
        </button>
        <div id="theme-menu" x-show="open" x-cloak x-transition.origin.top.right
            class="absolute right-0 z-50 mt-2 w-72 rounded-xl border border-line-strong bg-surface p-2 shadow-[var(--shadow-card)]" role="menu" aria-label="Colour theme">
            <p class="px-2 pb-2 pt-1 text-xs font-semibold uppercase tracking-wide text-subtle">Colour theme</p>
            @foreach ($themes->portal() as $key => $theme)
                <button type="button" role="menuitemradio" :aria-checked="(own && choice === '{{ $key }}').toString()" x-on:click="choose('{{ $key }}')"
                    class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left text-sm text-ink hover:bg-surface-2">
                    <span class="flex shrink-0 overflow-hidden rounded-md ring-1 ring-line-strong" aria-hidden="true">
                        @foreach ($theme['swatch'] as $color)<span class="block h-6 w-3" style="background: {{ $color }}"></span>@endforeach
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ $theme['label'] }}</span>
                    <x-ui.icon name="check-circle" class="size-4 text-brand-300" x-show="own && choice === '{{ $key }}'" x-cloak />
                </button>
            @endforeach
            <div class="mt-1 border-t border-line pt-1">
                <button type="button" role="menuitemradio" :aria-checked="(! own).toString()" x-on:click="choose('default')"
                    class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left text-sm text-muted hover:bg-surface-2 hover:text-ink">
                    <span class="min-w-0 flex-1">Use the SureHelp default <span class="text-subtle">({{ $themes->portal()[$default]['label'] }})</span></span>
                    <x-ui.icon name="check-circle" class="size-4 text-brand-300" x-show="! own" x-cloak />
                </button>
                <a href="{{ route('account.profile') }}#appearance" class="block rounded-lg px-2 py-2 text-xs text-subtle hover:bg-surface-2 hover:text-ink">More about themes</a>
            </div>
        </div>
    </div>
@else
    <div x-data="themePicker(@js($config))" {{ $attributes }}>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" role="radiogroup" aria-label="Colour theme">
            @foreach ($themes->portal() as $key => $theme)
                <button type="button" role="radio" :aria-checked="(own && choice === '{{ $key }}').toString()" x-on:click="choose('{{ $key }}')"
                    class="rounded-xl border p-3 text-left transition-colors hover:border-brand-400"
                    :class="own && choice === '{{ $key }}' ? 'border-brand-400 ring-1 ring-brand-400' : 'border-line-strong'">
                    <span class="flex h-14 overflow-hidden rounded-lg ring-1 ring-line" aria-hidden="true">
                        @foreach ($theme['swatch'] as $i => $color)<span class="block h-full" style="background: {{ $color }}; flex: {{ $i < 2 ? 3 : 1 }}"></span>@endforeach
                    </span>
                    <span class="mt-2 flex items-center justify-between gap-2">
                        <span class="text-sm font-medium text-ink">{{ $theme['label'] }}</span>
                        <x-ui.icon name="check-circle" class="size-4 text-brand-300" x-show="own && choice === '{{ $key }}'" x-cloak />
                    </span>
                    <span class="mt-0.5 block text-xs text-subtle">{{ $theme['description'] }}</span>
                </button>
            @endforeach
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-3 text-sm">
            <p class="text-muted" x-show="own" x-cloak>This is your own choice: it stays the same on every device you sign in on.</p>
            <p class="text-muted" x-show="! own" x-cloak>You're using the SureHelp default ({{ $themes->portal()[$default]['label'] }}). Pick a theme to make it yours.</p>
            <x-ui.button size="sm" variant="ghost" x-show="own" x-cloak x-on:click="choose('default')">Use the default instead</x-ui.button>
        </div>
    </div>
@endif
