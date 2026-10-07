@php
    // Pages a business's customers open from their emails: the business's name first, no SureHelp account needed.
    $title ??= null;
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? 'Your appointment' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full">
    <main class="flex min-h-full items-start justify-center px-4 py-10 sm:items-center">
        <div class="w-full max-w-lg">
            {{ $slot }}
            <p class="mt-6 text-center text-xs text-subtle">Bookings handled by SureHelp Solutions</p>
        </div>
    </main>
    <x-ui.toasts />
    @livewireScripts
</body>
</html>
