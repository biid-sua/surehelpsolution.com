@props(['active'])
{{-- Training management sections (D41). Each page checks its own permission again. --}}
@php
    $user = auth()->user();
    $tabs = array_filter([
        'courses' => ['Courses', 'agent.training.courses', true],
        'paths' => ['Learning paths', 'agent.training.paths', $user->hasPermissionIn('training.update')],
        'progress' => ['Progress', 'agent.training.progress', $user->hasPermissionIn('training.view_progress')],
        'certificates' => ['Certifications', 'agent.training.certificates', $user->hasPermissionIn('training.view_progress')],
    ], fn ($tab) => $tab[2]);
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Training management">
    @foreach ($tabs as $key => [$label, $route])
        <a href="{{ route($route) }}" @if ($active === $key) aria-current="page" @endif
            @class(['-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium', 'border-brand-400 text-ink' => $active === $key, 'border-transparent text-muted hover:text-ink' => $active !== $key])>{{ $label }}</a>
    @endforeach
</nav>
