{{-- A customer unsubscribed from a business's reminders and offers (D116). --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes(null) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Unsubscribed') }} · {{ $brand['name'] }}</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css'])
</head>
<body class="h-full">
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full max-w-md rounded-2xl border border-line bg-surface p-6 text-center shadow-sm">
            <h1 class="text-xl font-semibold text-ink">{{ __('You\'re unsubscribed') }}</h1>
            <p class="mt-2 text-sm text-muted">{{ __(':business won\'t send you reminders or offers any more. You\'ll still hear about appointments you book.', ['business' => $brand['name']]) }}</p>
        </div>
    </main>
</body>
</html>
