@props(['company', 'active' => 'workspace'])
{{-- "Currently viewing" bar for agent company pages (spec §20A): which company, a switcher limited to
     the agent's current assignments, and the company's sections. --}}
@php
    $user = auth()->user();
    $others = $user->isAdmin()
        ? collect()
        : $user->assignedOrganizations()->where('organizations.id', '!=', $company->id)->orderBy('name')->get(['organizations.id', 'organizations.ulid', 'organizations.name']);
    $tabs = array_filter([
        'workspace' => ['Calls & booking', 'agent.businesses.show'],
        'customers' => $user->hasPermissionIn('customers.view', $company) ? ['Customers', 'agent.businesses.customers'] : null,
        'appointments' => $user->hasPermissionIn('appointments.view', $company) ? ['Appointments', 'agent.businesses.appointments'] : null,
        'messages' => $user->hasPermissionIn('messages.view', $company) ? ['Messages', 'agent.businesses.messages'] : null,
        'tasks' => $user->hasPermissionIn('tasks.view', $company) ? ['Tasks', 'agent.businesses.tasks'] : null,
        'training' => $user->hasPermissionIn('agent_university.view', $company) ? ['Training', 'agent.businesses.training'] : null,
    ]);
    // Unfinished required training for this company (D43): a reminder, or what it stops.
    $readiness = $user->isAgent() ? app(\App\Services\Training\TrainingReadiness::class)->for($user, $company) : null;
    $level = $readiness && ! $readiness['ready'] ? $readiness['enforcement'] : null;
    if ($level === 'blocking') {
        $tabs = array_intersect_key($tabs, ['training' => true]);
    }
@endphp
<div class="mb-6 rounded-2xl border border-brand-500/30 bg-brand-500/5 px-4 py-3">
    <div class="flex flex-wrap items-center gap-3">
        <p class="text-sm text-muted">Currently viewing:</p>
        <p class="text-base font-semibold text-ink">{{ $company->name }}</p>
        @if ($others->isNotEmpty())
            <div x-data="{ open: false }" class="relative ml-auto">
                <x-ui.button size="sm" variant="secondary" x-on:click="open = !open" x-bind:aria-expanded="open" aria-haspopup="menu">Switch company</x-ui.button>
                <ul x-show="open" x-cloak x-on:click.outside="open = false" role="menu" class="absolute right-0 z-20 mt-2 w-64 overflow-hidden rounded-xl border border-line-strong bg-surface py-1 shadow-xl">
                    @foreach ($others as $other)
                        <li role="none"><a role="menuitem" href="{{ route('agent.businesses.show', $other->ulid) }}" class="block px-4 py-2 text-sm text-ink hover:bg-surface-2">{{ $other->name }}</a></li>
                    @endforeach
                    <li role="none" class="border-t border-line"><a role="menuitem" href="{{ route('agent.companies') }}" class="block px-4 py-2 text-sm text-muted hover:bg-surface-2">All my companies</a></li>
                </ul>
            </div>
        @else
            <a href="{{ route('agent.companies') }}" class="ml-auto text-sm text-muted hover:text-ink">All my companies</a>
        @endif
    </div>
    @if ($level && $level !== 'informational' && $active !== 'training')
        <p @class(['mt-3 rounded-lg px-3 py-2 text-sm ring-1', 'bg-red-500/10 text-red-200 ring-red-400/30' => in_array($level, ['restricted', 'blocking'], true), 'bg-amber-500/10 text-amber-200 ring-amber-400/30' => ! in_array($level, ['restricted', 'blocking'], true)]) role="status">
            @if (in_array($level, ['restricted', 'blocking'], true))
                {{ app(\App\Services\Training\TrainingReadiness::class)->message($company, $level) }}
            @else
                Training required for {{ $company->name }}: {{ implode(', ', $readiness['missing']) }}.
            @endif
            <a href="{{ route('agent.businesses.training', $company->ulid) }}" class="font-medium underline">Open the training</a>
        </p>
    @endif
    <nav class="mt-3 flex gap-1 overflow-x-auto" aria-label="{{ $company->name }} sections">
        @foreach ($tabs as $key => [$label, $route])
            <a href="{{ route($route, $company->ulid) }}" @if ($active === $key) aria-current="page" @endif
                @class(['whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium', 'bg-surface-3 text-ink' => $active === $key, 'text-muted hover:text-ink' => $active !== $key])>{{ $label }}</a>
        @endforeach
    </nav>
</div>
