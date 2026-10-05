<x-auth-layout title="Choose a new password" description="This signs you out on every other device, including the mobile app.">
    <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email" class="sh-label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" class="sh-input" required autocomplete="username">
        </div>
        <div>
            <label for="password" class="sh-label">New password</label>
            <input id="password" name="password" type="password" class="sh-input" required minlength="8" autocomplete="new-password" aria-describedby="password-hint" autofocus>
            <p id="password-hint" class="mt-1 text-xs text-subtle">At least 8 characters. A short sentence is easy to remember and hard to guess.</p>
        </div>
        <div>
            <label for="password_confirmation" class="sh-label">Repeat new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" class="sh-input" required minlength="8" autocomplete="new-password">
        </div>
        <x-ui.button type="submit" class="w-full">Save new password</x-ui.button>
    </form>
</x-auth-layout>
