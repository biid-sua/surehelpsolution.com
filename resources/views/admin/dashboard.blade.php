 !DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SureHelp Admin Dashboard - System Management</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="{{ asset('assets/css/admin-style.css') }}" rel="stylesheet">
    
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
        }

        .sidebar .nav-text {
            transition: opacity 0.3s ease !important;
        }

        .sidebar.collapsed .nav-text {
            opacity: 0 !important;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px !important;
            min-height: 100vh !important;
            transition: margin-left 0.3s ease !important;
        }

        .sidebar.collapsed + .main-content {
            margin-left: 70px !important;
        }

        /* Header */
        .dashboard-header {
            background: var(--card-bg) !important;
            backdrop-filter: blur(20px) !important;
            border-bottom: 1px solid var(--card-border) !important;
            padding: 1rem 2rem !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 999 !important;
        }

        /* Cards */
        .dashboard-card {
            background: var(--card-bg) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 16px !important;
            padding: 1.5rem !important;
            transition: all 0.3s ease !important;
        }

        .dashboard-card:hover {
            transform: translateY(-5px) !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3) !important;
        }

        .stat-card {
            text-align: center !important;
            padding: 2rem 1.5rem !important;
            height: 200px !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            align-items: center !important;
        }

        .stat-icon {
            width: 50px !important;
            height: 50px !important;
            border-radius: 12px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin-bottom: 1rem !important;
            font-size: 1.25rem !important;
        }

        .stat-number {
            font-size: 2.25rem !important;
            font-weight: 700 !important;
            margin-bottom: 0.5rem !important;
            line-height: 1.2 !important;
        }

        .stat-label {
            color: var(--text-secondary) !important;
            font-size: 0.9rem !important;
        }

        .stat-card small {
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            margin-top: 0.25rem !important;
            display: block !important;
            line-height: 1.2 !important;
        }

        .stat-card .text-success {
            color: #10b981 !important;
        }

        .stat-card .text-danger {
            color: #ef4444 !important;
        }

        /* Buttons */
        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
            border-radius: 12px !important;
            padding: 0.75rem 1.5rem !important;
            font-weight: 600 !important;
            transition: all 0.3s ease !important;
        }

        .btn-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3) !important;
        }

        /* Form Styling */
        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
        }

        .form-control:focus, .form-select:focus {
            background: rgba(255, 255, 255, 0.15) !important;
            border-color: #625ED0 !important;
            box-shadow: 0 0 0 0.2rem rgba(98, 94, 208, 0.25) !important;
            color: var(--text-primary) !important;
        }

        .form-label {
            color: var(--text-primary) !important;
            font-weight: 500 !important;
        }

        .form-check-label {
            color: var(--text-primary) !important;
        }

        .form-text {
            color: var(--text-secondary) !important;
        }

        .btn-secondary {
            color: var(--text-primary) !important;
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid var(--card-border) !important;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2) !important;
            color: var(--text-primary) !important;
        }

        .alert-info {
            background: rgba(13, 202, 240, 0.1) !important;
            border: 1px solid rgba(13, 202, 240, 0.3) !important;
            color: #7dd3fc !important;
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.1) !important;
            border: 1px solid rgba(239, 68, 68, 0.3) !important;
            color: #fca5a5 !important;
        }

        /* Dropdown Styles */
        .dropdown-menu {
            background: rgba(15, 15, 35, 0.95) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 12px !important;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3) !important;
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

        .btn-outline-light.dropdown-toggle {
            color: var(--text-primary) !important;
            border-color: var(--card-border) !important;
        }

        .btn-outline-light.dropdown-toggle:hover {
            background: rgba(255, 255, 255, 0.1) !important;
            border-color: var(--accent-color) !important;
            color: var(--text-primary) !important;
        }

        /* Form select dropdown styling */
        .form-select {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
        }

        .form-select:focus {
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

        /* Tables */
        .table-dark {
            background: var(--card-bg) !important;
            border-radius: 12px !important;
            overflow: hidden !important;
        }

        .table-dark th {
            background: rgba(255, 255, 255, 0.08) !important;
            border: none !important;
            color: var(--text-primary) !important;
            font-weight: 600 !important;
            font-size: 0.9rem !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }

        .table-dark td {
            border: none !important;
            color: var(--text-primary) !important;
            vertical-align: middle !important;
            font-weight: 500 !important;
        }

        .table-dark tbody tr:hover {
            background: rgba(255, 255, 255, 0.08) !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2) !important;
        }

        /* Enhanced table styling for better visibility */
        .table-dark tbody tr {
            transition: all 0.2s ease !important;
        }

        .table-dark tbody tr:nth-child(even) {
            background: rgba(255, 255, 255, 0.02) !important;
        }

        /* Avatar */
        .avatar-sm {
            width: 32px !important;
            height: 32px !important;
            font-size: 0.875rem !important;
        }

        /* Badges */
        .badge {
            font-size: 0.75rem !important;
            font-weight: 500 !important;
        }

        /* Action buttons */
        .btn-group-sm .btn {
            padding: 0.25rem 0.5rem !important;
            font-size: 0.75rem !important;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%) !important;
            }
            
            .sidebar.show {
                transform: translateX(0) !important;
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            .stat-card {
                height: 180px !important;
                padding: 1.5rem 1rem !important;
            }
            
            .stat-number {
                font-size: 2rem !important;
            }
            
            .stat-icon {
                width: 45px !important;
                height: 45px !important;
                font-size: 1.1rem !important;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="p-3">
            <div class="d-flex align-items-center mb-4">
                <div class="logo-icon me-3">
                    <img src="{{ asset('assets/img/logo.png') }}" alt="SHS Logo" style="width: 100%; height: 100%; object-fit: contain;">
                </div>
                <!-- <div class="nav-text">
                    <h5 class="mb-0">SureHelp</h5>
                    <small class="text-muted">Admin Panel</small>
                </div> -->
            </div>
            
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link active" href="{{ route('admin.dashboard') }}">
                        <i class="fas fa-tachometer-alt"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('admin.agent-dashboard') }}">
                        <i class="fas fa-users"></i>
                        <span class="nav-text">Agents</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('admin.client-dashboard') }}">
                        <i class="fas fa-user-tie"></i>
                        <span class="nav-text">Clients</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('duty-schedules.index') }}">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="nav-text">Duty Schedules</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('admin.users.index') }}">
                        <i class="fas fa-user-cog"></i>
                        <span class="nav-text">User Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="{{ route('admin.contact-submissions.index') }}">
                        <i class="fas fa-envelope"></i>
                        <span class="nav-text">Contact Forms</span>
                    </a>
                </li>
                <li class="nav-item mt-3">
                    <a class="nav-link" href="{{ route('auth.logout') }}">
                        <i class="fas fa-sign-out-alt"></i>
                        <span class="nav-text">Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <header class="dashboard-header">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="btn btn-outline-light me-3 d-md-none" id="sidebarToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-shield me-2"></i>
                        <h4 class="mb-0">Admin Dashboard</h4>
                    </div>
                </div>
                <div class="d-flex align-items-center">
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-2"></i>
                            {{ explode(' ', Auth::user()->name)[0] }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('auth.logout') }}"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </header>

        <!-- Dashboard Content -->
        <div class="container-fluid p-4">
            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="dashboard-card stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-users text-white"></i>
                        </div>
                        <div class="stat-number text-white">{{ $stats['total_agents']['formatted'] }}</div>
                        <div class="stat-label">Total Agents</div>
                        @if($stats['total_agents']['change'] != 0)
                            <small class="text-{{ $stats['total_agents']['change'] > 0 ? 'success' : 'danger' }}">
                                <i class="fas fa-arrow-{{ $stats['total_agents']['change'] > 0 ? 'up' : 'down' }}"></i>
                                {{ abs($stats['total_agents']['change']) }}%
                            </small>
                        @endif
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="dashboard-card stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8);">
                            <i class="fas fa-user-tie text-white"></i>
                        </div>
                        <div class="stat-number text-white">{{ $stats['active_clients']['formatted'] }}</div>
                        <div class="stat-label">Active Clients</div>
                        @if($stats['active_clients']['change'] != 0)
                            <small class="text-{{ $stats['active_clients']['change'] > 0 ? 'success' : 'danger' }}">
                                <i class="fas fa-arrow-{{ $stats['active_clients']['change'] > 0 ? 'up' : 'down' }}"></i>
                                {{ abs($stats['active_clients']['change']) }}%
                            </small>
                        @endif
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="dashboard-card stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-phone text-white"></i>
                        </div>
                        <div class="stat-number text-white">{{ $stats['calls_today']['formatted'] }}</div>
                        <div class="stat-label">Calls Today</div>
                        @if($stats['calls_today']['change'] != 0)
                            <small class="text-{{ $stats['calls_today']['change'] > 0 ? 'success' : 'danger' }}">
                                <i class="fas fa-arrow-{{ $stats['calls_today']['change'] > 0 ? 'up' : 'down' }}"></i>
                                {{ abs($stats['calls_today']['change']) }}%
                            </small>
                        @endif
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="dashboard-card stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                            <i class="fas fa-chart-line text-white"></i>
                        </div>
                        <div class="stat-number text-white">{{ $stats['success_rate']['formatted'] }}</div>
                        <div class="stat-label">Success Rate</div>
                        @if($stats['success_rate']['change'] != 0)
                            <small class="text-{{ $stats['success_rate']['change'] > 0 ? 'success' : 'danger' }}">
                                <i class="fas fa-arrow-{{ $stats['success_rate']['change'] > 0 ? 'up' : 'down' }}"></i>
                                {{ abs($stats['success_rate']['change']) }}%
                            </small>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Duty Schedules Management -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="text-white mb-0">Duty Schedules Management</h5>
                            <div>
                                <a href="{{ route('duty-schedules.create') }}" class="btn btn-primary btn-sm me-2">
                                    <i class="fas fa-plus me-1"></i>Add Schedule
                                </a>
                                <a href="{{ route('duty-schedules.index') }}" class="btn btn-outline-light btn-sm">
                                    <i class="fas fa-list me-1"></i>View All
                                </a>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Title</th>
                                        <th>Start Date & Time</th>
                                        <th>End Date & Time</th>
                                        <th>Type</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="schedulesTableBody">
                                    <!-- Data will be loaded via AJAX -->
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center py-3" id="schedulesLoading">
                            <div class="spinner-border text-light" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Form Submissions -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="text-white mb-0">Recent Contact Form Submissions</h5>
                            <a href="{{ route('admin.contact-submissions.index') }}" class="btn btn-outline-light btn-sm">
                                <i class="fas fa-list me-1"></i>View All
                            </a>
                        </div>
                        @if($recentContactSubmissions->count() > 0)
                            <div class="table-responsive">
                                <table class="table table-dark table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Type</th>
                                            <th>Message</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($recentContactSubmissions as $submission)
                                            <tr>
                                                <td>{{ $submission->created_at->format('M j, Y g:i A') }}</td>
                                                <td>{{ $submission->name }}</td>
                                                <td>{{ $submission->email }}</td>
                                                <td>{{ ucfirst($submission->inquiry_type) }}</td>
                                                <td>{{ Str::limit($submission->message, 50) }}</td>
                                                <td>
                                                    <a href="{{ route('admin.contact-submissions.show', $submission) }}" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p class="text-secondary mb-0">No contact form submissions yet.</p>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="row">
                <div class="col-lg-8 mb-4">
                    <div class="dashboard-card">
                        <h5 class="text-white mb-3">Recent Calls</h5>
                        @if($recentCalls->isEmpty())
                            <p class="text-secondary mb-0">No calls logged yet. Calls appear here as soon as an agent saves a call log.</p>
                        @else
                        <div class="table-responsive">
                            <table class="table table-dark">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Agent</th>
                                        <th>Client</th>
                                        <th>Outcome</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentCalls as $call)
                                        @php
                                            $badge = match ($call->statusTone()) {
                                                'completed' => 'bg-success',
                                                'scheduled' => 'bg-primary',
                                                'progress' => 'bg-warning text-dark',
                                                'danger' => 'bg-danger',
                                                default => 'bg-secondary',
                                            };
                                        @endphp
                                        <tr>
                                            <td title="{{ $call->created_at->toDayDateTimeString() }}">{{ $call->created_at->diffForHumans() }}</td>
                                            <td>{{ \App\Models\CallLog::display($call->user->name ?? $call->agent_name) }}</td>
                                            <td>{{ \App\Models\CallLog::display($clientNames[$call->client_id] ?? null) }}</td>
                                            <td>{{ \App\Models\CallLog::display($call->call_outcome ? \Illuminate\Support\Str::headline($call->call_outcome) : null) }}</td>
                                            <td><span class="badge {{ $badge }}">{{ $call->statusLabel() }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>
                </div>
                <div class="col-lg-4 mb-4">
                    <div class="dashboard-card">
                        <h5 class="text-white mb-3">Quick Actions</h5>
                        <div class="d-grid gap-2">
                            <a href="{{ route('duty-schedules.create') }}" class="btn btn-primary">
                                <i class="fas fa-calendar-plus me-2"></i>Add New Schedule
                            </a>
                            <button class="btn btn-outline-light" onclick="loadDutySchedules()">
                                <i class="fas fa-sync-alt me-2"></i>Refresh Schedules
                            </button>
                            <a href="{{ route('duty-schedules.index') }}" class="btn btn-outline-light">
                                <i class="fas fa-calendar-alt me-2"></i>Manage All Schedules
                            </a>
                            <button class="btn btn-outline-light" id="add-user" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                <i class="fas fa-user-plus me-2"></i>Add New User
                            </button>
                            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-light">
                                <i class="fas fa-user-cog me-2"></i>Manage Users
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background: rgba(15, 15, 35, 0.95); border: 1px solid var(--card-border);">
                <div class="modal-header" style="border-bottom: 1px solid var(--card-border);">
                    <h5 class="modal-title text-white" id="addUserModalLabel">
                        <i class="fas fa-user-plus me-2"></i>Add New User
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addUserForm">
                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Name -->
                            <div class="col-md-6">
                                <label for="userName" class="form-label">Full Name *</label>
                                <input type="text" class="form-control" id="userName" name="name" required>
                            </div>
                            
                            <!-- Email -->
                            <div class="col-md-6">
                                <label for="userEmail" class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="userEmail" name="email" required>
                            </div>
                            
                            <!-- Phone -->
                            <div class="col-md-6">
                                <label for="userPhone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="userPhone" name="phone" placeholder="+1 (555) 123-4567">
                            </div>
                            
                            <!-- Role -->
                            <div class="col-md-6">
                                <label for="userRole" class="form-label">Role *</label>
                                <select class="form-select" id="userRole" name="role" required>
                                    <option value="">Select Role...</option>
                                    <option value="admin">Administrator</option>
                                    <option value="agent">Agent</option>
                                    <option value="client">Client</option>
                                </select>
                            </div>
                            
                            <!-- Password -->
                            <div class="col-md-6">
                                <label for="userPassword" class="form-label">Default Password *</label>
                                <input type="password" class="form-control" id="userPassword" name="password" required minlength="8">
                                <div class="form-text">Share this with the user manually. Agents and clients must change it on first login.</div>
                            </div>
                            
                            <!-- Confirm Password -->
                            <div class="col-md-6">
                                <label for="userPasswordConfirm" class="form-label">Confirm Default Password *</label>
                                <input type="password" class="form-control" id="userPasswordConfirm" name="password_confirmation" required>
                            </div>
                            
                            <!-- Status -->
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="userActive" name="is_active" checked>
                                    <label class="form-check-label" for="userActive">
                                        Active User
                                    </label>
                                    <div class="form-text">Uncheck to create an inactive user account</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top: 1px solid var(--card-border);">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="{{ asset('assets/js/admin-script.js') }}"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize AOS animations
            AOS.init({
                duration: 800,
                easing: 'ease-in-out',
                once: true
            });

            // Sidebar toggle for mobile
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('show');
                });
            }

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                if (window.innerWidth <= 768) {
                    if (!sidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                        sidebar.classList.remove('show');
                    }
                }
            });

            // Load duty schedules
            loadDutySchedules();

            if (window.location.hash === '#add-user') {
                const addUserModal = document.getElementById('addUserModal');
                if (addUserModal) {
                    bootstrap.Modal.getOrCreateInstance(addUserModal).show();
                }
            }
        });

        // Load duty schedules data
        function loadDutySchedules() {
            const tbody = document.getElementById('schedulesTableBody');
            const loading = document.getElementById('schedulesLoading');
            
            fetch('/admin/duty-schedules', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(response => response.json())
                .then(data => {
                    tbody.innerHTML = data.html;
                    loading.style.display = 'none';
                })
                .catch(error => {
                    console.error('Error loading schedules:', error);
                    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error loading schedules</td></tr>';
                    loading.style.display = 'none';
                });
        }

        // Delete schedule function
        function deleteSchedule(scheduleId, scheduleTitle) {
            if (confirm(`Are you sure you want to delete the schedule "${scheduleTitle}"?`)) {
                fetch(`/admin/duty-schedules/${scheduleId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Content-Type': 'application/json',
                    }
                })
                .then(response => {
                    if (response.ok) {
                        // Reload the schedules table
                        loadDutySchedules();
                        
                        // Show success message
                        showNotification('Schedule deleted successfully!', 'success');
                    } else {
                        throw new Error('Failed to delete schedule');
                    }
                })
                .catch(error => {
                    console.error('Error deleting schedule:', error);
                    showNotification('Error deleting schedule. Please try again.', 'error');
                });
            }
        }

        // Show notification function
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show position-fixed`;
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.zIndex = '9999';
            notification.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 5000);
        }

        // Handle edit schedule
        function editSchedule(scheduleId) {
            window.location.href = `/admin/duty-schedules/${scheduleId}/edit`;
        }

        // Handle view schedule
        function viewSchedule(scheduleId) {
            window.location.href = `/admin/duty-schedules/${scheduleId}`;
        }

        // Add User Form Handling
        document.getElementById('addUserForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Client-side validation
            const password = document.getElementById('userPassword').value;
            const passwordConfirm = document.getElementById('userPasswordConfirm').value;
            const name = document.getElementById('userName').value.trim();
            const email = document.getElementById('userEmail').value.trim();
            const role = document.getElementById('userRole').value;
            
            // Validation checks
            if (!name) {
                showNotification('Please enter a full name!', 'error');
                return;
            }
            
            if (!email) {
                showNotification('Please enter an email address!', 'error');
                return;
            }
            
            if (!role) {
                showNotification('Please select a role!', 'error');
                return;
            }
            
            if (!password) {
                showNotification('Please enter a password!', 'error');
                return;
            }
            
            if (password !== passwordConfirm) {
                showNotification('Passwords do not match!', 'error');
                return;
            }
            
            if (password.length < 8) {
                showNotification('Password must be at least 8 characters long!', 'error');
                return;
            }
            
            // Show loading state
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating...';
            submitBtn.disabled = true;
            
            // Collect form data with proper checkbox handling
            const formData = new FormData();
            formData.append('name', name);
            formData.append('email', email);
            formData.append('phone', document.getElementById('userPhone').value);
            formData.append('role', role);
            formData.append('password', password);
            formData.append('password_confirmation', passwordConfirm);
            
            // Handle checkbox value properly
            const isActiveCheckbox = document.getElementById('userActive');
            formData.append('is_active', isActiveCheckbox.checked ? '1' : '0');
            
            try {
                const response = await fetch('/admin/users', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json'
                    }
                });
                
                // Check if response is ok
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Server response error:', response.status, errorText);
                    throw new Error(`Server error: ${response.status}`);
                }
                
                const result = await response.json();
                console.log('Server response:', result);
                
                if (result.success) {
                    showNotification(result.message || 'User created successfully!', 'success');
                    
                    // Reset form and close modal
                    this.reset();
                    document.getElementById('userActive').checked = true; // Reset checkbox
                    
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('addUserModal'));
                    modal.hide();
                    
                    // Refresh page data if needed
                    loadDutySchedules();
                } else {
                    showNotification(result.message || 'Failed to create user', 'error');
                    console.error('Server error response:', result);
                }
            } catch (error) {
                console.error('Error creating user:', error);
                if (error.name === 'TypeError' && error.message.includes('fetch')) {
                    showNotification('Network error. Please check your connection and try again.', 'error');
                } else if (error.message.includes('Server error')) {
                    showNotification('Server error. Please try again later.', 'error');
                } else {
                    showNotification('An error occurred. Please try again.', 'error');
                }
            } finally {
                // Reset button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });
    </script>
</body>
</html>
