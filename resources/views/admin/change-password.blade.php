<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Change Password - Sure Help</title>

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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .change-password-card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            max-width: 480px;
            width: 100%;
            padding: 2rem;
        }

        .form-control, .form-control:focus {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--card-border);
            color: var(--text-primary);
        }

        .form-control:focus {
            box-shadow: 0 0 0 0.2rem rgba(98, 94, 208, 0.25);
        }

        .form-label {
            color: var(--text-secondary);
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            border-radius: 12px;
            font-weight: 600;
        }

        .alert-info {
            background: rgba(98, 94, 208, 0.2);
            border-color: rgba(98, 94, 208, 0.4);
            color: #c4b5fd;
        }
    </style>
</head>
<body>
    <div class="change-password-card">
        <div class="text-center mb-4">
            <i class="fas fa-key fa-2x mb-3" style="color: #625ED0;"></i>
            <h3 class="mb-2">Change Your Password</h3>
            <p class="text-secondary mb-0">For security, you must set a new password before accessing your dashboard.</p>
        </div>

        @if($isForced ?? false)
            <div class="alert alert-info mb-4">
                <i class="fas fa-info-circle me-2"></i>
                Use the default password provided by your administrator as your current password.
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <form method="POST" action="{{ route('password.change.update') }}">
            @csrf
            <div class="mb-3">
                <label for="current_password" class="form-label">Current Password</label>
                <input type="password" class="form-control" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <input type="password" class="form-control" id="password" name="password" required minlength="8" autocomplete="new-password">
                <div class="form-text text-secondary">Minimum 8 characters</div>
            </div>
            <div class="mb-4">
                <label for="password_confirmation" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-check me-2"></i>Update Password
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="{{ route('auth.logout') }}" class="text-secondary text-decoration-none">
                <i class="fas fa-sign-out-alt me-1"></i>Logout
            </a>
        </div>
    </div>
</body>
</html>
