@extends('layouts.app')

@section('title', 'SureHelp Solutions - Professional Call Management Platform')
@section('description', 'Never miss a customer call again with SureHelp Solutions. Professional call answering service platform for growing businesses.')

@section('styles')
<style>
    .py-120 {
      padding-top: 120px;
      padding-bottom: 120px;
    }

    @media (max-width: 991.98px) {
      .py-120 {
        padding-top: 80px;
        padding-bottom: 80px;
      }
    }

    @media (max-width: 767.98px) {
      .py-120 {
        padding-top: 60px;
        padding-bottom: 60px;
      }
    }

    .hero-container {
            padding-top: 130px;
            position: relative;
            background: linear-gradient(135deg, #1E3A8A 0%, #625ED0 50%, #4D8BCC 100%);
            overflow: hidden;
        }

        .hero-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 20%, rgba(79, 70, 229, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(124, 58, 237, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.1) 0%, transparent 60%);
            pointer-events: none;
        }

        .floating-shapes {
            top: 180px;
            position: relative;
            z-index: 1;
        }
        .features-section {
            position: relative;
            padding: 8rem 2rem;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
            min-height: 100vh;
        }

        .features-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 25% 25%, rgba(79, 70, 229, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(236, 72, 153, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
            pointer-events: none;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .section-header {
            text-align: center;
            margin-bottom: 5rem;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 1s ease-out 0.2s forwards;
        }

        .section-title {
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 800;
            background: linear-gradient(135deg, #fff 0%, #a855f7 50%, #ccb1be 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }

        .section-subtitle {
            font-size: 1.25rem;
            color: rgba(255, 255, 255, 0.7);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 2rem;
            margin-top: 4rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 2rem 2.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            cursor: pointer;
            opacity: 0;
            transform: translateY(50px);
            animation: slideInUp 0.8s ease-out forwards;
        }

        .feature-card:nth-child(1) { animation-delay: 0.1s; }
        .feature-card:nth-child(2) { animation-delay: 0.2s; }
        .feature-card:nth-child(3) { animation-delay: 0.3s; }
        .feature-card:nth-child(4) { animation-delay: 0.4s; }
        .feature-card:nth-child(5) { animation-delay: 0.5s; }
        .feature-card:nth-child(6) { animation-delay: 0.6s; }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1) 0%, rgba(236, 72, 153, 0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card::after {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(135deg, #142848, #5745d9, #293c60);
            border-radius: 24px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-card:hover::after {
            opacity: 1;
        }

        .feature-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }

        .feature-icon-container {
            position: relative;
            margin-bottom: 2rem;
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #ccb1be 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .feature-card:hover .feature-icon {
            transform: rotate(10deg) scale(1.1);
            box-shadow: 0 15px 30px rgba(79, 70, 229, 0.4);
        }

        .feature-card:hover .feature-icon::before {
            left: 100%;
        }

        .feature-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 1rem;
            transition: color 0.3s ease;
        }

        .feature-card:hover .feature-title {
            background: linear-gradient(135deg, #fff 0%, #a855f7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .feature-description {
            color: rgba(255, 255, 255, 0.7);
            line-height: 1.6;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .feature-list {
            list-style: none;
        }

        .feature-list-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            padding: 0.5rem 0;
            position: relative;
            transition: all 0.3s ease;
            border-radius: 8px;
        }

        .feature-list-item:hover {
            background: rgba(255, 255, 255, 0.05);
            padding-left: 1rem;
        }

        .check-icon {
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 0.8rem;
            color: white;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .feature-list-item:hover .check-icon {
            transform: scale(1.2);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }

        .feature-list-text {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            transition: color 0.3s ease;
        }

        .feature-list-item:hover .feature-list-text {
            color: white;
        }

        .floating-elements {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .floating-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            animation: float 8s ease-in-out infinite;
        }

        .floating-circle:nth-child(1) {
            width: 60px;
            height: 60px;
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .floating-circle:nth-child(2) {
            width: 40px;
            height: 40px;
            top: 70%;
            right: 10%;
            animation-delay: 2s;
            animation-duration: 6s;
        }

        .floating-circle:nth-child(3) {
            width: 80px;
            height: 80px;
            bottom: 15%;
            left: 15%;
            animation-delay: 4s;
            animation-duration: 10s;
        }

        .demo-button {
            display: inline-block;
            margin-top: 0.5rem;
            padding: 0.8rem 2rem;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .demo-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .demo-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4);
        }

        .demo-button:hover::before {
            left: 100%;
        }

        .interactive-showcase {
            margin-top: 6rem;
            text-align: center;
        }

        .showcase-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .showcase-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 2rem 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .showcase-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-5px);
        }

        .showcase-number {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #4f46e5 0%, #ccb1be 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .showcase-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .features-grid {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 1.5rem;
            }
            
            .feature-card {
                padding: 2.5rem 2rem;
            }
        }

        @media (max-width: 768px) {
            .features-section {
                padding: 4rem 1rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .feature-card {
                padding: 2rem 1.5rem;
            }
            
            .section-title {
                font-size: 2.5rem;
            }
            
            .section-subtitle {
                font-size: 1.1rem;
            }
            
            .showcase-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .features-section {
                padding: 3rem 1rem;
            }
            
            .feature-card {
                padding: 1.5rem 1rem;
            }
            
            .feature-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .feature-title {
                font-size: 1.25rem;
            }
            
            .showcase-grid {
                grid-template-columns: 1fr;
            }

            .timeline-step {
                padding: 2rem 1rem!important;
                margin: 0!important;
            }
        }
</style>
@endsection

@section('content')
<!-- Hero Section -->
<div class="hero-container py-120">
  <div class="floating-shapes">
      <div class="shape"></div>
      <div class="shape"></div>
      <div class="shape"></div>
  </div>

  <div class="main-content">
      <div class="left-content">
          <h1 class="hero-title">Don't Let a Missed Call Cost You a Customer</h1>
          <p class="hero-subtitle"><span style="font-weight: bold;">With 24/7 live human agents, SureHelp Solution ensures you never miss a lead, a booking, or a client.</span><br>We answer your business calls day and night - capturing leads, handling inquiries, and booking appointments. So you can focus on running your business.</p>

          <div class="stats-grid">
              <div class="stat-card" data-stat="95">
                  <i class="fas fa-phone-volume stat-icon"></i>
                  <span class="stat-number">0</span>
                  <div class="stat-label">Call Capture Rate</div>
              </div>
              <div class="stat-card" data-stat="60">
                  <i class="fas fa-piggy-bank stat-icon"></i>
                  <span class="stat-number">0</span>
                  <div class="stat-label">Cost Savings</div>
              </div>
              <div class="stat-card" data-stat="2600">
                  <i class="fas fa-chart-line stat-icon"></i>
                  <span class="stat-number">0</span>
                  <div class="stat-label">Average ROI</div>
              </div>
          </div>

          <div class="cta-buttons">
              <a href="#" class="btn btn-primary">
                  Start Free Trial
                  <i class="fas fa-arrow-right"></i>
              </a>
              <a href="#" class="btn btn-secondary">
                  Watch Demo
                  <i class="fas fa-play"></i>
              </a>
          </div>

          <div class="trust-badge">
              <i class="fas fa-shield-alt"></i>
              No setup fees • Cancel anytime • 30-day money-back guarantee
          </div>
          
          <div class="client-section">
              <p class="client-text">Trusted by 500+ growing businesses</p>
              <div class="client-logos">
                  <img src="https://upload.wikimedia.org/wikipedia/commons/9/96/Microsoft_logo_%282012%29.svg" alt="Microsoft"
                      style="height: 23px;">
                  <img src="https://upload.wikimedia.org/wikipedia/commons/5/51/IBM_logo.svg" alt="IBM" style="height: 25px;">
                  <img src="https://upload.wikimedia.org/wikipedia/commons/2/2f/Google_2015_logo.svg" alt="Google"
                      style="height: 30px;">
                  <img src="https://upload.wikimedia.org/wikipedia/commons/0/08/Netflix_2015_logo.svg" alt="Netflix"
                      style="height: 35px;">
                  <img src="https://upload.wikimedia.org/wikipedia/commons/f/fa/Apple_logo_black.svg" alt="Apple"
                      style="height: 35px;">
              </div>
          </div>
      </div>

      <div class="right-content" style="margin-top: -135px;">
          <div class="visual-container">
              <div class="phone-mockup" style="display: none;">
                  <div class="phone-screen">
                      <div class="screen-content">
                          <div class="app-header">
                              <div class="app-title">Sure Help</div>
                              <div class="app-subtitle">Smart Call Management</div>
                          </div>

                          <div class="call-visualization">
                              <div class="call-ring">
                                  <img src="assets/img/agent.png" style="width: 100%;">
                              </div>
                              <!-- <div class="call-ring">
                                  <i class="fas fa-phone call-icon"></i>
                              </div> -->
                              <div class="incoming-call">
                                  <div class="caller-name">New Customer</div>
                                  <div class="caller-number">+1 (555) 123-4567</div>
                              </div>
                          </div>
                      </div>
                  </div>
              </div>

              <div class="floating-cards">
                  <div class="floating-card">
                      <i class="fas fa-chart-bar"></i>
                      <div>95% Capture Rate</div>
                  </div>
                  <div class="floating-card">
                      <i class="fas fa-clock"></i>
                      <div>24/7 Support</div>
                  </div>
                  <div class="floating-card">
                      <i class="fas fa-shield-alt"></i>
                      <div>Secure & Reliable</div>
                  </div>
              </div>
              <img src="assets/img/agent.png" style="width: 100%;">
          </div>
      </div>
  </div>
</div>

<!-- Features Section -->
<section class="features-section">
  <div class="container">
    <div class="section-header">
      <h2 class="section-title">Everything You Need to Never Miss a Call</h2>
      <p class="section-subtitle">Our comprehensive platform combines cutting-edge technology with human expertise to deliver unparalleled call management services.</p>
    </div>

    <div class="features-grid">
      <!-- Feature 1 -->
      <div class="feature-card">
        <div class="feature-icon-container">
          <div class="feature-icon">
            <i class="fas fa-phone-alt"></i>
          </div>
        </div>
        <h3 class="feature-title">24/7 Call Answering</h3>
        <p class="feature-description">Professional human agents available around the clock to handle your calls with care and expertise.</p>
        <ul class="feature-list">
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Live human receptionists</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Custom call scripts</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Instant message delivery</span>
          </li>
        </ul>
        <a href="#" class="demo-button">Learn More</a>
      </div>

      <!-- Feature 2 -->
      <div class="feature-card">
        <div class="feature-icon-container">
          <div class="feature-icon">
            <i class="fas fa-calendar-check"></i>
          </div>
        </div>
        <h3 class="feature-title">Smart Scheduling</h3>
        <p class="feature-description">Seamlessly book appointments and manage your calendar with intelligent scheduling tools.</p>
        <ul class="feature-list">
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Calendar integration</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Automated reminders</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Time zone handling</span>
          </li>
        </ul>
        <a href="#" class="demo-button">Learn More</a>
      </div>

      <!-- Feature 3 -->
      <div class="feature-card">
        <div class="feature-icon-container">
          <div class="feature-icon">
            <i class="fas fa-users"></i>
          </div>
        </div>
        <h3 class="feature-title">Customer Management</h3>
        <p class="feature-description">Complete CRM solution to track leads, manage customer relationships, and grow your business.</p>
        <ul class="feature-list">
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Lead tracking</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Customer profiles</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Follow-up automation</span>
          </li>
        </ul>
        <a href="#" class="demo-button">Learn More</a>
      </div>

      <!-- Feature 4 -->
      <div class="feature-card">
        <div class="feature-icon-container">
          <div class="feature-icon">
            <i class="fas fa-chart-line"></i>
          </div>
        </div>
        <h3 class="feature-title">Analytics Dashboard</h3>
        <p class="feature-description">Comprehensive insights into your call performance, customer interactions, and business growth.</p>
        <ul class="feature-list">
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Real-time metrics</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Performance reports</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">ROI tracking</span>
          </li>
        </ul>
        <a href="#" class="demo-button">Learn More</a>
      </div>

      <!-- Feature 5 -->
      <div class="feature-card">
        <div class="feature-icon-container">
          <div class="feature-icon">
            <i class="fas fa-plug"></i>
          </div>
        </div>
        <h3 class="feature-title">Easy Integration</h3>
        <p class="feature-description">Connect with your existing tools and workflows through our extensive integration marketplace.</p>
        <ul class="feature-list">
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Popular CRM systems</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Calendar platforms</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">API access</span>
          </li>
        </ul>
        <a href="#" class="demo-button">Learn More</a>
      </div>

      <!-- Feature 6 -->
      <div class="feature-card">
        <div class="feature-icon-container">
          <div class="feature-icon">
            <i class="fas fa-shield-alt"></i>
          </div>
        </div>
        <h3 class="feature-title">Enterprise Security</h3>
        <p class="feature-description">Bank-level security and compliance to protect your data and maintain customer trust.</p>
        <ul class="feature-list">
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">SOC 2 compliance</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">End-to-end encryption</span>
          </li>
          <li class="feature-list-item">
            <div class="check-icon">
              <i class="fas fa-check"></i>
            </div>
            <span class="feature-list-text">Regular audits</span>
          </li>
        </ul>
        <a href="#" class="demo-button">Learn More</a>
      </div>
    </div>

    <!-- Interactive Showcase -->
    <div class="interactive-showcase">
      <h3 style="color: white; font-size: 2rem; margin-bottom: 1rem;">Trusted by Growing Businesses</h3>
      <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 2rem;">Join thousands of businesses that have transformed their customer communication</p>
      
      <div class="showcase-grid">
        <div class="showcase-item">
          <div class="showcase-number">500+</div>
          <div class="showcase-label">Active Clients</div>
        </div>
        <div class="showcase-item">
          <div class="showcase-number">95%</div>
          <div class="showcase-label">Call Capture Rate</div>
        </div>
        <div class="showcase-item">
          <div class="showcase-number">24/7</div>
          <div class="showcase-label">Availability</div>
        </div>
        <div class="showcase-item">
          <div class="showcase-number">99.9%</div>
          <div class="showcase-label">Uptime</div>
        </div>
      </div>
    </div>
  </div>
</section>
@endsection

@section('scripts')
<script>
  // Add click event to the toggle
  document.getElementById('pricingToggle').addEventListener('change', function() {
      const monthlyPrices = document.querySelectorAll('.price-monthly');
      const annualPrices = document.querySelectorAll('.price-annual');
      
      monthlyPrices.forEach(price => {
          if (this.checked) {
              price.classList.add('d-none');
              price.style.display = 'none';
          } else {
              price.classList.remove('d-none');
              price.style.display = 'flex';
          }
      });
      
      annualPrices.forEach(price => {
          if (this.checked) {
              price.classList.remove('d-none');
              price.style.display = 'flex';
          } else {
              price.classList.add('d-none');
              price.style.display = 'none';
          }
      });
  });

  // Image Preview Functionality
  document.addEventListener('DOMContentLoaded', function() {
      // Function to handle dropdown menu image previews
      function initializeDropdownPreviews(dropdownMenu) {
          const dropdownItems = dropdownMenu.querySelectorAll('.dropdown-item[data-preview]');
          const previewContainer = dropdownMenu.querySelector('.dropdown-preview');
          const previewImages = previewContainer.querySelectorAll('.preview-image');
          const defaultImage = previewContainer.querySelector('.preview-image[data-item="default"]');

          // Show default image initially
          if (defaultImage) {
              defaultImage.classList.add('active');
          }

          dropdownItems.forEach(item => {
              item.addEventListener('mouseenter', function() {
                  const previewType = this.getAttribute('data-preview');
                  
                  // Hide all images in this container
                  previewImages.forEach(img => img.classList.remove('active'));
                  
                  // Show the corresponding image
                  const targetImage = previewContainer.querySelector(`.preview-image[data-item="${previewType}"]`);
                  if (targetImage) {
                      targetImage.classList.add('active');
                  } else {
                      // If no matching image, show default
                      defaultImage.classList.add('active');
                  }
              });
          });

          // Handle mouseleave for the entire dropdown menu
          dropdownMenu.addEventListener('mouseleave', function() {
              // Hide all images
              previewImages.forEach(img => img.classList.remove('active'));
              // Show default image
              if (defaultImage) {
                  defaultImage.classList.add('active');
              }
          });
      }

      // Initialize for each dropdown menu
      const dropdownMenus = document.querySelectorAll('.dropdown-menu.mega-menu');
      dropdownMenus.forEach(menu => {
          initializeDropdownPreviews(menu);
      });
  });
</script>
@endsection
