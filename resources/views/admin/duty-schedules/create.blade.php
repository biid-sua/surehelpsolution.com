<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Create Duty Schedule - SureHelp Admin</title>
    
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

        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
        }

        .form-select option {
            background: #1a1a3e !important;
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

        h2, h5 {
            color: var(--text-primary) !important;
        }

        small {
            color: var(--text-secondary) !important;
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

        .alert-warning {
            background: rgba(245, 158, 11, 0.1) !important;
            border: 1px solid rgba(245, 158, 11, 0.3) !important;
            color: #fcd34d !important;
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
                <h2><i class="fas fa-calendar-plus me-2"></i>Create New Duty Schedule</h2>
                <a href="{{ route('duty-schedules.index') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Schedules
                </a>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Schedule Details</h5>
                </div>
                <div class="card-body">
                    <form action="{{ route('duty-schedules.store') }}" method="POST">
                        @csrf
                        
                        <!-- Agent Selection -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="agent_id" class="form-label">Select Agent <span class="text-danger">*</span></label>
                                    <select class="form-select @error('agent_id') is-invalid @enderror" id="agent_id" name="agent_id" required>
                                        <option value="">Choose an agent...</option>
                                        @foreach($agents as $agent)
                                            <option value="{{ $agent->id }}" {{ old('agent_id') == $agent->id ? 'selected' : '' }}>
                                                {{ $agent->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('agent_id')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="title" class="form-label">Schedule Title <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title') }}" placeholder="e.g., Morning Shift, Night Coverage, etc." required>
                                    @error('title')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <!-- Schedule Type -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="shift_type" class="form-label">Shift Type <span class="text-danger">*</span></label>
                                    <select class="form-select @error('shift_type') is-invalid @enderror" id="shift_type" name="shift_type" required>
                                        <option value="">Select shift type...</option>
                                        <option value="morning" {{ old('shift_type') == 'morning' ? 'selected' : '' }}>Morning</option>
                                        <option value="afternoon" {{ old('shift_type') == 'afternoon' ? 'selected' : '' }}>Afternoon</option>
                                        <option value="evening" {{ old('shift_type') == 'evening' ? 'selected' : '' }}>Evening</option>
                                        <option value="night" {{ old('shift_type') == 'night' ? 'selected' : '' }}>Night</option>
                                        <option value="off" {{ old('shift_type') == 'off' ? 'selected' : '' }}>Off Duty</option>
                                    </select>
                                    @error('shift_type')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <!-- Duty Time Slot -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="start_datetime" class="form-label">Start Date & Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control @error('start_datetime') is-invalid @enderror" id="start_datetime" name="start_datetime" value="{{ old('start_datetime') }}" required>
                                    @error('start_datetime')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="end_datetime" class="form-label">End Date & Time <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control @error('end_datetime') is-invalid @enderror" id="end_datetime" name="end_datetime" value="{{ old('end_datetime') }}" required>
                                    @error('end_datetime')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>

                        <!-- Working Hours Alert -->
                        <div class="alert alert-info" id="workingHoursAlert" style="display: none;">
                            <i class="fas fa-clock me-2"></i>
                            <strong>Working Hours:</strong> <span id="workingHoursText"></span> allocated for this duty schedule.
                        </div>

                        <!-- Overnight Shift Alert -->
                        <div class="alert alert-warning" id="overnightAlert" style="display: none;">
                            <i class="fas fa-moon me-2"></i>
                            <strong>Overnight Shift Detected:</strong> This schedule spans multiple days. Make sure the end date is correct.
                        </div>

                        <!-- Notes -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description (Optional)</label>
                            <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="3" placeholder="Add any additional notes or instructions for this duty schedule...">{{ old('description') }}</textarea>
                            @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="{{ route('duty-schedules.index') }}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Create Schedule
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Form elements
    const startDatetimeInput = document.getElementById('start_datetime');
    const endDatetimeInput = document.getElementById('end_datetime');
    const titleInput = document.getElementById('title');
    const shiftTypeSelect = document.getElementById('shift_type');
    const workingHoursAlert = document.getElementById('workingHoursAlert');
    const workingHoursText = document.getElementById('workingHoursText');
    const overnightAlert = document.getElementById('overnightAlert');
    
    // Set default values
    const now = new Date();
    const tomorrow = new Date(now);
    tomorrow.setDate(now.getDate() + 1);
    tomorrow.setHours(8, 0, 0, 0);
    
    const endTime = new Date(tomorrow);
    endTime.setHours(16, 0, 0, 0);
    
    // Format datetime for input (YYYY-MM-DDTHH:MM)
    const formatDateTime = (date) => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    };
    
    // Set default values if not already set
    if (!startDatetimeInput.value) {
        startDatetimeInput.value = formatDateTime(tomorrow);
    }
    if (!endDatetimeInput.value) {
        endDatetimeInput.value = formatDateTime(endTime);
    }
    
    // Calculate and display working hours
    function calculateWorkingHours() {
        const startDatetime = startDatetimeInput.value;
        const endDatetime = endDatetimeInput.value;
        
        if (startDatetime && endDatetime) {
            const startDate = new Date(startDatetime);
            const endDate = new Date(endDatetime);
            
            // Calculate difference in milliseconds
            const diffMs = endDate - startDate;
            
            if (diffMs > 0) {
                // Convert to hours
                const diffHours = Math.round(diffMs / (1000 * 60 * 60) * 10) / 10;
                
                workingHoursText.textContent = `${diffHours} hours`;
                workingHoursAlert.style.display = 'block';
                
                // Check if it's an overnight shift
                const startDateStr = startDate.toDateString();
                const endDateStr = endDate.toDateString();
                
                if (startDateStr !== endDateStr) {
                    overnightAlert.style.display = 'block';
                } else {
                    overnightAlert.style.display = 'none';
                }
                
                // Auto-suggest shift type and title based on time
                autoSuggestShiftType(startDate);
            } else {
                workingHoursAlert.style.display = 'none';
                overnightAlert.style.display = 'none';
            }
        } else {
            workingHoursAlert.style.display = 'none';
            overnightAlert.style.display = 'none';
        }
    }
    
    // Auto-suggest shift type and title based on time
    function autoSuggestShiftType(startDate) {
        const hour = startDate.getHours();
        
        let shiftType = '';
        let title = '';
        
        if (hour >= 6 && hour < 12) {
            shiftType = 'morning';
            title = 'Morning Shift';
        } else if (hour >= 12 && hour < 18) {
            shiftType = 'afternoon';
            title = 'Afternoon Shift';
        } else if (hour >= 18 && hour < 22) {
            shiftType = 'evening';
            title = 'Evening Shift';
        } else {
            shiftType = 'night';
            title = 'Night Shift';
        }
        
        // Only set if not already selected
        if (!shiftTypeSelect.value) {
            shiftTypeSelect.value = shiftType;
        }
        if (!titleInput.value) {
            titleInput.value = title;
        }
    }
    
    // Event listeners
    startDatetimeInput.addEventListener('change', calculateWorkingHours);
    endDatetimeInput.addEventListener('change', calculateWorkingHours);
    
    // Initialize working hours calculation
    calculateWorkingHours();
    
    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const startDatetime = new Date(startDatetimeInput.value);
        const endDatetime = new Date(endDatetimeInput.value);
        
        if (endDatetime <= startDatetime) {
            e.preventDefault();
            alert('End date and time must be after start date and time.');
            return false;
        }
        
        // Check if the schedule is too long (more than 24 hours)
        const diffMs = endDatetime - startDatetime;
        const diffHours = diffMs / (1000 * 60 * 60);
        
        if (diffHours > 24) {
            if (!confirm('This schedule is longer than 24 hours. Are you sure you want to create it?')) {
                e.preventDefault();
                return false;
            }
        }
    });
});
</script>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
