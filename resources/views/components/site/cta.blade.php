@props(['title' => 'Stop missing calls this week', 'lead' => 'Tell us about your business and we\'ll have your line answered in 48 hours. No setup fees, cancel any time, and a 30-day money-back guarantee.'])
<section class="bg-white py-20 sm:py-24">
    <div class="site-container">
        <div class="site-glow relative overflow-hidden rounded-3xl px-6 py-16 text-center text-white sm:px-16">
            <div class="site-grid pointer-events-none absolute inset-0" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-2xl">
                <h2 class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl">{{ $title }}</h2>
                <p class="mt-5 text-lg leading-8 text-slate-300">{{ $lead }}</p>
                <div class="mt-8 flex flex-wrap justify-center gap-3">
                    <a href="{{ route('site.contact', ['topic' => 'setup']) }}" class="btn-mint">Start your free setup</a>
                    <a href="{{ route('site.contact', ['topic' => 'demo']) }}" class="btn-light">Book a demo</a>
                </div>
                <p class="mt-6 text-sm text-slate-400">Prefer to talk? Call <a href="tel:{{ config('marketing.phone_href') }}" class="font-medium text-white hover:text-mint-300">{{ config('marketing.phone') }}</a></p>
            </div>
        </div>
    </div>
</section>
