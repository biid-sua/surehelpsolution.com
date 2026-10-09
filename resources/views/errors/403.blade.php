<x-site.layout title="No access" description="You don't have access to this page.">
    <section class="site-glow relative flex min-h-[80vh] items-center overflow-hidden pt-28 pb-20 text-white">
        <div class="site-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
        <div class="site-container relative text-center">
            <p class="eyebrow text-mint-300">Error 403</p>
            <h1 class="mt-4 text-4xl font-semibold tracking-tight sm:text-5xl">This page isn't available to you</h1>
            <p class="mx-auto mt-5 max-w-xl text-lg text-slate-300">{{ $exception->getMessage() && $exception->getMessage() !== 'Unauthorized access.' && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : 'Your account doesn\'t have permission to open it. If you think it should, ask your account owner or contact us.' }}</p>
            <div class="mt-9 flex flex-wrap justify-center gap-3">
                @auth<a href="{{ auth()->user()->homeUrl() }}" class="btn-mint">Back to your portal</a>@else<a href="{{ route('login') }}" class="btn-mint">Sign in</a>@endauth
                <a href="{{ route('home') }}" class="btn-light">Home page</a>
            </div>
        </div>
    </section>
</x-site.layout>
