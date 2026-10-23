{{-- A customer's payment link (D85), in the business's look. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes(null) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ $payment->amount() }} · {{ $brand['name'] }}</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css'])
    @if ($brand['white_label'])<style>{!! \App\Services\Business\Branding::shades($brand['color']) !!}</style>@endif
</head>
<body class="h-full">
    <main class="flex min-h-full items-start justify-center px-4 py-12 sm:items-center">
        <div class="w-full max-w-md text-center">
            @if ($brand['logo'])
                <img src="{{ $brand['logo'] }}" alt="" class="mx-auto h-16 w-auto rounded-lg bg-white object-contain p-1.5">
            @endif
            <p class="mt-3 text-sm font-medium text-muted">{{ $brand['name'] }}</p>

            <div class="mt-6 rounded-2xl border border-line bg-surface p-6 text-left shadow-sm">
                @if ($payment->status === 'paid')
                    <h1 class="text-xl font-semibold text-ink">{{ $payment->customer?->first_name ? __('Paid, thank you, :name!', ['name' => $payment->customer->first_name]) : __('Paid, thank you!') }}</h1>
                    <p class="mt-2 text-sm text-muted">{{ __(':amount for :what. A receipt was emailed to you by Stripe.', ['amount' => $payment->amount(), 'what' => $payment->description]) }}</p>
                @elseif ($returning && $payment->status === 'open')
                    <h1 class="text-xl font-semibold text-ink">{{ __('Thank you!') }}</h1>
                    <p class="mt-2 text-sm text-muted">{{ __('Your payment is being confirmed. You\'ll get a receipt by email in a moment.') }}</p>
                @elseif ($payment->status !== 'open')
                    <h1 class="text-xl font-semibold text-ink">{{ __('This payment link is no longer active') }}</h1>
                    <p class="mt-2 text-sm text-muted">{{ __('Please contact :business if you think this is a mistake.', ['business' => $brand['name']]) }}</p>
                @else
                    <p class="text-sm text-muted">{{ $payment->kind === 'deposit' ? __('Deposit requested') : __('Payment requested') }}</p>
                    <p class="mt-1 text-3xl font-semibold text-ink">{{ $payment->amount() }}</p>
                    <p class="mt-2 text-sm text-ink">{{ $payment->description }}</p>
                    @if ($error)<p class="mt-4 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ $error }}</p>@endif
                    <form method="POST" action="{{ route('pay.checkout', $payment->token) }}" class="mt-6">
                        @csrf
                        <button type="submit" class="w-full rounded-xl bg-brand-600 px-5 py-3.5 text-base font-semibold text-white hover:bg-brand-500">{{ __('Pay :amount by card', ['amount' => $payment->amount()]) }}</button>
                    </form>
                    <p class="mt-3 text-center text-xs text-subtle">{{ __('Secure checkout by Stripe. Your card details go straight to Stripe, and the payment goes to :business.', ['business' => $brand['name']]) }}</p>
                @endif
            </div>
            @unless ($brand['white_label'])
                <p class="mt-8 text-xs text-subtle">{{ __('Payments handled by SureHelp Solutions') }}</p>
            @endunless
        </div>
    </main>
</body>
</html>
