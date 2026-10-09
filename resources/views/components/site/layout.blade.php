@props([
    'title' => null,
    'description' => 'Live receptionists answer your business calls 24/7, book appointments into your calendar and send you every detail. Go live in 48 hours, no setup fees.',
    'dark' => true, {{-- the page starts with a navy hero: the header sits on it --}}
])
@php
    $fullTitle = $title ? $title.' · SureHelp Solution' : 'SureHelp Solution · 24/7 live call answering and appointment booking';
    $canonical = url()->current();
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $fullTitle }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta name="theme-color" content="#0b0c22">
    <link rel="icon" href="{{ asset('assets/img/favicon.png') }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="SureHelp Solution">
    <meta property="og:title" content="{{ $fullTitle }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ asset('assets/img/og-image.png') }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:site" content="@SureHelpSol">

    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'SureHelp Solution',
        'url' => url('/'),
        'logo' => asset('assets/img/logo.png'),
        'email' => config('company.email'),
        'telephone' => config('marketing.phone'),
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => config('company.address.street'), 'addressLocality' => config('company.address.city'), 'addressRegion' => config('company.address.state'), 'postalCode' => config('company.address.zip'), 'addressCountry' => 'US'],
        'sameAs' => array_values(config('marketing.social')),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    {{ $head ?? '' }}

    @vite(['resources/css/site.css', 'resources/js/site.js'])
</head>
<body class="min-h-full bg-white font-sans text-slate-900 antialiased">
    <a href="#main" class="sr-only z-[60] rounded-full bg-mint-400 px-4 py-2 font-semibold text-navy-950 focus:not-sr-only focus:fixed focus:top-4 focus:left-4">Skip to content</a>

    <x-site.header :dark="$dark" />

    <main id="main">
        {{ $slot }}
    </main>

    <x-site.footer />

    @livewireScripts
</body>
</html>
