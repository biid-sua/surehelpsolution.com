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
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="call-answering">
                        <i class="fas fa-phone-alt text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Call Answering Service</h6>
                          <small class="text-muted">24/7 Professional Call Support</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="appointment-booking">
                        <i class="fas fa-calendar-check text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Appointment Booking</h6>
                          <small class="text-muted">Smart Scheduling System</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="customer-management">
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
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="analytics-dashboard">
                        <i class="fas fa-headset text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">SureHelp Answer</h6>
                          <small class="text-muted">Smart Call Routing</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="appointment-booking">
                        <i class="fas fa-tasks text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">SureHelp Schedule</h6>
                          <small class="text-muted">Automated Booking</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="integration-market">
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
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="small-business">
                        <i class="fas fa-store text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Small Business</h6>
                          <small class="text-muted">1-10 Employees</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="growing-business">
                        <i class="fas fa-building text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Growing Business</h6>
                          <small class="text-muted">11-50 Employees</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="enterprise">
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
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="health-personal-care">
                        <i class="fas fa-heart text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Health & Personal Care</h6>
                          <small class="text-muted">Wellness & Personal Services</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="home-property-services">
                        <i class="fas fa-home text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Home & Property Services</h6>
                          <small class="text-muted">Maintenance & Property Care</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="specialty-trades">
                        <i class="fas fa-tools text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Specialty Trades & Construction</h6>
                          <small class="text-muted">Skilled Trade Services</small>
                        </div>
                      </a>
                      </div>
                      <div class="col-lg-4">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="legal-financial">
                        <i class="fas fa-balance-scale text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Legal & Financial Services</h6>
                          <small class="text-muted">Professional Services</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="education-coaching">
                        <i class="fas fa-graduation-cap text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Education & Coaching</h6>
                          <small class="text-muted">Learning & Development</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="retail-ecommerce">
                        <i class="fas fa-shopping-cart text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                            <h6 class="mb-0">Retail & eCommerce Support</h6>
                            <small class="text-muted">Sales & Customer Support</small>
                        </div>
                    </a>
                      </div>
                      <div class="col-lg-4">
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="event-venue">
                        <i class="fas fa-calendar-alt text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Event & Venue Services</h6>
                          <small class="text-muted">Event Management</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="nonprofits-ministries">
                        <i class="fas fa-hands-helping text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Nonprofits & Ministries</h6>
                          <small class="text-muted">Mission-driven Organizations</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="franchise-businesses">
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
            <a class="nav-link" href="#pricing">
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
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="success-stories">
                        <i class="fas fa-book text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Success Stories</h6>
                          <small class="text-muted">Customer Case Studies</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="roi-calculator">
                        <i class="fas fa-calculator text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1" data-bs-toggle="modal" data-bs-target="#roiCalculatorModal">
                          <h6 class="mb-0">ROI Calculator</h6>
                          <small class="text-muted">Measure Your Savings</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="implementation-guide">
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
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="support-center">
                        <i class="fas fa-life-ring text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Help Center</h6>
                          <small class="text-muted">Guides & Documentation</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="implementation-guide">
                        <i class="fas fa-video text-primary fs-4 me-3"></i>
                        <div class="flex-grow-1">
                          <h6 class="mb-0">Webinars & Training</h6>
                          <small class="text-muted">Live & Recorded Sessions</small>
                        </div>
                      </a>
                      <a class="dropdown-item rounded-3 p-3 mb-2 d-flex align-items-center" href="#" data-preview="api-documentation">
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
            <a class="nav-link" href="#contact">
              <i class="fas fa-envelope me-1"></i>Contact
            </a>
          </li>
        </ul>
        
        <div class="d-flex align-items-center gap-3">
          @guest
            <button class="btn btn-outline-primary rounded-pill px-4 py-2" data-bs-toggle="modal" data-bs-target="#loginModal">
              <i class="fas fa-user me-2"></i>Login
            </button>
            <a href="{{ route('home') }}#contact" class="btn btn-primary rounded-pill px-4 py-2">
              <i class="fas fa-rocket me-2"></i>Get Started
            </a>
          @else
            <div class="dropdown">
              <button class="btn btn-outline-primary rounded-pill px-4 py-2 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-user me-2"></i>{{ Auth::user()->name }}
              </button>
              <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="{{ route('admin.dashboard') }}">Dashboard</a></li>
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

  @if(session('error') && !session('account_deactivated'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin: 0; border-radius: 0; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; border: none;">
      <i class="fas fa-exclamation-circle me-2"></i>
      {{ session('error') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  @endif

  <style>
    #accountDeactivatedModal .account-deactivated-wrapper {
      background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
      border-radius: 20px;
      padding: 2.5rem 2rem;
      text-align: center;
      color: #ffffff;
      box-shadow: 0 25px 50px rgba(0, 0, 0, 0.35);
    }

    #accountDeactivatedModal .account-deactivated-message {
      color: rgba(255, 255, 255, 0.8);
      margin-bottom: 1.5rem;
    }

    #accountDeactivatedModal .account-deactivated-email {
      color: #93c5fd;
      text-decoration: none;
    }

    #accountDeactivatedModal .account-deactivated-email:hover {
      color: #bfdbfe;
    }
  </style>

  <!-- Account Deactivated Modal -->
  <div class="modal fade" id="accountDeactivatedModal" tabindex="-1" aria-labelledby="accountDeactivatedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg">
        <div class="account-deactivated-wrapper">
          <div class="mb-3">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 72px; height: 72px; background: rgba(239, 68, 68, 0.2); color: #f87171;">
              <i class="fas fa-user-slash fa-2x"></i>
            </span>
          </div>
          <h4 class="mb-3 text-white" id="accountDeactivatedModalLabel">Account Deactivated</h4>
          <p class="account-deactivated-message" id="accountDeactivatedMessage">
            Your account has been deactivated. Please contact the concerned person for assistance.
          </p>
          @if(config('company.email'))
            <p class="mb-4">
              <a href="mailto:{{ config('company.email') }}" class="account-deactivated-email">
                <i class="fas fa-envelope me-2"></i>{{ config('company.email') }}
              </a>
            </p>
          @endif
          <button type="button" class="btn btn-primary px-4" data-bs-dismiss="modal">OK</button>
        </div>
      </div>
    </div>
  </div>

  @guest
    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="login-modal-wrapper">
            <!-- Close Button -->
            <button type="button" class="modal-close-btn" data-bs-dismiss="modal" aria-label="Close">
              <i class="fas fa-times"></i>
            </button>

            <!-- Login Form -->
            <div class="login-form-container">
              <div class="login-header">
                <div class="login-logo">
                  <i class="fas fa-phone-alt"></i>
                </div>
                <h3>Welcome Back!</h3>
                <p>Log in to access your SureHelp dashboard</p>
              </div>

              <form class="login-form" id="loginForm" method="POST" action="{{ route('auth.login') }}">
                @csrf
                <div class="form-floating mb-3">
                  <input type="email" class="form-control" id="loginEmail" name="email" placeholder="name@example.com" required>
                  <label for="loginEmail">Email address</label>
                </div>
                
                <div class="form-floating mb-3 password-field">
                  <input type="password" class="form-control" id="loginPassword" name="password" placeholder="Password" required>
                  <label for="loginPassword">Password</label>
                  <button type="button" class="password-toggle" onclick="togglePassword()">
                    <i class="fas fa-eye"></i>
                  </button>
                </div>

                <div class="form-options mb-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="rememberMe" name="remember">
                    <label class="form-check-label" for="rememberMe">
                      Remember me
                    </label>
                  </div>
                  <a href="#" class="forgot-password">Forgot password?</a>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                  <span>Log In</span>
                  <i class="fas fa-arrow-right"></i>
                </button>
              </form>
            </div>
          </div>

          <script>
            function togglePassword() {
              const passwordInput = document.getElementById('loginPassword');
              const toggleBtn = document.querySelector('.password-toggle i');
              
              if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleBtn.classList.remove('fa-eye');
                toggleBtn.classList.add('fa-eye-slash');
              } else {
                passwordInput.type = 'password';
                toggleBtn.classList.remove('fa-eye-slash');
                toggleBtn.classList.add('fa-eye');
              }
            }

            function showAccountDeactivatedModal(message) {
              const modalEl = document.getElementById('accountDeactivatedModal');
              const messageEl = document.getElementById('accountDeactivatedMessage');
              if (!modalEl) return;

              if (messageEl && message) {
                messageEl.textContent = message;
              }

              const loginModalEl = document.getElementById('loginModal');
              if (loginModalEl) {
                const loginModal = bootstrap.Modal.getInstance(loginModalEl);
                if (loginModal) {
                  loginModal.hide();
                }
              }

              bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }

            // Enhance login to handle JSON responses and follow redirects
            (function() {
              const form = document.getElementById('loginForm');
              if (!form) return;

              form.addEventListener('submit', async function(e) {
                // Allow normal submit if JS fails
                e.preventDefault();

                const submitBtn = document.getElementById('loginBtn');
                const originalHtml = submitBtn ? submitBtn.innerHTML : '';
                try {
                  if (submitBtn) {
                    submitBtn.innerHTML = '<span>Logging in...</span><i class="fas fa-spinner fa-spin"></i>';
                    submitBtn.disabled = true;
                  }

                  const formData = new FormData(form);
                  const resp = await fetch(form.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                      'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                  });

                  // If server returns an HTTP redirect, follow it
                  if (resp.redirected) {
                    window.location.href = resp.url;
                    return;
                  }

                  // Try to parse JSON and follow provided redirect URL
                  const contentType = resp.headers.get('content-type') || '';
                  if (contentType.includes('application/json')) {
                    const data = await resp.json();

                    if (data && data.account_deactivated) {
                      showAccountDeactivatedModal(data.message);
                      return;
                    }

                    if (data && data.success && data.redirect) {
                      window.location.href = data.redirect;
                      return;
                    }

                    if (data && data.message && !data.success) {
                      alert(data.message);
                      return;
                    }
                  }

                  // Fallback: if HTML, replace document
                  const text = await resp.text();
                  if (text && text.trim().length > 0) {
                    document.open();
                    document.write(text);
                    document.close();
                  } else {
                    window.location.reload();
                  }
                } catch (err) {
                  console.error('Login failed:', err);
                  window.location.reload();
                } finally {
                  if (submitBtn) {
                    submitBtn.innerHTML = originalHtml;
                    submitBtn.disabled = false;
                  }
                }
              });
            })();
          </script>
        </div>
      </div>
    </div>
  @endguest

  @if(session('account_deactivated'))
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const message = @json(session('account_deactivated_message', 'Your account has been deactivated. Please contact the concerned person for assistance.'));
        const modalEl = document.getElementById('accountDeactivatedModal');
        const messageEl = document.getElementById('accountDeactivatedMessage');

        if (messageEl) {
          messageEl.textContent = message;
        }

        if (modalEl) {
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
      });
    </script>
  @endif

