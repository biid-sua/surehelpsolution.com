{{-- Sub-navigation for the Business section. --}}
@php
    $tabs = [
        'app.business.profile' => 'Profile',
        'app.business.hours' => 'Hours',
        'app.business.services' => 'Services',
        'app.business.knowledge' => 'Knowledge',
        'app.business.rules' => 'Rules',
        'app.business.outcomes' => 'Call outcomes',
        'app.business.phone' => 'Phone line',
        'app.business.emails' => 'Customer emails',
        'app.business.automations' => 'Automations',
        'app.business.branding' => 'Branding',
        'app.business.calendars' => 'Calendar sync',
        'app.business.integrations' => 'API & webhooks',
    ];
    // Connected apps (D110, D111) only where the business can use one.
    $current = app(\App\Support\Tenancy\CurrentOrganization::class)->get();
    $features = app(\App\Services\Billing\FeatureAccess::class);
    if ($current && collect(array_keys(\App\Livewire\Client\Business\ConnectedApps::APPS))->contains(fn ($app) => $features->allows($current, $app))) {
        $tabs['app.business.apps'] = 'Connected apps';
    }
@endphp
<nav class="mb-6 flex gap-1 overflow-x-auto border-b border-line" aria-label="Business settings">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
            @class([
                '-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                'border-brand-400 text-ink' => request()->routeIs($route),
                'border-transparent text-muted hover:text-ink' => ! request()->routeIs($route),
            ])>{{ $label }}</a>
    @endforeach
</nav>
