<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>User Management - Sure Help Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            font-family: 'Inter', sans-serif;
            background: var(--dark-bg);
            color: var(--text-primary);
            min-height: 100vh;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
        }

        .card-header {
            background: rgba(255, 255, 255, 0.05);
            border-bottom: 1px solid var(--card-border);
            color: var(--text-primary);
        }

        .table {
            --bs-table-bg: transparent;
            --bs-table-color: var(--text-secondary);
        }

        .table th {
            color: var(--text-primary);
            border-color: var(--card-border);
        }

        .table td {
            border-color: var(--card-border);
            vertical-align: middle;
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 12px;
            font-weight: 600;
        }

        .btn-secondary, .btn-outline-light {
            color: var(--text-primary);
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--card-border);
            border-radius: 12px;
        }

        .badge-agent { background: rgba(16, 185, 129, 0.3); color: #6ee7b7; }
        .badge-client { background: rgba(59, 130, 246, 0.3); color: #93c5fd; }
        .badge-pending { background: rgba(245, 158, 11, 0.3); color: #fcd34d; }
        .badge-active { background: rgba(16, 185, 129, 0.3); color: #6ee7b7; }
        .badge-inactive { background: rgba(239, 68, 68, 0.3); color: #fca5a5; }

        .btn-activate { color: #6ee7b7; border-color: rgba(16, 185, 129, 0.5); }
        .btn-deactivate { color: #fca5a5; border-color: rgba(239, 68, 68, 0.5); }

        .actions-cell { white-space: nowrap; }

        .container-fluid { padding: 2rem; }

        .modal-content {
            background: rgba(15, 15, 35, 0.95);
            border: 1px solid var(--card-border);
        }

        .modal-header, .modal-footer {
            border-color: var(--card-border);
        }

        .form-control, .form-select {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--card-border);
            color: var(--text-primary);
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1"><i class="fas fa-users me-2"></i>User Management</h2>
            <p class="text-secondary mb-0">Manage agents and clients. Activate or deactivate accounts, reset passwords, and require a change on next login.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.dashboard') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Dashboard
            </a>
            <a href="{{ route('admin.dashboard') }}#add-user" class="btn btn-primary">
                <i class="fas fa-user-plus me-2"></i>Add User
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Agents & Clients</h5>
            <span class="text-secondary">{{ $users->count() }} users</span>
        </div>
        <div class="card-body">
            @if($users->count() > 0)
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>ID</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Password</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($users as $user)
                                <tr>
                                    <td>{{ $user->name }}</td>
                                    <td>{{ $user->email }}</td>
                                    <td><code>{{ $user->unique_id }}</code></td>
                                    <td>
                                        <span class="badge {{ $user->role === 'agent' ? 'badge-agent' : 'badge-client' }}">
                                            {{ ucfirst($user->role) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($user->is_active)
                                            <span class="badge badge-active status-badge" data-user-id="{{ $user->id }}">Active</span>
                                        @else
                                            <span class="badge badge-inactive status-badge" data-user-id="{{ $user->id }}">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($user->must_change_password)
                                            <span class="badge badge-pending">Must change on login</span>
                                        @else
                                            <span class="text-secondary">Set by user</span>
                                        @endif
                                    </td>
                                    <td class="actions-cell">
                                        <div class="d-flex gap-1 flex-wrap">
                                            @if($user->is_active)
                                                <button type="button"
                                                        class="btn btn-outline-light btn-sm btn-deactivate toggle-status-btn"
                                                        data-user-id="{{ $user->id }}"
                                                        data-user-name="{{ $user->name }}"
                                                        data-action="deactivate">
                                                    <i class="fas fa-user-slash me-1"></i>Deactivate
                                                </button>
                                            @else
                                                <button type="button"
                                                        class="btn btn-outline-light btn-sm btn-activate toggle-status-btn"
                                                        data-user-id="{{ $user->id }}"
                                                        data-user-name="{{ $user->name }}"
                                                        data-action="activate">
                                                    <i class="fas fa-user-check me-1"></i>Activate
                                                </button>
                                            @endif
                                            <button type="button"
                                                    class="btn btn-outline-light btn-sm reset-password-btn"
                                                    data-user-id="{{ $user->id }}"
                                                    data-user-name="{{ $user->name }}"
                                                    data-user-email="{{ $user->email }}">
                                                <i class="fas fa-key me-1"></i>Reset Password
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-5 text-secondary">
                    <i class="fas fa-users fa-3x mb-3"></i>
                    <p class="mb-0">No agents or clients yet. Add users from the dashboard.</p>
                </div>
            @endif
        </div>
    </div>
</div>

<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-white">
                    <i class="fas fa-key me-2"></i>Reset Password
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="resetPasswordForm">
                <div class="modal-body">
                    <p class="text-secondary">
                        Set a new default password for <strong id="resetUserName" class="text-white"></strong>
                        (<span id="resetUserEmail"></span>). Share it manually with the user — they must change it on next login.
                    </p>
                    <input type="hidden" id="resetUserId" name="user_id">
                    <div class="mb-3">
                        <label for="resetPassword" class="form-label">New Default Password</label>
                        <input type="password" class="form-control" id="resetPassword" name="password" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label for="resetPasswordConfirm" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="resetPasswordConfirm" name="password_confirmation" required minlength="8">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));

    document.querySelectorAll('.reset-password-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.getElementById('resetUserId').value = this.dataset.userId;
            document.getElementById('resetUserName').textContent = this.dataset.userName;
            document.getElementById('resetUserEmail').textContent = this.dataset.userEmail;
            document.getElementById('resetPasswordForm').reset();
            resetModal.show();
        });
    });

    document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', async function () {
            const userId = this.dataset.userId;
            const userName = this.dataset.userName;
            const action = this.dataset.action;
            const confirmMessage = action === 'deactivate'
                ? `Deactivate ${userName}? They will not be able to log in until reactivated.`
                : `Activate ${userName}? They will be able to log in again.`;

            if (!confirm(confirmMessage)) {
                return;
            }

            const originalHtml = this.innerHTML;
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            try {
                const response = await fetch(`/admin/users/${userId}/toggle-status`, {
                    method: 'PATCH',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const result = await response.json();

                if (result.success) {
                    window.location.reload();
                } else {
                    alert(result.message || 'Failed to update account status.');
                    this.disabled = false;
                    this.innerHTML = originalHtml;
                }
            } catch (error) {
                alert('An error occurred. Please try again.');
                this.disabled = false;
                this.innerHTML = originalHtml;
            }
        });
    });

    document.getElementById('resetPasswordForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const userId = document.getElementById('resetUserId').value;
        const password = document.getElementById('resetPassword').value;
        const passwordConfirm = document.getElementById('resetPasswordConfirm').value;
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalHtml = submitBtn.innerHTML;

        if (password !== passwordConfirm) {
            alert('Passwords do not match.');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

        try {
            const formData = new FormData();
            formData.append('password', password);
            formData.append('password_confirmation', passwordConfirm);

            const response = await fetch(`/admin/users/${userId}/reset-password`, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });

            const result = await response.json();

            if (result.success) {
                resetModal.hide();
                alert(result.message);
                window.location.reload();
            } else {
                alert(result.message || 'Failed to reset password.');
            }
        } catch (error) {
            alert('An error occurred. Please try again.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHtml;
        }
    });
</script>
</body>
</html>
