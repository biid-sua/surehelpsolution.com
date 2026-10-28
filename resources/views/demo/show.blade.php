{{-- A visitor's demo call with the SureHelp team (D96): see it, move it or cancel it. --}}
<!DOCTYPE html>
<html lang="en" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes(null) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>Your demo · SureHelp</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css'])
</head>
<body class="h-full">
    <main class="flex min-h-full items-start justify-center px-4 py-12">
        <div class="w-full max-w-lg">
            <img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp" class="mx-auto h-12 w-auto">

            <div class="mt-8 rounded-2xl border border-line bg-surface p-6 shadow-sm">
                @if (session('demo_done') === 'cancelled')
                    <h1 class="text-xl font-semibold text-ink">Your demo is cancelled</h1>
                    <p class="mt-2 text-sm text-muted">We've emailed you to confirm. Whenever you'd like to talk, pick a new time below.</p>
                @elseif (session('demo_done') === 'moved')
                    <h1 class="text-xl font-semibold text-ink">You're booked for {{ $label }}</h1>
                    <p class="mt-2 text-sm text-muted">We've emailed the new invitation. See you then!</p>
                @elseif ($step === 'upcoming')
                    <p class="text-sm font-medium text-brand-300">Hi {{ \Illuminate\Support\Str::before(trim($enquiry->name).' ', ' ') }}, your demo is booked</p>
                    <h1 class="mt-1 text-xl font-semibold text-ink">{{ $label }}</h1>
                    <p class="mt-1 text-sm text-muted">30 minutes with the SureHelp team.</p>
                @elseif ($step === 'rebook')
                    <h1 class="text-xl font-semibold text-ink">Pick a new time</h1>
                    <p class="mt-2 text-sm text-muted">{{ $enquiry->demo_status === 'no_show' ? 'Sorry we missed you. ' : '' }}Choose a time that suits you and we'll send a fresh invitation.</p>
                @else
                    <h1 class="text-xl font-semibold text-ink">Thanks for meeting with us</h1>
                    <p class="mt-2 text-sm text-muted">Your next step is a free trial: setup takes a few minutes and you can be live in 48 hours.</p>
                    <a href="{{ url('/start') }}" class="mt-5 inline-block w-full rounded-xl bg-brand-600 px-5 py-3.5 text-center text-base font-semibold text-white hover:bg-brand-500">Start your free trial</a>
                @endif

                @if (session('demo_error'))
                    <p class="mt-4 rounded-xl bg-red-500/10 p-3 text-sm text-red-200 ring-1 ring-red-500/30" role="alert">{{ session('demo_error') }}</p>
                @endif

                @if ($step === 'upcoming' && $meetingUrl)
                    <a href="{{ $meetingUrl }}" rel="noopener" target="_blank" class="mt-5 inline-block w-full rounded-xl bg-brand-600 px-5 py-3.5 text-center text-base font-semibold text-white hover:bg-brand-500">Join on {{ \Illuminate\Support\Str::before($location, ': ') }}</a>
                    <p class="mt-2 text-center text-xs text-subtle">The link works from the time of the call.</p>
                @elseif ($step === 'upcoming')
                    <p class="mt-4 text-sm text-ink"><span class="text-subtle">Where:</span> {{ $location }}</p>
                @endif

                @if ($days !== [])
                    <form method="POST" action="{{ route('demo.reschedule', $enquiry->demo_token) }}" class="mt-6">
                        @csrf
                        <h2 class="text-sm font-semibold text-ink">{{ $step === 'upcoming' ? 'Need another time?' : 'Open times' }} <span class="font-normal text-subtle">({{ $timezone }})</span></h2>
                        <div class="mt-3 space-y-4">
                            @foreach ($days as $day)
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wide text-subtle">{{ $day['label'] }}</p>
                                    <div class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-4">
                                        @foreach ($day['slots'] as $slot)
                                            <button type="submit" name="starts_at" value="{{ $slot['starts_at'] }}"
                                                class="rounded-lg border border-line bg-surface-2 px-2 py-2 text-sm font-medium text-ink hover:border-brand-400 hover:bg-brand-600 hover:text-white">{{ $slot['label'] }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </form>
                @elseif (in_array($step, ['upcoming', 'rebook'], true))
                    <p class="mt-6 text-sm text-muted">There are no open times in the next two weeks. Reply to your invitation email and we'll find one.</p>
                @endif

                @if ($step === 'upcoming')
                    <form method="POST" action="{{ route('demo.cancel', $enquiry->demo_token) }}" class="mt-6 border-t border-line pt-5">
                        @csrf
                        <button type="submit" class="text-sm text-muted underline underline-offset-2 hover:text-ink">Cancel this demo</button>
                    </form>
                @endif
            </div>

            <p class="mt-6 text-center text-xs text-subtle">Questions? Reply to your invitation email. <a href="{{ url('/') }}" class="underline underline-offset-2">surehelpsolution.com</a></p>
        </div>
    </main>
</body>
</html>
