// Navbar scroll effect
const navbar = document.querySelector('.navbar');
window.addEventListener('scroll', () => {
  if (window.scrollY > 50) {
    navbar.classList.add('scrolled');
  } else {
    navbar.classList.remove('scrolled');
  }
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function(e) {
    e.preventDefault();
    const target = document.querySelector(this.getAttribute('href'));
    if (target) {
      target.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  });
});

// Mega menu hover for desktop
const dropdowns = document.querySelectorAll('.navbar .dropdown');
if (window.innerWidth > 991) {
  dropdowns.forEach(dropdown => {
    dropdown.addEventListener('mouseenter', function() {
      const menu = this.querySelector('.dropdown-menu');
      if (menu) {
        menu.classList.add('show');
        // Add animation class to menu items
        menu.querySelectorAll('.dropdown-item').forEach((item, index) => {
          item.style.animationDelay = `${index * 0.1}s`;
          item.classList.add('animate-fadeInUp');
        });
      }
    });
    dropdown.addEventListener('mouseleave', function() {
      const menu = this.querySelector('.dropdown-menu');
      if (menu) {
        menu.classList.remove('show');
        menu.querySelectorAll('.dropdown-item').forEach(item => {
          item.classList.remove('animate-fadeInUp');
        });
      }
    });
  });
}

// Animate elements on scroll
const animateOnScroll = () => {
  const elements = document.querySelectorAll('.animate-on-scroll');
  elements.forEach(element => {
    const elementTop = element.getBoundingClientRect().top;
    const elementBottom = element.getBoundingClientRect().bottom;
    const isVisible = (elementTop < window.innerHeight - 100) && (elementBottom > 0);
    
    if (isVisible) {
      element.classList.add('animate-fadeInUp');
    }
  });
};

window.addEventListener('scroll', animateOnScroll);
window.addEventListener('load', animateOnScroll);

// Testimonial carousel
document.addEventListener('DOMContentLoaded', function() {
  const testimonialCarousel = new bootstrap.Carousel(document.querySelector('#testimonialCarousel'), {
    interval: 5000,
    wrap: true
  });
});

// Stats counter animation
const animateStats = () => {
  const stats = document.querySelectorAll('.stat-number');
  stats.forEach(stat => {
    const target = parseInt(stat.getAttribute('data-target'));
    const duration = 2000; // 2 seconds
    const increment = target / (duration / 16); // 60fps
    let current = 0;

    const updateCount = () => {
      if (current < target) {
        current += increment;
        stat.textContent = Math.round(current);
        requestAnimationFrame(updateCount);
      } else {
        stat.textContent = target;
      }
    };

    updateCount();
  });
};

// Initialize stats animation when section is visible
const statsSection = document.querySelector('.stats-section');
if (statsSection) {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        animateStats();
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.5 });

  observer.observe(statsSection);
}

// Pricing toggle
const pricingToggle = document.querySelector('.pricing-toggle');
if (pricingToggle) {
  pricingToggle.addEventListener('change', function() {
    const monthlyPrices = document.querySelectorAll('.price-monthly');
    const annualPrices = document.querySelectorAll('.price-annual');
    
    monthlyPrices.forEach(price => {
      price.classList.toggle('d-none');
    });
    annualPrices.forEach(price => {
      price.classList.toggle('d-none');
    });
  });
}

// Form validation
const forms = document.querySelectorAll('.needs-validation');
forms.forEach(form => {
  form.addEventListener('submit', function(event) {
    if (!form.checkValidity()) {
      event.preventDefault();
      event.stopPropagation();
    }
    form.classList.add('was-validated');
  });
});

// Mobile menu
const mobileMenu = document.querySelector('.navbar-toggler');
if (mobileMenu) {
  mobileMenu.addEventListener('click', function() {
    document.body.classList.toggle('menu-open');
  });
}

// Initialize tooltips
const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
tooltips.forEach(tooltip => {
  new bootstrap.Tooltip(tooltip);
});

// Initialize popovers
const popovers = document.querySelectorAll('[data-bs-toggle="popover"]');
popovers.forEach(popover => {
  new bootstrap.Popover(popover);
}); 

function animateCounter(element, target, duration = 2000) {
  let start = 0;
  const increment = target / (duration / 16);
  const timer = setInterval(() => {
    start += increment;
    if (start >= target) {
      element.textContent = target + (target === 2600 ? '%' : '%');
      clearInterval(timer);
    } else {
      element.textContent = Math.floor(start) + (target === 2600 ? '%' : '%');
    }
  }, 16);
}

// Intersection Observer for animations
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const statCard = entry.target;
      const target = parseInt(statCard.dataset.stat);
      const numberElement = statCard.querySelector('.stat-number');
      animateCounter(numberElement, target);
      observer.unobserve(statCard);
    }
  });
});

// Observe stat cards
document.querySelectorAll('.stat-card').forEach(card => {
  observer.observe(card);
});

// Button hover effects
document.querySelectorAll('.btn').forEach(btn => {
  btn.addEventListener('mouseenter', function () {
    this.style.transform = 'translateY(-3px) scale(1.05)';
  });

  btn.addEventListener('mouseleave', function () {
    this.style.transform = 'translateY(0) scale(1)';
  });
});

// Interactive stat cards
document.querySelectorAll('.stat-card').forEach(card => {
  card.addEventListener('click', function () {
    this.style.transform = 'scale(0.95)';
    setTimeout(() => {
      this.style.transform = 'scale(1)';
    }, 150);
  });
});

// Parallax effect for floating shapes
document.addEventListener('mousemove', (e) => {
  const shapes = document.querySelectorAll('.shape');
  const x = e.clientX / window.innerWidth;
  const y = e.clientY / window.innerHeight;

  shapes.forEach((shape, index) => {
    const speed = (index + 1) * 0.5;
    shape.style.transform = `translate(${x * speed * 10}px, ${y * speed * 10}px)`;
  });
});

// CTA button click effects
document.querySelectorAll('.btn').forEach(btn => {
  btn.addEventListener('click', function (e) {
    e.preventDefault();

    // Create ripple effect
    const ripple = document.createElement('span');
    const rect = this.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    const x = e.clientX - rect.left - size / 2;
    const y = e.clientY - rect.top - size / 2;

    ripple.style.width = ripple.style.height = size + 'px';
    ripple.style.left = x + 'px';
    ripple.style.top = y + 'px';
    ripple.style.position = 'absolute';
    ripple.style.borderRadius = '50%';
    ripple.style.background = 'rgba(255,255,255,0.3)';
    ripple.style.transform = 'scale(0)';
    ripple.style.animation = 'ripple-effect 0.6s linear';
    ripple.style.pointerEvents = 'none';

    this.style.position = 'relative';
    this.style.overflow = 'hidden';
    this.appendChild(ripple);

    setTimeout(() => {
      ripple.remove();
    }, 600);
  });
});

// Add ripple animation to CSS
const style = document.createElement('style');
style.textContent = `
            @keyframes ripple-effect {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
        `;
document.head.appendChild(style);