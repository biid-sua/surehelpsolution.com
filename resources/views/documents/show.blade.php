{{-- An estimate or invoice a business sent its customer (D115), in the business's look. --}}
@php
    $money = fn (int $cents) => \App\Support\Money::format($cents);
    $isEstimate = $document->isEstimate();
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes(null) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ $document->label() }} · {{ $brand['name'] }}</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css'])
    @if ($brand['white_label'])<style>{!! \App\Services\Business\Branding::shades($brand['color']) !!}</style>@endif
</head>
<body class="h-full">
    <main class="flex min-h-full justify-center px-4 py-10">
        <div class="w-full max-w-2xl">
            <div class="text-center">
                @if ($brand['logo'])
                    <img src="{{ $brand['logo'] }}" alt="" class="mx-auto h-16 w-auto rounded-lg bg-white object-contain p-1.5">
                @endif
                <p class="mt-3 text-sm font-medium text-muted">{{ $brand['name'] }}</p>
            </div>

            <div class="mt-6 rounded-2xl border border-line bg-surface p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm text-muted">{{ $isEstimate ? __('Estimate') : __('Invoice') }} {{ $document->number }}</p>
                        <h1 class="mt-1 text-xl font-semibold text-ink">{{ $document->title }}</h1>
                        <p class="mt-1 text-sm text-muted">{{ __('For :name', ['name' => $document->customer->fullName()]) }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-semibold text-ink">{{ $document->total() }}</p>
                        @if ($isEstimate && $document->valid_until)<p class="text-xs text-subtle">{{ __('Valid until :date', ['date' => $document->valid_until->format('M j, Y')]) }}</p>@endif
                        @if (! $isEstimate && $document->due_on)<p class="text-xs text-subtle">{{ __('Due :date', ['date' => $document->due_on->format('M j, Y')]) }}</p>@endif
                    </div>
                </div>

                <div class="mt-6 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase tracking-wide text-subtle">
                            <tr class="border-b border-line">
                                <th class="py-2 pr-3 font-medium">{{ __('Description') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('Qty') }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ __('Price') }}</th>
                                <th class="py-2 pl-3 text-right font-medium">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($document->items as $item)
                                <tr>
                                    <td class="py-2 pr-3 text-ink">{{ $item['description'] }}</td>
                                    <td class="px-3 py-2 text-right text-muted">{{ rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.') }}</td>
                                    <td class="px-3 py-2 text-right text-muted">{{ $money((int) $item['unit_cents']) }}</td>
                                    <td class="py-2 pl-3 text-right text-ink">{{ $money((int) round($item['quantity'] * $item['unit_cents'])) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="text-sm">
                            @if ($document->tax_cents > 0)
                                <tr><td colspan="3" class="pt-3 text-right text-muted">{{ __('Subtotal') }}</td><td class="pt-3 text-right text-ink">{{ $money($document->subtotal_cents) }}</td></tr>
                                <tr><td colspan="3" class="text-right text-muted">{{ __('Tax (:rate%)', ['rate' => rtrim(rtrim(number_format($document->tax_rate_bp / 100, 2), '0'), '.')]) }}</td><td class="text-right text-ink">{{ $money($document->tax_cents) }}</td></tr>
                            @endif
                            <tr><td colspan="3" class="pt-2 text-right font-semibold text-ink">{{ __('Total') }}</td><td class="pt-2 text-right font-semibold text-ink">{{ $document->total() }}</td></tr>
                        </tfoot>
                    </table>
                </div>
                @if ($document->notes)<p class="mt-5 whitespace-pre-line rounded-xl bg-surface-2 p-4 text-sm text-muted">{{ $document->notes }}</p>@endif

                @if ($error)<p class="mt-5 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ $error }}</p>@endif

                <div class="mt-6 border-t border-line pt-6">
                    @if ($isEstimate)
                        @if ($document->status === 'accepted' || $document->status === 'invoiced')
                            <p class="text-base font-semibold text-ink">{{ __('Accepted, thank you!') }}</p>
                            <p class="mt-1 text-sm text-muted">{{ __(':business will be in touch to schedule the work.', ['business' => $brand['name']]) }}</p>
                        @elseif ($document->status === 'declined')
                            <p class="text-base font-semibold text-ink">{{ __('You declined this estimate.') }}</p>
                            <p class="mt-1 text-sm text-muted">{{ __('Changed your mind? Contact :business.', ['business' => $brand['name']]) }}</p>
                        @elseif ($document->isOpen())
                            <form method="POST" action="{{ route('documents.accept', $document->token) }}" class="space-y-3">
                                @csrf
                                <label for="d-name" class="sh-label">{{ __('Type your name to accept this estimate') }}</label>
                                <input id="d-name" name="name" type="text" required maxlength="120" autocomplete="name" class="sh-input" value="{{ old('name') }}">
                                @error('name')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                                <button type="submit" class="w-full rounded-xl bg-brand-600 px-5 py-3.5 text-base font-semibold text-white hover:bg-brand-500">{{ __('Accept :amount', ['amount' => $document->total()]) }}</button>
                            </form>
                            <details class="mt-4 text-sm">
                                <summary class="cursor-pointer text-muted">{{ __('Decline instead') }}</summary>
                                <form method="POST" action="{{ route('documents.decline', $document->token) }}" class="mt-3 space-y-2">
                                    @csrf
                                    <label for="d-reason" class="sh-label">{{ __('Anything you\'d like them to know? (optional)') }}</label>
                                    <textarea id="d-reason" name="reason" rows="2" maxlength="500" class="sh-input"></textarea>
                                    <button type="submit" class="rounded-lg border border-line-strong px-4 py-2 text-sm font-medium text-ink hover:bg-surface-2">{{ __('Decline the estimate') }}</button>
                                </form>
                            </details>
                        @else
                            <p class="text-sm text-muted">{{ __('This estimate is no longer open. Please contact :business.', ['business' => $brand['name']]) }}</p>
                        @endif
                    @else
                        @if ($document->status === 'paid')
                            <p class="text-base font-semibold text-ink">{{ __('Paid, thank you!') }}</p>
                        @elseif ($returning && $document->status === 'sent')
                            <p class="text-base font-semibold text-ink">{{ __('Thank you!') }}</p>
                            <p class="mt-1 text-sm text-muted">{{ __('Your payment is being confirmed. You\'ll get a receipt by email in a moment.') }}</p>
                        @elseif ($canPay)
                            <form method="POST" action="{{ route('documents.pay', $document->token) }}">
                                @csrf
                                <button type="submit" class="w-full rounded-xl bg-brand-600 px-5 py-3.5 text-base font-semibold text-white hover:bg-brand-500">{{ __('Pay :amount by card', ['amount' => $document->total()]) }}</button>
                            </form>
                            <p class="mt-3 text-center text-xs text-subtle">{{ __('Secure checkout by Stripe. The payment goes to :business.', ['business' => $brand['name']]) }}</p>
                        @elseif ($document->status === 'sent')
                            <p class="text-sm text-muted">{{ __('Please pay :business the way you agreed.', ['business' => $brand['name']]) }}</p>
                        @else
                            <p class="text-sm text-muted">{{ __('This invoice is no longer open. Please contact :business.', ['business' => $brand['name']]) }}</p>
                        @endif
                    @endif
                </div>
            </div>
            @unless ($brand['white_label'])
                <p class="mt-8 text-center text-xs text-subtle">{{ __('Sent with SureHelp Solutions') }}</p>
            @endunless
        </div>
    </main>
</body>
</html>
