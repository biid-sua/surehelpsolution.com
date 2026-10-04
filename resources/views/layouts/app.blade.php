<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title', 'SureHelp Solutions - Professional Call Management Platform')</title>
  <meta name="description" content="@yield('description', 'Never miss a customer call again with SureHelp Solutions. Professional call answering service platform for growing businesses.')">
  
  <!-- Favicon -->
  <link rel="icon" href="{{ asset('assets/img/favicon.png') }}">
  
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Custom CSS -->
  <link href="{{ asset('assets/css/style.css') }}" rel="stylesheet">
  
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
  
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <!-- AOS Animation Library -->
  <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

  <style>
    /* Navbar: blur on ::before (not on .navbar) so mega-menu glass can also blur page content. */
    .navbar {
      background: transparent;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      transition: all 0.3s ease;
      padding: 0.75rem 0;
    }

    .navbar::before {
      content: '';
      position: absolute;
      inset: 0;
      z-index: 0;
      pointer-events: none;
      background: rgba(15, 15, 35, 0.48);
      backdrop-filter: blur(12px) saturate(1.1);
      -webkit-backdrop-filter: blur(12px) saturate(1.1);
      transform: translateZ(0);
    }

    .navbar.scrolled {
      padding: 0.5rem 0;
      box-shadow: 0 2px 12px rgba(0, 0, 0, 0.12);
    }

    .navbar.scrolled::before {
      background: rgba(15, 15, 35, 0.58);
    }

    .navbar > .container {
      position: relative;
      z-index: 1;
    }

    @media (min-width: 992px) {
      .navbar-expand-lg .navbar-collapse {
        overflow: visible;
      }
    }

    .brand-icon {
      width: 190px;
      /* height: 100px; */
      background: transparent;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.3s ease;
      overflow: hidden;
    }

    .brand-icon img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      transition: all 0.3s ease;
    }

    .brand-text {
      display: flex;
      flex-direction: column;
    }

    .brand-name {
      font-size: 1.5rem;
      font-weight: 700;
      color: white;
      line-height: 1.2;
    }

    .brand-slogan {
      font-size: 0.75rem;
      color: rgba(255, 255, 255, 0.7);
      line-height: 1.2;
    }

    .navbar-brand {
      padding: 0;
    }

    /* .navbar-brand:hover .brand-icon {
      transform: scale(1.1);
      box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
    }

    .navbar-brand:hover .brand-icon img {
      transform: scale(1.05);
    } */

    .navbar-brand:hover .brand-icon, .navbar-brand:hover .brand-icon img {
      transform: none !important;
      box-shadow: none !important;
    }

    .nav-link {
      color: rgba(255, 255, 255, 0.8) !important;
      font-weight: 500;
      padding: 0.5rem 1rem !important;
      transition: all 0.3s ease;
      position: relative;
    }

    .nav-link:hover {
      color: white !important;
      transform: translateY(-2px);
    }

    .nav-link:focus,
    .nav-link:active,
    .nav-link:focus-visible,
    .dropdown-toggle:focus,
    .dropdown-toggle:active,
    .dropdown-toggle:focus-visible {
      outline: none !important;
      box-shadow: none !important;
      border: none !important;
    }

    .nav-link::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 50%;
      width: 0;
      height: 2px;
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
      transition: all 0.3s ease;
      transform: translateX(-50%);
    }

    .nav-link:hover::after {
      width: 80%;
      left: 50%;
      transform: translateX(-50%);
    }

    .navbar-toggler {
      border: none;
      padding: 0;
      width: 40px;
      height: 40px;
      position: relative;
      transition: all 0.3s ease;
    }

    .navbar-toggler:focus {
      box-shadow: none;
    }

    /* Remove focus indicators from dropdown toggles */
    .dropdown-toggle:focus,
    .dropdown-toggle:focus-visible,
    .dropdown-toggle:active {
      outline: none !important;
      box-shadow: none !important;
      border: none !important;
      background: transparent !important;
    }

    /* Remove any Bootstrap default focus styles */
    .btn:focus,
    .btn:focus-visible,
    .btn:active {
      outline: none !important;
      box-shadow: none !important;
    }

     /* Specifically target dropdown toggles */
     a.dropdown-toggle:focus,
     a.dropdown-toggle:focus-visible,
     a.dropdown-toggle:active {
       outline: none !important;
       box-shadow: none !important;
       border: none !important;
       background: transparent !important;
       color: rgba(255, 255, 255, 0.8) !important;
     }

     /* Ensure dropdown toggles also have centered underlines */
     .dropdown-toggle::after {
       content: '';
       position: absolute;
       bottom: 0;
       left: 50%;
       width: 0;
       height: 2px;
       background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
       transition: all 0.3s ease;
       transform: translateX(-50%);
     }

     .dropdown-toggle:hover::after {
       width: 80%;
       left: 50%;
       transform: translateX(-50%);
     }

    .navbar-toggler-icon {
      background-image: none;
      position: relative;
      width: 24px;
      height: 2px;
      background: white;
      transition: all 0.3s ease;
    }

    .navbar-toggler-icon::before,
    .navbar-toggler-icon::after {
      content: '';
      position: absolute;
      width: 24px;
      height: 2px;
      background: white;
      transition: all 0.3s ease;
    }

    .navbar-toggler-icon::before {
      top: -6px;
    }

    .navbar-toggler-icon::after {
      bottom: -6px;
    }

    .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon {
      background: transparent;
    }

    .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon::before {
      transform: rotate(45deg);
      top: 0;
    }

    .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon::after {
      transform: rotate(-45deg);
      bottom: 0;
    }

    .dropdown-menu.mega-menu {
      --bs-dropdown-bg: transparent;
      border-radius: 0 0 15px 15px;
      background-color: transparent !important;
      border: 1px solid rgba(255, 255, 255, 0.12);
      box-shadow: 0 20px 45px rgba(15, 23, 42, 0.16);
      margin-top: 0;
      left: 0 !important;
      right: 0 !important;
      transform: none !important;
      width: 100% !important;
      max-width: none !important;
    }

    .dropdown-menu.mega-menu::before {
      content: '';
      position: absolute;
      inset: 0;
      z-index: 0;
      border-radius: inherit;
      background: rgba(15, 15, 35, 0.42);
      backdrop-filter: blur(20px) saturate(1.25);
      -webkit-backdrop-filter: blur(20px) saturate(1.25);
      transform: translateZ(0);
      pointer-events: none;
    }

    .dropdown-menu.mega-menu > .container {
      position: relative;
      z-index: 1;
    }

    .dropdown.position-static .dropdown-menu {
      position: absolute !important;
      top: 100% !important;
      left: 0 !important;
      right: 0 !important;
      transform: none !important;
    }

    .mega-menu .dropdown-item {
      transition: all 0.3s ease;
      border: 0px solid transparent;
      background: transparent;
      color: rgba(248, 250, 252, 0.95);
    }

    .mega-menu .dropdown-item:hover {
      background: rgba(255, 255, 255, 0.52);
      border-color: var(--bs-primary-border-subtle);
      transform: translateX(5px);
    }

    .mega-menu .dropdown-header {
      font-weight: 600;
      letter-spacing: 1px;
      text-transform: uppercase;
      font-size: 0.85rem;
      color: rgba(226, 232, 240, 0.92) !important;
    }

    .mega-menu .dropdown-item h6 {
      color: rgba(248, 250, 252, 0.98);
    }

    .mega-menu .dropdown-item small,
    .mega-menu .dropdown-item .text-muted {
      color: rgba(226, 232, 240, 0.82) !important;
    }

    .mega-menu .dropdown-item i {
      color: rgba(44, 44, 84, 0.96) !important;
    }

    .btn-outline-primary {
      border-color: rgba(255, 255, 255, 0.3);
      color: white;
      background: transparent;
      transition: all 0.3s ease;
    }

    .btn-outline-primary:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: rgba(255, 255, 255, 0.5);
      color: white;
    }

    .btn-primary {
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
      border: none;
      transition: all 0.3s ease;
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
    }

    /* Dropdown Preview Styles */
    .dropdown-preview {
      position: relative;
      width: 100%;
      height: 300px;
      overflow: hidden;
      border-radius: 1rem;
    }

    .preview-image {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      opacity: 0;
      transform: scale(1.1);
      transition: all 0.4s ease-in-out;
    }

    .preview-image.active {
      opacity: 1;
      transform: scale(1);
    }

    @media (max-width: 991.98px) {
      .navbar-collapse {
        background: white;
        padding: 1rem;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        margin-top: 1rem;
      }

      .mega-menu {
        box-shadow: none;
        padding: 0 !important;
      }

      .mega-menu .dropdown-item {
        padding: 0.5rem 1rem !important;
      }

      .mega-menu img {
        display: none;
      }

      .navbar .nav-link::after {
        display: none;
      }

      .d-flex {
        flex-direction: column;
        gap: 1rem;
      }

      .dropdown-preview {
        display: none;
      }
    }

    /* Footer Styles */
    .footer {
      background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
      color: white;
      position: relative;
      overflow: hidden;
    }

    .footer::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: 
        radial-gradient(circle at 25% 25%, rgba(79, 70, 229, 0.1) 0%, transparent 50%),
        radial-gradient(circle at 75% 75%, rgba(236, 72, 153, 0.1) 0%, transparent 50%);
      pointer-events: none;
    }

    .footer-top {
      padding: 50px 0 20px 0;
      position: relative;
    }

    .footer .container {
      display: grid;
      grid-template-columns: 1.5fr 2fr;
      gap: 4rem;
    }

    .footer-brand {
      padding-right: 2rem;
    }

    .footer-logo {
      display: flex;
      align-items: center;
      gap: 1rem;
      text-decoration: none;
      margin-bottom: 1rem;
    }

    .logo-icon {
      max-width: 270px;
      /* height: 50px; */
      background: transparent;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    .logo-icon img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .logo-text {
      display: flex;
      flex-direction: column;
    }

    .brand-name {
      font-size: 1.5rem;
      font-weight: 700;
      color: white;
      line-height: 1.2;
    }

    .brand-slogan {
      font-size: 0.9rem;
      color: rgba(255, 255, 255, 0.7);
    }

    .brand-description {
      color: rgba(255, 255, 255, 0.7);
      margin-bottom: 1rem;
      font-size: 1rem;
      line-height: 1.6;
    }

    .social-links {
      display: flex;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .social-link {
      width: 40px;
      height: 40px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      text-decoration: none;
      transition: all 0.3s ease;
    }

    .social-link:hover {
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
      transform: translateY(-3px);
      color: white;
    }

    .contact-info {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .contact-item {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.95rem;
    }

    .contact-item i {
      color: #4f46e5;
      font-size: 1.1rem;
      margin-top: 0.2rem;
    }

    .contact-item a {
      color: inherit;
      text-decoration: none;
      transition: color 0.2s ease;
    }

    .contact-item a:hover {
      color: white;
    }

    .contact-item--address {
      align-items: flex-start;
    }

    .contact-item--address i {
      margin-top: 0.35rem;
    }

    .company-address-lines {
      line-height: 1.55;
    }

    .footer-links {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 2rem;
    }

    .footer-col h5 {
      color: white;
      font-size: 1.1rem;
      font-weight: 600;
      margin-bottom: 1.5rem;
      position: relative;
    }

    .footer-col h5::after {
      content: '';
      position: absolute;
      bottom: -0.5rem;
      left: 0;
      width: 30px;
      height: 2px;
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    }

    .footer-col ul {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .footer-col ul li {
      margin-bottom: 0.75rem;
    }

    .footer-col ul a {
      color: rgba(255, 255, 255, 0.7);
      text-decoration: none;
      transition: all 0.3s ease;
      display: inline-block;
    }

    .footer-col ul a:hover {
      color: white;
      transform: translateX(5px);
    }

    .footer-bottom {
      padding: 20px 0;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      position: relative;
      background: rgba(0, 0, 0, 0.2);
      backdrop-filter: blur(10px);
    }

    .footer-bottom-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .copyright {
      color: rgba(255, 255, 255, 0.7);
      margin: 0;
      font-size: 0.9rem;
    }

    .legal-links {
      display: flex;
      gap: 2rem;
    }

    .legal-links a {
      color: rgba(255, 255, 255, 0.7);
      text-decoration: none;
      font-size: 0.9rem;
      transition: all 0.3s ease;
      padding: 0.5rem 0;
      position: relative;
    }

    .legal-links a::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      width: 0;
      height: 2px;
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
      transition: width 0.3s ease;
    }

    .legal-links a:hover {
      color: white;
    }

    .legal-links a:hover::after {
      width: 100%;
    }

    @media (max-width: 991px) {
      .footer .container {
        grid-template-columns: 1fr;
        gap: 3rem;
      }

      .footer-brand {
        padding-right: 0;
        text-align: center;
      }

      .footer-logo {
        justify-content: center;
      }

      .social-links {
        justify-content: center;
      }

      .contact-info {
        align-items: center;
      }

      .footer-links {
        grid-template-columns: repeat(3, 1fr);
        text-align: center;
      }

      .footer-col h5::after {
        left: 50%;
        transform: translateX(-50%);
      }
    }

    @media (max-width: 576px) {
      .footer-links {
        grid-template-columns: 1fr;
      }

      .footer-bottom-content {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
      }

      .legal-links {
        flex-direction: column;
        gap: 0.5rem;
      }
    }

    /* Back to Top Button */
    .back-to-top {
      position: fixed;
      bottom: 2rem;
      right: 2rem;
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
      color: white;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      display: none;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition: all 0.3s ease;
      z-index: 1000;
      box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
    }

    .back-to-top:hover {
      transform: translateY(-3px);
      box-shadow: 0 10px 20px rgba(79, 70, 229, 0.4);
      color: white;
    }

    .back-to-top.show {
      display: flex;
    }

    /* Modal Styles */
    .modal-content{
      background-color: transparent;
      border-radius: 20px;
    }
    
    .login-modal-wrapper {
      position: relative;
      background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
      border-radius: 20px;
      overflow: hidden;
      padding: 2.5rem;
    }

    .login-modal-wrapper::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: 
        radial-gradient(circle at 20% 20%, rgba(79, 70, 229, 0.15) 0%, transparent 40%),
        radial-gradient(circle at 80% 80%, rgba(124, 58, 237, 0.15) 0%, transparent 40%);
      pointer-events: none;
    }

    .modal-close-btn {
      position: absolute;
      top: 1rem;
      right: 1rem;
      background: rgba(255, 255, 255, 0.1);
      border: none;
      color: white;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .modal-close-btn:hover {
      background: rgba(255, 255, 255, 0.2);
      transform: rotate(90deg);
    }

    .login-header {
      text-align: center;
      margin-bottom: 2rem;
    }

    .login-logo {
      width: 60px;
      height: 60px;
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
      border-radius: 15px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 1rem;
      font-size: 1.5rem;
      color: white;
    }

    .login-header h3 {
      color: white;
      font-size: 1.75rem;
      margin-bottom: 0.5rem;
    }

    .login-header p {
      color: rgba(255, 255, 255, 0.7);
      font-size: 1rem;
    }

    .form-floating > label {
      color: #6b7280;
    }

    .form-control {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: white;
      height: 55px;
    }

    .form-control:focus {
      background: rgba(255, 255, 255, 0.08);
      border-color: rgba(255, 255, 255, 0.2);
      box-shadow: none;
      color: white;
    }

    .password-field {
      position: relative;
    }

    .password-toggle {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: rgba(255, 255, 255, 0.5);
      cursor: pointer;
      z-index: 5;
    }

    .password-toggle:hover {
      color: white;
    }

    .form-options {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .form-check-label {
      color: rgba(255, 255, 255, 0.7);
    }

    .forgot-password {
      color: #4f46e5;
      text-decoration: none;
      font-size: 0.9rem;
    }

    .forgot-password:hover {
      color: #7c3aed;
    }

    .login-btn {
      width: 100%;
      height: 48px;
      background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
      border: none;
      border-radius: 10px;
      color: white;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .login-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
    }

    @media (max-width: 576px) {
      .login-modal-wrapper {
        padding: 2rem 1.5rem;
      }
    }
  </style>

  @yield('styles')
</head>

@include('partials.header')

@yield('content')

@include('partials.footer')

<!-- Additional Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

<script>
  // Initialize AOS
  AOS.init({
    duration: 1000,
    once: true,
    offset: 100
  });

  // Navbar scroll effect
  window.addEventListener('scroll', () => {
    const navbar = document.querySelector('.navbar');
    if (window.scrollY > 50) {
      navbar.classList.add('scrolled');
    } else {
      navbar.classList.remove('scrolled');
    }
  });

  // Back to top functionality
  const backToTopButton = document.getElementById('backToTop');
  
  window.addEventListener('scroll', () => {
    if (window.pageYOffset > 300) {
      backToTopButton.classList.add('show');
    } else {
      backToTopButton.classList.remove('show');
    }
  });
  
  backToTopButton.addEventListener('click', (e) => {
    e.preventDefault();
    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  });

  // Dropdown Menu Functionality
  document.addEventListener('DOMContentLoaded', function() {
    // Initialize Bootstrap dropdowns
    const dropdownToggleList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
    const dropdownList = dropdownToggleList.map(function (dropdownToggleEl) {
      return new bootstrap.Dropdown(dropdownToggleEl);
    });

    // Handle dropdown visibility
    const dropdownMenus = document.querySelectorAll('.dropdown-menu.mega-menu');
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');

    dropdownToggles.forEach(toggle => {
      const dropdown = bootstrap.Dropdown.getInstance(toggle);
      
      // Handle mouse enter to show dropdown
      toggle.addEventListener('mouseenter', function() {
        if (dropdown) {
          dropdown.show();
        }
      });

      // Handle dropdown events
      toggle.addEventListener('show.bs.dropdown', function () {
        // Close other dropdowns when opening a new one
        dropdownToggles.forEach(otherToggle => {
          if (otherToggle !== toggle) {
            const otherDropdown = bootstrap.Dropdown.getInstance(otherToggle);
            if (otherDropdown) {
              otherDropdown.hide();
            }
          }
        });
      });
    });

    // Handle dropdown menu hover to keep it open
    dropdownMenus.forEach(menu => {
      menu.addEventListener('mouseenter', function() {
        const toggle = this.previousElementSibling;
        if (toggle && toggle.classList.contains('dropdown-toggle')) {
          const dropdown = bootstrap.Dropdown.getInstance(toggle);
          if (dropdown) {
            dropdown.show();
          }
        }
      });

      menu.addEventListener('mouseleave', function() {
        const toggle = this.previousElementSibling;
        if (toggle && toggle.classList.contains('dropdown-toggle')) {
          const dropdown = bootstrap.Dropdown.getInstance(toggle);
          if (dropdown) {
            dropdown.hide();
          }
        }
      });
    });

    // Function to handle dropdown menu image previews
    function initializeDropdownPreviews(dropdownMenu) {
      const dropdownItems = dropdownMenu.querySelectorAll('.dropdown-item[data-preview]');
      const previewContainer = dropdownMenu.querySelector('.dropdown-preview');
      
      if (!previewContainer) return;
      
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
            if (defaultImage) {
              defaultImage.classList.add('active');
            }
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
    dropdownMenus.forEach(menu => {
      initializeDropdownPreviews(menu);
    });

    // Handle click outside to close dropdowns
    document.addEventListener('click', function(event) {
      const isClickInsideDropdown = event.target.closest('.dropdown');
      if (!isClickInsideDropdown) {
        dropdownToggles.forEach(toggle => {
          const dropdown = bootstrap.Dropdown.getInstance(toggle);
          if (dropdown) {
            dropdown.hide();
          }
        });
      }
    });
  });

// Global fetch error/session-timeout handler
(function() {
  if (!window.fetch) return;
  const originalFetch = window.fetch.bind(window);
  window.fetch = async function(input, init) {
    try {
      const response = await originalFetch(input, init);
      // Handle common session timeout statuses
      if (response && (response.status === 401 || response.status === 419)) {
        alert('Your session has timed out. Redirecting to the home page.');
        window.location.href = '{{ route('home') }}';
        return response;
      }
      // Handle server errors (often shown as 500 when session/CSRF mismatches happen)
      if (response && response.status >= 500) {
        alert('Something went wrong. Your session may have expired. Redirecting to the home page.');
        window.location.href = '{{ route('home') }}';
        return response;
      }
      return response;
    } catch (err) {
      // Network or CORS errors
      alert('Network error. Redirecting to the home page.');
      window.location.href = '{{ route('home') }}';
      throw err;
    }
  };
})();
</script>

@yield('scripts')

</body>
</html>
