{{-- The Modern design's footer (D121). --}}
@php $home = request()->routeIs('home') ? '' : route('home'); @endphp
<footer class="ftr">
    <div class="wrap">
        <div class="ftr-grid">
            <div class="ftr-brand">
                <img src="{{ asset('assets/img/logo-on-light.png') }}" alt="{{ config('site.name') }}" width="126" height="32" loading="lazy">
                <p>Live receptionists and the software to run your front office, for small businesses across the US.</p>
            </div>
            <div>
                <h4>Product</h4>
                <ul>
                    <li><a href="{{ route('pages.show', 'call-answering') }}">Call answering</a></li>
                    <li><a href="{{ route('pages.show', 'appointment-booking') }}">Appointment booking</a></li>
                    <li><a href="{{ route('pages.show', 'customer-management') }}">Customer management</a></li>
                    <li><a href="{{ route('pages.show', 'integrations') }}">Integrations</a></li>
                    <li><a href="{{ $home }}#pricing">Pricing</a></li>
                </ul>
            </div>
            <div>
                <h4>Company</h4>
                <ul>
                    <li><a href="{{ route('legal.faq') }}">FAQ</a></li>
                    <li><a href="{{ route('pages.show', 'help-center') }}">Help center</a></li>
                    <li><a href="{{ $home }}#contact">Contact</a></li>
                    <li><a href="{{ route('login') }}">Sign in</a></li>
                </ul>
            </div>
            <div>
                <h4>Legal</h4>
                <ul>
                    <li><a href="{{ route('legal.terms-of-use') }}">Terms of Use</a></li>
                    <li><a href="{{ route('legal.privacy-policy') }}">Privacy Policy</a></li>
                    <li><a href="{{ route('legal.cookie-notice') }}">Cookie Notice</a></li>
                    <li><a href="{{ route('legal.data-security') }}">Data Security</a></li>
                    <li><a href="{{ route('legal.business-associate-agreement') }}">Business Associate Agreement</a></li>
                </ul>
            </div>
        </div>
        <div class="ftr-base">
            <span>© {{ now()->year }} {{ config('site.name') }}. All rights reserved.</span>
            <span>{{ config('company.address.line1') }}, {{ config('company.address.line2') }}</span>
        </div>
    </div>
</footer>
