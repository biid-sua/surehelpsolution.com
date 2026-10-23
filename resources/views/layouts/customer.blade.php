@php
    // Pages a business's customers open from their emails: the business's name first, no SureHelp account needed.
    $title ??= null;
    $brand ??= null;   // the business's look (D82)
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" class="h-full" {!! app(\App\Support\Ui\Themes::class)->htmlAttributes(null) !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? __('Your appointment') }}</title>
    @include('partials.theme-boot')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @if ($brand && $brand['white_label'])<style>{!! \App\Services\Business\Branding::shades($brand['color']) !!}</style>@endif
</head>
<body class="h-full">
    <main class="flex min-h-full items-start justify-center px-4 py-10 sm:items-center">
        <div class="w-full max-w-lg">
            {{ $slot }}
            @unless ($brand['white_label'] ?? false)
                <p class="mt-6 text-center text-xs text-subtle">{{ __('Bookings handled by SureHelp Solutions') }}</p>
            @endunless
        </div>
    </main>
    <x-ui.toasts />
    @livewireScripts
</body>
</html>
