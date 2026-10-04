<!-- Contact Section -->
<section id="contact" class="contact-section py-120">
  <div class="container">
    <div class="section-header text-center mb-5">
      <h2 class="section-title">Get in Touch</h2>
      <p class="section-subtitle">Have questions about plans, demos, or enterprise solutions? Our team typically responds within one business day.</p>
    </div>

    <div class="row g-4 g-lg-5 align-items-stretch">
      <div class="col-lg-5">
        <div class="contact-info-panel">
          <h3>Let's talk your business to move forward.</h3>
          <p class="lead mb-4" style="color: rgba(255,255,255,.8); font-size: 1.05rem; line-height: 1.65;">
            Ready to stop missing calls? Tell us about your business and we'll help you find the right answering solution.
          </p>

          <div class="contact-info-card">
            <div class="contact-info-icon"><i class="fas fa-phone"></i></div>
            <div>
              <h5>Call us</h5>
              <p><a href="tel: +1 (858) 321 3947">+1 (858) 321 3947</a></p>
            </div>
          </div>

          <div class="contact-info-card">
            <div class="contact-info-icon"><i class="fas fa-envelope"></i></div>
            <div>
              <h5>Email us</h5>
              <p><a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></p>
            </div>
          </div>

          <div class="contact-info-card">
            <div class="contact-info-icon"><i class="fas fa-map-marker-alt"></i></div>
            <div>
              <h5>Mailing address</h5>
              <p>{{ config('company.address.line1') }}<br>{{ config('company.address.line2') }}</p>
            </div>
          </div>

          <div class="contact-info-card">
            <div class="contact-info-icon"><i class="fas fa-clock"></i></div>
            <div>
              <h5>Support hours</h5>
              <p>Mon–Fri: 7 AM – 9 PM ET<br>Sat–Sun: 9 AM – 5 PM ET</p>
            </div>
          </div>

          <div class="contact-trust-badges">
            <span class="contact-trust-badge"><i class="fas fa-shield-alt"></i> HIPAA-ready</span>
            <span class="contact-trust-badge"><i class="fas fa-bolt"></i> 48-hour setup</span>
            <span class="contact-trust-badge"><i class="fas fa-headset"></i> 24/7 answering</span>
          </div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="contact-form-card h-100">
          <h3>Send us a message</h3>
          <p class="form-lead">Fill out the form below and we'll get back to you shortly.</p>

          @if (session('contact_success'))
            <div class="contact-alert contact-alert-success" role="alert">
              <i class="fas fa-check-circle mt-1"></i>
              <div>{{ session('contact_success') }}</div>
            </div>
          @endif

          @if ($errors->any())
            <div class="contact-alert contact-alert-error" role="alert">
              <i class="fas fa-exclamation-circle mt-1"></i>
              <div>
                <strong>Please fix the following:</strong>
                <ul class="mb-0 mt-2 ps-3">
                  @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                  @endforeach
                </ul>
              </div>
            </div>
          @endif

          <form class="contact-form" action="{{ route('contact.store') }}" method="POST" novalidate>
            @csrf

            <div class="row g-3">
              <div class="col-md-6">
                <label for="contact_name" class="form-label">Full name <span class="required">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-user"></i></span>
                  <input type="text" class="form-control @error('name') is-invalid @enderror" id="contact_name" name="name" value="{{ old('name') }}" placeholder="Jane Smith" required autocomplete="name">
                </div>
                @error('name')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
              </div>

              <div class="col-md-6">
                <label for="contact_company" class="form-label">Company <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-building"></i></span>
                  <input type="text" class="form-control @error('company') is-invalid @enderror" id="contact_company" name="company" value="{{ old('company') }}" placeholder="Your business name" autocomplete="organization">
                </div>
                @error('company')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
              </div>

              <div class="col-md-6">
                <label for="contact_email" class="form-label">Email address <span class="required">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                  <input type="email" class="form-control @error('email') is-invalid @enderror" id="contact_email" name="email" value="{{ old('email') }}" placeholder="you@company.com" required autocomplete="email">
                </div>
                @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
              </div>

              <div class="col-md-6">
                <label for="contact_phone" class="form-label">Phone number</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-phone"></i></span>
                  <input type="tel" class="form-control @error('phone') is-invalid @enderror" id="contact_phone" name="phone" value="{{ old('phone') }}" placeholder="(555) 123-4567" autocomplete="tel">
                </div>
                @error('phone')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
              </div>
              <div class="col-md-12" style="margin-top: 5px;">
                <p class="form-text" style="color: rgba(255, 56, 56, 0.8); font-size: 0.7rem; line-height: 1.2; margin: 0;">Note: We do not use your phone number for texting or any marketing campaigns. Your privacy is our priority.</p>
              </div>

              <div class="col-12">
                <label for="contact_inquiry" class="form-label">How can we help? <span class="required">*</span></label>
                <div class="input-group">
                  <span class="input-group-text"><i class="fas fa-tag"></i></span>
                  <select class="form-select @error('inquiry_type') is-invalid @enderror" id="contact_inquiry" name="inquiry_type" required>
                    <option value="" disabled {{ old('inquiry_type') ? '' : 'selected' }}>Select a topic</option>
                    <option value="general" @selected(old('inquiry_type') === 'general')>General inquiry</option>
                    <option value="sales" @selected(old('inquiry_type') === 'sales')>Pricing &amp; plans</option>
                    <option value="demo" @selected(old('inquiry_type') === 'demo')>Book a demo</option>
                    <option value="enterprise" @selected(old('inquiry_type') === 'enterprise')>Enterprise solutions</option>
                    <option value="support" @selected(old('inquiry_type') === 'support')>Customer support</option>
                  </select>
                </div>
                @error('inquiry_type')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
              </div>

              <div class="col-12">
                <label for="contact_message" class="form-label">Your message <span class="required">*</span></label>
                <textarea class="form-control @error('message') is-invalid @enderror" id="contact_message" name="message" style="min-height: 90px;" placeholder="Tell us about your call volume, industry, or what you're looking for..." required>{{ old('message') }}</textarea>
                @error('message')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
              </div>

              <div class="col-12">
                <div class="contact-consent-block">

                  <div class="form-check">
                    <input class="form-check-input @error('privacy') is-invalid @enderror" type="checkbox" id="contact_privacy" name="privacy" value="1" {{ old('privacy') ? 'checked' : '' }} required>
                    <label class="form-check-label" for="contact_privacy" style="font-size: 0.7rem; line-height: 1.2;">
                      I agree to receive communications by text message about inquiries, confirm appoints, schedule appointments from Sure Help Solution. You may opt-out by replying STOP or ask for more information by replying HELP. Message frequency varies. Message and data rates may apply. You may review our <a href="{{ route('legal.privacy-policy') }}" target="_blank" rel="noopener">Privacy Policy</a> to learn how your data is used.<span class="required"></span>
                    </label>
                    @error('privacy')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                  </div>

                  <p class="contact-sms-disclaimer" style="font-size: 0.7rem; line-height: 1.2;">
                    You may opt out at any time by replying STOP, or reply HELP for more information. Message frequency varies. Message and data rates may apply. You may review our <a href="{{ route('legal.privacy-policy') }}" target="_blank" rel="noopener">Privacy Policy</a> to learn how your data is used.
                  </p>
                </div>
              </div>

              <div class="col-12 pt-1">
                <button type="submit" class="contact-submit-btn" id="contactSubmitBtn">
                  <i class="fas fa-paper-plane"></i>
                  <span>Send Message</span>
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
