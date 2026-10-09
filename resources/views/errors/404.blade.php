<x-site.layout title="Page not found" description="This page doesn't exist.">
    <section class="site-glow relative flex min-h-[80vh] items-center overflow-hidden pt-28 pb-20 text-white">
        <div class="site-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
        <div class="site-container relative text-center">
            <p class="eyebrow text-mint-300">Error 404</p>
            <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">We couldn't find that page</h1>
            <p class="mx-auto mt-5 max-w-xl text-lg text-slate-300">The link may be old, or the page may have moved. Everything we offer is a click away.</p>
            <div class="mt-9 flex flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}" class="btn-mint">Go to the home page</a>
                @auth<a href="{{ auth()->user()->homeUrl() }}" class="btn-light">Open your portal</a>@else<a href="{{ route('site.contact') }}" class="btn-light">Contact us</a>@endauth
            </div>
        </div>
    </section>
</x-site.layout>
