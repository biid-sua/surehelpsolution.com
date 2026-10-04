<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sure Help Solution - Admin Dashboard</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="{{ asset('assets/css/admin-style.css') }}" rel="stylesheet">
    
    <!-- Redesigned Dashboard Styles -->
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1E3A8A 0%, #625ED0 50%, #4D8BCC 100%);
            --secondary-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #ccb1be 100%);
            --dark-bg: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
            --card-bg: rgba(255, 255, 255, 0.05);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --accent-color: #a855f7;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            background: var(--dark-bg) !important;
            color: var(--text-primary) !important;
            min-height: 100vh !important;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 20%, rgba(79, 70, 229, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(124, 58, 237, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.1) 0%, transparent 60%);
            pointer-events: none;
            z-index: -1;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            background: rgba(15, 15, 35, 0.95) !important;
            backdrop-filter: blur(20px) !important;
            border-right: 1px solid var(--card-border) !important;
            z-index: 1000 !important;
            transition: all 0.3s ease !important;
            overflow-y: auto !important;
            padding: 0 !important;
        }

        .sidebar.collapsed {
            width: 70px !important;
        }

        .sidebar .nav-link {
            display: flex !important;
            align-items: center !important;
            padding: 0.75rem 1.5rem !important;
            color: var(--text-secondary) !important;
            text-decoration: none !important;
            transition: all 0.3s ease !important;
            border-radius: 0 !important;
            margin: 0 !important;
            gap: 0.75rem !important;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.05) !important;
            color: var(--text-primary) !important;
            transform: translateX(5px) !important;
        }

        .sidebar .nav-link.active {
            background: var(--primary-gradient) !important;
            color: white !important;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3) !important;
        }

        .sidebar .nav-link i {
            font-size: 1.1rem !important;
            width: 20px !important;
            text-align: center !important;
            flex-shrink: 0 !important;
        }

        .sidebar .nav-link span {
            font-weight: 500 !important;
            white-space: nowrap !important;
            opacity: 1 !important;
            transition: opacity 0.3s ease !important;
        }

        .sidebar.collapsed .nav-link span {
            opacity: 0 !important;
            width: 0 !important;
            overflow: hidden !important;
        }

        .sidebar-header {
            height: 83px;
            padding: 0.5rem 1.5rem !important;
            border-bottom: 1px solid var(--card-border) !important;
            margin-bottom: 0 !important;
        }

        .sidebar-header.collapsed {
            padding: 1rem 0.5rem !important;
            text-align: center !important;
        }

        .logo-icon {
            max-width: 100% !important;
            /* height: 40px !important; */
            background: transparent !important;
            border-radius: 10px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin-right: 0.75rem !important;
            flex-shrink: 0 !important;
            overflow: hidden !important;
        }

        .logo-icon img {
            width: 100% !important;
            height: 100% !important;
            object-fit: contain !important;
        }

        .sidebar.collapsed .logo-icon {
            margin-right: 0 !important;
        }

        .logo-text {
            font-size: 1.25rem !important;
            font-weight: 700 !important;
            color: var(--text-primary) !important;
            opacity: 1 !important;
            transition: opacity 0.3s ease !important;
        }

        .sidebar.collapsed .logo-text {
            opacity: 0 !important;
            width: 0 !important;
            overflow: hidden !important;
        }

        /* Main Content Adjustment */
        .main-content {
            margin-left: 280px !important;
            transition: margin-left 0.3s ease !important;
        }

        .main-content.sidebar-collapsed {
            margin-left: 70px !important;
        }

        /* Responsive Sidebar */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%) !important;
                width: 280px !important;
            }

            .sidebar.show {
                transform: translateX(0) !important;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 1rem !important;
            }

            .main-content.sidebar-collapsed {
                margin-left: 0 !important;
            }
        }

        /* Sidebar Toggle Button */
        .sidebar-toggle {
            position: fixed !important;
            top: 1rem !important;
            left: 1rem !important;
            z-index: 1001 !important;
            background: var(--primary-gradient) !important;
            border: none !important;
            border-radius: 8px !important;
            padding: 0.5rem !important;
            color: white !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            display: none !important;
        }

        @media (max-width: 991.98px) {
            .sidebar-toggle {
                display: block !important;
            }
        }

        /* Top Navigation */
        .navbar {
            background: rgba(255, 255, 255, 0.05) !important;
            backdrop-filter: blur(20px) !important;
            border-bottom: 1px solid var(--card-border) !important;
            padding: 1rem 2rem !important;
            z-index: 1000 !important;
            position: relative !important;
        }

        .navbar .btn-link {
            color: var(--text-primary) !important;
            border-radius: 10px !important;
            padding: 0.5rem !important;
            transition: all 0.3s ease !important;
            position: relative !important;
            z-index: 1001 !important;
        }

        .navbar .btn-link:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            transform: scale(1.05) !important;
        }

        .dropdown-menu {
            background: rgba(15, 15, 35, 0.95) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 12px !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3) !important;
            z-index: 9999 !important;
            padding: 0.5rem 0 !important;
        }

        .dropdown-item {
            color: var(--text-primary) !important;
            padding: 0.5rem 1rem !important;
            transition: all 0.3s ease !important;
            border: none !important;
            background: none !important;
        }

        .dropdown-item:hover,
        .dropdown-item:focus {
            background: rgba(79, 70, 229, 0.2) !important;
            color: var(--text-primary) !important;
            transform: translateX(5px) !important;
        }

        .dropdown-item:active {
            background: rgba(79, 70, 229, 0.3) !important;
            color: var(--text-primary) !important;
        }

        .dropdown-divider {
            border-color: var(--card-border) !important;
            margin: 0.5rem 0 !important;
        }

        .dropdown-header {
            color: var(--text-secondary) !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            padding: 0.5rem 1rem !important;
            margin-bottom: 0.25rem !important;
        }

        /* Bootstrap dropdown toggle button styling */
        .dropdown-toggle::after {
            border-top-color: var(--text-primary) !important;
        }

        /* Dashboard Cards */
        .dashboard-card {
            background: var(--card-bg) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 20px !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1) !important;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
            overflow: hidden !important;
            position: relative !important;
            z-index: 1 !important;
        }

        .dashboard-card::before {
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

        .dashboard-card:hover::before {
            opacity: 1;
        }

        .dashboard-card:hover {
            transform: translateY(-5px) scale(1) !important;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3) !important;
        }

        /* KPI Cards */
        .kpi-card {
            background: var(--card-bg) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 16px !important;
            padding: 1.5rem !important;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
            position: relative !important;
            overflow: hidden !important;
            min-height: 140px !important;
            display: flex !important;
            align-items: center !important;
            gap: 1rem !important;
        }

        .kpi-card::after {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: var(--primary-gradient);
            border-radius: 16px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .kpi-card:hover::after {
            opacity: 1;
        }

        .kpi-card:hover {
            transform: translateY(-6px) scale(1.02) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3) !important;
        }

        .kpi-icon {
            width: 50px !important;
            height: 50px !important;
            background: var(--primary-gradient) !important;
            border-radius: 12px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 1.25rem !important;
            color: white !important;
            transition: all 0.3s ease !important;
            flex-shrink: 0 !important;
        }

        .kpi-card:hover .kpi-icon {
            transform: rotate(8deg) scale(1.1) !important;
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4) !important;
        }

        .kpi-content {
            flex-grow: 1 !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
        }

        .kpi-label {
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            color: rgba(255, 255, 255, 0.8) !important;
            margin-bottom: 0.5rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }

        .kpi-value {
            font-family: 'Poppins', sans-serif !important;
            font-size: 1.75rem !important;
            font-weight: 800 !important;
            margin-bottom: 0.5rem !important;
            color: #ffffff !important;
            line-height: 1 !important;
        }

        .kpi-change {
            display: flex !important;
            align-items: center !important;
            gap: 0.25rem !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
        }

        /* Duty schedule summary cards — WCAG AA contrast on the dark tinted backgrounds (FIX-07) */
        .schedule-stat-label { color: rgba(255, 255, 255, 0.85); font-weight: 500; }
        .schedule-stat-value { font-weight: 700; }
        .stat-green { color: #4ade80; }
        .stat-blue { color: #60a5fa; }
        .stat-purple { color: #c084fc; }

        .trend-up { color: #10b981 !important; }
        .trend-down { color: #ef4444 !important; }

        /* Chart Cards */
        .chart-card {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 20px !important;
            padding: 0.5rem !important;
            transition: all 0.3s ease !important;
            backdrop-filter: blur(10px) !important;
            position: relative !important;
            overflow: visible !important;
            height: auto !important;
            min-height: auto !important;
            transform: scale(1) !important;
            opacity: 1 !important;
        }

        .chart-card:hover {
            transform: translateY(-5px) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2) !important;
        }

        .chart-title {
            font-family: 'Poppins', sans-serif !important;
            font-weight: 600 !important;
            color: var(--text-primary) !important;
            margin-bottom: 1rem !important;
            font-size: 1.25rem !important;
        }

        .chart-subtitle {
            color: var(--text-secondary) !important;
            font-size: 0.9rem !important;
            margin-bottom: 0 !important;
        }

        /* Form Styling */
        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 12px !important;
            color: var(--text-primary) !important;
            padding: 0.75rem 0.5rem !important;
            transition: all 0.3s ease !important;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(255, 255, 255, 0.1) !important;
            border-color: var(--accent-color) !important;
            box-shadow: 0 0 0 0.2rem rgba(168, 85, 247, 0.25) !important;
            color: var(--text-primary) !important;
        }

        /* Fix dropdown options background and text color */
        .form-select option {
            background: rgba(15, 15, 35, 0.95) !important;
            color: var(--text-primary) !important;
            padding: 0.5rem !important;
        }

        .form-select option:hover,
        .form-select option:focus,
        .form-select option:checked {
            background: rgba(168, 85, 247, 0.3) !important;
            color: white !important;
        }

        /* Form validation styling */
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }

        .form-control.is-valid, .form-select.is-valid {
            border-color: #198754 !important;
            box-shadow: 0 0 0 0.2rem rgba(25, 135, 84, 0.25) !important;
        }

        /* Readonly field styling */
        .form-control[readonly] {
            background: rgba(255, 255, 255, 0.02) !important;
            color: var(--text-secondary) !important;
            cursor: not-allowed;
            opacity: 0.8;
        }


        .form-label {
            color: var(--text-primary) !important;
            font-weight: 600 !important;
            margin-bottom: 0.5rem !important;
        }

        /* Button Styling */
        .btn-primary {
            background: var(--secondary-gradient) !important;
            border: none !important;
            border-radius: 12px !important;
            padding: 0.75rem 1.5rem !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
            position: relative !important;
            overflow: hidden !important;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-3px) !important;
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4) !important;
        }

        .btn-outline-secondary {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
            border-radius: 12px !important;
            padding: 0.75rem 1.5rem !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
        }

        .btn-outline-secondary:hover {
            background: rgba(255, 255, 255, 0.15) !important;
            border-color: var(--accent-color) !important;
            color: var(--text-primary) !important;
            transform: translateY(-2px) !important;
        }

        /* Responsive Design */
        @media (max-width: 991.98px) {
            .sidebar {
                transform: translateX(-100%) !important;
                width: 280px !important;
            }

            .sidebar.show {
                transform: translateX(0) !important;
            }

            .main-content {
                margin-left: 0 !important;
            }

            .kpi-value {
                font-size: 1.5rem !important;
            }
        }

        @media (max-width: 768px) {
            .navbar {
                padding: 1rem !important;
            }

            .kpi-card {
                padding: 1.25rem !important;
                min-height: 120px !important;
                gap: 0.75rem !important;
            }

            .kpi-value {
                font-size: 1.5rem !important;
            }

            .kpi-icon {
                width: 45px !important;
                height: 45px !important;
                font-size: 1.1rem !important;
            }

            .chart-card {
                padding: 1.5rem !important;
            }
        }

        @media (max-width: 576px) {
            .kpi-card {
                padding: 1rem !important;
                min-height: 110px !important;
                gap: 0.5rem !important;
            }

            .kpi-value {
                font-size: 1.25rem !important;
            }

            .kpi-icon {
                width: 40px !important;
                height: 40px !important;
                font-size: 1rem !important;
            }

            .kpi-label {
                font-size: 0.7rem !important;
            }

            .chart-title {
                font-size: 1.1rem !important;
            }
        }

        /* Floating Elements Animation */
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

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        /* Live Indicator */
        .live-indicator {
            display: inline-flex !important;
            align-items: center !important;
            background: rgba(16, 185, 129, 0.2) !important;
            padding: 0.25rem 0.75rem !important;
            border-radius: 15px !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            margin-left: 1rem !important;
            color: var(--success-color) !important;
        }

        .pulse-dot {
            width: 8px !important;
            height: 8px !important;
            background: var(--success-color) !important;
            border-radius: 50% !important;
            margin-right: 0.5rem !important;
            animation: pulse 2s infinite !important;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
        }
        .btn-group label{
            margin-right: 5px;
        }
        th{
            background: transparent!important;
        }
        /* Calendar Container - No Scrolling */
        #teamsCalendar {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-view-harness {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-timegrid-body {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-scroller {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }

        .fc-scroller-liquid {
            height: auto !important;
            max-height: none !important;
            overflow: visible !important;
        }
    </style>
</head>
<body>
    <!-- Floating Background Elements -->
    <div class="floating-elements">
        <div class="floating-circle"></div>
        <div class="floating-circle"></div>
        <div class="floating-circle"></div>
    </div>

    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="d-flex align-items-center">
                    <div class="logo-icon me-3">
                        <img src="{{ asset('assets/img/logo.png') }}" alt="SHS Logo" style="width: 100%; height: 100%; object-fit: contain;">
                    </div>
                    <!-- <div>
                        <h5 class="mb-0 text-white">Sure Help</h5>
                        <small class="text-white-50">Admin Dashboard</small>
                    </div> -->
                </div>
            </div>
            <ul class="nav flex-column p-3">
                <li class="nav-item mb-2">
                    <a href="#performance" class="nav-link active">
                        <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item mb-2">
                    <a href="#duty-schedule" class="nav-link">
                        <i class="fas fa-calendar"></i><span>My Duty Schedule</span>
                    </a>
                </li>
                <li class="nav-item mb-2">
                    <a href="#call-log-entry" class="nav-link">
                        <i class="fas fa-phone"></i><span>Call Log Entry</span>
                    </a>
                </li>
                @if(Auth::user()->isAdmin())
                <li class="nav-item mb-2">
                    <a href="{{ route('duty-schedules.index') }}" class="nav-link">
                        <i class="fas fa-calendar-alt"></i><span>Manage Schedules</span>
                    </a>
                </li>
                @endif
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation -->
            <nav class="navbar">
                <div class="container-fluid">
                    <button class="btn btn-link sidebar-toggle" id="sidebarToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center ms-2">
                        <i class="fa-solid fa-headset me-2"></i>
                        <span class="fw-semibold">Agent Dashboard</span>
                    </div>
                    <div class="d-flex align-items-center ms-auto">
                        <div class="dropdown me-3">
                            <button class="btn btn-link position-relative" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-label="Notifications">
                                <i class="fas fa-bell"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <h6 class="dropdown-header">Notifications</h6>
                                <span class="dropdown-item-text text-muted small">In-app notifications are coming soon.</span>
                            </div>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-link d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown">
                                <img src="../assets/img/agent.png" alt="Admin" class="rounded-circle me-2" style="width: 32px; height: 32px;">
                                <span>{{ explode(' ', Auth::user()->name)[0] }}</span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item" href="{{ route('auth.logout') }}"><i class="fas fa-sign-out-alt me-2"></i>Logout</a>
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Dashboard Content -->
            <div class="container-fluid p-4">
                <!-- Dashboard Header -->
                <div class="row mb-4" id="performance" data-aos="fade-down" data-aos-duration="800">
                    <div class="col-12">
                        <div class="dashboard-card">
                            <div class="card-body p-4">
                                <div class="d-flex flex-wrap align-items-center justify-content-between">
                                    <div class="header-content">
                                        <h2 class="mb-2">
                                            <i class="fas fa-chart-bar me-3"></i>Call Center Performance
                                            <span class="live-indicator">
                                                <span class="pulse-dot"></span>LIVE
                                            </span>
                                        </h2>
                                        <p class="mb-0 text-secondary">Real-time insights and analytics for your call center operations</p>
                                    </div>
                                    <div class="d-flex flex-wrap gap-3 align-items-center">
                                        <div class="period-filters">
                                            <div class="btn-group" role="group">
                                                <input type="radio" class="btn-check" name="periodFilter" id="todayFilter" value="today" checked>
                                                <label class="btn btn-outline-secondary" for="todayFilter">
                                                    <i class="fas fa-calendar-day me-1"></i>Today
                                                </label>
                                                
                                                <input type="radio" class="btn-check" name="periodFilter" id="weeklyFilter" value="weekly">
                                                <label class="btn btn-outline-secondary" for="weeklyFilter">
                                                    <i class="fas fa-calendar-week me-1"></i>Week
                                                </label>
                                                
                                                <input type="radio" class="btn-check" name="periodFilter" id="monthlyFilter" value="monthly">
                                                <label class="btn btn-outline-secondary" for="monthlyFilter">
                                                    <i class="fas fa-calendar-alt me-1"></i>Month
                                                </label>
                                            </div>
                                        </div>
                                        <a class="btn btn-primary" id="exportBtn" href="{{ route('admin.call-logs.export') }}">
                                            <i class="fas fa-download me-2"></i>Export My Calls (CSV)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- KPI Cards Row -->
                <div class="row g-4 mb-5">
                    <!-- Total Calls -->
                    <div class="col-lg col-md-4 col-sm-6 col-6" data-aos="fade-up" data-aos-delay="100">
                        <div class="kpi-card">
                            <div class="kpi-icon">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="kpi-content">
                                <h6 class="kpi-label">Total Calls</h6>
                                <h3 class="kpi-value" id="totalCallsValue" data-target="{{ $kpiData['total_calls']['value'] }}">{{ $kpiData['total_calls']['value'] }}</h3>
                                <div class="kpi-change {{ $kpiData['total_calls']['change'] >= 0 ? 'trend-up' : 'trend-down' }}">
                                    <i class="fas fa-arrow-{{ $kpiData['total_calls']['change'] >= 0 ? 'up' : 'down' }} me-1"></i>
                                    <span id="totalCallsChange">{{ $kpiData['total_calls']['change'] >= 0 ? '+' : '' }}{{ $kpiData['total_calls']['change'] }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Service Requests -->
                    <div class="col-lg col-md-4 col-sm-6 col-6" data-aos="fade-up" data-aos-delay="200">
                        <div class="kpi-card">
                            <div class="kpi-icon">
                                <i class="fas fa-tools"></i>
                            </div>
                            <div class="kpi-content">
                                <h6 class="kpi-label">Service Requests</h6>
                                <h3 class="kpi-value" id="serviceRequestsValue" data-target="{{ $kpiData['service_requests']['value'] }}">{{ $kpiData['service_requests']['value'] }}</h3>
                                <div class="kpi-change {{ $kpiData['service_requests']['change'] >= 0 ? 'trend-up' : 'trend-down' }}">
                                    <i class="fas fa-arrow-{{ $kpiData['service_requests']['change'] >= 0 ? 'up' : 'down' }} me-1"></i>
                                    <span id="serviceRequestsChange">{{ $kpiData['service_requests']['change'] >= 0 ? '+' : '' }}{{ $kpiData['service_requests']['change'] }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conversion Rate -->
                    <div class="col-lg col-md-4 col-sm-6 col-6" data-aos="fade-up" data-aos-delay="300">
                        <div class="kpi-card">
                            <div class="kpi-icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <div class="kpi-content">
                                <h6 class="kpi-label">Conversion Rate</h6>
                                <h3 class="kpi-value" id="conversionRateValue" data-target="{{ $kpiData['conversion_rate']['value'] }}">{{ $kpiData['conversion_rate']['value'] }}%</h3>
                                <div class="kpi-change {{ $kpiData['conversion_rate']['change'] >= 0 ? 'trend-up' : 'trend-down' }}">
                                    <i class="fas fa-arrow-{{ $kpiData['conversion_rate']['change'] >= 0 ? 'up' : 'down' }} me-1"></i>
                                    <span id="conversionRateChange">{{ $kpiData['conversion_rate']['change'] >= 0 ? '+' : '' }}{{ $kpiData['conversion_rate']['change'] }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Total Schedules -->
                    <div class="col-lg col-md-4 col-sm-6 col-6" data-aos="fade-up" data-aos-delay="400">
                        <div class="kpi-card">
                            <div class="kpi-icon">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="kpi-content">
                                <h6 class="kpi-label">Total Schedules</h6>
                                <h3 class="kpi-value" id="totalSchedulesValue" data-target="{{ $kpiData['total_schedules']['value'] }}">{{ $kpiData['total_schedules']['value'] }}</h3>
                                <div class="kpi-change {{ $kpiData['total_schedules']['change'] >= 0 ? 'trend-up' : 'trend-down' }}">
                                    <i class="fas fa-arrow-{{ $kpiData['total_schedules']['change'] >= 0 ? 'up' : 'down' }} me-1"></i>
                                    <span id="totalSchedulesChange">{{ $kpiData['total_schedules']['change'] >= 0 ? '+' : '' }}{{ $kpiData['total_schedules']['change'] }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Request Callback -->
                    <div class="col-lg col-md-4 col-sm-6 col-6" data-aos="fade-up" data-aos-delay="500">
                        <div class="kpi-card">
                            <div class="kpi-icon">
                                <i class="fas fa-phone-slash"></i>
                            </div>
                            <div class="kpi-content">
                                <h6 class="kpi-label">Request Callback</h6>
                                <h3 class="kpi-value" id="requestCallbackValue" data-target="{{ $kpiData['request_callback']['value'] }}">{{ $kpiData['request_callback']['value'] }}</h3>
                                <div class="kpi-change {{ $kpiData['request_callback']['change'] >= 0 ? 'trend-up' : 'trend-down' }}">
                                    <i class="fas fa-arrow-{{ $kpiData['request_callback']['change'] >= 0 ? 'up' : 'down' }} me-1"></i>
                                    <span id="requestCallbackChange">{{ $kpiData['request_callback']['change'] >= 0 ? '+' : '' }}{{ $kpiData['request_callback']['change'] }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Performance Analytics Row -->
                <div class="row g-4 mb-5">
                    <!-- Call Volume Chart -->
                    <div class="col-12">
                        <div class="chart-card">
                            <div class="chart-header mb-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="chart-title">
                                            <i class="fas fa-chart-line me-2"></i><span id="chartTitle">Daily Call Volume (Last 7 Days)</span>
                                        </h5>
                                        <p class="chart-subtitle" id="chartSubtitle">Call distribution over the past week</p>
                                    </div>
                                    <div class="chart-controls">
                                        <div class="btn-group" role="group">
                                            <input type="radio" class="btn-check" name="chartPeriodFilter" id="chartTodayFilter" value="today" checked>
                                            <label class="btn btn-outline-secondary btn-sm" for="chartTodayFilter">
                                                <i class="fas fa-calendar-day me-1"></i>Today
                                            </label>
                                            
                                            <input type="radio" class="btn-check" name="chartPeriodFilter" id="chartWeeklyFilter" value="weekly">
                                            <label class="btn btn-outline-secondary btn-sm" for="chartWeeklyFilter">
                                                <i class="fas fa-calendar-week me-1"></i>Week
                                            </label>
                                            
                                            <input type="radio" class="btn-check" name="chartPeriodFilter" id="chartMonthlyFilter" value="monthly">
                                            <label class="btn btn-outline-secondary btn-sm" for="chartMonthlyFilter">
                                                <i class="fas fa-calendar-alt me-1"></i>Month
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="chart-body">
                                <canvas id="dailyCallsChart" height="100"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Main Content Row -->
                <div class="row g-4">
                    <!-- Left Column - Calendar -->
                    <div class="col-lg-8">
                        <!-- Calendar Card -->
                        <div class="chart-card" id="duty-schedule" data-aos="fade-right" data-aos-delay="600">
                            <div class="chart-header mb-4">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="chart-title">
                                            <i class="fas fa-calendar-alt me-2"></i>My Duty Schedule
                                        </h5>
                                        <p class="chart-subtitle">Welcome back, <span id="currentAgentName">{{ Auth::user()->name }}</span></p>
                                    </div>
                                    <div class="calendar-controls">
                                        <button class="btn btn-outline-secondary btn-sm" id="todayBtn" style="width: 185px;">
                                            <i class="fas fa-calendar-day me-1"></i>Today
                                        </button>
                                        <div class="btn-group" role="group">
                                            <button class="btn btn-outline-secondary btn-sm" id="prevBtn">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                            <button class="btn btn-outline-secondary btn-sm" id="nextBtn">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                        <select class="form-select form-select-sm" id="viewSelector">
                                            <option value="timeGridWeek">Week</option>
                                            <option value="timeGridDay">Day</option>
                                            <option value="dayGridMonth">Month</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="chart-body">
                                <!-- Weekly Summary -->
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center p-2" style="background: rgba(34, 197, 94, 0.1); border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.2);">
                                            <div class="me-3">
                                                <i class="fas fa-clock stat-green" style="font-size: 1.5rem;"></i>
                                            </div>
                                            <div>
                                                <div class="schedule-stat-value stat-green" id="weeklyHours">0 hrs</div>
                                                <small class="schedule-stat-label">This Week</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center p-2" style="background: rgba(59, 130, 246, 0.1); border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.2);">
                                            <div class="me-3">
                                                <i class="fas fa-calendar-day stat-blue" style="font-size: 1.5rem;"></i>
                                            </div>
                                            <div>
                                                <div class="schedule-stat-value stat-blue" id="todayHours">0 hrs</div>
                                                <small class="schedule-stat-label">Today</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center p-2" style="background: rgba(168, 85, 247, 0.1); border-radius: 8px; border: 1px solid rgba(168, 85, 247, 0.2);">
                                            <div class="me-3">
                                                <i class="fas fa-calendar-check stat-purple" style="font-size: 1.5rem;"></i>
                                            </div>
                                            <div>
                                                <div class="schedule-stat-value stat-purple" id="scheduledDays">0</div>
                                                <small class="schedule-stat-label">Scheduled Days</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="teamsCalendar" style="height: auto; max-height: none; overflow: visible;"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column - Call Log Form -->
                    <div class="col-lg-4">
                        <!-- Call Logging Form -->
                        <div class="chart-card" id="call-log-entry" data-aos="fade-left" data-aos-delay="700">
                            <div class="chart-header mb-4">
                                <h5 class="chart-title">
                                    <i class="fas fa-phone me-2"></i>Call Log Entry
                                </h5>
                                <p class="chart-subtitle">Record new call details</p>
                            </div>
                            <div class="chart-body">
                                <form id="callLogForm" class="call-log-form">
                                    <div class="row g-3">
                                        <!-- Call ID -->
                                        <div class="col-md-12">
                                            <label class="form-label">Call ID</label>
                                            <input type="text" class="form-control" id="callId" readonly value="" placeholder="Assigned automatically when saved">
                                        </div>
                                        
                                        <!-- Client Selection -->
                                        <div class="col-md-12">
                                            <label class="form-label">Select Client</label>
                                            <div class="client-selector">
                                                <div class="search-container">
                                                    <input type="text" class="form-control" id="clientSearch" placeholder="Search clients by name, email, or ID..." autocomplete="off">
                                                    <i class="fas fa-search search-icon"></i>
                                                </div>
                                                <div class="client-dropdown" id="clientDropdown" style="display: none;">
                                                    <div class="dropdown-content" id="clientList">
                                                        <!-- Client options will be populated here -->
                                                    </div>
                                                </div>
                                                <input type="hidden" id="selectedClientId" name="client_id">
                                                <div class="selected-client" id="selectedClient" style="display: none;">
                                                    <div class="selected-client-info">
                                                        <span class="client-name" id="selectedClientName"></span>
                                                        <span class="client-email" id="selectedClientEmail"></span>
                                                    </div>
                                                    <button type="button" class="btn-remove-client" id="removeClient">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Date -->
                                        <div class="col-md-6">
                                            <label class="form-label">Date</label>
                                            <input type="date" class="form-control" id="callDate">
                                        </div>
                                        
                                        <!-- Time -->
                                        <div class="col-md-6">
                                            <label class="form-label">Time</label>
                                            <input type="time" class="form-control" id="callTime">
                                        </div>
                                        
                                        <!-- Caller Name -->
                                        <div class="col-12">
                                            <label class="form-label">Caller Name</label>
                                            <input type="text" class="form-control" id="callerName" placeholder="Enter caller's full name">
                                        </div>
                                        
                                        <!-- Caller Phone -->
                                        <div class="col-md-6">
                                            <label class="form-label">Caller Phone</label>
                                            <input type="tel" class="form-control" id="callerPhone" placeholder="+1 (555) 123-4567">
                                        </div>
                                        
                                        <!-- Caller Email -->
                                        <div class="col-md-6">
                                            <label class="form-label">Caller Email</label>
                                            <input type="email" class="form-control" id="callerEmail" placeholder="caller@email.com">
                                        </div>
                                        
                                        <!-- Reason for Call -->
                                        <div class="col-12">
                                            <label class="form-label">Reason for Call</label>
                                            <select class="form-select" id="reasonForCall">
                                                <option value="">Select reason...</option>
                                                @foreach (\App\Models\CallLog::REASONS as $reasonKey => $reasonLabel)
                                                    <option value="{{ $reasonKey }}">{{ $reasonLabel }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        
                                        <!-- Call Outcome -->
                                        <div class="col-12">
                                            <label class="form-label">Call Outcome</label>
                                            <select class="form-select" id="callOutcome">
                                                <option value="">Select outcome...</option>
                                                {{-- Platform outcomes; replaced by the selected business's own list (spec §14). --}}
                                                @foreach (app(\App\Services\Calls\CallOutcomes::class)->active(null) as $outcome)
                                                    <option value="{{ $outcome['key'] }}" data-category="{{ $outcome['category']->value }}">{{ $outcome['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        
                                        <!-- Escalation detail: shown for outcomes that escalate to the business (spec §25) -->
                                        <div class="col-12" id="escalationFields" style="display: none;">
                                            <label class="form-label" for="escalationType">What kind of escalation?</label>
                                            <select class="form-select" id="escalationType">
                                                @foreach (\App\Enums\EscalationType::cases() as $escalationType)
                                                    @continue($escalationType === \App\Enums\EscalationType::AiUncertainty)
                                                    <option value="{{ $escalationType->value }}">{{ $escalationType->label() }}</option>
                                                @endforeach
                                            </select>
                                            <small class="text-muted">Urgent issues and emergencies alert the business immediately.</small>
                                        </div>

                                        <!-- Agent Name -->
                                        <div class="col-12">
                                            <label class="form-label">Agent Name</label>
                                            <input type="text" class="form-control" id="agentName" value="{{ explode(' ', Auth::user()->name)[0] }}" readonly>
                                        </div>
                                        
                                        <!-- Status -->
                                        <div class="col-md-6">
                                            <label class="form-label">Status</label>
                                            <select class="form-select" id="callStatus">
                                                <option value="new">New</option>
                                                <option value="service-requested">Service Requested</option>
                                                <option value="information-provided">Information Provided Only</option>
                                                <option value="cancelled">Cancelled by Caller</option>
                                                <option value="spam">Spam / Wrong Number</option>
                                                <option value="completed">Completed</option>
                                            </select>
                                        </div>

                                        <!-- Service Request -->
                                        <div class="col-md-6">
                                            <label class="form-label">Service Request</label>
                                            <select class="form-select" id="serviceRequest">
                                                <option value="no">No</option>
                                                <option value="yes">Yes</option>
                                            </select>
                                        </div>
                                        
                                        <!-- Scheduled Service Date -->
                                        <div class="col-md-6" id="serviceDateGroup" style="display: none;">
                                            <label class="form-label">Scheduled Service Date</label>
                                            <input type="date" class="form-control" id="serviceDate">
                                        </div>
                                        
                                        <!-- Service Window -->
                                        <div class="col-md-6" id="serviceWindowGroup" style="display: none;">
                                            <label class="form-label">Scheduled Service Time</label>
                                            <input type="time" class="form-control" id="serviceWindow">
                                        </div>
                                        
                                        <!-- Service Location -->
                                        <div class="col-12" id="serviceLocationGroup" style="display: none;">
                                            <label class="form-label">Service Location</label>
                                            <textarea class="form-control" id="serviceLocation" rows="2" placeholder="Enter complete service address"></textarea>
                                        </div>
                                        
                                        <!-- Notes -->
                                        <div class="col-12">
                                            <label class="form-label">Notes</label>
                                            <textarea class="form-control" id="callNotes" rows="3" placeholder="Additional notes about the call..."></textarea>
                                        </div>
                                        
                                        <!-- Form Actions -->
                                        <div class="col-12">
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-primary flex-fill" style="background: linear-gradient(135deg, #1E3A8A 0%, #625ED0 50%, #4D8BCC 100%)!important;">
                                                    <i class="fas fa-save me-2"></i>Save Call Log
                                                </button>
                                                <button type="button" class="btn btn-outline-secondary" id="clearForm">
                                                    <i class="fas fa-times me-2"></i>Clear
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    <!-- Footer -->
    <footer class="dashboard-footer">
        <div class="container-fluid">
            <div class="footer-content">
                <div class="footer-links">
                    <a href="../terms-of-use.php">Terms of Use</a>
                    <a href="../privacy-policy.php">Privacy Policy</a>
                    <a href="../data-processing-addendum.php">DPA</a>
                    <a href="../business-associate-agreement.php">BAA</a>
                    <a href="../data-security.php">Security</a>
                    <a href="../cookie-notice.php">Cookie Notice</a>
                    <a href="../faq.php">FAQ</a>
                </div>
                <p class="copyright">&copy; {{ now()->year }} SureHelp Solution. All rights reserved.</p>
            </div>
        </div>
    </footer>

        </div>
    </div>
    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <!-- AOS Animation Library -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <!-- FullCalendar.js for Teams-style calendar -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <!-- Custom JS -->
    <script src="{{ asset('assets/js/admin-script.js') }}"></script>
    
    <!-- Enhanced Dashboard JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize AOS animations
            if (typeof AOS !== 'undefined') {
                AOS.init({
                    duration: 800,
                    easing: 'ease-out-cubic',
                    once: true,
                    offset: 100
                });
            }
            
            // Enhanced counter animation with smooth easing
            function animateCounters() {
                const counters = document.querySelectorAll('.kpi-value[data-target]');
                counters.forEach(counter => {
                    const target = parseInt(counter.getAttribute('data-target'));
                    const duration = 2500;
                    const startTime = performance.now();
                    
                    function updateCounter(currentTime) {
                        const elapsed = currentTime - startTime;
                        const progress = Math.min(elapsed / duration, 1);
                        
                        // Easing function for smooth animation
                        const easeOutQuart = 1 - Math.pow(1 - progress, 4);
                        const current = Math.floor(target * easeOutQuart);
                        
                        if (counter.id.includes('Rate')) {
                            counter.textContent = current + '%';
                        } else if (counter.id.includes('Time')) {
                            counter.textContent = (current / 10).toFixed(1) + 'm';
                        } else {
                            counter.textContent = current.toLocaleString();
                        }
                        
                        if (progress < 1) {
                            requestAnimationFrame(updateCounter);
                        }
                    }
                    
                    requestAnimationFrame(updateCounter);
                });
            }
            
            // Function to refresh KPI data
            async function refreshKpiData() {
                try {
                    const response = await fetch('{{ route("admin.agent-dashboard") }}');
                    const text = await response.text();
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    
                    // Extract new KPI values from the refreshed page
                    const newKpiData = {
                        total_calls: {
                            value: parseInt(doc.querySelector('#totalCallsValue').getAttribute('data-target')),
                            change: doc.querySelector('#totalCallsChange').textContent
                        },
                        service_requests: {
                            value: parseInt(doc.querySelector('#serviceRequestsValue').getAttribute('data-target')),
                            change: doc.querySelector('#serviceRequestsChange').textContent
                        },
                        conversion_rate: {
                            value: parseInt(doc.querySelector('#conversionRateValue').getAttribute('data-target')),
                            change: doc.querySelector('#conversionRateChange').textContent
                        },
                        total_schedules: {
                            value: parseInt(doc.querySelector('#totalSchedulesValue').getAttribute('data-target')),
                            change: doc.querySelector('#totalSchedulesChange').textContent
                        },
                        request_callback: {
                            value: parseInt(doc.querySelector('#requestCallbackValue').getAttribute('data-target')),
                            change: doc.querySelector('#requestCallbackChange').textContent
                        }
                    };
                    
                    // Update KPI cards with new data
                    updateKpiCards(newKpiData);
                    
                } catch (error) {
                    console.error('Failed to refresh KPI data:', error);
                }
            }

            // Function to update KPI cards with new data
            function updateKpiCards(kpiData) {
                const cards = [
                    { id: 'totalCallsValue', value: kpiData.total_calls.value },
                    { id: 'serviceRequestsValue', value: kpiData.service_requests.value },
                    { id: 'conversionRateValue', value: kpiData.conversion_rate.value, isPercentage: true },
                    { id: 'totalSchedulesValue', value: kpiData.total_schedules.value },
                    { id: 'requestCallbackValue', value: kpiData.request_callback.value }
                ];
                
                cards.forEach(card => {
                    const element = document.getElementById(card.id);
                                if (element) {
                        const currentValue = parseInt(element.textContent.replace(/[^\d]/g, ''));
                        const newValue = card.value;
                        
                        if (currentValue !== newValue) {
                            // Animate to new value
                            animateValue(element, currentValue, newValue, card.isPercentage);
                        }
                    }
                });
            }

            // Function to animate value changes
            function animateValue(element, start, end, isPercentage = false) {
                                    const duration = 1000;
                                    const startTime = performance.now();
                                    
                                    function updateValue(currentTime) {
                                        const elapsed = currentTime - startTime;
                                        const progress = Math.min(elapsed / duration, 1);
                    
                    const easeProgress = 1 - Math.pow(1 - progress, 3);
                    const current = Math.round(start + (end - start) * easeProgress);
                    
                    element.textContent = isPercentage ? current + '%' : current;
                    element.setAttribute('data-target', end);
                                        
                                        if (progress < 1) {
                                            requestAnimationFrame(updateValue);
                                        }
                                    }
                                    
                                    requestAnimationFrame(updateValue);
            }

            // Initialize dashboard with enhanced animations
            setTimeout(() => {
                animateCounters();
                initializeCharts('today'); // Initialize with today as default
            }, 1000);

            // Global chart variable
            let callsChart = null;

            // Initialize performance charts
            function initializeCharts(period = 'today') {
                // Destroy existing chart if it exists
                if (callsChart) {
                    callsChart.destroy();
                }

                // Get chart data based on period
                const chartData = getChartDataForPeriod(period);
                
                // Update chart title and subtitle
                updateChartTitle(period);

                // Daily Calls Chart
                const dailyCallsCtx = document.getElementById('dailyCallsChart').getContext('2d');
                callsChart = new Chart(dailyCallsCtx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: 'Calls',
                            data: chartData.data,
                            borderColor: '#4f46e5',
                            backgroundColor: 'rgba(79, 70, 229, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#4f46e5',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: 'rgba(255, 255, 255, 0.7)'
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(255, 255, 255, 0.1)'
                                },
                                ticks: {
                                    color: 'rgba(255, 255, 255, 0.7)'
                                }
                            }
                        }
                    }
                });
            }

            // Function to get chart data based on period
            // Real chart data from CallStatsService — same source as the KPI cards (FIX-01)
            let performanceData = @json($performanceData);

            function getChartDataForPeriod(period) {
                switch(period) {
                    case 'today':
                        return {
                            labels: performanceData.hourly_calls.map(row => row.hour),
                            data: performanceData.hourly_calls.map(row => row.calls)
                        };
                    case 'monthly':
                        return {
                            labels: performanceData.weekly_calls.map(row => row.week),
                            data: performanceData.weekly_calls.map(row => row.calls)
                        };
                    case 'weekly':
                    default:
                        return {
                            labels: performanceData.daily_calls.map(row => row.date),
                            data: performanceData.daily_calls.map(row => row.calls)
                        };
                }
            }

            // Function to update chart title and subtitle
            function updateChartTitle(period) {
                const titleElement = document.getElementById('chartTitle');
                const subtitleElement = document.getElementById('chartSubtitle');
                
                switch(period) {
                    case 'today':
                        titleElement.textContent = 'Hourly Call Volume (Today)';
                        subtitleElement.textContent = 'Call distribution throughout the day';
                        break;
                    case 'weekly':
                        titleElement.textContent = 'Daily Call Volume (Last 7 Days)';
                        subtitleElement.textContent = 'Call distribution over the past week';
                        break;
                    case 'monthly':
                        titleElement.textContent = 'Weekly Call Volume (This Month)';
                        subtitleElement.textContent = 'Call distribution over the past month';
                        break;
                    default:
                        titleElement.textContent = 'Daily Call Volume (Last 7 Days)';
                        subtitleElement.textContent = 'Call distribution over the past week';
                }
            }

            // Function to refresh performance charts
            async function refreshPerformanceCharts() {
                try {
                    const period = document.querySelector('input[name="chartPeriodFilter"]:checked')?.value || 'today';
                    const response = await fetch(`{{ route('admin.kpi-data', ['period' => '__PERIOD__']) }}`.replace('__PERIOD__', period));
                    const result = await response.json();

                    if (result.success) {
                        performanceData = result.performance_data;
                        initializeCharts(period);
                    }
                } catch (error) {
                    console.error('Failed to refresh performance charts:', error);
                }
            }
            
            // Period filter functionality with dynamic data
            const periodFilters = document.querySelectorAll('input[name="periodFilter"]');
            periodFilters.forEach(filter => {
                filter.addEventListener('change', async function() {
                    if (this.checked) {
                        try {
                            // Fetch dynamic data from the server
                            const response = await fetch(`{{ route('admin.kpi-data', ['period' => '__PERIOD__']) }}`.replace('__PERIOD__', this.value));
                            const result = await response.json();
                            
                            if (result.success) {
                                const kpiData = result.kpi_data;
                                performanceData = result.performance_data;

                                // Update KPI cards with new data
                                updateKpiCard('totalCallsValue', kpiData.total_calls.value, '');
                                updateKpiCard('serviceRequestsValue', kpiData.service_requests.value, '');
                                updateKpiCard('conversionRateValue', kpiData.conversion_rate.value, '%');
                                updateKpiCard('totalSchedulesValue', kpiData.total_schedules.value, '');
                                updateKpiCard('requestCallbackValue', kpiData.request_callback.value, '');
                                
                                // Update trend indicators
                                updateTrendIndicator('totalCallsChange', kpiData.total_calls.change);
                                updateTrendIndicator('serviceRequestsChange', kpiData.service_requests.change);
                                updateTrendIndicator('conversionRateChange', kpiData.conversion_rate.change);
                                updateTrendIndicator('totalSchedulesChange', kpiData.total_schedules.change);
                                updateTrendIndicator('requestCallbackChange', kpiData.request_callback.change);
                                
                                // Update chart with new period
                                updateChartForPeriod(this.value);
                            }
                        } catch (error) {
                            console.error('Failed to fetch KPI data:', error);
                            showNotification('Failed to load performance data', 'error');
                        }
                    }
                });
            });

            // Chart period filter functionality
            const chartPeriodFilters = document.querySelectorAll('input[name="chartPeriodFilter"]');
            chartPeriodFilters.forEach(filter => {
                filter.addEventListener('change', function() {
                    if (this.checked) {
                        updateChartForPeriod(this.value);
                        
                        // Sync with main period filter
                        const mainFilter = document.querySelector(`input[name="periodFilter"][value="${this.value}"]`);
                        if (mainFilter) {
                            mainFilter.checked = true;
                        }
                    }
                });
            });

            // Function to update chart for specific period
            function updateChartForPeriod(period) {
                // Update chart data and title
                initializeCharts(period);
                
                // Add smooth transition effect
                const chartContainer = document.querySelector('#dailyCallsChart').closest('.chart-card');
                chartContainer.style.transform = 'scale(0.98)';
                chartContainer.style.opacity = '0.8';
                
                setTimeout(() => {
                    chartContainer.style.transform = 'scale(1)';
                    chartContainer.style.opacity = '1';
                }, 200);
            }

            // Function to update individual KPI cards
            function updateKpiCard(elementId, newValue, suffix = '') {
                const element = document.getElementById(elementId);
                if (element) {
                    const current = parseInt(element.textContent.replace(/[^\d]/g, ''));
                    animateValue(element, current, newValue, suffix === '%');
                    element.setAttribute('data-target', newValue);
                }
            }

            // Function to update trend indicators
            function updateTrendIndicator(elementId, change) {
                const element = document.getElementById(elementId);
                if (element) {
                    const isPositive = change >= 0;
                    const icon = element.querySelector('i');
                    const span = element.querySelector('span');
                    
                    // Update icon
                    icon.className = isPositive ? 'fas fa-arrow-up me-1' : 'fas fa-arrow-down me-1';
                    
                    // Update text
                    span.textContent = (isPositive ? '+' : '') + change + '%';
                    
                    // Update parent class
                    const parent = element.parentElement;
                    parent.className = `kpi-change ${isPositive ? 'trend-up' : 'trend-down'}`;
                }
            }
            
            // Enhanced export functionality
            // Enhanced hover effects for KPI cards
            const kpiCards = document.querySelectorAll('.kpi-card');
            kpiCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });
            
            // Call Log Form functionality with enhanced UX
            const callLogForm = document.getElementById('callLogForm');
            const serviceRequestSelect = document.getElementById('serviceRequest');
            const serviceDateGroup = document.getElementById('serviceDateGroup');
            const serviceWindowGroup = document.getElementById('serviceWindowGroup');
            const serviceLocationGroup = document.getElementById('serviceLocationGroup');

            // Client Selector functionality
            const clientSearch = document.getElementById('clientSearch');
            const clientDropdown = document.getElementById('clientDropdown');
            const clientList = document.getElementById('clientList');
            const selectedClient = document.getElementById('selectedClient');
            const selectedClientName = document.getElementById('selectedClientName');
            const selectedClientEmail = document.getElementById('selectedClientEmail');
            const selectedClientId = document.getElementById('selectedClientId');
            const removeClientBtn = document.getElementById('removeClient');

            // Client data will be fetched from database
            let clientsData = [];

            let filteredClients = [...clientsData];
            let selectedClientData = null;

            // Fetch client data from database
            async function fetchClientsData() {
                try {
                    const response = await fetch('{{ route("admin.clients.list") }}', {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        clientsData = result.clients || [];
                        filteredClients = [...clientsData];
                        renderClientList();
                        return true;
                    } else {
                        console.error('Failed to fetch clients:', result.message);
                        return false;
                    }
                } catch (error) {
                    console.error('Error fetching clients data:', error);
                    showNotification('Failed to load client list. Please refresh the page.', 'error');
                    return false;
                }
            }

            // Initialize client selector
            async function initializeClientSelector() {
                // Show loading state
                clientList.innerHTML = '<div class="loading-clients"><i class="fas fa-spinner fa-spin"></i> Loading clients...</div>';
                
                // Fetch client data first
                const success = await fetchClientsData();
                
                if (!success) {
                    clientList.innerHTML = '<div class="no-results">Failed to load clients</div>';
                }
                
                setupEventListeners();
            }

            // Setup event listeners for client selector
            function setupEventListeners() {
                // Search input events
                clientSearch.addEventListener('input', handleSearch);
                clientSearch.addEventListener('focus', showDropdown);
                clientSearch.addEventListener('blur', hideDropdownOnBlur);

                // Remove client button
                removeClientBtn.addEventListener('click', clearSelectedClient);

                // Click outside to close dropdown
                document.addEventListener('click', handleOutsideClick);
            }

            // Handle search input
            function handleSearch(e) {
                const query = e.target.value.toLowerCase().trim();
                
                if (query.length === 0) {
                    filteredClients = [...clientsData];
                } else {
                    filteredClients = clientsData.filter(client => 
                        client.name.toLowerCase().includes(query) || 
                        client.email.toLowerCase().includes(query) ||
                        (client.phone && client.phone.includes(query)) ||
                        (client.unique_id && client.unique_id.toLowerCase().includes(query))
                    );
                }
                
                renderClientList();
                showDropdown();
            }

            // Render client list
            function renderClientList() {
                if (filteredClients.length === 0) {
                    clientList.innerHTML = '<div class="no-results">No clients found</div>';
                    return;
                }

                clientList.innerHTML = filteredClients.map(client => `
                    <div class="client-option" data-client-id="${client.id}" data-client-email="${client.email}" data-client-unique-id="${client.unique_id}">
                        <div class="client-info">
                            <span class="client-name">${client.name} <span class="client-id-badge">${client.unique_id}</span></span>
                            <span class="client-email">${client.email}</span>
                        </div>
                    </div>
                `).join('');

                // Add click listeners to client options
                clientList.querySelectorAll('.client-option').forEach(option => {
                    option.addEventListener('click', () => selectClient(option));
                });
            }

            // Each business has its own call outcomes; fall back to the platform list.
            const outcomeSelect = document.getElementById('callOutcome');
            const defaultOutcomeOptions = outcomeSelect.innerHTML;
            function setOutcomeOptions(outcomes) {
                const current = outcomeSelect.value;
                if (!Array.isArray(outcomes) || outcomes.length === 0) {
                    outcomeSelect.innerHTML = defaultOutcomeOptions;
                } else {
                    outcomeSelect.innerHTML = '<option value="">Select outcome...</option>';
                    outcomes.forEach(o => { const opt = new Option(o.label, o.key); opt.dataset.category = o.category; outcomeSelect.add(opt); });
                }
                outcomeSelect.value = [...outcomeSelect.options].some(o => o.value === current) ? current : '';
                toggleEscalationFields();
            }

            const escalationFields = document.getElementById('escalationFields');
            function isEscalationOutcome() {
                return outcomeSelect.selectedOptions[0]?.dataset.category === 'escalated';
            }
            function toggleEscalationFields() {
                escalationFields.style.display = isEscalationOutcome() ? '' : 'none';
            }
            outcomeSelect.addEventListener('change', toggleEscalationFields);

            // Select a client
            function selectClient(optionElement) {
                const clientId = optionElement.dataset.clientId;
                const clientEmail = optionElement.dataset.clientEmail;
                const clientUniqueId = optionElement.dataset.clientUniqueId;
                const client = clientsData.find(c => c.id == clientId);

                if (client) {
                    selectedClientData = client;
                    selectedClientName.innerHTML = `${client.name} <span class="client-id-badge">${client.unique_id}</span>`;
                    selectedClientEmail.textContent = client.email;
                    selectedClientId.value = client.id; // Store actual client ID
                    setOutcomeOptions(client.call_outcomes);
                    
                    // Show selected client and hide search
                    selectedClient.style.display = 'flex';
                    clientSearch.style.display = 'none';
                    hideDropdown();
                    
                    // Clear search
                    clientSearch.value = '';
                    filteredClients = [...clientsData];
                }
            }

            // Clear selected client
            function clearSelectedClient() {
                selectedClientData = null;
                selectedClient.style.display = 'none';
                clientSearch.style.display = 'block';
                selectedClientId.value = '';
                setOutcomeOptions(null);
                clientSearch.focus();
            }

            // Show dropdown
            function showDropdown() {
                if (filteredClients.length > 0) {
                    clientDropdown.style.display = 'block';
                }
            }

            // Hide dropdown
            function hideDropdown() {
                clientDropdown.style.display = 'none';
            }

            // Hide dropdown on blur (with delay to allow clicks)
            function hideDropdownOnBlur() {
                setTimeout(() => {
                    if (!clientDropdown.contains(document.activeElement)) {
                        hideDropdown();
                    }
                }, 150);
            }

            // Handle clicks outside the client selector
            function handleOutsideClick(e) {
                if (!e.target.closest('.client-selector')) {
                    hideDropdown();
                }
            }

            // Initialize client selector
            initializeClientSelector();
            
            // Notification function
            function showNotification(message, type = 'info') {
                const notification = document.createElement('div');
                notification.className = `alert alert-${type === 'success' ? 'success' : type === 'error' ? 'danger' : 'info'} alert-dismissible fade show position-fixed`;
                notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
                notification.innerHTML = `
                    <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                
                document.body.appendChild(notification);
                
                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 5000);
            }
            
            // Call IDs are assigned by the server on save (FIX-04); the field only shows the last saved ID.
            document.getElementById('callDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('callTime').value = new Date().toTimeString().split(' ')[0].substring(0, 5);
            
            // Enhanced Service Request toggle functionality
            serviceRequestSelect.addEventListener('change', function() {
                const groups = [serviceDateGroup, serviceWindowGroup, serviceLocationGroup];
                
                if (this.value === 'yes') {
                    groups.forEach((group, index) => {
                        setTimeout(() => {
                            group.style.display = 'block';
                            group.style.opacity = '0';
                            group.style.transform = 'translateY(-10px)';
                            
                            setTimeout(() => {
                                group.style.transition = 'all 0.3s ease';
                                group.style.opacity = '1';
                                group.style.transform = 'translateY(0)';
                            }, 50);
                        }, index * 100);
                    });
                } else {
                    groups.forEach(group => {
                        group.style.transition = 'all 0.3s ease';
                        group.style.opacity = '0';
                        group.style.transform = 'translateY(-10px)';
                        
                        setTimeout(() => {
                            group.style.display = 'none';
                        }, 300);
                    });
                }
            });
            
            // Enhanced form submission with better feedback
            callLogForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                
                // Client-side validation
                const requiredFields = ['callDate', 'callTime', 'reasonForCall', 'callOutcome', 'agentName', 'callStatus'];
                const missingFields = [];
                
                // Check if client is selected
                if (!selectedClientId.value.trim()) {
                    showNotification('Please select a client before submitting the form', 'error');
                    clientSearch.focus();
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                    return;
                }
                
                requiredFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (!field.value.trim()) {
                        missingFields.push(fieldId);
                        field.classList.add('is-invalid');
                    } else {
                        field.classList.remove('is-invalid');
                    }
                });
                
                if (missingFields.length > 0) {
                    showNotification('Please fill in all required fields: ' + missingFields.join(', '), 'error');
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                    return;
                }
                
                // Show loading state
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                submitBtn.disabled = true;
                
                // Collect form data
                const formData = new FormData();
                formData.append('client_id', document.getElementById('selectedClientId').value);
                formData.append('call_date', document.getElementById('callDate').value);
                formData.append('call_time', document.getElementById('callTime').value);
                formData.append('caller_name', document.getElementById('callerName').value);
                formData.append('caller_phone', document.getElementById('callerPhone').value);
                formData.append('caller_email', document.getElementById('callerEmail').value);
                formData.append('reason_for_call', document.getElementById('reasonForCall').value);
                formData.append('call_outcome', document.getElementById('callOutcome').value);
                if (isEscalationOutcome()) {
                    formData.append('escalation_type', document.getElementById('escalationType').value);
                }
                formData.append('agent_name', document.getElementById('agentName').value);
                formData.append('status', document.getElementById('callStatus').value);
                formData.append('service_request', document.getElementById('serviceRequest').value === 'yes' ? '1' : '0');
                formData.append('service_date', document.getElementById('serviceDate').value);
                formData.append('service_window', document.getElementById('serviceWindow').value);
                formData.append('service_location', document.getElementById('serviceLocation').value);
                formData.append('notes', document.getElementById('callNotes').value);
                
                try {
                    const response = await fetch('{{ route("admin.call-logs.store") }}', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                        }
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Success feedback
                submitBtn.innerHTML = '<i class="fas fa-check me-2"></i>Saved!';
                submitBtn.classList.add('btn-success');
                        
                        // Show success message
                        showNotification('Call log saved successfully! Call ID: ' + result.call_id, 'success');
                        
                        // Refresh KPI data and charts after successful submission
                        setTimeout(() => {
                            refreshKpiData();
                            refreshPerformanceCharts();
                        }, 500);
                
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.classList.remove('btn-success');
                    submitBtn.disabled = false;
                    
                    // Reset form, keeping the server-assigned ID of the call just saved visible
                    this.reset();
                    document.getElementById('escalationFields').style.display = 'none';
                    document.getElementById('callId').value = '';
                    document.getElementById('callId').placeholder = 'Last saved: ' + result.call_id;
                    document.getElementById('callDate').value = new Date().toISOString().split('T')[0];
                    document.getElementById('callTime').value = new Date().toTimeString().split(' ')[0].substring(0, 5);
                            document.getElementById('agentName').value = '{{ explode(' ', Auth::user()->name)[0] }}';
                            clearSelectedClient(); // Reset client selector
                    serviceRequestSelect.dispatchEvent(new Event('change'));
                }, 2000);
                    } else {
                        // Error feedback
                        submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Error!';
                        submitBtn.classList.add('btn-danger');
                        showNotification(result.message || 'Failed to save call log', 'error');
                        
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.classList.remove('btn-danger');
                            submitBtn.disabled = false;
                        }, 3000);
                    }
                } catch (error) {
                    // Network error feedback
                    submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Error!';
                    submitBtn.classList.add('btn-danger');
                    showNotification('Network error. Please try again.', 'error');
                    
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.classList.remove('btn-danger');
                        submitBtn.disabled = false;
                    }, 3000);
                }
            });
            
            // Enhanced clear form functionality
            document.getElementById('clearForm').addEventListener('click', function() {
                callLogForm.reset();
                document.getElementById('callId').value = '';
                document.getElementById('callDate').value = new Date().toISOString().split('T')[0];
                document.getElementById('callTime').value = new Date().toTimeString().split(' ')[0].substring(0, 5);
                document.getElementById('agentName').value = '{{ explode(' ', Auth::user()->name)[0] }}';
                clearSelectedClient(); // Reset client selector
                serviceRequestSelect.dispatchEvent(new Event('change'));
                
                // Add visual feedback
                this.innerHTML = '<i class="fas fa-check me-2"></i>Cleared!';
                setTimeout(() => {
                    this.innerHTML = '<i class="fas fa-times me-2"></i>Clear';
                }, 1000);
            });
            
            // Microsoft Teams Style Calendar with FullCalendar
            const currentAgentName = document.getElementById('currentAgentName');
            const todayBtn = document.getElementById('todayBtn');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const viewSelector = document.getElementById('viewSelector');
            
            // Get logged-in user data from Laravel
            const loggedInUser = {
                id: {{ Auth::id() }},
                name: '{{ Auth::user()->name }}',
                role: '{{ Auth::user()->role }}'
            };
            
            // Set the current agent name
            currentAgentName.textContent = loggedInUser.name;
            
            // Initialize FullCalendar with enhanced styling
            let calendar;
            
            function initializeCalendar() {
                const calendarEl = document.getElementById('teamsCalendar');
                
                console.log('Initializing FullCalendar...');
                console.log('Calendar element:', calendarEl);
                console.log('FullCalendar available:', typeof FullCalendar !== 'undefined');
                
                if (!calendarEl) {
                    console.error('Calendar element not found!');
                    return;
                }
                
                if (typeof FullCalendar === 'undefined') {
                    console.error('FullCalendar library not loaded!');
                    return;
                }
                
                calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'timeGridWeek',
                    headerToolbar: false,
                    height: 'auto',
                    slotMinTime: '00:00:00',
                    slotMaxTime: '24:00:00',
                    slotDuration: '01:00:00',
                    allDaySlot: false,
                    nowIndicator: true,
                    dayHeaderFormat: { weekday: 'short', day: 'numeric' },
                    slotLabelFormat: {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    },
                    eventDisplay: 'block',
                    eventTimeFormat: {
                        hour: '2-digit',
                        minute: '2-digit',
                        hour12: true
                    },
                    events: function(info, successCallback, failureCallback) {
                        // Fetch events from the server for the current agent
                        const url = `{{ route('admin.duty-schedules.calendar-data') }}?start=${info.start.toISOString()}&end=${info.end.toISOString()}&agent_id=${loggedInUser.id}`;
                        
                        console.log('Fetching calendar data from:', url);
                        console.log('User ID:', loggedInUser.id);
                        console.log('Date range:', info.start.toISOString(), 'to', info.end.toISOString());
                        
                        fetch(url, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                            }
                        })
                            .then(response => {
                                console.log('Response status:', response.status);
                                console.log('Response headers:', response.headers);
                                
                                if (!response.ok) {
                                    throw new Error(`HTTP error! status: ${response.status}`);
                                }
                                return response.json();
                            })
                            .then(data => {
                                console.log('Calendar data received:', data);
                                console.log('Number of events:', data.length);
                                
                                if (data.length === 0) {
                                    console.warn('No events returned from server');
                                }
                                
                                successCallback(data);
                                // Update weekly summary after events are loaded
                                setTimeout(updateWeeklySummary, 200);
                            })
                            .catch(error => {
                                console.error('Error fetching calendar data:', error);
                                console.error('Error details:', error.message);
                                // Show user-friendly error message
                                showNotification('Failed to load duty schedule. Please refresh the page.', 'error');
                                failureCallback(error);
                            });
                    },
                    eventDidMount: function(info) {
                        const eventType = info.event.extendedProps.type;
                        info.el.classList.add(`fc-event-${eventType}`);
                        
                        // Add tooltip for better UX
                        info.el.setAttribute('title', `${info.event.title} (${info.event.extendedProps.type})`);
                    },
                    eventClick: function(info) {
                        const event = info.event;
                        const startTime = event.start.toLocaleTimeString('en-US', { 
                            hour: '2-digit', 
                            minute: '2-digit', 
                            hour12: true 
                        });
                        const endTime = event.end.toLocaleTimeString('en-US', { 
                            hour: '2-digit', 
                            minute: '2-digit', 
                            hour12: true 
                        });
                        
                        // Check if it's an overnight shift
                        const isOvernight = event.extendedProps.is_overnight;
                        const startDate = event.start.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
                        const endDate = event.end.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
                        
                        // Calculate working hours
                        const startDateTime = new Date(event.start);
                        const endDateTime = new Date(event.end);
                        const diffMs = endDateTime - startDateTime;
                        const workingHours = Math.round(diffMs / (1000 * 60 * 60) * 10) / 10;
                        
                        // Enhanced event details display with modal-like styling
                        const eventDetails = `
                            <div class="event-details p-3" style="background: rgba(15, 15, 35, 0.95); border-radius: 12px; color: white; max-width: 400px;">
                                <h6 class="mb-3" style="color: #4f46e5; font-weight: 600;">${event.title}</h6>
                                <div class="mb-2">
                                    <i class="fas fa-clock me-2" style="color: #10b981;"></i>
                                    <strong>Working Hours:</strong> ${startTime} - ${endTime} 
                                    <span class="badge bg-success ms-2">${workingHours} hrs</span>
                                </div>
                                ${isOvernight ? 
                                    `<div class="mb-2">
                                        <i class="fas fa-calendar me-2" style="color: #f59e0b;"></i>
                                        <strong>Date:</strong> ${startDate} - ${endDate} 
                                        <span class="badge bg-warning ms-2">Overnight Shift</span>
                                    </div>` :
                                    `<div class="mb-2">
                                        <i class="fas fa-calendar me-2" style="color: #3b82f6;"></i>
                                        <strong>Date:</strong> ${startDate}
                                    </div>`
                                }
                                <div class="mb-2">
                                    <i class="fas fa-tag me-2" style="color: #8b5cf6;"></i>
                                    <strong>Shift Type:</strong> <span class="badge bg-${event.extendedProps.type === 'morning' ? 'primary' : event.extendedProps.type === 'afternoon' ? 'info' : event.extendedProps.type === 'night' ? 'purple' : 'secondary'}">${event.extendedProps.type}</span>
                                </div>
                                ${event.extendedProps.description ? `
                                    <div class="mb-2">
                                        <i class="fas fa-info-circle me-2" style="color: #06b6d4;"></i>
                                        <strong>Notes:</strong> ${event.extendedProps.description}
                                    </div>
                                ` : ''}
                                <div class="mb-2">
                                    <i class="fas fa-user-tie me-2" style="color: #ec4899;"></i>
                                    <strong>Assigned by:</strong> Admin
                                </div>
                                ${event.extendedProps.has_conflicts ? `
                                    <div class="mb-2">
                                        <i class="fas fa-exclamation-triangle me-2" style="color: #f59e0b;"></i>
                                        <strong>Warning:</strong> <span class="text-warning">${event.extendedProps.conflict_count} conflicting schedule(s) exist for this day</span>
                                    </div>
                                ` : ''}
                                <div class="mt-3 pt-2" style="border-top: 1px solid rgba(255, 255, 255, 0.1);">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-check me-1"></i>
                                        Your assigned duty schedule
                                    </small>
                                </div>
                            </div>
                        `;
                        
                        // Create a custom modal for better UX
                        showEventModal(eventDetails);
                    }
                });
                
                calendar.render();
                
                console.log('Calendar rendered successfully');
                console.log('Calendar instance:', calendar);
                
                // Test if calendar is working by checking for events
                setTimeout(() => {
                    const events = calendar.getEvents();
                    console.log('Calendar events after render:', events.length);
                }, 1000);
            }
            
            // Function to show event details in a modal
            function showEventModal(content) {
                // Remove existing modal if any
                const existingModal = document.getElementById('eventModal');
                if (existingModal) {
                    existingModal.remove();
                }
                
                // Create modal
                const modal = document.createElement('div');
                modal.id = 'eventModal';
                modal.className = 'modal fade show';
                modal.style.display = 'block';
                modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
                modal.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content" style="background: rgba(15, 15, 35, 0.95); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px;">
                            <div class="modal-header" style="border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                                <h5 class="modal-title" style="color: white;">Duty Schedule Details</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="this.closest('.modal').remove()"></button>
                            </div>
                            <div class="modal-body">
                                ${content}
                            </div>
                            <div class="modal-footer" style="border-top: 1px solid rgba(255, 255, 255, 0.1);">
                                <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Close</button>
                            </div>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(modal);
                
                // Close modal when clicking outside
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        modal.remove();
                            }
                        });
                    }
                    
            
            // Enhanced event listeners
            todayBtn.addEventListener('click', function() {
                calendar.today();
            });
            
            prevBtn.addEventListener('click', function() {
                calendar.prev();
            });
            
            nextBtn.addEventListener('click', function() {
                calendar.next();
            });
            
            viewSelector.addEventListener('change', function() {
                calendar.changeView(this.value);
            });
            
            // Initialize calendar
            initializeCalendar();
            
            // Function to refresh calendar data
            function refreshCalendar() {
                if (calendar) {
                    calendar.refetchEvents();
                    showNotification('Duty schedule refreshed', 'success');
                    // Update summary after refresh
                    setTimeout(updateWeeklySummary, 500);
                }
            }
            
            // Add refresh button functionality
            const refreshBtn = document.createElement('button');
            refreshBtn.className = 'btn btn-outline-secondary btn-sm';
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt me-1"></i>Refresh';
            refreshBtn.onclick = refreshCalendar;
            
            // Add refresh button to calendar controls
            const calendarControls = document.querySelector('.calendar-controls');
            if (calendarControls) {
                calendarControls.appendChild(refreshBtn);
            }
            
            // Function to calculate and update weekly summary
            function updateWeeklySummary() {
                if (!calendar) return;
                
                const events = calendar.getEvents();
                const today = new Date();
                const startOfWeek = new Date(today);
                startOfWeek.setDate(today.getDate() - today.getDay());
                const endOfWeek = new Date(startOfWeek);
                endOfWeek.setDate(startOfWeek.getDate() + 6);
                
                let weeklyHours = 0;
                let todayHours = 0;
                let scheduledDays = new Set();
                
                events.forEach(event => {
                    const eventStart = new Date(event.start);
                    const eventEnd = new Date(event.end);
                    const eventDuration = (eventEnd - eventStart) / (1000 * 60 * 60); // hours
                    
                    // Check if event is within current week
                    if (eventStart >= startOfWeek && eventStart <= endOfWeek) {
                        weeklyHours += eventDuration;
                        scheduledDays.add(eventStart.toDateString());
                        
                        // Check if event is today
                        if (eventStart.toDateString() === today.toDateString()) {
                            todayHours += eventDuration;
                        }
                    }
                });
                
                // Update summary display
                document.getElementById('weeklyHours').textContent = Math.round(weeklyHours * 10) / 10 + ' hrs';
                document.getElementById('todayHours').textContent = Math.round(todayHours * 10) / 10 + ' hrs';
                document.getElementById('scheduledDays').textContent = scheduledDays.size;
            }
            
            // Update summary when calendar events change
            function onCalendarEventsChanged() {
                setTimeout(updateWeeklySummary, 100);
            }
            
            // Sidebar Toggle Functionality
            const sidebar = document.querySelector('.sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const mainContent = document.querySelector('.main-content');
            const sidebarHeader = document.querySelector('.sidebar-header');

            function toggleSidebar() {
                sidebar.classList.toggle('collapsed');
                mainContent.classList.toggle('sidebar-collapsed');
                sidebarHeader.classList.toggle('collapsed');
            }

            // Desktop sidebar toggle
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (window.innerWidth > 991.98) {
                        toggleSidebar();
                    } else {
                        // Mobile sidebar toggle
                        sidebar.classList.toggle('show');
                    }
                });
            }

            // Close sidebar on mobile when clicking outside
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 991.98) {
                    if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                        sidebar.classList.remove('show');
                    }
                }
            });

            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth > 991.98) {
                    sidebar.classList.remove('show');
                }
            });
            
            console.log('Enhanced dashboard with dark theme and improved UX initialized successfully!');
        });
    </script>

    <style>
        .dashboard-footer {
            background: rgba(15, 15, 35, 0.95);
            border-top: 1px solid var(--card-border);
            padding: 1.5rem 0;
            margin-top: 3rem;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .footer-links {
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--text-primary);
        }

        .copyright {
            color: var(--text-secondary);
            margin: 0;
            font-size: 0.9rem;
        }

        /* Enhanced Calendar Styles */
        .fc {
            font-family: 'Inter', sans-serif !important;
            background: transparent !important;
        }

        /* Agent-specific calendar styling */
        .fc-event {
            border: none !important;
            border-radius: 8px !important;
            padding: 6px 10px !important;
            font-size: 0.8rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2) !important;
        }

        .fc-event:hover {
            transform: translateY(-2px) scale(1.02) !important;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3) !important;
        }

        /* Enhanced shift type colors */
        .fc-event-morning {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
            color: white !important;
            border-left: 4px solid #1e40af !important;
        }

        .fc-event-afternoon {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%) !important;
            color: white !important;
            border-left: 4px solid #0e7490 !important;
        }

        .fc-event-evening {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
            color: white !important;
            border-left: 4px solid #b45309 !important;
        }

        .fc-event-night {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%) !important;
            color: white !important;
            border-left: 4px solid #6d28d9 !important;
        }

        .fc-event-off {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%) !important;
            color: white !important;
            border-left: 4px solid #374151 !important;
        }

        /* Calendar day highlighting */
        .fc-daygrid-day.fc-day-today {
            background: rgba(34, 197, 94, 0.1) !important;
            border: 2px solid rgba(34, 197, 94, 0.3) !important;
        }

        .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%) !important;
            color: white !important;
            border-radius: 50% !important;
            width: 28px !important;
            height: 28px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 4px !important;
            font-weight: 700 !important;
            box-shadow: 0 2px 8px rgba(34, 197, 94, 0.3) !important;
        }

        /* Time grid enhancements */
        .fc-timegrid-slot {
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
            min-height: 40px !important;
        }

        .fc-timegrid-slot-label {
            color: var(--text-secondary) !important;
            font-size: 0.75rem !important;
            font-weight: 500 !important;
            padding: 4px 8px !important;
        }

        /* Current time indicator */
        .fc-timegrid-now-indicator-line {
            border-color: #ef4444 !important;
            border-width: 2px !important;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.5) !important;
        }

        .fc-timegrid-now-indicator-arrow {
            border-color: #ef4444 !important;
            background: #ef4444 !important;
        }

        /* Calendar header styling */
        .fc-col-header-cell {
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid var(--card-border) !important;
            padding: 12px 8px !important;
            font-weight: 600 !important;
        }

        .fc-col-header-cell-cushion {
            color: var(--text-primary) !important;
            font-weight: 600 !important;
            font-size: 0.9rem !important;
            text-decoration: none !important;
        }

        /* Weekend styling */
        .fc-daygrid-day.fc-day-sat,
        .fc-daygrid-day.fc-day-sun {
            background: rgba(255, 255, 255, 0.02) !important;
        }

        .fc-daygrid-day.fc-day-sat .fc-daygrid-day-number,
        .fc-daygrid-day.fc-day-sun .fc-daygrid-day-number {
            color: var(--text-secondary) !important;
        }

        .fc-toolbar {
            display: none !important;
        }

        .fc-daygrid-day {
            border: 1px solid var(--card-border) !important;
        }

        .fc-daygrid-day-frame {
            min-height: 80px !important;
        }

        .fc-daygrid-day-number {
            color: var(--text-primary) !important;
            font-weight: 500 !important;
            padding: 8px !important;
        }

        .fc-daygrid-day.fc-day-today {
            background: rgba(34, 197, 94, 0.15) !important;
        }

        .fc-daygrid-day.fc-day-today .fc-daygrid-day-number {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%) !important;
            color: white !important;
            border-radius: 50% !important;
            width: 24px !important;
            height: 24px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 4px !important;
        }

        .fc-event {
            border: none !important;
            border-radius: 8px !important;
            padding: 4px 8px !important;
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            cursor: pointer !important;
            transition: all 0.3s ease !important;
        }

        .fc-event:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3) !important;
        }

        .fc-event-morning {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%) !important;
            color: white !important;
        }

        .fc-event-afternoon {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%) !important;
            color: white !important;
        }

        .fc-event-night {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%) !important;
            color: white !important;
        }

        .fc-event-off {
            background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%) !important;
            color: white !important;
        }

        /* Calendar Controls Styling */
        .calendar-controls {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
        }

        .calendar-controls .btn {
            border-radius: 8px !important;
            padding: 0.5rem 0.75rem !important;
            font-size: 0.875rem !important;
            font-weight: 500 !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
            background: rgba(255, 255, 255, 0.05) !important;
            transition: all 0.3s ease !important;
        }

        .calendar-controls .btn:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            border-color: var(--accent-color) !important;
            transform: translateY(-1px) !important;
        }

        .calendar-controls .form-select {
            border-radius: 8px !important;
            padding: 0.5rem 0.75rem !important;
            font-size: 0.875rem !important;
            border: 1px solid var(--card-border) !important;
            background: rgba(255, 255, 255, 0.05) !important;
            color: var(--text-primary) !important;
        }

        .calendar-controls .form-select:focus {
            border-color: var(--accent-color) !important;
            box-shadow: 0 0 0 0.2rem rgba(168, 85, 247, 0.25) !important;
        }

        /* Chart Controls Styling */
        .chart-controls {
            display: flex !important;
            align-items: center !important;
            gap: 0.75rem !important;
        }

        .chart-controls .btn {
            border-radius: 8px !important;
            padding: 0.5rem 0.75rem !important;
            font-size: 0.875rem !important;
            font-weight: 500 !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
            background: rgba(255, 255, 255, 0.05) !important;
            transition: all 0.3s ease !important;
        }

        .chart-controls .btn:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            border-color: var(--accent-color) !important;
            transform: translateY(-1px) !important;
        }

        .chart-controls .btn-check:checked + .btn {
            background: var(--primary-gradient) !important;
            border-color: var(--accent-color) !important;
            color: white !important;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3) !important;
        }

        /* Calendar Header Styling */
        .fc-col-header-cell {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--card-border) !important;
            padding: 12px 8px !important;
        }

        .fc-col-header-cell-cushion {
            color: var(--text-primary) !important;
            font-weight: 600 !important;
            font-size: 0.875rem !important;
            text-decoration: none !important;
        }

        /* Time Grid Styling */
        .fc-timegrid-slot {
            border: 1px solid rgba(255, 255, 255, 0.05) !important;
        }

        .fc-timegrid-slot-label {
            color: var(--text-secondary) !important;
            font-size: 0.75rem !important;
            font-weight: 500 !important;
        }

        .fc-timegrid-axis {
            border-right: 1px solid var(--card-border) !important;
            background: rgba(255, 255, 255, 0.02) !important;
        }

        .fc-timegrid-now-indicator-line {
            border-color: var(--accent-color) !important;
            border-width: 2px !important;
        }

        .fc-timegrid-now-indicator-arrow {
            border-color: var(--accent-color) !important;
            background: var(--accent-color) !important;
        }

        /* Enhanced Form Styles */
        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 12px !important;
            color: var(--text-primary) !important;
            padding: 0.75rem 0.5rem !important;
            transition: all 0.3s ease !important;
            font-size: 0.875rem !important;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(255, 255, 255, 0.08) !important;
            border-color: var(--accent-color) !important;
            box-shadow: 0 0 0 0.2rem rgba(168, 85, 247, 0.25) !important;
            color: var(--text-primary) !important;
        }

        .form-control::placeholder {
            color: var(--text-secondary) !important;
        }

        .form-label {
            color: var(--text-primary) !important;
            font-weight: 600 !important;
            margin-bottom: 0.5rem !important;
            font-size: 0.875rem !important;
        }

        .form-check-input {
            background-color: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--card-border) !important;
        }

        .form-check-input:checked {
            background-color: var(--accent-color) !important;
            border-color: var(--accent-color) !important;
        }

        .form-check-label {
            color: var(--text-primary) !important;
            font-size: 0.875rem !important;
        }

        /* Compact Form Layout */
        .call-log-form {
            max-height: none !important;
            overflow: visible !important;
        }

        .call-log-form .row {
            margin-bottom: 0.75rem !important;
        }

        .call-log-form .col-md-6 {
            margin-bottom: 0.75rem !important;
        }

        .call-log-form .form-group {
            margin-bottom: 0.75rem !important;
        }

        .call-log-form textarea.form-control {
            min-height: 60px !important;
            max-height: 80px !important;
            resize: none !important;
        }

        .call-log-form .btn {
            padding: 0.5rem 1rem !important;
            font-size: 0.875rem !important;
        }

        /* Success button state */
        .btn-success {
            background: var(--success-color) !important;
            border-color: var(--success-color) !important;
        }

        /* Client Selector Styles */
        .client-selector {
            position: relative;
            width: 100%;
        }

        .search-container {
            position: relative;
        }

        .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
            z-index: 2;
        }

        .client-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: rgba(15, 15, 35, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            z-index: 1000;
            max-height: 300px;
            overflow-y: auto;
            margin-top: 4px;
        }

        .dropdown-content {
            padding: 0.5rem 0;
        }

        .client-option {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            width: 100%;
        }

        .client-option:last-child {
            border-bottom: none;
        }

        .client-option:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateX(5px);
        }

        .client-option.selected {
            background: rgba(79, 70, 229, 0.2);
            border-left: 3px solid var(--accent-color);
        }


        .client-info {
            flex-grow: 1;
            min-width: 0;
            width: 100%;
        }

        .client-name {
            display: block;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .client-email {
            display: block;
            color: var(--text-secondary);
            font-size: 0.8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .client-id-badge {
            background: var(--primary-gradient);
            color: white;
            padding: 0.125rem 0.375rem;
            border-radius: 6px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 0.5rem;
            display: inline-block;
            white-space: nowrap;
        }

        .selected-client {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background: rgba(79, 70, 229, 0.1);
            border: 1px solid var(--accent-color);
            border-radius: 12px;
            margin-top: 0.5rem;
        }

        .selected-client-info {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .selected-client .client-name {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .selected-client .client-email {
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .btn-remove-client {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 50%;
            transition: all 0.3s ease;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-remove-client:hover {
            background: rgba(239, 68, 68, 0.2);
            color: var(--danger-color);
            transform: scale(1.1);
        }

        .no-results {
            padding: 1rem;
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
        }

        .loading-clients {
            padding: 1rem;
            text-align: center;
            color: var(--text-secondary);
        }

        .loading-clients i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .footer-content {
                flex-direction: column;
                text-align: center;
            }

            .footer-links {
                justify-content: center;
            }
        }
    </style>
</body>
</html> 
