<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Duty Schedule - SureHelp Admin</title>
    
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

        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
            border-radius: 12px !important;
            font-weight: 600 !important;
        }

        .btn-primary:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3) !important;
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

        h2, h5 {
            color: var(--text-primary) !important;
        }

        small {
            color: var(--text-secondary) !important;
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
                <h2><i class="fas fa-calendar-alt me-2"></i>Duty Schedule Details</h2>
                <div class="d-flex gap-2">
                    <a href="{{ route('duty-schedules.edit', $dutySchedule) }}" class="btn btn-warning">
                        <i class="fas fa-edit me-2"></i>Edit
                    </a>
                    <a href="{{ route('duty-schedules.index') }}" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Schedules
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Schedule Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Agent</label>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-2">
                                                <span class="text-white fw-bold">{{ substr($dutySchedule->agent->name, 0, 1) }}</span>
                                            </div>
                                            <span>{{ $dutySchedule->agent->name }}</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Schedule Title</label>
                                        <p class="mb-0">{{ $dutySchedule->title }}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Start Date & Time</label>
                                        <p class="mb-0">
                                            {{ $dutySchedule->start_datetime->format('M j, Y h:i A') }}
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">End Date & Time</label>
                                        <p class="mb-0">
                                            {{ $dutySchedule->end_datetime->format('M j, Y h:i A') }}
                                            @if($dutySchedule->start_datetime->format('Y-m-d') !== $dutySchedule->end_datetime->format('Y-m-d'))
                                                <span class="badge bg-warning ms-2" title="Overnight Shift">
                                                    <i class="fas fa-moon"></i> Overnight
                                                </span>
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Shift Type</label>
                                        <p class="mb-0">
                                            @php
                                                $badgeClass = match($dutySchedule->shift_type) {
                                                    'morning' => 'bg-success',
                                                    'afternoon' => 'bg-info', 
                                                    'evening' => 'bg-warning',
                                                    'night' => 'bg-dark',
                                                    'off' => 'bg-secondary',
                                                    default => 'bg-secondary'
                                                };
                                            @endphp
                                            <span class="badge {{ $badgeClass }}">
                                                {{ ucfirst($dutySchedule->shift_type) }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>


                            @if($dutySchedule->description)
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Description</label>
                                    <p class="mb-0">{{ $dutySchedule->description }}</p>
                                </div>
                            @endif

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Status</label>
                                        <p class="mb-0">
                                            <span class="badge bg-{{ $dutySchedule->is_active ? 'success' : 'danger' }}">
                                                {{ $dutySchedule->is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Duration</label>
                                        <p class="mb-0">
                                            @php
                                                $duration = $dutySchedule->start_datetime->diffInHours($dutySchedule->end_datetime);
                                                $minutes = $dutySchedule->start_datetime->diffInMinutes($dutySchedule->end_datetime) % 60;
                                            @endphp
                                            {{ $duration }}h {{ $minutes }}m
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Schedule Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="{{ route('duty-schedules.edit', $dutySchedule) }}" class="btn btn-warning">
                                    <i class="fas fa-edit me-2"></i>Edit Schedule
                                </a>
                                
                                <form action="{{ route('duty-schedules.destroy', $dutySchedule) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this schedule? This action cannot be undone.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger w-100">
                                        <i class="fas fa-trash me-2"></i>Delete Schedule
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0">Schedule Statistics</h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-6">
                                    <h4 class="text-primary mb-1">
                                        @php
                                            $duration = $dutySchedule->start_datetime->diffInHours($dutySchedule->end_datetime);
                                        @endphp
                                        {{ $duration }}
                                    </h4>
                                    <small class="text-muted">Total Hours</small>
                                </div>
                                <div class="col-6">
                                    <h4 class="text-success mb-1">
                                        @if($dutySchedule->start_datetime->format('Y-m-d') !== $dutySchedule->end_datetime->format('Y-m-d'))
                                            <i class="fas fa-moon text-warning"></i>
                                        @else
                                            <i class="fas fa-sun text-warning"></i>
                                        @endif
                                    </h4>
                                    <small class="text-muted">
                                        @if($dutySchedule->start_datetime->format('Y-m-d') !== $dutySchedule->end_datetime->format('Y-m-d'))
                                            Overnight Shift
                                        @else
                                            Same Day
                                        @endif
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0">Created Information</h5>
                        </div>
                        <div class="card-body">
                            <small class="text-muted">
                                <strong>Created:</strong> {{ $dutySchedule->created_at->format('M d, Y \a\t h:i A') }}<br>
                                <strong>Last Updated:</strong> {{ $dutySchedule->updated_at->format('M d, Y \a\t h:i A') }}
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
