<x-auth-layout title="Choose your password" description="Welcome, {{ auth()->user()->name }}. Before you start, replace the temporary password you were given with one only you know.">
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

    <x-slot:footer>
        Not you? <a href="{{ route('auth.logout') }}" class="text-brand-300 hover:underline">Sign out</a>
    </x-slot:footer>
</x-auth-layout>
