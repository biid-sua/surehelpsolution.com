@extends('layouts.app')

@section('title', $page['title'].' - SureHelp Solution')
@section('description', $page['subtitle'])

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
<style>
    .page-card-grid .page-card { height: 100%; padding: 1.25rem; border-radius: 14px; background: rgba(99, 102, 241, 0.06); border: 1px solid rgba(99, 102, 241, 0.15); }
    .page-card-grid .page-card h4 { font-size: 1rem; font-weight: 600; margin-bottom: .4rem; }
    .page-card-grid .page-card p { margin: 0; font-size: .95rem; }
    .page-cta { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1rem; }
    .roi-result { padding: 1.25rem; border-radius: 14px; background: linear-gradient(135deg, #1E3A8A 0%, #625ED0 50%, #4D8BCC 100%); color: #fff; text-align: center; }
    .roi-result .amount { font-size: 2rem; font-weight: 700; }
    .roi-field label { font-weight: 600; display: flex; justify-content: space-between; }
    .related-links a { display: inline-block; margin: 0 .5rem .5rem 0; }
</style>
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">{{ $page['title'] }}</h1>
            <p class="page-subtitle">{{ $page['subtitle'] }}</p>
        </div>
    </div>
</section>

<!-- Content Section -->
<section class="content-section">
    <div class="container">
        @if ($page['calculator'] ?? false)
            <div class="legal-card" data-aos="fade-up" id="roi-calculator">
                <h3 class="section-title"><i class="fas fa-calculator"></i> Calculate your loss</h3>
                <div class="section-content">
                    <div class="row g-4 align-items-center">
                        <div class="col-lg-7">
                            <div class="roi-field mb-4">
                                <label for="roi-calls">Calls you miss each month <span id="roi-calls-value">20</span></label>
                                <input type="range" class="form-range" id="roi-calls" min="1" max="200" value="20">
                            </div>
                            <div class="roi-field mb-4">
                                <label for="roi-value">Average value of a new customer <span>$<span id="roi-value-value">250</span></span></label>
                                <input type="range" class="form-range" id="roi-value" min="25" max="2000" step="25" value="250">
                            </div>
                            <div class="roi-field">
                                <label for="roi-rate">Callers who would have booked <span><span id="roi-rate-value">30</span>%</span></label>
                                <input type="range" class="form-range" id="roi-rate" min="5" max="80" step="5" value="30">
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="roi-result mb-3">
                                <div>Revenue lost per month</div>
                                <div class="amount" id="roi-monthly">$1,500</div>
                            </div>
                            <div class="roi-result">
                                <div>Revenue lost per year</div>
                                <div class="amount" id="roi-yearly">$18,000</div>
                            </div>
                        </div>
                    </div>
                    <p class="mt-3 mb-0 small text-muted">An estimate from your own numbers, not a promise of results.</p>
                </div>
            </div>
        @endif

        @foreach ($page['sections'] as $section)
            <div class="legal-card" data-aos="fade-up">
                <h3 class="section-title">
                    <i class="fas {{ $section['icon'] }}"></i>
                    {{ $section['heading'] }}
                </h3>
                <div class="section-content">
                    @foreach ($section['text'] ?? [] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach

                    @if (! empty($section['points']))
                        <ul>
                            @foreach ($section['points'] as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if (! empty($section['cards']))
                        <div class="row g-3 page-card-grid">
                            @foreach ($section['cards'] as [$cardTitle, $cardText])
                                <div class="col-md-6 col-lg-4">
                                    <div class="page-card">
                                        <h4>{{ $cardTitle }}</h4>
                                        <p>{{ $cardText }}</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($section['highlight']))
                        <div class="highlight-box">
                            <h4>{{ $section['highlight'][0] }}</h4>
                            <p>{{ $section['highlight'][1] }}</p>
                        </div>
                    @endif

                    @if ($section['logo'] ?? false)
                        <p><a href="{{ asset('assets/img/logo.png') }}" download class="btn btn-outline-primary rounded-pill px-4"><i class="fas fa-download me-2"></i>Download logo (PNG)</a></p>
                    @endif

                    @if ($section['address'] ?? false)
                        <p class="mb-1"><strong>Email:</strong> <a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></p>
                        <p class="mb-1"><strong>Phone:</strong> <a href="tel:+18583213947">+1 (858) 321 3947</a></p>
                        <p class="mb-0"><strong>Address:</strong> {{ config('company.address.line1') }}, {{ config('company.address.line2') }}</p>
                    @endif
                </div>
            </div>
        @endforeach

        @if ($page['help_links'] ?? false)
            <div class="legal-card" data-aos="fade-up">
                <h3 class="section-title"><i class="fas fa-book"></i> Helpful pages</h3>
                <div class="section-content related-links">
                    <a href="{{ route('legal.faq') }}" class="btn btn-outline-primary rounded-pill px-4">FAQ</a>
                    <a href="{{ route('pages.show', 'implementation-guide') }}" class="btn btn-outline-primary rounded-pill px-4">Implementation Guide</a>
                    <a href="{{ route('pages.show', 'integrations') }}" class="btn btn-outline-primary rounded-pill px-4">Integration Directory</a>
                    <a href="{{ route('legal.data-security') }}" class="btn btn-outline-primary rounded-pill px-4">Data Security</a>
                    <a href="{{ route('login') }}" class="btn btn-outline-primary rounded-pill px-4">Sign in to your portal</a>
                </div>
            </div>
        @endif

        <!-- Call to action -->
        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title"><i class="fas fa-rocket"></i> Ready to get started?</h3>
            <div class="section-content">
                <p>Tell us about your business and we'll have your calls answered within 48 hours. No setup fees, cancel anytime, and a 30-day money-back guarantee.</p>
                <div class="page-cta">
                    <a href="{{ route('home') }}#contact" class="btn btn-primary rounded-pill px-4 py-2">Book a Demo</a>
                    <a href="{{ route('home') }}#pricing" class="btn btn-outline-primary rounded-pill px-4 py-2">View Pricing</a>
                    <a href="tel:+18583213947" class="btn btn-outline-primary rounded-pill px-4 py-2"><i class="fas fa-phone-alt me-2"></i>+1 (858) 321 3947</a>
                </div>
            </div>
        </div>

        @if ($related->isNotEmpty())
            <div class="legal-card" data-aos="fade-up">
                <h3 class="section-title"><i class="fas fa-compass"></i> Explore more</h3>
                <div class="section-content related-links">
                    @foreach ($related as $slug => $other)
                        <a href="{{ route('pages.show', $slug) }}" class="btn btn-outline-primary rounded-pill px-4">{{ $other['title'] }}</a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Same entrance animation as the legal pages.
    const sections = document.querySelectorAll('.legal-card');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });
    sections.forEach(section => {
        section.style.opacity = '0';
        section.style.transform = 'translateY(30px)';
        section.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(section);
    });

    // ROI calculator: the visitor's own numbers, nothing sent anywhere.
    const calls = document.getElementById('roi-calls');
    if (!calls) return;
    const value = document.getElementById('roi-value');
    const rate = document.getElementById('roi-rate');
    const money = (n) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(n);
    const update = () => {
        document.getElementById('roi-calls-value').textContent = calls.value;
        document.getElementById('roi-value-value').textContent = value.value;
        document.getElementById('roi-rate-value').textContent = rate.value;
        const monthly = Math.round(calls.value * (rate.value / 100) * value.value);
        document.getElementById('roi-monthly').textContent = money(monthly);
        document.getElementById('roi-yearly').textContent = money(monthly * 12);
    };
    [calls, value, rate].forEach(input => input.addEventListener('input', update));
    update();
});
</script>
@endsection
