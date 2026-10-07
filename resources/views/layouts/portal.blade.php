@php
    // Livewire full-page layout. Data: $portal (client|admin|agent), $title (from #[Title]), $slot.
    $title ??= null;
    $user = auth()->user();
    // Pages shared by every portal (your account) render inside the person's own portal.
    $portal ??= ['admin' => 'admin', 'agent' => 'agent'][$user->role] ?? 'client';
    $navigation = app(\App\Support\Navigation\PortalNavigation::class)->for($user, $portal);
    $organization = app(\App\Support\Tenancy\CurrentOrganization::class)->get();
    $businessLogo = $organization ? \App\Models\BusinessProfile::withoutGlobalScopes()->where('organization_id', $organization->id)->whereNotNull('logo_path')->first()?->logoUrl() : null;
    $portalLabel = ['client' => 'Business portal', 'admin' => 'Admin console', 'agent' => 'Agent workspace'][$portal] ?? '';
    $initials = collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
@endphp

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' · ' : '' }}SureHelp</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
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
                <a href="{{ $navigation[0]['url'] ?? url('/') }}" class="flex items-center gap-2">
                    <img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp Solutions" class="h-8 w-auto">
                </a>
                <button type="button" class="rounded-lg p-1.5 text-muted hover:text-ink lg:hidden" x-on:click="sidebarOpen = false" aria-label="Close navigation">
                    <x-ui.icon name="x" />
                </button>
            </div>

            <p class="px-5 pt-5 text-xs font-semibold uppercase tracking-wider text-subtle">{{ $portalLabel }}</p>

            <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-3">
                @foreach ($navigation as $item)
                    @if ($item['status'] === 'soon')
                        <span class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-subtle" aria-disabled="true">
                            <x-ui.icon :name="$item['icon']" class="size-5" />
                            <span class="flex-1">{{ $item['label'] }}</span>
                            <span class="rounded-full bg-white/5 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide">Soon</span>
                        </span>
                    @elseif ($item['url'])
                        <a href="{{ $item['url'] }}" @if ($item['current']) aria-current="page" @endif
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
                    @if ($organization)
                        <p class="truncate text-sm font-semibold text-ink">{{ $organization->name }}</p>
                        <p class="truncate text-xs text-subtle">{{ $organization->timezone ?? 'Timezone not set yet' }}</p>
                    @endif
                </div>

                <div class="flex items-center gap-3">
                    <livewire:notification-bell />
                    <a href="{{ route('account.profile') }}" class="flex items-center gap-3 rounded-xl px-1.5 py-1 hover:bg-surface-2" title="Your account and security">
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
