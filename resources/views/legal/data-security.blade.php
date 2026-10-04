@extends('layouts.app')

@section('title', 'Data Security Statement - SureHelp Solution')
@section('description', 'Our commitment to protecting your data with enterprise-grade security')

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">Data Security Statement</h1>
            <p class="page-subtitle">Our commitment to protecting your data with enterprise-grade security</p>
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
                <li><a href="#security-framework">Security Framework</a></li>
                <li><a href="#data-encryption">Data Encryption</a></li>
                <li><a href="#access-controls">Access Controls</a></li>
                <li><a href="#infrastructure">Infrastructure Security</a></li>
                <li><a href="#monitoring">Monitoring & Incident Response</a></li>
                <li><a href="#compliance">Compliance & Certifications</a></li>
                <li><a href="#contact">Contact Us</a></li>
            </ul>
        </div>

        <!-- Legal Content -->
        <div class="legal-card" id="introduction" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-shield-alt"></i>
                Introduction
            </h3>
            <div class="section-content">
                <p>SureHelp Solution ("SHS") is committed to protecting the security and privacy of your data. This Data Security Statement outlines our comprehensive security measures, practices, and commitments to ensure your information remains secure.</p>
                
                <div class="highlight-box">
                    <h4>Our Security Commitment:</h4>
                    <p>We implement enterprise-grade security measures to protect your data from unauthorized access, use, disclosure, alteration, or destruction. Our security program is designed to meet or exceed industry standards and regulatory requirements.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" id="security-framework" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cogs"></i>
                Security Framework
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Our Security Framework Includes:</h4>
                    <ul>
                        <li><strong>ISO 27001 Compliance:</strong> Information security management system</li>
                        <li><strong>SOC 2 Type II:</strong> Security, availability, and confidentiality controls</li>
                        <li><strong>Regular Security Assessments:</strong> Third-party penetration testing</li>
                        <li><strong>Security Training:</strong> Ongoing employee security awareness</li>
                        <li><strong>Incident Response Plan:</strong> Comprehensive incident management</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="data-encryption" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-lock"></i>
                Data Encryption
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Encryption Standards:</h4>
                    <ul>
                        <li><strong>Data in Transit:</strong> TLS 1.3 encryption for all communications</li>
                        <li><strong>Data at Rest:</strong> AES-256 encryption for stored data</li>
                        <li><strong>Database Encryption:</strong> Transparent data encryption (TDE)</li>
                        <li><strong>Backup Encryption:</strong> Encrypted backups with separate keys</li>
                        <li><strong>Key Management:</strong> Hardware security modules (HSM)</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="access-controls" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-user-shield"></i>
                Access Controls
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Access Control Measures:</h4>
                    <ul>
                        <li><strong>Multi-Factor Authentication:</strong> Required for all administrative access</li>
                        <li><strong>Role-Based Access:</strong> Principle of least privilege</li>
                        <li><strong>Regular Access Reviews:</strong> Quarterly access audits</li>
                        <li><strong>Session Management:</strong> Automatic session timeouts</li>
                        <li><strong>Privileged Access Management:</strong> Just-in-time access provisioning</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="infrastructure" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-server"></i>
                Infrastructure Security
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Infrastructure Security Measures:</h4>
                    <ul>
                        <li><strong>Cloud Security:</strong> AWS/Azure with enterprise-grade controls</li>
                        <li><strong>Network Security:</strong> Firewalls, intrusion detection systems</li>
                        <li><strong>DDoS Protection:</strong> Cloudflare and AWS Shield</li>
                        <li><strong>Vulnerability Management:</strong> Regular security patches</li>
                        <li><strong>Disaster Recovery:</strong> Multi-region backup and recovery</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="monitoring" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-eye"></i>
                Monitoring & Incident Response
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Monitoring & Response:</h4>
                    <ul>
                        <li><strong>24/7 Security Monitoring:</strong> Continuous threat detection</li>
                        <li><strong>Security Information and Event Management (SIEM):</strong> Centralized logging</li>
                        <li><strong>Incident Response Team:</strong> Dedicated security professionals</li>
                        <li><strong>Breach Notification:</strong> 72-hour notification timeline</li>
                        <li><strong>Forensic Capabilities:</strong> Digital forensics and analysis</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="compliance" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-certificate"></i>
                Compliance & Certifications
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Compliance Standards:</h4>
                    <ul>
                        <li><strong>HIPAA:</strong> Healthcare data protection compliance</li>
                        <li><strong>GDPR:</strong> European data protection regulation</li>
                        <li><strong>CCPA:</strong> California consumer privacy act</li>
                        <li><strong>SOX:</strong> Sarbanes-Oxley compliance</li>
                        <li><strong>PCI DSS:</strong> Payment card industry standards</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="contact" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-envelope"></i>
                Contact Us
            </h3>
            <div class="section-content">
                <p>For security-related questions or to report a security incident, please contact us:</p>
                
                <div class="highlight-box">
                    <h4>Security Contact Information:</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <p><i class="fas fa-envelope me-2"></i><strong>Security Email:</strong><br><a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></p>
                            <p><i class="fas fa-phone me-2"></i><strong>Security Hotline:</strong><br>1-800-SUREHELP (1-800-787-3435)</p>
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
                Download Data Security Statement
            </h3>
            <p class="download-description">
                Download a PDF copy of this Data Security Statement for your records and security documentation.
            </p>
            <button class="download-button" onclick="downloadPDF('data-security-statement')">
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

    // Add security badge animations
    document.querySelectorAll('.section-title i').forEach(icon => {
        icon.addEventListener('mouseenter', function() {
            this.style.animation = 'pulse 0.6s ease-in-out';
        });
        
        icon.addEventListener('animationend', function() {
            this.style.animation = '';
        });
    });

    // Add security level indicators
    document.querySelectorAll('.highlight-box').forEach(box => {
        const securityLevel = document.createElement('div');
        securityLevel.className = 'security-level-indicator';
        securityLevel.innerHTML = '<i class="fas fa-shield-alt"></i> Enterprise Grade';
        securityLevel.style.cssText = `
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            opacity: 0;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        `;
        
        box.style.position = 'relative';
        box.appendChild(securityLevel);
        
        box.addEventListener('mouseenter', () => {
            securityLevel.style.opacity = '1';
            securityLevel.style.transform = 'translateY(-5px)';
        });
        
        box.addEventListener('mouseleave', () => {
            securityLevel.style.opacity = '0';
            securityLevel.style.transform = 'translateY(0)';
        });
    });
});
</script>
@endsection
