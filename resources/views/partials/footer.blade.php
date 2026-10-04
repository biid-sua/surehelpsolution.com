  <!-- Footer -->
  <footer class="footer">
    <div class="footer-top">
        <div class="container">
            <div class="footer-brand">
                <a class="footer-logo" href="{{ route('home') }}">
                    <div class="logo-icon">
                        <img src="{{ asset('assets/img/logo.png') }}" alt="SHS Logo" style="width: 100%; height: 100%; object-fit: contain;">
                    </div>
                    <!-- <div class="logo-text">
                        <span class="brand-name">Sure Help Solutions</span>
                        <span class="brand-slogan">Answer. Connect. Grow.</span>
                    </div> -->
                </a>
                <p class="brand-description">From emergency calls to everyday questions our team has your back, 24/7.</p>
                <div class="social-links">
                    <a href="https://www.linkedin.com/company/surehelpsolution/" class="social-link" aria-label="LinkedIn" target="_blank" rel="noopener noreferrer">
                        <i class="fab fa-linkedin"></i>
                    </a>
                    <a href="https://www.facebook.com/surehelpsolution" class="social-link" aria-label="Facebook" target="_blank" rel="noopener noreferrer">
                        <i class="fab fa-facebook"></i>
                    </a>
                    <a href="https://x.com/SureHelpSol" class="social-link" aria-label="X" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 512 512" width="1em" height="1em" fill="currentColor" aria-hidden="true" style="vertical-align: -0.125em;">
                            <path d="M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z"/>
                        </svg>
                    </a>
                    <a href="https://www.instagram.com/surehelpsolution/" class="social-link" aria-label="Instagram" target="_blank" rel="noopener noreferrer">
                        <i class="fab fa-instagram"></i>
                    </a>
                </div>
                <div class="contact-info">
                    <div class="contact-item">
                        <i class="fas fa-phone"></i>
                        <span><a href="tel: +1 (858) 321 3947">+1 (858) 321 3947</a></span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a>
                    </div>
                    <div class="contact-item contact-item--address">
                        <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                        @include('partials.company-address')
                    </div>
                </div>
            </div>

            <div class="footer-links">
                <div class="footer-col">
                    <h5>What we do</h5>
                    <ul>
                        <li><a href="#">Call Answering</a></li>
                        <li><a href="#">Appointment Booking</a></li>
                        <li><a href="#">Customer Management</a></li>
                        <li><a href="#">Analytics Dashboard</a></li>
                        <li><a href="#">Integration Market</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h5>Who we serve</h5>
                    <ul>
                        <li><a href="#">Small Business</a></li>
                        <li><a href="#">Growing Business</a></li>
                        <li><a href="#">Enterprise</a></li>
                        <li><a href="#">Healthcare</a></li>
                        <li><a href="#">Home Services</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h5>Resources</h5>
                    <ul>
                        <li><a href="#">Success Stories</a></li>
                        <li><a href="#" data-bs-toggle="modal" data-bs-target="#roiCalculatorModal">ROI Calculator</a></li>
                        <li><a href="#">Implementation Guide</a></li>
                        <li><a href="#">Support Center</a></li>
                        <li><a href="#">API Documentation</a></li>
                    </ul>
                </div>

                <div class="footer-col">
                    <h5>Who we are</h5>
                    <ul>
                        <li><a href="#">About Us</a></li>
                        <li><a href="#">Careers</a></li>
                        <li><a href="#">Press & Media</a></li>
                        <li><a href="{{ route('home') }}#contact">Contact Us</a></li>
                        <li><a href="#">Partner Program</a></li>
                    </ul>
                </div>

                <!-- <div class="footer-col">
                    <h5>Legal</h5>
                    <ul>
                        <li><a href="{{ route('legal.terms-of-use') }}">Terms of Use</a></li>
                        <li><a href="{{ route('legal.privacy-policy') }}">Privacy Policy</a></li>
                        <li><a href="{{ route('legal.data-processing-addendum') }}">Data Processing Addendum</a></li>
                        <li><a href="{{ route('legal.business-associate-agreement') }}">Business Associate Agreement</a></li>
                        <li><a href="{{ route('legal.data-security') }}">Data Security Statement</a></li>
                        <li><a href="{{ route('legal.cookie-notice') }}">Cookie Notice</a></li>
                        <li><a href="{{ route('legal.faq') }}">FAQ</a></li>
                    </ul>
                </div> -->
            </div>
        </div>
    </div>

    <div class="footer-bottom">
        <div class="footer-bottom-content container">
            <p class="copyright">&copy; {{ now()->year }} Sure Help Solutions. All rights reserved.</p>
            <div class="legal-links">
                <a href="{{ route('legal.terms-of-use') }}">Terms of Use</a>
                <a href="{{ route('legal.privacy-policy') }}">Privacy Policy</a>
                <a href="{{ route('legal.data-processing-addendum') }}">DPA</a>
                <a href="{{ route('legal.business-associate-agreement') }}">BAA</a>
                <a href="{{ route('legal.data-security') }}">Security</a>
                <a href="{{ route('legal.cookie-notice') }}">Cookie Notice</a>
                <a href="{{ route('legal.faq') }}">FAQ</a>
            </div>
        </div>
    </div>
</footer>

  <!-- Back to Top Button -->
  <button id="backToTop" class="back-to-top" aria-label="Back to top">
    <div class="button-content">
      <div class="button-icon">
        <i class="fas fa-arrow-up"></i>
      </div>
    </div>
  </button>
