@extends('layouts.app')

@section('title', 'Business Associate Agreement (BAA) - SureHelp Solution')
@section('description', 'HIPAA compliance for healthcare organizations')

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">Business Associate Agreement (BAA)</h1>
            <p class="page-subtitle">HIPAA compliance for healthcare organizations</p>
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
                <li><a href="#definitions">Definitions</a></li>
                <li><a href="#business-associate-obligations">Obligations of Business Associate</a></li>
                <li><a href="#permitted-uses">Permitted Uses and Disclosures</a></li>
                <li><a href="#covered-entity-obligations">Obligations of Covered Entity</a></li>
                <li><a href="#term-termination">Term and Termination</a></li>
                <li><a href="#miscellaneous">Miscellaneous</a></li>
                <li><a href="#signatures">Signatures</a></li>
            </ul>
        </div>

        <!-- Legal Content -->
        <div class="legal-card" id="introduction" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-file-contract"></i>
                Introduction
            </h3>
            <div class="section-content">
                <p>This Business Associate Agreement (the "Agreement") is entered into by and between the Covered Entity ("Covered Entity") and SureHelp Solution, Inc. ("Business Associate" or "SHS"), a Delaware corporation. This Agreement is made pursuant to the Health Insurance Portability and Accountability Act of 1996 ("HIPAA"), the Health Information Technology for Economic and Clinical Health Act ("HITECH Act"), and their implementing regulations.</p>
                
                <div class="highlight-box">
                    <h4>Purpose:</h4>
                    <p>This agreement establishes the terms and conditions under which SHS will provide services to Covered Entity while ensuring compliance with HIPAA and HITECH Act requirements for the protection of Protected Health Information (PHI).</p>
                </div>
            </div>
        </div>

        <div class="legal-card" id="definitions" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-book"></i>
                Definitions
            </h3>
            <div class="section-content">
                <p>Terms used in this Agreement shall have the same meaning as those terms in HIPAA, the HITECH Act, and regulations promulgated thereunder, including but not limited to:</p>
                
                <div class="highlight-box">
                    <h4>Key Terms:</h4>
                    <ul>
                        <li><strong>"Protected Health Information" (PHI):</strong> Individually identifiable health information</li>
                        <li><strong>"Electronic Protected Health Information" (ePHI):</strong> PHI that is transmitted by or maintained in electronic media</li>
                        <li><strong>"Covered Entity":</strong> A health plan, health care clearinghouse, or health care provider</li>
                        <li><strong>"Business Associate":</strong> A person or entity that performs certain functions involving PHI</li>
                        <li><strong>"Secretary":</strong> The Secretary of the Department of Health and Human Services</li>
                        <li><strong>"Security Incident":</strong> The attempted or successful unauthorized access, use, disclosure, modification, or destruction of information</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="business-associate-obligations" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-shield-alt"></i>
                Obligations of Business Associate
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Business Associate agrees to:</h4>
                    <ul>
                        <li><strong>Use and Disclosure:</strong> Not use or disclose PHI other than as permitted or required by this Agreement or as required by law</li>
                        <li><strong>Safeguards:</strong> Use appropriate safeguards, and comply with Subpart C of 45 CFR Part 164 with respect to ePHI, to prevent use or disclosure of PHI other than as provided for by this Agreement</li>
                        <li><strong>Reporting:</strong> Report to Covered Entity any use or disclosure of PHI not provided for by this Agreement of which it becomes aware, including any Security Incident or Breach of Unsecured PHI</li>
                        <li><strong>Subcontractors:</strong> Ensure that any subcontractors who create, receive, maintain, or transmit PHI on behalf of the Business Associate agree in writing to the same restrictions and conditions that apply to Business Associate under this Agreement</li>
                        <li><strong>Access:</strong> Make PHI available to Covered Entity as necessary to satisfy Covered Entity's obligations under 45 CFR 164.524</li>
                        <li><strong>Amendment:</strong> Make PHI available for amendment and incorporate any amendments as required under 45 CFR 164.526</li>
                        <li><strong>Accounting:</strong> Make available the information required to provide an accounting of disclosures in accordance with 45 CFR 164.528</li>
                        <li><strong>Compliance:</strong> Make its internal practices, books, and records relating to the use and disclosure of PHI available to the Secretary for purposes of determining compliance</li>
                        <li><strong>Return/Destruction:</strong> Upon termination of this Agreement, return or destroy all PHI received from, or created or received on behalf of, Covered Entity, retaining no copies unless required by law</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="permitted-uses" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-check-circle"></i>
                Permitted Uses and Disclosures by Business Associate
            </h3>
            <div class="section-content">
                <p>Except as otherwise limited in this Agreement, Business Associate may:</p>
                
                <div class="highlight-box">
                    <h4>Permitted Activities:</h4>
                    <ul>
                        <li><strong>Service Performance:</strong> Use or disclose PHI as necessary to perform services for Covered Entity under the Service Agreement</li>
                        <li><strong>Management:</strong> Use PHI for proper management and administration of the Business Associate or to carry out its legal responsibilities</li>
                        <li><strong>Required Disclosures:</strong> Disclose PHI for proper management and administration of the Business Associate, provided the disclosures are required by law, or Business Associate obtains reasonable assurances that the PHI will remain confidential</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="covered-entity-obligations" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-user-shield"></i>
                Obligations of Covered Entity
            </h3>
            <div class="section-content">
                <p>Covered Entity agrees to:</p>
                
                <div class="highlight-box">
                    <h4>Covered Entity Responsibilities:</h4>
                    <ul>
                        <li><strong>Permissible Requests:</strong> Not request Business Associate to use or disclose PHI in any manner that would not be permissible under HIPAA if done by Covered Entity</li>
                        <li><strong>Notice Limitations:</strong> Notify Business Associate of any limitations in its notice of privacy practices</li>
                        <li><strong>Permission Changes:</strong> Notify Business Associate of any changes in, or revocation of, permission by an individual to use or disclose PHI</li>
                        <li><strong>Restrictions:</strong> Notify Business Associate of any restriction on the use or disclosure of PHI to which Covered Entity has agreed</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="term-termination" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-clock"></i>
                Term and Termination
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Agreement Terms:</h4>
                    <ul>
                        <li><strong>Term:</strong> This Agreement shall be effective as of the Effective Date and shall terminate when all PHI is returned or destroyed</li>
                        <li><strong>Termination for Cause:</strong> Covered Entity may terminate this Agreement if it determines that Business Associate has violated a material term</li>
                        <li><strong>Effect of Termination:</strong> Upon termination, Business Associate shall return or destroy all PHI. If return or destruction is infeasible, Business Associate shall extend protections to such PHI</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="miscellaneous" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cogs"></i>
                Miscellaneous
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Additional Provisions:</h4>
                    <ul>
                        <li><strong>Regulatory References:</strong> A reference in this Agreement to HIPAA or the HITECH Act means the law as amended</li>
                        <li><strong>Amendment:</strong> The parties agree to take necessary action to amend this Agreement to comply with changes in the law</li>
                        <li><strong>Survival:</strong> The obligations of Business Associate under this Agreement shall survive termination</li>
                        <li><strong>Governing Law:</strong> This Agreement shall be governed by and construed under the laws of the State of Delaware</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="signatures" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-signature"></i>
                Signatures
            </h3>
            <div class="section-content">
                <p>IN WITNESS WHEREOF, the parties have executed this Business Associate Agreement as of the Effective Date.</p>
                
                <div class="signature-section">
                    <h4>Covered Entity:</h4>
                    <div class="signature-line"></div>
                    <p><strong>By:</strong></p>
                    <div class="signature-line"></div>
                    <p><strong>Name:</strong></p>
                    <div class="signature-line"></div>
                    <p><strong>Title:</strong></p>
                    <div class="signature-line"></div>
                    <p><strong>Date:</strong></p>
                </div>
                
                <div class="signature-section">
                    <h4>SureHelp Solution, Inc. (Business Associate)</h4>
                    <div class="signature-line"></div>
                    <p><strong>By:</strong></p>
                    <div class="signature-line"></div>
                    <p><strong>Name:</strong></p>
                    <div class="signature-line"></div>
                    <p><strong>Title:</strong></p>
                    <div class="signature-line"></div>
                    <p><strong>Date:</strong></p>
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
                Download Business Associate Agreement
            </h3>
            <p class="download-description">
                Download a PDF copy of this Business Associate Agreement for your records and compliance documentation.
            </p>
            <button class="download-button" onclick="downloadPDF('business-associate-agreement')">
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

    // Add signature section interactions
    document.querySelectorAll('.signature-section').forEach(section => {
        section.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
            this.style.boxShadow = '0 20px 60px rgba(79, 70, 229, 0.2)';
        });
        
        section.addEventListener('mouseleave', function() {
            this.style.transform = '';
            this.style.boxShadow = '';
        });
    });
});
</script>
@endsection

