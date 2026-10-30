{{-- Sub-navigation for the Social section. --}}
@php
    $portal = $this->socialPortal();
    $tabs = array_filter([
        $portal.'.social.index' => 'Posts',
        // SureHelp's own posting schedule (D104).
        $portal.'.social.schedule' => $portal === 'admin' ? 'Schedule' : null,
        $portal.'.social.media' => 'Media library',
        $portal.'.social.accounts' => $portal === 'admin' ? 'Accounts' : 'Accounts & settings',
    ]);
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Social media">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" wire:navigate @if (request()->routeIs($route)) aria-current="page" @endif
            @class([
                '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                'border-brand-400 text-ink' => request()->routeIs($route),
                'border-transparent text-muted hover:text-ink' => ! request()->routeIs($route),
            ])>{{ $label }}</a>
    @endforeach
</nav>
