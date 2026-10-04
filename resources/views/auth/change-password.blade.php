<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Choose your password · SureHelp</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <img src="{{ asset('assets/img/logo.png') }}" alt="SureHelp Solutions" class="mx-auto h-9 w-auto">

            <x-ui.card class="mt-8">
                <h1 class="text-xl font-semibold text-ink">Choose your password</h1>
                <p class="mt-1 text-sm text-muted">Welcome, {{ auth()->user()->name }}. Before you start, replace the temporary password you were given with one only you know.</p>

                @if ($errors->any())
                    <x-ui.alert tone="danger" class="mt-5">
                        <ul class="list-disc pl-4">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                @endif

                <form method="POST" action="{{ route('password.change.update') }}" class="mt-6 space-y-4">
                    @csrf
                    <div>
                        <label for="current_password" class="sh-label">Temporary password</label>
                        <input id="current_password" name="current_password" type="password" class="sh-input" required autocomplete="current-password" autofocus>
                    </div>
                    <div>
                        <label for="password" class="sh-label">New password</label>
                        <input id="password" name="password" type="password" class="sh-input" required minlength="8" autocomplete="new-password" aria-describedby="password-hint">
                        <p id="password-hint" class="mt-1 text-xs text-subtle">At least 8 characters. A short sentence is easy to remember and hard to guess.</p>
                    </div>
                    <div>
                        <label for="password_confirmation" class="sh-label">Repeat new password</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" class="sh-input" required minlength="8" autocomplete="new-password">
                    </div>
                    <x-ui.button type="submit" class="w-full">Save and continue</x-ui.button>
                </form>
            </x-ui.card>

            <p class="mt-6 text-center text-sm text-muted">
                Not you? <a href="{{ route('auth.logout') }}" class="text-brand-300 hover:underline">Sign out</a>
            </p>
        </div>
    </main>
</body>
</html>
