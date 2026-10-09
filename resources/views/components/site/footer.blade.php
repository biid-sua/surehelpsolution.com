@php
    $social = config('marketing.social');
    $icons = [
        'facebook' => 'M22 12a10 10 0 1 0-11.56 9.88v-6.99H7.9V12h2.54V9.8c0-2.5 1.49-3.89 3.78-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56V12h2.78l-.44 2.89h-2.34v6.99A10 10 0 0 0 22 12Z',
        'instagram' => 'M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 0 1-1.38-.9 3.72 3.72 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16ZM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63a5.88 5.88 0 0 0-2.13 1.38A5.88 5.88 0 0 0 .63 4.14C.33 4.9.13 5.78.07 7.05.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.38 2.13a5.88 5.88 0 0 0 2.13 1.38c.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.88 5.88 0 0 0 2.13-1.38 5.88 5.88 0 0 0 1.38-2.13c.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.88 5.88 0 0 0-1.38-2.13A5.88 5.88 0 0 0 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0Zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32ZM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm6.4-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88Z',
        'linkedin' => 'M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z',
        'x' => 'M18.24 2.25h3.31l-7.23 8.26 8.5 11.24h-6.66l-5.21-6.82-5.97 6.82H1.67l7.73-8.84L1.25 2.25h6.83l4.71 6.23 5.45-6.23Zm-1.16 17.52h1.83L7.08 4.13H5.12l11.96 15.64Z',
    ];
    $columns = [
        'Services' => collect(config('marketing.services'))->map(fn ($s, $slug) => [$s['nav'], route('site.services.show', $slug)])->values()->push(['How it works', route('site.how')])->all(),
        'Company' => [['About us', route('site.about')], ['Industries', route('site.industries.index')], ['Pricing', route('site.pricing')], ['FAQ', route('legal.faq')], ['Contact', route('site.contact')], ['Sign in', route('login')]],
        'Trust & legal' => [['Privacy Policy', route('legal.privacy-policy')], ['Terms of Use', route('legal.terms-of-use')], ['Data Security', route('legal.data-security')], ['Cookie Notice', route('legal.cookie-notice')], ['Data Processing Addendum', route('legal.data-processing-addendum')], ['Business Associate Agreement', route('legal.business-associate-agreement')]],
    ];
@endphp
<footer class="bg-navy-950 text-slate-300" aria-labelledby="footer-heading">
    <h2 id="footer-heading" class="sr-only">Footer</h2>
    <div class="site-container py-16">
        <div class="grid gap-12 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp Solution" class="h-9 w-auto" width="160" height="38" loading="lazy">
                <p class="mt-5 max-w-sm text-sm leading-6 text-slate-400">Live receptionists, smart scheduling and one portal for every customer conversation. Built for businesses that can't afford to miss a call.</p>
                <dl class="mt-6 space-y-2 text-sm">
                    <div><dt class="sr-only">Phone</dt><dd><a href="tel:{{ config('marketing.phone_href') }}" class="text-white hover:text-mint-300">{{ config('marketing.phone') }}</a></dd></div>
                    <div><dt class="sr-only">Email</dt><dd><a href="mailto:{{ config('company.email') }}" class="text-white hover:text-mint-300">{{ config('company.email') }}</a></dd></div>
                    <div><dt class="sr-only">Address</dt><dd class="text-slate-400">{{ config('company.address.line1') }}<br>{{ config('company.address.line2') }}</dd></div>
                </dl>
                <ul class="mt-6 flex gap-3" role="list">
                    @foreach ($social as $network => $url)
                        <li>
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="flex size-9 items-center justify-center rounded-full bg-white/5 text-slate-300 ring-1 ring-white/10 hover:bg-white/10 hover:text-white">
                                <span class="sr-only">SureHelp on {{ ucfirst($network) }}</span>
                                <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="{{ $icons[$network] }}" /></svg>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
            @foreach ($columns as $heading => $links)
                <div>
                    <h3 class="text-sm font-semibold text-white">{{ $heading }}</h3>
                    <ul class="mt-4 space-y-3 text-sm" role="list">
                        @foreach ($links as [$label, $url])
                            <li><a href="{{ $url }}" class="text-slate-400 hover:text-white">{{ $label }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
        <div class="mt-14 flex flex-col gap-4 border-t border-white/10 pt-8 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ now()->year }} SureHelp Solution. All rights reserved.</p>
            <p>Calls answered by people. Data handled with care.</p>
        </div>
    </div>
</footer>
