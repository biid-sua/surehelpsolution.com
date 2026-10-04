@extends('layouts.app')

@section('title', 'Cookie Notice - SureHelp Solution')
@section('description', 'How we use cookies to enhance your browsing experience')

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">Cookie Notice</h1>
            <p class="page-subtitle">How we use cookies to enhance your browsing experience</p>
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
                <li><a href="#what-are-cookies">What Are Cookies?</a></li>
                <li><a href="#types-of-cookies">Types of Cookies We Use</a></li>
                <li><a href="#how-we-use">How We Use Cookies</a></li>
                <li><a href="#third-party">Third-Party Cookies</a></li>
                <li><a href="#managing-cookies">Managing Cookies</a></li>
                <li><a href="#updates">Updates to This Notice</a></li>
                <li><a href="#contact">Contact Us</a></li>
            </ul>
        </div>

        <!-- Legal Content -->
        <div class="legal-card" id="introduction" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cookie-bite"></i>
                Introduction
            </h3>
            <div class="section-content">
                <p>SureHelp Solution ("SHS," "we," "our," or "us") uses cookies and similar technologies on our website www.surehelpsolution.com (the "Site") to provide you with a secure, reliable, and personalized experience. This Cookie Notice explains what cookies are, the types we use, and how you can manage your preferences.</p>
                
                <div class="highlight-box">
                    <h4>Our Commitment:</h4>
                    <p>We are committed to transparency about our use of cookies and providing you with control over your privacy preferences. This notice helps you understand how cookies enhance your experience on our website.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" id="what-are-cookies" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-question-circle"></i>
                What Are Cookies?
            </h3>
            <div class="section-content">
                <p>Cookies are small text files stored on your device when you visit a website. They help websites recognize your device, remember your preferences, and improve your browsing experience.</p>
                
                <div class="highlight-box">
                    <h4>How Cookies Work:</h4>
                    <ul>
                        <li>Cookies are created when you visit a website</li>
                        <li>They store information about your visit</li>
                        <li>They help websites remember your preferences</li>
                        <li>They can improve website performance and security</li>
                        <li>They enable personalized experiences</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="types-of-cookies" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-th-large"></i>
                Types of Cookies We Use
            </h3>
            <div class="section-content">
                <div class="cookie-type">
                    <h5><i class="fas fa-shield-alt"></i>Strictly Necessary Cookies</h5>
                    <p>Required for the operation of our Site, including login authentication, security, and call answering portal access. These cookies cannot be disabled as they are essential for basic site functionality.</p>
                </div>
                
                <div class="cookie-type">
                    <h5><i class="fas fa-chart-line"></i>Performance & Analytics Cookies</h5>
                    <p>Collect information about how visitors use our Site, helping us improve functionality and measure service quality. These cookies help us understand which pages are most popular and how users navigate our site.</p>
                </div>
                
                <div class="cookie-type">
                    <h5><i class="fas fa-cog"></i>Functionality Cookies</h5>
                    <p>Remember choices you make (such as language, region, or saved preferences) to provide a more personalized experience. These cookies enhance your browsing experience by remembering your preferences.</p>
                </div>
                
                <div class="cookie-type">
                    <h5><i class="fas fa-bullhorn"></i>Advertising Cookies</h5>
                    <p>Used to deliver relevant marketing campaigns and measure effectiveness. These may include cookies from trusted third-party partners to help us provide you with relevant content and offers.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" id="how-we-use" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cogs"></i>
                How We Use Cookies
            </h3>
            <div class="section-content">
                <p>SHS uses cookies to:</p>
                
                <div class="highlight-box">
                    <h4>Primary Uses:</h4>
                    <ul>
                        <li><strong>Enable secure login</strong> to the client portal and app</li>
                        <li><strong>Improve performance</strong> of call answering and scheduling/CRM services</li>
                        <li><strong>Remember preferences</strong> for customer communication</li>
                        <li><strong>Analyze traffic patterns</strong> and optimize user experience</li>
                        <li><strong>Deliver targeted updates</strong> and service offers</li>
                        <li><strong>Enhance security</strong> and prevent fraud</li>
                        <li><strong>Provide personalized content</strong> based on your preferences</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="third-party" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-external-link-alt"></i>
                Third-Party Cookies
            </h3>
            <div class="section-content">
                <p>We may allow trusted third-party service providers (e.g., analytics, advertising, CRM integrations) to place cookies on your device for performance measurement and personalization. These providers have their own privacy and cookie notices.</p>
                
                <div class="highlight-box">
                    <h4>Third-Party Services:</h4>
                    <ul>
                        <li><strong>Analytics Services:</strong> Google Analytics, Mixpanel</li>
                        <li><strong>Advertising Platforms:</strong> Google Ads, Facebook Pixel</li>
                        <li><strong>CRM Integrations:</strong> Salesforce, HubSpot</li>
                        <li><strong>Communication Tools:</strong> Intercom, Zendesk</li>
                        <li><strong>Security Services:</strong> Cloudflare, reCAPTCHA</li>
                    </ul>
                </div>
                
                <p><strong>Important:</strong> These third-party services have their own privacy policies and cookie practices. We recommend reviewing their policies for more information.</p>
            </div>
        </div>

        <div class="legal-card" id="managing-cookies" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-sliders-h"></i>
                Managing Cookies
            </h3>
            <div class="section-content">
                <p>You can control or disable cookies through your browser settings. Please note that disabling certain cookies may limit your ability to use some features of our Site.</p>
                
                <div class="highlight-box">
                    <h4>Cookie Management Options:</h4>
                    
                    <div class="cookie-toggle">
                        <div>
                            <strong>Strictly Necessary Cookies</strong>
                            <p class="mb-0">Required for basic site functionality</p>
                        </div>
                        <div class="toggle-switch active" data-cookie-type="necessary"></div>
                    </div>
                    
                    <div class="cookie-toggle">
                        <div>
                            <strong>Performance & Analytics Cookies</strong>
                            <p class="mb-0">Help us improve our website</p>
                        </div>
                        <div class="toggle-switch" data-cookie-type="analytics"></div>
                    </div>
                    
                    <div class="cookie-toggle">
                        <div>
                            <strong>Functionality Cookies</strong>
                            <p class="mb-0">Remember your preferences</p>
                        </div>
                        <div class="toggle-switch" data-cookie-type="functionality"></div>
                    </div>
                    
                    <div class="cookie-toggle">
                        <div>
                            <strong>Advertising Cookies</strong>
                            <p class="mb-0">Provide relevant content and offers</p>
                        </div>
                        <div class="toggle-switch" data-cookie-type="advertising"></div>
                    </div>
                </div>
                
                <div class="highlight-box">
                    <h4>Browser Settings:</h4>
                    <ul>
                        <li><strong>Chrome:</strong> Settings > Privacy and security > Cookies and other site data</li>
                        <li><strong>Firefox:</strong> Options > Privacy & Security > Cookies and Site Data</li>
                        <li><strong>Safari:</strong> Preferences > Privacy > Manage Website Data</li>
                        <li><strong>Edge:</strong> Settings > Cookies and site permissions > Cookies and site data</li>
                    </ul>
                </div>
                
                <p>For more information about managing cookies, visit <a href="https://www.allaboutcookies.org" target="_blank" style="color: var(--accent-color);">www.allaboutcookies.org</a>.</p>
            </div>
        </div>

        <div class="legal-card" id="updates" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-sync-alt"></i>
                Updates to This Notice
            </h3>
            <div class="section-content">
                <p>We may update this Cookie Notice from time to time to reflect changes in technology, law, or our business practices. Any updates will be posted on this page with a new "Last Updated" date.</p>
                
                <div class="highlight-box">
                    <h4>How We Notify You:</h4>
                    <ul>
                        <li>Updated notice posted on our website</li>
                        <li>New "Last Updated" date displayed</li>
                        <li>Email notifications for significant changes</li>
                        <li>Banner notifications on our website</li>
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
                <p>If you have any questions about this Cookie Notice or how SHS uses cookies, please contact us:</p>
                
                <div class="highlight-box">
                    <h4>Contact Information:</h4>
                    <div class="row">
                        <div class="col-md-6">
                            <p><i class="fas fa-envelope me-2"></i><strong>Email:</strong><br><a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></p>
                            <p><i class="fas fa-globe me-2"></i><strong>Website:</strong><br>www.surehelpsolution.com</p>
                        </div>
                        <div class="col-md-6">
                            <p><i class="fas fa-phone me-2"></i><strong>Phone:</strong><br>1-800-SUREHELP (1-800-787-3435)</p>
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
                Download Cookie Notice
            </h3>
            <p class="download-description">
                Download a PDF copy of this Cookie Notice for your records and compliance documentation.
            </p>
            <button class="download-button" onclick="downloadPDF('cookie-notice')">
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
    // Enhanced cookie toggle functionality
    document.querySelectorAll('.toggle-switch').forEach(toggle => {
        toggle.addEventListener('click', function() {
            const cookieType = this.getAttribute('data-cookie-type');
            
            // Don't allow disabling necessary cookies
            if (cookieType === 'necessary') {
                showNotification('Necessary cookies cannot be disabled as they are required for basic site functionality.', 'warning');
                return;
            }
            
            this.classList.toggle('active');
            
            // Save preference to localStorage
            const isActive = this.classList.contains('active');
            localStorage.setItem(`cookie_${cookieType}`, isActive);
            
            // Show feedback with animation
            const status = isActive ? 'enabled' : 'disabled';
            const type = isActive ? 'success' : 'info';
            showNotification(`${cookieType.replace('-', ' ')} cookies have been ${status}`, type);
            
            // Add visual feedback
            this.style.transform = 'scale(1.1)';
            setTimeout(() => {
                this.style.transform = '';
            }, 200);
        });
    });

    // Load saved cookie preferences
    document.querySelectorAll('.toggle-switch').forEach(toggle => {
        const cookieType = toggle.getAttribute('data-cookie-type');
        if (cookieType !== 'necessary') {
            const saved = localStorage.getItem(`cookie_${cookieType}`);
            if (saved === 'false') {
                toggle.classList.remove('active');
            }
        }
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

    // Cookie type hover effects
    document.querySelectorAll('.cookie-type').forEach(type => {
        type.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
        });
        
        type.addEventListener('mouseleave', function() {
            this.style.transform = '';
        });
    });
});

// Notification system
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.cookie-notification');
    existingNotifications.forEach(notification => notification.remove());
    
    const notification = document.createElement('div');
    notification.className = `cookie-notification cookie-notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Add notification styles
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? 'linear-gradient(135deg, #10b981, #059669)' : 
                     type === 'warning' ? 'linear-gradient(135deg, #f59e0b, #d97706)' : 
                     'linear-gradient(135deg, #3b82f6, #2563eb)'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        z-index: 10000;
        display: flex;
        align-items: center;
        gap: 1rem;
        max-width: 400px;
        animation: slideInRight 0.3s ease-out;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
    `;
    
    const content = notification.querySelector('.notification-content');
    content.style.cssText = `
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex: 1;
    `;
    
    const closeBtn = notification.querySelector('.notification-close');
    closeBtn.style.cssText = `
        background: none;
        border: none;
        color: white;
        cursor: pointer;
        padding: 0.25rem;
        border-radius: 4px;
        transition: background 0.2s ease;
    `;
    
    closeBtn.addEventListener('mouseenter', () => {
        closeBtn.style.background = 'rgba(255, 255, 255, 0.2)';
    });
    
    closeBtn.addEventListener('mouseleave', () => {
        closeBtn.style.background = 'none';
    });
    
    document.body.appendChild(notification);
    
    // Auto remove after 4 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideOutRight 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }
    }, 4000);
}

// Add notification animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
</script>
@endsection
