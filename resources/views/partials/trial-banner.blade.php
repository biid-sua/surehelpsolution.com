{{-- Free trial from the website (D97): days and calls left, or read-only once it has ended. --}}
@php
    $trials = app(\App\Services\Billing\Trials::class);
    $pending = $trials->pending($organization);
    $ended = ! $pending && $trials->ended($organization);
    $onBilling = request()->routeIs('app.billing');
@endphp
@if (session('trial_locked'))
    <x-ui.alert tone="warning" class="mb-6">{{ session('trial_locked') }}</x-ui.alert>
@elseif ($ended)
    <x-ui.alert tone="warning" class="mb-6" title="Your free trial has ended">
        Your account is read-only and nothing has been deleted. Choose a plan to switch everything back on, including our receptionists.
        @unless ($onBilling)<a href="{{ route('app.billing') }}" class="ml-1 font-semibold underline underline-offset-2">Choose a plan</a>@endunless
    </x-ui.alert>
@elseif ($pending)
    @php
        $days = $trials->daysLeft($pending);
        $included = $trials->includedCalls();
        $used = $included !== null ? $trials->callsUsed($pending) : null;
    @endphp
    <x-ui.alert :tone="$days <= 3 || ($included !== null && $used >= $included) ? 'warning' : 'info'" class="mb-6"
        :title="$days === 0 ? 'Your free trial ends today' : ($days === 1 ? 'Your free trial ends tomorrow' : 'Free trial: '.$days.' days left')">
        @if ($included !== null && $used >= $included)
            You've used the {{ $included }} calls our receptionists answer during the trial, so calls now go to your own number. Keep your plan to have us answer again.
        @else
            Keep your {{ $pending->plan->name }} plan to carry on after the trial: nothing is charged until it ends.@if ($included !== null) {{ $used }} of {{ $included }} trial calls answered so far.@endif
        @endif
        @unless ($onBilling)<a href="{{ route('app.billing') }}" class="ml-1 font-semibold underline underline-offset-2">Keep my plan</a>@endunless
    </x-ui.alert>
@endif
