{{-- Sub-navigation for the Inbox section. --}}
@php
    $tabs = array_filter([
        'app.inbox.index' => 'Conversations',
        'app.inbox.assistant' => auth()->user()->can('ai.view', app(\App\Support\Tenancy\CurrentOrganization::class)->get()) ? 'AI assistant' : null,
        'app.inbox.channels' => 'Channels',
    ]);
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Inbox">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" @if (request()->routeIs($route.'*')) aria-current="page" @endif
            @class([
                '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                'border-brand-400 text-ink' => request()->routeIs($route.'*'),
                'border-transparent text-muted hover:text-ink' => ! request()->routeIs($route.'*'),
            ])>{{ $label }}</a>
    @endforeach
</nav>
