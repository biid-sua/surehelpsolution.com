@extends('layouts.app')

@section('title', 'Frequently Asked Questions - SureHelp Solution')
@section('description', 'Common questions about our call management services')

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">Frequently Asked Questions</h1>
            <p class="page-subtitle">Common questions about our call management services</p>
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
        <!-- FAQ Content -->
        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-question-circle"></i>
                General Questions
            </h3>
            <div class="section-content">
                <div class="faq-item">
                    <h5>What services does SureHelp Solution provide?</h5>
                    <p>SureHelp Solution provides professional call answering services, appointment booking, customer management, and CRM integrations for businesses of all sizes. We offer 24/7 support to ensure you never miss important calls.</p>
                </div>

                <div class="faq-item">
                    <h5>How quickly can I get started?</h5>
                    <p>We can have your business up and running with our services within 48 hours of signup. Our onboarding process is streamlined to get you connected quickly.</p>
                </div>

                <div class="faq-item">
                    <h5>What industries do you serve?</h5>
                    <p>We serve a wide range of industries including healthcare, home services, legal services, real estate, small businesses, and more. Our services are tailored to meet industry-specific needs.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-phone"></i>
                Call Answering Services
            </h3>
            <div class="section-content">
                <div class="faq-item">
                    <h5>How does the call answering service work?</h5>
                    <p>Our professional agents answer your calls 24/7 using your business name. They take detailed messages, handle basic inquiries, and can transfer urgent calls to you when needed.</p>
                </div>

                <div class="faq-item">
                    <h5>Can I customize how calls are handled?</h5>
                    <p>Yes, we provide extensive customization options including call scripts, business hours, escalation procedures, and specific instructions for different types of calls.</p>
                </div>

                <div class="faq-item">
                    <h5>How are messages delivered to me?</h5>
                    <p>Messages can be delivered via email, SMS, phone calls, or through our secure client portal. You can choose your preferred delivery method and timing.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-calendar-check"></i>
                Appointment Booking
            </h3>
            <div class="section-content">
                <div class="faq-item">
                    <h5>Can you book appointments for my business?</h5>
                    <p>Yes, our agents can schedule appointments directly into your calendar system. We integrate with popular calendar platforms and can sync with your existing scheduling software.</p>
                </div>

                <div class="faq-item">
                    <h5>What calendar systems do you support?</h5>
                    <p>We support Google Calendar, Outlook, Apple Calendar, and most popular CRM systems. We can also work with custom scheduling solutions.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-dollar-sign"></i>
                Pricing & Billing
            </h3>
            <div class="section-content">
                <div class="faq-item">
                    <h5>How is pricing structured?</h5>
                    <p>Our pricing is based on the number of calls handled and services selected. We offer flexible plans starting from basic call answering to comprehensive business solutions.</p>
                </div>

                <div class="faq-item">
                    <h5>Are there any setup fees?</h5>
                    <p>No, there are no setup fees. You only pay for the services you use on a monthly basis.</p>
                </div>

                <div class="faq-item">
                    <h5>Can I change my plan at any time?</h5>
                    <p>Yes, you can upgrade or downgrade your plan at any time. Changes take effect on your next billing cycle.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-shield-alt"></i>
                Security & Privacy
            </h3>
            <div class="section-content">
                <div class="faq-item">
                    <h5>How do you protect my customer data?</h5>
                    <p>We implement enterprise-grade security measures including encryption, secure data centers, access controls, and regular security audits to protect your customer information.</p>
                </div>

                <div class="faq-item">
                    <h5>Are you HIPAA compliant?</h5>
                    <p>Yes, we are HIPAA compliant and can sign Business Associate Agreements (BAA) with healthcare providers.</p>
                </div>

                <div class="faq-item">
                    <h5>Where is my data stored?</h5>
                    <p>Your data is stored in secure, SOC 2 compliant data centers with redundant backups and disaster recovery procedures.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-headset"></i>
                Support & Training
            </h3>
            <div class="section-content">
                <div class="faq-item">
                    <h5>What kind of support do you provide?</h5>
                    <p>We provide 24/7 customer support via phone, email, and chat. Our dedicated account managers are available to help with any questions or concerns.</p>
                </div>

                <div class="faq-item">
                    <h5>Do you provide training for my team?</h5>
                    <p>Yes, we provide comprehensive training for your team on how to use our services effectively and get the most value from our platform.</p>
                </div>

                <div class="faq-item">
                    <h5>How do I access my account and reports?</h5>
                    <p>You can access your account, view reports, and manage settings through our secure client portal available 24/7.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-envelope"></i>
                Contact Us
            </h3>
            <div class="section-content">
                <p>Still have questions? We're here to help!</p>
                
                <div class="highlight-box">
                    <h4>Get in Touch:</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <p><i class="fas fa-phone me-2"></i><strong>Phone:</strong><br>1-800-SUREHELP (1-800-787-3435)</p>
                            <p><i class="fas fa-envelope me-2"></i><strong>Email:</strong><br><a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></p>
                        </div>
                        <div class="col-md-6">
                            <p><i class="fas fa-clock me-2"></i><strong>Hours:</strong><br>24/7 Customer Support</p>
                            <p><i class="fas fa-globe me-2"></i><strong>Website:</strong><br>www.surehelpsolution.com</p>
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
                Download FAQ Document
            </h3>
            <p class="download-description">
                Download a PDF copy of this FAQ document for your records and reference.
            </p>
            <button class="download-button" onclick="downloadPDF('faq-document')">
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
    // Add interactive FAQ functionality
    const faqItems = document.querySelectorAll('.faq-item');
    
    faqItems.forEach(item => {
        const question = item.querySelector('h5');
        const answer = item.querySelector('p');
        
        // Create expand/collapse functionality
        answer.style.maxHeight = 'none';
        answer.style.overflow = 'hidden';
        answer.style.transition = 'max-height 0.3s ease';
        
        // Initially show answers
        answer.style.maxHeight = answer.scrollHeight + 'px';
        
        question.style.cursor = 'pointer';
        question.innerHTML = '<i class="fas fa-chevron-down me-2"></i>' + question.textContent;
        
        question.addEventListener('click', function() {
            const icon = this.querySelector('i');
            const isExpanded = answer.style.maxHeight !== '0px';
            
            if (isExpanded) {
                answer.style.maxHeight = '0px';
                icon.className = 'fas fa-chevron-right me-2';
                item.style.background = 'transparent';
            } else {
                answer.style.maxHeight = answer.scrollHeight + 'px';
                icon.className = 'fas fa-chevron-down me-2';
                item.style.background = 'rgba(79, 70, 229, 0.05)';
            }
        });
    });
    
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
});
</script>
@endsection

