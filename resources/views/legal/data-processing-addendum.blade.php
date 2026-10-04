@extends('layouts.app')

@section('title', 'Data Processing Addendum (DPA) - SureHelp Solution')
@section('description', 'GDPR and data protection compliance for our services')

@section('styles')
<link href="{{ asset('assets/css/legal-pages.css') }}" rel="stylesheet">
@endsection

@section('content')
<div class="legal-pages-wrapper">
<!-- Hero Section -->
<section class="hero-section">
    <div class="container">
        <div class="hero-content text-center" data-aos="fade-up">
            <h1 class="page-title">Data Processing Addendum (DPA)</h1>
            <p class="page-subtitle">GDPR and data protection compliance for our services</p>
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
                <li><a href="#term">Term</a></li>
                <li><a href="#processing">Processing of Customer Personal Data</a></li>
                <li><a href="#personnel">Provider Personnel</a></li>
                <li><a href="#sub-processors">Sub-processors</a></li>
                <li><a href="#security">Security</a></li>
                <li><a href="#cross-border">Cross-Border Transfers</a></li>
                <li><a href="#data-subject-rights">Data Subject Rights</a></li>
                <li><a href="#security-incident">Security Incident Response</a></li>
                <li><a href="#impact-assessment">Data Protection Impact Assessment</a></li>
                <li><a href="#return-destruction">Return or Destruction of Personal Data</a></li>
                <li><a href="#audit">Audit</a></li>
                <li><a href="#jurisdiction">Jurisdiction and Governing Law</a></li>
                <li><a href="#indemnification">Indemnification; Limitations on Liability</a></li>
                <li><a href="#severance">Severance</a></li>
                <li><a href="#exhibit-a">Exhibit A: Details of Processing</a></li>
                <li><a href="#exhibit-b">Exhibit B: Security Measures</a></li>
            </ul>
        </div>

        <!-- Legal Content -->
        <div class="legal-card" id="introduction" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-file-contract"></i>
                Introduction
            </h3>
            <div class="section-content">
                <p>This Data Processing Addendum (the "DPA") is entered into by and between you ("Customer", "you," and "yours") (collectively, with its Affiliates, "Customer") and SureHelp Solution ("SHS," "us," "we," or "our"). This DPA supplements and is incorporated into the existing agreement between Customer and SHS (the "Agreement") pursuant to which SHS will provide services ("Services") to Customer and has the same Effective Date as the Agreement.</p>
                
                <div class="highlight-box">
                    <h4>Purpose:</h4>
                    <p>In the course of providing the Services to Customer, SHS may Process Personal Data on behalf of Customer, and the parties agree to comply with the following provisions with respect to any Personal Data.</p>
                </div>
            </div>
        </div>

        <div class="legal-card" id="definitions" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-book"></i>
                Definitions
            </h3>
            <div class="section-content">
                <div class="definition-list">
                    <div class="definition-item">
                        <div class="definition-term">"Affiliate"</div>
                        <div class="definition-description">means with respect to an entity, any other entity that, now or in the future, either directly or through one or more intermediaries, controls, is controlled by, or is under common control with, that entity or any of its successors.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"CCPA"</div>
                        <div class="definition-description">means the California Consumer Privacy Act of 2018, as amended by the California Privacy Rights Act, and implementing regulations.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Controller"</div>
                        <div class="definition-description">means the definition of a controller, business, or equivalent term under Data Protection Laws.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Customer Personal Data"</div>
                        <div class="definition-description">means any Personal Data Processed by SHS (or a Sub-processor) on behalf of Customer pursuant to or in connection with the Agreement.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Data Protection Laws"</div>
                        <div class="definition-description">means any applicable international, national, federal, state, local, municipal, or territorial law, regulation, or standard concerning data privacy and security, including GDPR, CCPA, PIPEDA, UK GDPR, etc.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Data Subject"</div>
                        <div class="definition-description">means the definition of a data subject, consumer, or equivalent term under Data Protection Laws.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"GDPR"</div>
                        <div class="definition-description">means the General Data Protection Regulation, Regulation (EU) 2016/679.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Personal Data"</div>
                        <div class="definition-description">means information that identifies or could reasonably identify a natural person, or is otherwise considered personal data under Data Protection Laws.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Process / Processing"</div>
                        <div class="definition-description">means any operation performed on Personal Data such as collection, storage, use, disclosure, alteration, or deletion.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Processor"</div>
                        <div class="definition-description">means the definition of a processor, service provider, or equivalent term under Data Protection Laws.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Security Incident"</div>
                        <div class="definition-description">means any confirmed unauthorized access, disclosure, theft, loss, or misuse of Personal Data.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"Sub-processor"</div>
                        <div class="definition-description">means any person appointed by SHS to Process Personal Data on behalf of Customer under the Agreement.</div>
                    </div>
                    
                    <div class="definition-item">
                        <div class="definition-term">"UK GDPR"</div>
                        <div class="definition-description">means the UK version of the EU GDPR as incorporated into UK law by the European Union (Withdrawal) Act 2018.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="legal-card" id="term" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-clock"></i>
                Term
            </h3>
            <div class="section-content">
                <p>The term of this DPA will commence on the Effective Date and continue as long as SHS Processes Customer Personal Data.</p>
            </div>
        </div>

        <div class="legal-card" id="processing" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cogs"></i>
                Processing of Customer Personal Data
            </h3>
            <div class="section-content">
                <p>Details on SHS's role as Processor, Customer authority, purposes of Processing, prohibitions on selling/sharing, compliance obligations, and Customer's responsibilities.</p>
                
                <div class="highlight-box">
                    <h4>Key Responsibilities:</h4>
                    <ul>
                        <li>SHS acts as a Processor on behalf of Customer</li>
                        <li>Customer retains authority over Personal Data</li>
                        <li>Processing limited to service delivery purposes</li>
                        <li>No sale or sharing of Personal Data</li>
                        <li>Compliance with applicable data protection laws</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="personnel" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-users"></i>
                Provider Personnel
            </h3>
            <div class="section-content">
                <p>SHS restricts employees from unauthorized Processing and requires confidentiality agreements.</p>
                
                <div class="highlight-box">
                    <h4>Personnel Requirements:</h4>
                    <ul>
                        <li>Background checks for all personnel</li>
                        <li>Confidentiality agreements signed</li>
                        <li>Regular training on data protection</li>
                        <li>Access controls and monitoring</li>
                        <li>Immediate termination for violations</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="sub-processors" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-network-wired"></i>
                Sub-processors
            </h3>
            <div class="section-content">
                <p>General authorization for SHS to engage Sub-processors with Customer's right to object, and contractual protections required.</p>
                
                <div class="highlight-box">
                    <h4>Sub-processor Requirements:</h4>
                    <ul>
                        <li>Customer notification of new Sub-processors</li>
                        <li>Right to object within 30 days</li>
                        <li>Contractual data protection obligations</li>
                        <li>Same level of protection as SHS</li>
                        <li>Regular audits and assessments</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="security" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-shield-alt"></i>
                Security
            </h3>
            <div class="section-content">
                <p>SHS implements technical, organizational, and physical measures to protect Personal Data (see Exhibit B).</p>
                
                <div class="highlight-box">
                    <h4>Security Measures Include:</h4>
                    <ul>
                        <li>Encryption of data in transit and at rest</li>
                        <li>Access controls and authentication</li>
                        <li>Regular security assessments</li>
                        <li>Incident response procedures</li>
                        <li>Business continuity planning</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="cross-border" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-globe"></i>
                Cross-Border Transfers
            </h3>
            <div class="section-content">
                <p>Restricted Transfers will be governed by Standard Contractual Clauses (SCCs) and the UK Transfer Addendum as applicable.</p>
                
                <div class="highlight-box">
                    <h4>Transfer Safeguards:</h4>
                    <ul>
                        <li>EU Standard Contractual Clauses</li>
                        <li>UK Transfer Addendum</li>
                        <li>Adequacy decisions where applicable</li>
                        <li>Additional safeguards as required</li>
                        <li>Regular review of transfer mechanisms</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="data-subject-rights" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-user-check"></i>
                Data Subject Rights
            </h3>
            <div class="section-content">
                <p>SHS will reasonably cooperate with Customer to fulfill Data Subject access, deletion, and correction requests.</p>
                
                <div class="highlight-box">
                    <h4>Supported Rights:</h4>
                    <ul>
                        <li>Right of access to Personal Data</li>
                        <li>Right to rectification</li>
                        <li>Right to erasure ("right to be forgotten")</li>
                        <li>Right to data portability</li>
                        <li>Right to restrict processing</li>
                        <li>Right to object to processing</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="security-incident" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-exclamation-triangle"></i>
                Security Incident Response
            </h3>
            <div class="section-content">
                <p>SHS shall notify Customer within 72 hours of becoming aware of a Security Incident and cooperate on investigation.</p>
                
                <div class="highlight-box">
                    <h4>Incident Response Process:</h4>
                    <ul>
                        <li>Immediate containment and assessment</li>
                        <li>Notification within 72 hours</li>
                        <li>Detailed investigation and documentation</li>
                        <li>Remediation and prevention measures</li>
                        <li>Regular updates to Customer</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="impact-assessment" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-clipboard-check"></i>
                Data Protection Impact Assessment
            </h3>
            <div class="section-content">
                <p>SHS shall reasonably cooperate with Customer in conducting required assessments.</p>
                
                <div class="highlight-box">
                    <h4>Assessment Support:</h4>
                    <ul>
                        <li>Provision of relevant documentation</li>
                        <li>Technical and organizational details</li>
                        <li>Risk assessment information</li>
                        <li>Mitigation measures documentation</li>
                        <li>Ongoing cooperation and updates</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="return-destruction" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-trash-alt"></i>
                Return or Destruction of Personal Data
            </h3>
            <div class="section-content">
                <p>Upon termination, SHS will return or delete Customer Personal Data unless required by law to retain.</p>
                
                <div class="highlight-box">
                    <h4>Data Disposition:</h4>
                    <ul>
                        <li>Secure deletion of all Personal Data</li>
                        <li>Return of data in standard format</li>
                        <li>Certification of deletion/return</li>
                        <li>Retention only where legally required</li>
                        <li>Ongoing protection of retained data</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="audit" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-search"></i>
                Audit
            </h3>
            <div class="section-content">
                <p>Customer may audit SHS compliance with this DPA, subject to confidentiality and reasonable notice.</p>
                
                <div class="highlight-box">
                    <h4>Audit Rights:</h4>
                    <ul>
                        <li>Reasonable notice required (30 days)</li>
                        <li>Confidentiality obligations apply</li>
                        <li>Access to relevant documentation</li>
                        <li>On-site audits permitted</li>
                        <li>Cost sharing for extensive audits</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="jurisdiction" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-balance-scale"></i>
                Jurisdiction and Governing Law
            </h3>
            <div class="section-content">
                <p>This DPA is governed by the laws of the State of Delaware. Any disputes shall be resolved in the state or federal courts located in Delaware.</p>
            </div>
        </div>

        <div class="legal-card" id="indemnification" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-handshake"></i>
                Indemnification; Limitations on Liability
            </h3>
            <div class="section-content">
                <p>SHS's aggregate liability under this DPA shall not exceed the lesser of Customer's pro-rated monthly service charge during the period of liability or $500.</p>
            </div>
        </div>

        <div class="legal-card" id="severance" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-cut"></i>
                Severance
            </h3>
            <div class="section-content">
                <p>If any provision of this DPA is invalid or unenforceable, the remaining provisions shall remain in full force.</p>
            </div>
        </div>

        <div class="legal-card" id="exhibit-a" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-file-alt"></i>
                Exhibit A: Details of Processing
            </h3>
            <div class="section-content">
                <div class="highlight-box">
                    <h4>Processing Details:</h4>
                    <ul>
                        <li><strong>Subject matter:</strong> Call answering, message taking, follow-ups, scheduling, CRM integrations</li>
                        <li><strong>Duration:</strong> As per Agreement</li>
                        <li><strong>Types of Data:</strong> Name, phone number, email, address, call content</li>
                        <li><strong>Categories:</strong> Customer's clients, Customer's employees, and Customer's end-users</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="legal-card" id="exhibit-b" data-aos="fade-up">
            <h3 class="section-title">
                <i class="fas fa-shield-virus"></i>
                Exhibit B: Security Measures
            </h3>
            <div class="section-content">
                <p>Includes physical, electronic, and organizational measures such as access controls, encryption, backup procedures, incident response, and vendor management.</p>
                
                <div class="highlight-box">
                    <h4>Security Framework:</h4>
                    <ul>
                        <li>ISO 27001 compliant security program</li>
                        <li>Regular penetration testing</li>
                        <li>Employee security training</li>
                        <li>Vendor security assessments</li>
                        <li>Business continuity planning</li>
                    </ul>
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
                Download Data Processing Addendum
            </h3>
            <p class="download-description">
                Download a PDF copy of this Data Processing Addendum for your records and GDPR compliance documentation.
            </p>
            <button class="download-button" onclick="downloadPDF('data-processing-addendum')">
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

    // Add definition search functionality
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.placeholder = 'Search definitions...';
    searchInput.style.cssText = `
        width: 100%;
        padding: 1rem 1.5rem;
        border: 2px solid rgba(79, 70, 229, 0.2);
        border-radius: 12px;
        font-size: 1rem;
        margin-bottom: 2rem;
        background: white;
        transition: all 0.3s ease;
    `;
    
    const definitionsSection = document.querySelector('#definitions');
    if (definitionsSection) {
        definitionsSection.insertBefore(searchInput, definitionsSection.querySelector('.section-content'));
        
        searchInput.addEventListener('input', (e) => {
            const searchTerm = e.target.value.toLowerCase();
            const definitions = document.querySelectorAll('.definition-item');
            
            definitions.forEach(definition => {
                const term = definition.querySelector('.definition-term').textContent.toLowerCase();
                const description = definition.querySelector('.definition-description').textContent.toLowerCase();
                
                if (term.includes(searchTerm) || description.includes(searchTerm)) {
                    definition.style.display = 'block';
                    definition.style.background = searchTerm ? 'rgba(79, 70, 229, 0.05)' : '';
                } else {
                    definition.style.display = 'none';
                }
            });
        });
    }
});
</script>
@endsection

