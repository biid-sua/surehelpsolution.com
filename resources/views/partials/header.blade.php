  <!-- Navigation -->
  <nav class="navbar navbar-expand-lg fixed-top py-3">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center" href="{{ route('home') }}">
        <div class="brand-icon">
          <img src="{{ asset('assets/img/logo.png') }}" alt="SHS Logo" style="width: 100%; height: 100%; object-fit: contain;">
        </div>
        <!-- <div class="brand-text">
          <span class="brand-name">SureHelp</span>
          <span class="brand-slogan">Professional Call Management</span>
        </div> -->
      </a>
      
      <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav mx-auto">
          <!-- Products Dropdown -->
          <li class="nav-item dropdown position-static px-2">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="fas fa-cube me-1"></i>Products
            </a>
            <div class="dropdown-menu mega-menu w-100 py-4">
              <div class="container">
                <div class="row g-4">
                  <div class="col-lg-4">
                    <h6 class="dropdown-header text-primary mb-3">Core Services</h6>
                    <div class="menu-item">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'call-answering') }}" data-preview="call-answering">
                        <i class="fas fa-phone-alt text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Call Answering Service</h6>
                          <small class="text-muted">24/7 Professional Call Support</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'appointment-booking') }}" data-preview="appointment-booking">
                        <i class="fas fa-calendar-check text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Appointment Booking</h6>
                          <small class="text-muted">Smart Scheduling System</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'customer-management') }}" data-preview="customer-management">
                        <i class="fas fa-users text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Customer Management</h6>
                          <small class="text-muted">Complete CRM Solution</small>
                        </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-lg-4">
                    <h6 class="dropdown-header text-primary mb-3">Solutions</h6>
                    <div class="menu-item">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'surehelp-answer') }}" data-preview="analytics-dashboard">
                        <i class="fas fa-headset text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">SureHelp Answer</h6>
                          <small class="text-muted">Smart Call Routing</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'surehelp-schedule') }}" data-preview="appointment-booking">
                        <i class="fas fa-tasks text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">SureHelp Schedule</h6>
                          <small class="text-muted">Automated Booking</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'surehelp-intelligence') }}" data-preview="integration-market">
                        <i class="fas fa-brain text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">SureHelp Intelligence</h6>
                          <small class="text-muted">AI-Powered Insights</small>
                        </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-lg-4">
                    <div class="dropdown-preview">
                      <img src="https://images.pexels.com/photos/3184291/pexels-photo-3184291.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image active" data-item="default" alt="Products Overview">
                      <img src="https://images.pexels.com/photos/1181671/pexels-photo-1181671.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="call-answering" alt="Call Answering Service">
                      <img src="https://images.pexels.com/photos/3183150/pexels-photo-3183150.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="appointment-booking" alt="Appointment Booking">
                      <img src="https://images.pexels.com/photos/1560932/pexels-photo-1560932.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="customer-management" alt="Customer Management">
                      <img src="https://images.pexels.com/photos/7681091/pexels-photo-7681091.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="analytics-dashboard" alt="Analytics Dashboard">
                      <img src="https://images.pexels.com/photos/546819/pexels-photo-546819.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="integration-market" alt="Integration Market">
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </li>
          
          <!-- Solutions Dropdown -->
          <li class="nav-item dropdown position-static px-2">
            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="fas fa-lightbulb me-1"></i>Solutions
            </a>
            <div class="dropdown-menu mega-menu w-100 py-4">
              <div class="container">
                <div class="row g-4">
                  <div class="col-lg-3">
                    <h6 class="dropdown-header text-primary mb-3">By Business Size</h6>
                    <div class="menu-item">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'small-business') }}" data-preview="small-business">
                        <i class="fas fa-store text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Small Business</h6>
                          <small class="text-muted">1-10 Employees</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'growing-business') }}" data-preview="growing-business">
                        <i class="fas fa-building text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Growing Business</h6>
                          <small class="text-muted">11-50 Employees</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'enterprise') }}" data-preview="enterprise">
                        <i class="fas fa-city text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Enterprise</h6>
                          <small class="text-muted">50+ Employees</small>
                        </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-lg-9">
                    <h6 class="dropdown-header text-primary mb-3">By Industry</h6>
                    <div class="menu-item"><div class="row g-4">
                        <div class="col-lg-4">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'health-personal-care') }}" data-preview="health-personal-care">
                        <i class="fas fa-heart text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Health & Personal Care</h6>
                          <small class="text-muted">Wellness & Personal Services</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'home-property-services') }}" data-preview="home-property-services">
                        <i class="fas fa-home text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Home & Property Services</h6>
                          <small class="text-muted">Maintenance & Property Care</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'specialty-trades') }}" data-preview="specialty-trades">
                        <i class="fas fa-tools text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Specialty Trades & Construction</h6>
                          <small class="text-muted">Skilled Trade Services</small>
                        </div>
                      </a>
                      </div>
                      <div class="col-lg-4">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'legal-financial') }}" data-preview="legal-financial">
                        <i class="fas fa-balance-scale text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Legal & Financial Services</h6>
                          <small class="text-muted">Professional Services</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'education-coaching') }}" data-preview="education-coaching">
                        <i class="fas fa-graduation-cap text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Education & Coaching</h6>
                          <small class="text-muted">Learning & Development</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'retail-ecommerce') }}" data-preview="retail-ecommerce">
                        <i class="fas fa-shopping-cart text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                            <h6 class="mb-0">Retail & eCommerce Support</h6>
                            <small class="text-muted">Sales & Customer Support</small>
                        </div>
                    </a>
                      </div>
                      <div class="col-lg-4">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'event-venue') }}" data-preview="event-venue">
                        <i class="fas fa-calendar-alt text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Event & Venue Services</h6>
                          <small class="text-muted">Event Management</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'nonprofits-ministries') }}" data-preview="nonprofits-ministries">
                        <i class="fas fa-hands-helping text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Nonprofits & Ministries</h6>
                          <small class="text-muted">Mission-driven Organizations</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'franchise-businesses') }}" data-preview="franchise-businesses">
                        <i class="fas fa-store-alt text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                            <h6 class="mb-0">Franchise Businesses & Multi-location Chains</h6>
                            <small class="text-muted">Multi-location Management</small>
                        </div>
                    </a>
                      </div>
                      </div>
                    </div>
                  </div>
                  <div class="col-lg-4" style="display: none;">
                    <div class="dropdown-preview">
                      <img src="https://images.pexels.com/photos/3184465/pexels-photo-3184465.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image active" data-item="default" alt="Solutions Overview">
                      <img src="https://images.pexels.com/photos/3182812/pexels-photo-3182812.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="small-business" alt="Small Business">
                      <img src="https://images.pexels.com/photos/1181435/pexels-photo-1181435.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="growing-business" alt="Growing Business">
                      <img src="https://images.pexels.com/photos/1181616/pexels-photo-1181616.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="enterprise" alt="Enterprise">
                      <img src="https://images.pexels.com/photos/7088530/pexels-photo-7088530.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="healthcare" alt="Healthcare">
                      <img src="https://images.pexels.com/photos/8486972/pexels-photo-8486972.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="home-services" alt="Home Services">
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </li>
          
          <li class="nav-item px-2">
            <a class="nav-link" href="{{ route('home') }}#pricing">
              <i class="fas fa-tag me-1"></i>Pricing
            </a>
          </li>
          
                  <!-- Resources Dropdown -->
                  <li class="nav-item dropdown position-static px-2">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                      <i class="fas fa-book me-1"></i>Resources
                    </a>
            <div class="dropdown-menu mega-menu w-100 py-4">
              <div class="container">
                <div class="row g-4">
                  <div class="col-lg-4">
                    <h6 class="dropdown-header text-primary mb-3">Learn & Grow</h6>
                    <div class="menu-item">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'success-stories') }}" data-preview="success-stories">
                        <i class="fas fa-book text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Success Stories</h6>
                          <small class="text-muted">Customer Case Studies</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'roi-calculator') }}" data-preview="roi-calculator">
                        <i class="fas fa-calculator text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1" data-bs-toggle="modal" data-bs-target="#roiCalculatorModal">
                          <h6 class="mb-0">ROI Calculator</h6>
                          <small class="text-muted">Measure Your Savings</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'implementation-guide') }}" data-preview="implementation-guide">
                        <i class="fas fa-graduation-cap text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Implementation Guide</h6>
                          <small class="text-muted">Setup & Best Practices</small>
                        </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-lg-4">
                    <h6 class="dropdown-header text-primary mb-3">Support</h6>
                    <div class="menu-item">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'help-center') }}" data-preview="support-center">
                        <i class="fas fa-life-ring text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Help Center</h6>
                          <small class="text-muted">Guides & Documentation</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'webinars-training') }}" data-preview="implementation-guide">
                        <i class="fas fa-video text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Webinars & Training</h6>
                          <small class="text-muted">Live & Recorded Sessions</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="{{ route('pages.show', 'integrations') }}" data-preview="api-documentation">
                        <i class="fas fa-puzzle-piece text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Integration Directory</h6>
                          <small class="text-muted">Connect Your Tools</small>
                        </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-lg-4">
                    <div class="dropdown-preview">
                      <img src="https://images.pexels.com/photos/3184292/pexels-photo-3184292.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image active" data-item="default" alt="Resources Overview">
                      <img src="https://images.pexels.com/photos/3184291/pexels-photo-3184291.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="success-stories" alt="Success Stories">
                      <img src="https://images.pexels.com/photos/6963857/pexels-photo-6963857.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="roi-calculator" alt="ROI Calculator">
                      <img src="https://images.pexels.com/photos/3183150/pexels-photo-3183150.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="implementation-guide" alt="Implementation Guide">
                      <img src="https://images.pexels.com/photos/3182812/pexels-photo-3182812.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="support-center" alt="Support Center">
                      <img src="https://images.pexels.com/photos/3183183/pexels-photo-3183183.jpeg?auto=compress&cs=tinysrgb&w=400" 
                           class="img-fluid rounded-4 shadow-lg preview-image" data-item="api-documentation" alt="API Documentation">
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </li>
          
          <li class="nav-item px-2">
            <a class="nav-link" href="{{ route('home') }}#contact">
              <i class="fas fa-envelope me-1"></i>Contact
            </a>
          </li>
        </ul>
        
        <div class="d-flex align-items-center gap-3">
          @guest
            <a href="{{ route('login') }}" class="btn btn-outline-primary rounded-pill px-4 py-2">
              <i class="fas fa-user me-2"></i>Sign in
            </a>
            <a href="{{ route('home') }}#contact" class="btn btn-primary rounded-pill px-4 py-2">
              <i class="fas fa-rocket me-2"></i>Get Started
            </a>
          @else
            <div class="dropdown">
              <button class="btn btn-outline-primary rounded-pill px-4 py-2 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-user me-2"></i>{{ Auth::user()->name }}
              </button>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="{{ Auth::user()->homeUrl() }}">Dashboard</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                  <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item">
                      <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </button>
                  </form>
                </li>
              </ul>
            </div>
          @endguest
        </div>
      </div>
    </div>
  </nav>

  <!-- Success/Error Messages -->
  @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin: 0; border-radius: 0; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none;">
      <i class="fas fa-check-circle me-2"></i>
      {{ session('success') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  @endif

  @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin: 0; border-radius: 0; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none;">
      <i class="fas fa-exclamation-circle me-2"></i>
      {{ session('error') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  @endif
