{{-- A customer's satisfaction survey (D84), in the business's look. --}}
<!DOCTYPE html>
<html lang="en" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes(null) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>How did we do? · {{ $brand['name'] }}</title>
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

            <div class="mt-6 rounded-2xl border border-line bg-surface p-6 shadow-sm">
                @if ($step === 'ask')
                    <h1 class="text-xl font-semibold text-ink">How did we do{{ $survey->customer?->first_name ? ', '.$survey->customer->first_name : '' }}?</h1>
                    <p class="mt-1 text-sm text-muted">Tap a number: 1 is poor, 5 is excellent.</p>
                    <form id="survey-form" method="POST" action="{{ route('survey.answer', $survey->token) }}" class="mt-6">
                        @csrf
                        <div class="grid grid-cols-5 gap-2" role="group" aria-label="Your rating">
                            @foreach (range(1, 5) as $n)
                                <button type="submit" name="score" value="{{ $n }}" @if ($preset === $n) data-preset @endif
                                    class="rounded-xl border border-line bg-surface-2 py-4 text-xl font-semibold text-ink transition hover:border-brand-400 hover:bg-brand-600 hover:text-white focus-visible:bg-brand-600 focus-visible:text-white {{ $preset === $n ? 'border-brand-400 bg-brand-600 text-white' : '' }}"
                                    aria-label="{{ $n }} out of 5">{{ $n }}</button>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-between text-xs text-subtle"><span>Poor</span><span>Excellent</span></div>
                    </form>
                    @if ($preset)
                        {{-- Chosen in the email: confirm it in one go (a script, so link scanners never vote). --}}
                        <script nonce="{{ Vite::cspNonce() }}">document.querySelector('[data-preset]')?.click();</script>
                    @endif
                @elseif ($step === 'happy')
                    <p class="text-4xl" aria-hidden="true">🎉</p>
                    <h1 class="mt-2 text-xl font-semibold text-ink">Thank you!</h1>
                    @if ($survey->review_url)
                        <p class="mt-2 text-sm text-muted">It means a lot. Would you share it in a quick Google review? It helps neighbours find us.</p>
                        <a href="{{ route('survey.review', $survey->token) }}" rel="noopener" class="mt-5 inline-block w-full rounded-xl bg-brand-600 px-5 py-3.5 text-base font-semibold text-white hover:bg-brand-500">Leave a Google review</a>
                    @else
                        <p class="mt-2 text-sm text-muted">We're glad we could help. See you next time.</p>
                    @endif
                @elseif ($step === 'sorry')
                    <h1 class="text-xl font-semibold text-ink">We're sorry it wasn't better</h1>
                    <p class="mt-2 text-sm text-muted">Someone from {{ $brand['name'] }} will be in touch. What could we have done better?</p>
                    <form method="POST" action="{{ route('survey.feedback', $survey->token) }}" class="mt-5 text-left">
                        @csrf
                        <label for="feedback" class="sh-label">Your feedback (only the business sees this)</label>
                        <textarea id="feedback" name="feedback" rows="4" maxlength="2000" required class="sh-input"></textarea>
                        @error('feedback') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        <button type="submit" class="mt-3 w-full rounded-xl bg-brand-600 px-5 py-3 font-semibold text-white hover:bg-brand-500">Send</button>
                    </form>
                @elseif ($step === 'closed')
                    <h1 class="text-xl font-semibold text-ink">This survey has closed</h1>
                    <p class="mt-2 text-sm text-muted">Thank you anyway. You can always contact {{ $brand['name'] }} directly.</p>
                @else
                    <h1 class="text-xl font-semibold text-ink">Thank you for telling us</h1>
                    <p class="mt-2 text-sm text-muted">We read every answer, and we'll be in touch.</p>
                @endif
            </div>
            @unless ($brand['white_label'])
                <p class="mt-8 text-xs text-subtle">Survey sent with SureHelp Solutions</p>
            @endunless
        </div>
    </main>
</body>
</html>
