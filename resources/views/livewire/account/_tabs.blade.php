<nav class="mb-6 flex gap-1 border-b border-line" aria-label="Account sections">
    @foreach (['account.profile' => 'Profile', 'account.security' => 'Security'] + (auth()->user()->isClient() ? [] : ['account.notifications' => 'Notifications']) as $route => $label)
        <a href="{{ route($route) }}" @class([
            'border-b-2 px-3 py-2 text-sm font-medium transition-colors',
            'border-brand-400 text-ink' => request()->routeIs($route),
            'border-transparent text-muted hover:text-ink' => ! request()->routeIs($route),
        ]) @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
