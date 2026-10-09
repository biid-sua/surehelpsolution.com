@extends('layouts.app')

@section('title', 'Terms of Use - SureHelp Solution')
@section('description', 'Terms and conditions for using our call management services')

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">Terms of Use</h1>
            <p class="page-subtitle">Terms and conditions for using our call management services</p>
            <div class="last-updated">
                <i class="fas fa-calendar-alt"></i>
                Last Updated: August 2025
            </div>
        </div>
    </div>
</section>

<!-- Content Section -->
<section class="content-section">
    <div class="container">
        <!-- Table of Contents -->
        <div class="table-of-contents" data-aos="fade-up">
            <h2 class="toc-title">
                <i class="fas fa-list"></i>
                Table of Contents
            </h2>
            <ul class="toc-list">
                <li><a href="#introduction">Introduction</a></li>
                <li><a href="#acceptance">Acceptance of Terms</a></li>
                <li><a href="#services">Our Services</a></li>
                <li><a href="#user-accounts">User Accounts</a></li>
                <li><a href="#acceptable-use">Acceptable Use Policy</a></li>
                <li><a href="#payment-terms">Payment Terms</a></li>
                <li><a href="#intellectual-property">Intellectual Property</a></li>
                <li><a href="#privacy">Privacy and Data Protection</a></li>
                <li><a href="#disclaimers">Disclaimers</a></li>
                <li><a href="#limitation-liability">Limitation of Liability</a></li>
                <li><a href="#termination">Termination</a></li>
                <li><a href="#governing-law">Governing Law</a></li>
                <li><a href="#changes">Changes to Terms</a></li>
                <li><a href="#contact">Contact Us</a></li>
            </ul>
        </div>

        <!-- Legal Content -->
        <div class="legal-card" id="introduction" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-file-contract"></i>
                Introduction
            </h3>
            <div class="section-content">
                <p>Welcome to SureHelp Solution ("SHS," "we," "our," or "us"). These Terms of Use ("Terms") govern your use of our website, services, and platform. By accessing or using our services, you agree to be bound by these Terms.</p>
                
                <div class="highlight-box">
                    <h4>Our Services:</h4>
                    <p>SureHelp Solution provides professional call answering services, appointment booking, customer management, and related business services to help businesses manage their communications effectively.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" id="acceptance" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-check-circle"></i>
                Acceptance of Terms
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>By Using Our Services, You Agree To:</h4>
                    <ul>
                        <li>Be bound by these Terms and our Privacy Policy</li>
                        <li>Use our services only for lawful purposes</li>
                        <li>Provide accurate and complete information</li>
                        <li>Comply with all applicable laws and regulations</li>
                        <li>Respect the rights of other users and third parties</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="services" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cogs"></i>
                Our Services
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Services We Provide:</h4>
                    <ul>
                        <li><strong>Call Answering:</strong> Professional 24/7 call answering services</li>
                        <li><strong>Appointment Booking:</strong> Automated and manual appointment scheduling</li>
                        <li><strong>Customer Management:</strong> CRM integration and customer data management</li>
                        <li><strong>Message Taking:</strong> Detailed message capture and delivery</li>
                        <li><strong>Analytics:</strong> Call reporting and business insights</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="user-accounts" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-user"></i>
                User Accounts
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Account Responsibilities:</h4>
                    <ul>
                        <li>You are responsible for maintaining account security</li>
                        <li>You must provide accurate and current information</li>
                        <li>You are responsible for all activities under your account</li>
                        <li>You must notify us immediately of any unauthorized use</li>
                        <li>You may not share your account credentials with others</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="acceptable-use" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-shield-alt"></i>
                Acceptable Use Policy
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Prohibited Activities:</h4>
                    <ul>
                        <li>Using our services for illegal or unauthorized purposes</li>
                        <li>Transmitting harmful, threatening, or offensive content</li>
                        <li>Attempting to gain unauthorized access to our systems</li>
                        <li>Interfering with the proper functioning of our services</li>
                        <li>Violating any applicable laws or regulations</li>
                        <li>Infringing on intellectual property rights</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="payment-terms" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-credit-card"></i>
                Payment Terms
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Payment Information:</h4>
                    <ul>
                        <li>Fees are billed monthly in advance</li>
                        <li>All fees are non-refundable unless otherwise stated</li>
                        <li>We accept major credit cards and ACH transfers</li>
                        <li>Late payments may result in service suspension</li>
                        <li>We may change pricing with 30 days' notice</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="intellectual-property" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-copyright"></i>
                Intellectual Property
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Ownership Rights:</h4>
                    <ul>
                        <li>We retain all rights to our platform and services</li>
                        <li>You retain ownership of your business data</li>
                        <li>You grant us a license to use your data to provide services</li>
                        <li>Our trademarks and logos are protected intellectual property</li>
                        <li>You may not copy or modify our proprietary technology</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="privacy" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-lock"></i>
                Privacy and Data Protection
            </h3>
            <div class="section-content">
                <p>Your privacy is important to us. Our collection and use of personal information is governed by our <a href="{{ route('legal.privacy-policy') }}" style="color: var(--accent-color);">Privacy Policy</a>. By using our services, you consent to the collection and use of information as described in our Privacy Policy.</p>
            </div>
        </div>

        <div class="legal-card" id="disclaimers" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-exclamation-triangle"></i>
                Disclaimers
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Service Disclaimers:</h4>
                    <ul>
                        <li>Our services are provided "as is" without warranties</li>
                        <li>We do not guarantee uninterrupted service availability</li>
                        <li>We are not responsible for third-party integrations</li>
                        <li>Call quality may vary based on network conditions</li>
                        <li>We reserve the right to modify services at any time</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="limitation-liability" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-balance-scale"></i>
                Limitation of Liability
            </h3>
            <div class="section-content">
                <p>To the maximum extent permitted by law, SureHelp Solution shall not be liable for any indirect, incidental, special, consequential, or punitive damages, including but not limited to loss of profits, data, or business opportunities, arising from your use of our services.</p>
            </div>
        </div>

        <div class="legal-card" id="termination" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-times-circle"></i>
                Termination
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Termination Rights:</h4>
                    <ul>
                        <li>You may terminate your account at any time</li>
                        <li>We may terminate accounts for Terms violations</li>
                        <li>Termination does not relieve you of payment obligations</li>
                        <li>We will provide reasonable notice of termination</li>
                        <li>Data will be retained according to our retention policy</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="governing-law" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-gavel"></i>
                Governing Law
            </h3>
            <div class="section-content">
                <p>These Terms are governed by and construed in accordance with the laws of the State of Delaware, without regard to conflict of law principles. Any disputes arising from these Terms shall be resolved in the state or federal courts located in Delaware.</p>
            </div>
        </div>

        <div class="legal-card" id="changes" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-sync-alt"></i>
                Changes to Terms
            </h3>
            <div class="section-content">
                <p>We may update these Terms from time to time. We will notify you of any material changes by posting the new Terms on this page and updating the "Last Updated" date. Your continued use of our services after such changes constitutes acceptance of the new Terms.</p>
            </div>
        </div>

        <div class="legal-card" id="contact" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-envelope"></i>
                Contact Us
            </h3>
            <div class="section-content">
                <p>If you have any questions about these Terms of Use, please contact us:</p>
                
                <div class="highlight-box">
                    <h4>Contact Information:</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <p><i class="fas fa-envelope me-2"></i><strong>Email:</strong><br><a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></p>
                            <p><i class="fas fa-phone me-2"></i><strong>Phone:</strong><br>1-800-SUREHELP (1-800-787-3435)</p>
                        </div>
                        <div class="col-md-6">
                            @include('partials.company-address', ['format' => 'legal'])
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Download Section -->
<section class="content-section">
    <div class="container">
        <div class="download-section">
            <h3 class="download-title">
                <i class="fas fa-download"></i>
                Download Terms of Use
            </h3>
            <p class="download-description">
                Download a PDF copy of this Terms of Use for your records and legal documentation.
            </p>
            <button class="download-button" onclick="downloadPDF('terms-of-use')">
                <i class="fas fa-file-pdf"></i>
                Download PDF
            </button>
        </div>
    </div>
</section>
</div>
@endsection

@section('scripts')
<script>
// PDF Download Function
function downloadPDF(documentType) {
    // For now, we'll create a simple alert. In a real implementation, 
    // this would trigger a server-side PDF generation or redirect to a PDF file.
    alert('PDF download functionality will be implemented. Document type: ' + documentType);
    
    // Future implementation would be something like:
    // window.open('/download/' + documentType + '.pdf', '_blank');
}

document.addEventListener('DOMContentLoaded', function() {
    // Add smooth scroll for TOC links
    const tocLinks = document.querySelectorAll('.toc-list a');
    tocLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href').substring(1);
            const targetElement = document.getElementById(targetId);
            
            if (targetElement) {
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
                
                // Highlight the target section
                targetElement.style.background = 'rgba(79, 70, 229, 0.1)';
                setTimeout(() => {
                    targetElement.style.background = '';
                }, 2000);
            }
        });
    });

    // Add reading progress indicator
    const progressBar = document.createElement('div');
    progressBar.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 0%;
        height: 4px;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        z-index: 10000;
        transition: width 0.3s ease;
    `;
    document.body.appendChild(progressBar);

    // Update progress on scroll
    window.addEventListener('scroll', () => {
        const scrollTop = window.pageYOffset;
        const docHeight = document.documentElement.scrollHeight - window.innerHeight;
        const scrollPercent = (scrollTop / docHeight) * 100;
        progressBar.style.width = scrollPercent + '%';
    });

    // Add section visibility tracking
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

    // Add copy-to-clipboard functionality for important sections
    document.querySelectorAll('.highlight-box').forEach(box => {
        const copyBtn = document.createElement('button');
        copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
        copyBtn.style.cssText = `
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(79, 70, 229, 0.1);
            border: none;
            border-radius: 8px;
            padding: 0.5rem;
            cursor: pointer;
            color: var(--primary-color);
            transition: all 0.3s ease;
            opacity: 0;
        `;
        
        box.style.position = 'relative';
        box.appendChild(copyBtn);
        
        box.addEventListener('mouseenter', () => {
            copyBtn.style.opacity = '1';
        });
        
        box.addEventListener('mouseleave', () => {
            copyBtn.style.opacity = '0';
        });
        
        copyBtn.addEventListener('click', () => {
            const text = box.textContent;
            navigator.clipboard.writeText(text).then(() => {
                copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                copyBtn.style.background = 'rgba(16, 185, 129, 0.2)';
                copyBtn.style.color = '#10b981';
                setTimeout(() => {
                    copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                    copyBtn.style.background = 'rgba(79, 70, 229, 0.1)';
                    copyBtn.style.color = 'var(--primary-color)';
                }, 2000);
            });
        });
    });
});
</script>
@endsection
