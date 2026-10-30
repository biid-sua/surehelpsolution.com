@php
    // Livewire full-page layout. Data: $portal (client|admin|agent), $title (from #[Title]), $slot.
    $title ??= null;
    $user = auth()->user();
    // Pages shared by every portal (your account) render inside the person's own portal.
    $portal ??= ['admin' => 'admin', 'agent' => 'agent'][$user->role] ?? 'client';
    $navigation = app(\App\Support\Navigation\PortalNavigation::class)->for($user, $portal);
    $organization = app(\App\Support\Tenancy\CurrentOrganization::class)->get();
    $businessLogo = $organization ? \App\Models\BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->whereNotNull('logo_path')->first()?->logoUrl() : null;
    // White-label (D82): a business's own portal can carry its logo, name and colour.
    $brand = $organization && $portal === 'client' ? app(\App\Services\Business\Branding::class)->for($organization) : null;
    $brand = $brand && $brand['portal'] ? $brand : null;
    $myBusinesses = $portal === 'client' && $user->isClient() ? $user->activeOrganizations() : collect();
    $portalLabel = ['client' => 'Business portal', 'admin' => 'Admin console', 'agent' => 'Agent workspace'][$portal] ?? '';
    $initials = collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
@endphp

<!DOCTYPE html>
<html lang="en" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes($user) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' · ' : '' }}{{ $brand['name'] ?? 'SureHelp' }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @include('partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- No nonce: inline styles are allowed anyway, and the head must match from page to page (D106). --}}
    @livewireStyles(['nonce' => ''])
    @if ($brand && ($css = app(\App\Services\Business\Branding::class)->css($organization)))<style>{!! $css !!}</style>@endif
</head>
<body class="h-full">
    <a href="#main" class="sr-only z-50 rounded-lg bg-brand-600 px-4 py-2 text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>

    <div x-data="{ sidebarOpen: false }" x-on:keydown.escape.window="sidebarOpen = false" class="min-h-full">
        {{-- Mobile drawer backdrop --}}
        <div x-show="sidebarOpen" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-black/60 lg:hidden" x-on:click="sidebarOpen = false"></div>

        {{-- Sidebar --}}
        <aside id="sidebar"
            class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-line bg-surface transition-transform lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen }" aria-label="Main navigation">
            <div class="flex h-16 items-center justify-between gap-2 border-b border-line px-5">
                <a href="{{ $navigation[0]['url'] ?? url('/') }}" wire:navigate class="flex items-center gap-2">
                    @if ($brand)
                        @if ($brand['logo'])<img src="{{ $brand['logo'] }}" alt="" class="size-8 rounded-lg bg-white object-contain p-0.5">@endif
                        <span class="max-w-[10rem] truncate text-base font-semibold text-ink">{{ $brand['name'] }}</span>
                    @else
                        <img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp Solutions" class="sh-logo-on-dark h-8 w-auto"><img src="{{ asset('assets/img/logo-on-light.png') }}" alt="SureHelp Solutions" class="sh-logo-on-light h-8 w-auto">
                    @endif
                </a>
                <button type="button" class="rounded-lg p-1.5 text-muted hover:text-ink lg:hidden" x-on:click="sidebarOpen = false" aria-label="Close navigation">
                    <x-ui.icon name="x" />
                </button>
            </div>

            <p class="px-5 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle">{{ $portalLabel }}</p>

            {{-- Menu links change only the page, not the whole window (D106); the menu keeps its scroll position. --}}
            <nav id="sidebar-nav" class="flex-1 space-y-1 overflow-y-auto px-3 py-3">
                @foreach ($navigation as $item)
                    @if ($item['status'] === 'soon')
                        <span class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-subtle" aria-disabled="true">
                            <x-ui.icon :name="$item['icon']" class="size-5" />
                            <span class="flex-1">{{ $item['label'] }}</span>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide">Soon</span>
                        </span>
                    @elseif ($item['url'])
                        <a href="{{ $item['url'] }}" wire:navigate @if ($item['current']) aria-current="page" @endif
                            @class([
                                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                'bg-brand-500/15 text-ink ring-1 ring-inset ring-brand-500/30' => $item['current'],
                                'text-muted hover:bg-surface-2 hover:text-ink' => ! $item['current'],
                            ])>
                            <x-ui.icon :name="$item['icon']" @class(['size-5', 'text-brand-300' => $item['current']]) />
                            <span class="flex-1">{{ $item['label'] }}</span>
                            @if (! empty($item['locked']))<span class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-300" title="Not in your plan">Upgrade</span>@endif
                        </a>
                    @endif
                @endforeach
            </nav>

            <div class="border-t border-line p-3">
                <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-muted hover:bg-surface-2 hover:text-ink">
                        <x-ui.icon name="logout" class="size-5" /> Sign out
                    </button>
                </form>
            </div>
        </aside>

        <div class="lg:pl-64">
            @if (session()->has(\App\Services\Account\Impersonation::KEY))
                {{-- Viewing as a client (spec ADM-05): impossible to miss, one click to stop. --}}
                <div class="sticky top-0 z-40 flex flex-wrap items-center justify-between gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950 sm:px-6 lg:px-8" role="status">
                    <span>You're viewing as {{ $user->name }}{{ $organization ? ' ('.$organization->name.')' : '' }}. Everything you do is recorded under your name.</span>
                    <form method="POST" action="{{ route('impersonation.stop') }}">
                        @csrf
                        <button type="submit" class="rounded-lg bg-amber-950 px-3 py-1 text-amber-50 hover:bg-amber-900">Stop viewing</button>
                    </form>
                </div>
            @endif
            {{-- Top bar --}}
            <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-line bg-canvas/80 px-4 backdrop-blur sm:px-6 lg:px-8">
                <button type="button" class="rounded-lg p-2 text-muted hover:bg-surface-2 hover:text-ink lg:hidden"
                    x-on:click="sidebarOpen = true" aria-controls="sidebar" :aria-expanded="sidebarOpen.toString()" aria-label="Open navigation">
                    <x-ui.icon name="menu" />
                </button>

                @if ($businessLogo)<img src="{{ $businessLogo }}" alt="" class="hidden size-9 rounded-lg object-contain sm:block">@endif
                <div class="min-w-0 flex-1">
                    @if ($portal === 'admin' && Route::has('admin.search'))
                        {{-- Global search (spec ADM-09) --}}
                        <form method="GET" action="{{ route('admin.search') }}" class="relative max-w-md" role="search">
                            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                            <label for="top-search" class="sr-only">Search everything</label>
                            <input id="top-search" name="q" type="search" value="{{ request()->routeIs('admin.search') ? request('q') : '' }}" class="sh-input py-1.5 pl-9 text-sm" placeholder="Search businesses, people, callers, call IDs…" autocomplete="off">
                        </form>
                    @endif
                    @if ($portal === 'client' && $organization && Route::has('app.search'))
                        {{-- Search the business's customers, calls, appointments and tasks --}}
                        <form method="GET" action="{{ route('app.search') }}" class="relative float-right ml-4 hidden w-72 md:block" role="search">
                            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
                            <label for="app-top-search" class="sr-only">Search your business</label>
                            <input id="app-top-search" name="q" type="search" value="{{ request()->routeIs('app.search') ? request('q') : '' }}" class="sh-input py-1.5 pl-9 text-sm" placeholder="Search customers, calls…" autocomplete="off">
                        </form>
                    @endif
                    @if ($organization && $portal === 'client' && $myBusinesses->count() > 1)
                        {{-- Multi-location (D89): switch business, or see them all. --}}
                        <div class="relative inline-block" x-data="{ open: false }" x-on:click.outside="open = false">
                            <button type="button" class="flex items-center gap-1 text-left" x-on:click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true">
                                <span class="truncate text-sm font-semibold text-ink">{{ $organization->name }}</span>
                                <x-ui.icon name="chevron-down" class="size-4 text-subtle" />
                            </button>
                            <p class="truncate text-xs text-subtle">{{ $myBusinesses->count() }} locations</p>
                            <div x-show="open" x-cloak x-transition class="absolute left-0 z-30 mt-2 w-72 rounded-xl border border-line bg-surface p-1 shadow-lg">
                                @foreach ($myBusinesses as $b)
                                    <form method="POST" action="{{ route('app.switch', $b->ulid) }}">
                                        @csrf
                                        <button type="submit" @class(['flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm hover:bg-surface-2', 'font-semibold text-ink' => $b->id === $organization->id, 'text-muted' => $b->id !== $organization->id])>
                                            <span class="flex-1 truncate">{{ $b->name }}</span>@if ($b->id === $organization->id)<x-ui.icon name="check-circle" class="size-4 text-brand-300" />@endif
                                        </button>
                                    </form>
                                @endforeach
                                <a href="{{ route('app.locations') }}" class="mt-1 block rounded-lg border-t border-line px-3 py-2 text-sm font-medium text-brand-300 hover:bg-surface-2">All locations overview</a>
                            </div>
                        </div>
                    @elseif ($organization && $portal !== 'admin')
                        <p class="truncate text-sm font-semibold text-ink">{{ $organization->name }}</p>
                        <p class="truncate text-xs text-subtle">{{ $organization->timezone ?? 'Timezone not set yet' }}</p>
                    @endif
                </div>

                <div class="flex items-center gap-3">
                    {{-- The browser phone for agents taking live calls (D60) --}}
                    @if ($portal === 'agent' && Route::has('agent.phone.check-in'))
                        {{-- Kept alive while moving between pages, so a live call isn't cut off (D106). --}}
                        @persist('softphone')
                            <x-agent.softphone />
                        @endpersist
                    @endif
                    {{-- Colour theme (D59); staff viewing as a client can't change the client's own choice. --}}
                    @unless (session()->has(\App\Services\Account\Impersonation::KEY))
                        <x-ui.theme-picker />
                    @endunless
                    <livewire:notification-bell />
                    <a href="{{ route('account.profile') }}" wire:navigate class="flex items-center gap-3 rounded-xl px-1.5 py-1 hover:bg-surface-2" title="Your account and security">
                        <span class="hidden text-right sm:block">
                            <span class="block text-sm font-medium text-ink">{{ $user->name }}</span>
                            <span class="block text-xs text-subtle">{{ $user->email }}</span>
                        </span>
                        <span class="grid size-9 place-items-center rounded-full bg-gradient-to-br from-brand-500 to-accent-500 text-sm font-semibold text-white" aria-hidden="true">{{ $initials }}</span>
                        <span class="sr-only">Your account</span>
                    </a>
                </div>
            </header>

            <main id="main" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8" tabindex="-1">
                @if ($portal === 'client' && $organization)
                    @include('partials.trial-banner', ['organization' => $organization])
                @endif
                @if (session('feature_locked'))
                    <x-ui.alert tone="info" class="mb-6" title="Not in your plan">{{ session('feature_locked') }}</x-ui.alert>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-ui.toasts />
    @livewireScripts
</body>
</html>
