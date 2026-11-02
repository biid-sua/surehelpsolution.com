{{-- The Modern design's header (D121). Section links point at the home page from every page. --}}
@php $home = request()->routeIs('home') ? '' : route('home'); @endphp
<header class="hdr">
    <div class="wrap hdr-in">
        <a class="hdr-logo" href="{{ route('home') }}" aria-label="{{ config('site.name') }} home">
            <img src="{{ asset('assets/img/logo-on-light.png') }}" alt="{{ config('site.name') }}" width="134" height="34">
        </a>
        <nav class="nav" aria-label="Main">
            <a href="{{ $home }}#features">Features</a>
            <a href="{{ $home }}#how">How it works</a>
            <a href="{{ $home }}#industries">Industries</a>
            <a href="{{ $home }}#pricing">Pricing</a>
            <a href="{{ $home }}#faq">FAQ</a>
        </nav>
        <div class="hdr-cta">
            <a class="signin" href="{{ route('login') }}">Sign in</a>
            <a class="btn btn-primary btn-sm" href="{{ route('signup') }}">Start free trial {{ \App\Support\Ui\SiteIcons::svg('arrow') }}</a>
        </div>
        <button type="button" class="menu-btn" data-menu aria-controls="mnav" aria-expanded="false" aria-label="Open menu">{{ \App\Support\Ui\SiteIcons::svg('menu') }}</button>
    </div>
    <div class="mnav" id="mnav">
        <a class="link" href="{{ $home }}#features">Features</a>
        <a class="link" href="{{ $home }}#how">How it works</a>
        <a class="link" href="{{ $home }}#industries">Industries</a>
        <a class="link" href="{{ $home }}#pricing">Pricing</a>
        <a class="link" href="{{ $home }}#faq">FAQ</a>
        <a class="link" href="{{ $home }}#contact">Contact</a>
        <div class="btns">
            <a class="btn btn-primary" href="{{ route('signup') }}">Start free trial</a>
            <a class="btn btn-ghost" href="{{ route('login') }}">Sign in</a>
        </div>
    </div>
</header>
