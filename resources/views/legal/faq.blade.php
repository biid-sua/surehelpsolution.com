@extends('layouts.app')

@section('title', config('site.meta.faq.title'))
@section('description', config('site.meta.faq.description'))

@push('schema')
{!! \App\Support\SiteSchema::script([
    \App\Support\SiteSchema::breadcrumbs([['Frequently Asked Questions', route('legal.faq')]]),
    \App\Support\SiteSchema::faq(collect(config('site.faq_page'))->flatMap(fn ($group) => $group[2])->all()),
]) !!}
@endpush

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
                Last Updated: October 2026
            </div>
        </div>
    </div>
</section>

<!-- Content Section -->
<section class="content-section">
    <div class="container">
        <!-- FAQ Content (config/site.php, also in the page's structured data) -->
        @foreach (config('site.faq_page') as [$icon, $heading, $questions])
            <div class="legal-card" data-aos="fade-up">
                <h2 class="section-title">
                    <i class="fas {{ $icon }}"></i>
                    {{ $heading }}
                </h2>
                <div class="section-content">
                    @foreach ($questions as [$question, $answer])
                        <div class="faq-item">
                            <h3>{{ $question }}</h3>
                            <p>{{ $answer }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="legal-card" data-aos="fade-up">
            <h2 class="section-title">
                <i class="fas fa-envelope"></i>
                Contact Us
            </h2>
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

