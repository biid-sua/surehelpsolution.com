{{-- The Modern website design (D121): the home page and every public page that uses layouts.app while
     Modern is live or being previewed. Its own header, footer, styles and script; no Bootstrap. --}}
@php($designs = app(\App\Support\Ui\SiteDesigns::class))
@if ($designs->previewing())
    @section('robots', 'noindex, nofollow')
@endif
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.site-head')
    <meta name="theme-color" content="#fbfaf6">
    <link rel="icon" href="{{ asset('assets/img/favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('assets/img/favicon.png') }}">
    @stack('head')
    @vite(['resources/css/site-modern.css', 'resources/js/site-modern.js'])
    <script nonce="{{ Vite::cspNonce() }}">
        // Buttons name the page function they run (data-call, data-arg) instead of inline onclick
        // attributes, which the Content-Security-Policy blocks (D67).
        document.addEventListener('click', (event) => {
            const el = event.target.closest('[data-call]');
            if (!el) return;
            if (el.dataset.call === 'removeParent') { el.parentElement?.remove(); return; }
            const fn = window[el.dataset.call];
            if (typeof fn === 'function') fn(el.dataset.arg);
        });
    </script>
</head>
<body class="hm">
<a class="skip" href="#main">Skip to content</a>
@include('partials.site-preview-bar')
@include('partials.modern.header')

<main id="main">
    @yield('content')
</main>

@include('partials.modern.footer')
@include('partials.sales-chat')
@yield('scripts')
</body>
</html>
