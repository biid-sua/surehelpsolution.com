@extends('layouts.app')

@section('title', 'Privacy Policy - SureHelp Solution')
@section('description', 'How we collect, use, and protect your personal information')

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">Privacy Policy</h1>
            <p class="page-subtitle">How we collect, use, and protect your personal information</p>
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
                <li><a href="#information-collection">Information We Collect</a></li>
                <li><a href="#information-use">How We Use Information</a></li>
                <li><a href="#sms-communications">Text Message Communications</a></li>
                <li><a href="#information-sharing">Information Sharing</a></li>
                <li><a href="#data-security">Data Security</a></li>
                <li><a href="#data-retention">Data Retention</a></li>
                <li><a href="#your-rights">Your Rights</a></li>
                <li><a href="#cookies">Cookies and Tracking</a></li>
                <li><a href="#third-party">Third-Party Services</a></li>
                <li><a href="#children-privacy">Children's Privacy</a></li>
                <li><a href="#changes">Changes to This Policy</a></li>
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
                <p>SureHelp Solutions ("SHS," "we," "our," or "us") is committed to protecting and respecting your privacy. We will only use your personal information to administer your account and to provide the products and services you requested from us. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our services.</p>
                
                <p>From time to time, we may contact you about our products and services, as well as other content that may be of interest to you, where you have given us permission to do so.</p>
                
                <div class="highlight-box">
                    <h4>Our Commitment:</h4>
                    <p>We are committed to transparency about our data practices and providing you with control over your personal information. This policy helps you understand how we handle your data and your rights regarding that information.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" id="information-collection" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-database"></i>
                Information We Collect
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Types of Information We Collect:</h4>
                    <ul>
                        <li><strong>Personal Information:</strong> Name, email address, phone number, business information</li>
                        <li><strong>Call Data:</strong> Call recordings, transcripts, customer communications</li>
                        <li><strong>Usage Information:</strong> How you use our services, preferences, settings</li>
                        <li><strong>Technical Information:</strong> IP address, browser type, device information</li>
                        <li><strong>Payment Information:</strong> Billing details processed securely through third-party providers</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="information-use" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cogs"></i>
                How We Use Information
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>We Use Your Information To:</h4>
                    <ul>
                        <li>Provide and maintain our call answering and business services</li>
                        <li>Process appointments and manage customer relationships</li>
                        <li>Improve our services and develop new features</li>
                        <li>Communicate with you about your account and services</li>
                        <li>Ensure security and prevent fraud</li>
                        <li>Comply with legal obligations</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="sms-communications" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-comment-dots"></i>
                Text Message Communications
            </h3>
            <div class="section-content">
                <p>If you opt in on our website or through another channel, SureHelp Solutions may send you text messages about inquiries, appointment confirmations, and appointment scheduling related to our call answering and business support services.</p>

                <div class="highlight-box">
                    <h4>SMS Terms:</h4>
                    <ul>
                        <li>Message frequency varies based on your account activity and requests.</li>
                        <li>Message and data rates may apply depending on your mobile carrier plan.</li>
                        <li>You may opt out at any time by replying <strong>STOP</strong>.</li>
                        <li>For help, reply <strong>HELP</strong> or contact us using the details in the Contact Us section below.</li>
                        <li>Consent to receive text messages is not required to purchase services, though a valid phone number may be needed to deliver certain features you request.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="information-sharing" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-share-alt"></i>
                Information Sharing
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>We May Share Information With:</h4>
                    <ul>
                        <li><strong>Service Providers:</strong> Third-party vendors who help us provide services</li>
                        <li><strong>Business Partners:</strong> With your consent for integrated services</li>
                        <li><strong>Legal Requirements:</strong> When required by law or to protect rights</li>
                        <li><strong>Business Transfers:</strong> In connection with mergers or acquisitions</li>
                        <li><strong>Consent:</strong> With your explicit consent for other purposes</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="data-security" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-shield-alt"></i>
                Data Security
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Security Measures:</h4>
                    <ul>
                        <li>Encryption of data in transit and at rest</li>
                        <li>Regular security assessments and audits</li>
                        <li>Access controls and authentication</li>
                        <li>Employee training on data protection</li>
                        <li>Incident response procedures</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="data-retention" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-clock"></i>
                Data Retention
            </h3>
            <div class="section-content">
                <p>We retain your personal information only as long as necessary to provide our services, comply with legal obligations, resolve disputes, and enforce our agreements. Call recordings and transcripts are typically retained for 90 days unless a longer retention period is required.</p>
            </div>
        </div>

        <div class="legal-card" id="your-rights" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-user-check"></i>
                Your Rights
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>You Have the Right To:</h4>
                    <ul>
                        <li><strong>Access:</strong> Request access to your personal information</li>
                        <li><strong>Correction:</strong> Request correction of inaccurate information</li>
                        <li><strong>Deletion:</strong> Request deletion of your personal information</li>
                        <li><strong>Portability:</strong> Request a copy of your data in a portable format</li>
                        <li><strong>Restriction:</strong> Request restriction of processing</li>
                        <li><strong>Objection:</strong> Object to certain processing activities</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="cookies" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cookie-bite"></i>
                Cookies and Tracking
            </h3>
            <div class="section-content">
                <p>We use cookies and similar technologies to enhance your experience, analyze usage, and provide personalized content. You can control cookie preferences through your browser settings. For more details, please see our <a href="{{ route('legal.cookie-notice') }}" style="color: var(--accent-color);">Cookie Notice</a>.</p>
            </div>
        </div>

        <div class="legal-card" id="third-party" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-external-link-alt"></i>
                Third-Party Services
            </h3>
            <div class="section-content">
                <p>Our services may integrate with third-party platforms and services. These third parties have their own privacy policies, and we encourage you to review them. We are not responsible for the privacy practices of third-party services.</p>
            </div>
        </div>

        <div class="legal-card" id="children-privacy" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-child"></i>
                Children's Privacy
            </h3>
            <div class="section-content">
                <p>Our services are not directed to children under 13 years of age. We do not knowingly collect personal information from children under 13. If we become aware that we have collected personal information from a child under 13, we will take steps to delete such information.</p>
            </div>
        </div>

        <div class="legal-card" id="changes" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-sync-alt"></i>
                Changes to This Policy
            </h3>
            <div class="section-content">
                <p>We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last Updated" date. We encourage you to review this Privacy Policy periodically.</p>
            </div>
        </div>

        <div class="legal-card" id="contact" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-envelope"></i>
                Contact Us
            </h3>
            <div class="section-content">
                <p>If you have any questions about this Privacy Policy or our data practices, please contact us:</p>
                
                <div class="highlight-box">
                    <h4>Contact Information:</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <p><i class="fas fa-envelope me-2"></i><strong>Privacy Email:</strong><br><a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></p>
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
                Download Privacy Policy
            </h3>
            <p class="download-description">
                Download a PDF copy of this Privacy Policy for your records and compliance documentation.
            </p>
            <button class="download-button" onclick="downloadPDF('privacy-policy')">
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
});
</script>
@endsection
