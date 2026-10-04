@forelse($schedules as $schedule)
<tr>
    <td>
        <div class="d-flex align-items-center">
            <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-2">
                <i class="fas fa-user text-white"></i>
            </div>
            <div>
                <div class="fw-bold text-white">{{ $schedule->agent->name }}</div>
                <small class="text-light opacity-75">{{ $schedule->agent->email }}</small>
            </div>
        </div>
    </td>
    <td class="text-white">{{ $schedule->title }}</td>
    <td class="text-white">
        <div>
            <div class="fw-bold text-white">{{ $schedule->start_datetime->format('M j, Y') }}</div>
            <small class="text-light opacity-75">{{ $schedule->start_datetime->format('g:i A') }}</small>
        </div>
    </td>
    <td class="text-white">
        <div>
            <div class="fw-bold text-white">{{ $schedule->end_datetime->format('M j, Y') }}</div>
            <small class="text-light opacity-75">{{ $schedule->end_datetime->format('g:i A') }}</small>
            @if($schedule->start_datetime->format('Y-m-d') !== $schedule->end_datetime->format('Y-m-d'))
                <span class="badge bg-warning ms-1" title="Overnight Shift">
                    <i class="fas fa-moon"></i>
                </span>
            @endif
        </div>
    </td>
    <td>
        @php
            $badgeClass = match($schedule->shift_type) {
                'morning' => 'bg-success',
                'afternoon' => 'bg-info', 
                'evening' => 'bg-warning',
                'night' => 'bg-dark',
                'off' => 'bg-secondary',
                default => 'bg-secondary'
            };
        @endphp
        <span class="badge {{ $badgeClass }}">
            {{ ucfirst($schedule->shift_type) }}
        </span>
    </td>
    <td>
        <span class="badge {{ $schedule->is_active ? 'bg-success' : 'bg-danger' }}">
            {{ $schedule->is_active ? 'Active' : 'Inactive' }}
        </span>
    </td>
    <td>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-info btn-sm" onclick="viewSchedule({{ $schedule->id }})" title="View">
                <i class="fas fa-eye"></i>
            </button>
            <button type="button" class="btn btn-outline-warning btn-sm" onclick="editSchedule({{ $schedule->id }})" title="Edit">
                <i class="fas fa-edit"></i>
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteSchedule({{ $schedule->id }}, '{{ $schedule->title }}')" title="Delete">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    </td>
</tr>
@empty
<tr>
    <td colspan="7" class="text-center text-light py-4">
        <div class="d-flex flex-column align-items-center">
            <i class="fas fa-calendar-times fa-2x mb-2 text-warning"></i>
            <p class="mb-0 text-white">No duty schedules found</p>
            <small class="text-light opacity-75">Click "Add Schedule" to create your first schedule</small>
        </div>
    </td>
</tr>
@endforelse
