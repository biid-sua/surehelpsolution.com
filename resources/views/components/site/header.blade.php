@props(['dark' => true])
@php
    $services = config('marketing.services');
    $industries = collect(config('marketing.industries'))->map(fn ($m, $key) => ['slug' => $key, 'label' => config("industries.{$key}.label", ucfirst($key))]);
    $user = auth()->user();
    $is = fn (string ...$names) => request()->routeIs(...$names);
@endphp
<header x-data="siteHeader" x-on:keydown.escape.window="close()" x-on:click.outside="menu = null"
    class="fixed inset-x-0 top-0 z-50 transition-colors duration-300"
    :class="scrolled || mobile || menu ? 'bg-navy-950/90 shadow-[0_1px_0_rgb(255_255_255/0.06)] backdrop-blur-md' : 'bg-transparent'">
    <div class="site-container flex h-18 items-center justify-between gap-6 py-3">
        <a href="{{ route('home') }}" class="flex shrink-0 items-center" aria-label="SureHelp Solution home">
            <img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp Solution" class="h-9 w-auto" width="160" height="38">
        </a>

        <nav class="hidden items-center gap-1 lg:flex" aria-label="Main">
            <div class="relative">
                <button type="button" x-on:click="toggle('services')" :aria-expanded="menu === 'services'" aria-controls="menu-services"
                    @class(['flex items-center gap-1 rounded-full px-3 py-2 text-sm font-medium transition hover:text-white', 'text-white' => $is('site.services.*'), 'text-slate-300' => ! $is('site.services.*')])>
                    Services <x-ui.icon name="chevron-right" class="size-3.5 rotate-90 transition" />
                </button>
                <div id="menu-services" x-show="menu === 'services'" x-cloak x-transition.opacity.duration.150ms
                    class="absolute top-full left-1/2 mt-3 w-[40rem] -translate-x-1/2 rounded-2xl bg-white p-3 shadow-2xl ring-1 ring-slate-900/5">
                    <div class="grid grid-cols-2 gap-1">
                        @foreach ($services as $slug => $s)
                            <a href="{{ route('site.services.show', $slug) }}" class="flex gap-3 rounded-xl p-3 hover:bg-slate-50">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600"><x-ui.icon :name="$s['icon']" class="size-5" /></span>
                                <span>
                                    <span class="block text-sm font-semibold text-slate-900">{{ $s['nav'] }}</span>
                                    <span class="mt-0.5 block text-xs leading-5 text-slate-500">{{ $s['summary'] }}</span>
                                </span>
                            </a>
                        @endforeach
                        <a href="{{ route('site.how') }}" class="flex items-center gap-3 rounded-xl bg-navy-900 p-3 text-white hover:bg-navy-800">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-mint-400/15 text-mint-300"><x-ui.icon name="sparkles" class="size-5" /></span>
                            <span><span class="block text-sm font-semibold">How it works</span><span class="block text-xs text-slate-300">Live in 48 hours, step by step</span></span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="relative">
                <button type="button" x-on:click="toggle('industries')" :aria-expanded="menu === 'industries'" aria-controls="menu-industries"
                    @class(['flex items-center gap-1 rounded-full px-3 py-2 text-sm font-medium transition hover:text-white', 'text-white' => $is('site.industries.*'), 'text-slate-300' => ! $is('site.industries.*')])>
                    Industries <x-ui.icon name="chevron-right" class="size-3.5 rotate-90" />
                </button>
                <div id="menu-industries" x-show="menu === 'industries'" x-cloak x-transition.opacity.duration.150ms
                    class="absolute top-full left-1/2 mt-3 w-80 -translate-x-1/2 rounded-2xl bg-white p-3 shadow-2xl ring-1 ring-slate-900/5">
                    <div class="grid grid-cols-2 gap-1">
                        @foreach ($industries as $i)
                            <a href="{{ route('site.industries.show', $i['slug']) }}" class="rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-slate-900">{{ $i['label'] }}</a>
                        @endforeach
                    </div>
                    <a href="{{ route('site.industries.index') }}" class="mt-2 flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm font-semibold text-brand-600 hover:bg-brand-50">All industries <x-ui.icon name="chevron-right" class="size-4" /></a>
                </div>
            </div>

            @foreach ([['Pricing', 'site.pricing'], ['About', 'site.about'], ['Contact', 'site.contact']] as [$label, $route])
                <a href="{{ route($route) }}" @if ($is($route)) aria-current="page" @endif
                    @class(['rounded-full px-3 py-2 text-sm font-medium transition hover:text-white', 'text-white' => $is($route), 'text-slate-300' => ! $is($route)])>{{ $label }}</a>
            @endforeach
        </nav>

        <div class="hidden items-center gap-3 lg:flex">
            <a href="tel:{{ config('marketing.phone_href') }}" class="text-sm font-medium text-slate-300 hover:text-white">{{ config('marketing.phone') }}</a>
            @if ($user)
                <a href="{{ $user->homeUrl() }}" class="btn-light !py-2.5">Open your portal</a>
            @else
                <a href="{{ route('login') }}" class="rounded-full px-3 py-2 text-sm font-medium text-slate-300 hover:text-white">Sign in</a>
                <a href="{{ route('site.contact', ['topic' => 'demo']) }}" class="btn-mint !py-2.5">Book a demo</a>
            @endif
        </div>

        <button type="button" class="rounded-lg p-2 text-white lg:hidden" x-on:click="mobile = !mobile" :aria-expanded="mobile" aria-controls="mobile-menu">
            <span class="sr-only">Menu</span>
            <x-ui.icon name="menu" class="size-6" x-show="!mobile" />
            <x-ui.icon name="x" class="size-6" x-show="mobile" x-cloak />
        </button>
    </div>

    <div id="mobile-menu" x-show="mobile" x-cloak x-transition.opacity class="max-h-[calc(100vh-4.5rem)] overflow-y-auto border-t border-white/10 bg-navy-950 lg:hidden">
        <nav class="site-container space-y-6 py-6" aria-label="Main">
            <div>
                <p class="eyebrow mb-2 text-mint-300">Services</p>
                @foreach ($services as $slug => $s)
                    <a href="{{ route('site.services.show', $slug) }}" class="block py-2 text-base text-white">{{ $s['nav'] }}</a>
                @endforeach
                <a href="{{ route('site.how') }}" class="block py-2 text-base text-white">How it works</a>
            </div>
            <div>
                <p class="eyebrow mb-2 text-mint-300">Industries</p>
                <div class="grid grid-cols-2">
                    @foreach ($industries as $i)
                        <a href="{{ route('site.industries.show', $i['slug']) }}" class="py-2 text-base text-white">{{ $i['label'] }}</a>
                    @endforeach
                </div>
            </div>
            <div class="space-y-1 border-t border-white/10 pt-4">
                <a href="{{ route('site.pricing') }}" class="block py-2 text-base text-white">Pricing</a>
                <a href="{{ route('site.about') }}" class="block py-2 text-base text-white">About</a>
                <a href="{{ route('legal.faq') }}" class="block py-2 text-base text-white">FAQ</a>
                <a href="{{ route('site.contact') }}" class="block py-2 text-base text-white">Contact</a>
            </div>
            <div class="grid gap-3 border-t border-white/10 pt-6">
                <a href="{{ route('site.contact', ['topic' => 'demo']) }}" class="btn-mint">Book a demo</a>
                @if ($user)
                    <a href="{{ $user->homeUrl() }}" class="btn-light">Open your portal</a>
                @else
                    <a href="{{ route('login') }}" class="btn-light">Sign in</a>
                @endif
                <a href="tel:{{ config('marketing.phone_href') }}" class="text-center text-sm text-slate-300">Call us: {{ config('marketing.phone') }}</a>
            </div>
        </nav>
    </div>
</header>
