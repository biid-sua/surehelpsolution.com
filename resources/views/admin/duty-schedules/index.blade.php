<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Duty Schedules - SureHelp Admin</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1E3A8A 0%, #625ED0 50%, #4D8BCC 100%);
            --dark-bg: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
            --card-bg: rgba(255, 255, 255, 0.05);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            background: var(--dark-bg) !important;
            color: var(--text-primary) !important;
            min-height: 100vh !important;
        }

        .card {
            background: var(--card-bg) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 16px !important;
        }

        .card-header {
            background: rgba(255, 255, 255, 0.05) !important;
            border-bottom: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
        }

        .table-dark {
            background: var(--card-bg) !important;
            border-radius: 12px !important;
            overflow: hidden !important;
        }

        .table-dark th {
            background: rgba(255, 255, 255, 0.05) !important;
            border: none !important;
            color: var(--text-primary) !important;
            font-weight: 600 !important;
        }

        .table-dark td {
            border: none !important;
            color: var(--text-secondary) !important;
        }

        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
            border-radius: 12px !important;
            font-weight: 600 !important;
        }

        .container-fluid {
            padding: 2rem !important;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-calendar-alt me-2"></i>Manage Agent Duty Schedules</h2>
                <a href="{{ route('duty-schedules.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus me-2"></i>Add New Schedule
                </a>
            </div>

            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show">
                    {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Agent Duty Schedules</h5>
                </div>
                <div class="card-body">
                    @if($schedules->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Agent</th>
                                        <th>Title</th>
                                        <th>Schedule</th>
                                        <th>Days</th>
                                        <th>Type</th>
                                        <th>Effective Period</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($schedules as $schedule)
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-2">
                                                        <span class="text-white fw-bold">{{ substr($schedule->agent->name, 0, 1) }}</span>
                                                    </div>
                                                    {{ $schedule->agent->name }}
                                                </div>
                                            </td>
                                            <td>{{ $schedule->title }}</td>
                                            <td>
                                                <small class="text-muted">
                                                    {{ \Carbon\Carbon::parse($schedule->start_time)->format('h:i A') }} - 
                                                    {{ \Carbon\Carbon::parse($schedule->end_time)->format('h:i A') }}
                                                </small>
                                            </td>
                                            <td>
                                                @php
                                                    $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                                    $selectedDays = array_map(function($day) use ($dayNames) {
                                                        return $dayNames[$day];
                                                    }, $schedule->days_of_week);
                                                @endphp
                                                <span class="badge bg-secondary">{{ implode(', ', $selectedDays) }}</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $schedule->shift_type === 'morning' ? 'primary' : ($schedule->shift_type === 'afternoon' ? 'info' : ($schedule->shift_type === 'night' ? 'purple' : 'secondary')) }}">
                                                    {{ ucfirst($schedule->shift_type) }}
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    @if($schedule->effective_from && $schedule->effective_until)
                                                        {{ $schedule->effective_from->format('M d, Y') }} - {{ $schedule->effective_until->format('M d, Y') }}
                                                    @elseif($schedule->effective_from)
                                                        From {{ $schedule->effective_from->format('M d, Y') }}
                                                    @else
                                                        Ongoing
                                                    @endif
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-{{ $schedule->is_active ? 'success' : 'danger' }}">
                                                    {{ $schedule->is_active ? 'Active' : 'Inactive' }}
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="{{ route('duty-schedules.show', $schedule) }}" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="{{ route('duty-schedules.edit', $schedule) }}" class="btn btn-sm btn-outline-warning">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <form action="{{ route('duty-schedules.destroy', $schedule) }}" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this schedule?')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No duty schedules found</h5>
                            <p class="text-muted">Start by creating a new duty schedule for your agents.</p>
                            <a href="{{ route('duty-schedules.create') }}" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i>Create First Schedule
                            </a>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
