<x-auth-layout title="Forgot your password?" description="Enter the email you sign in with and we'll send you a link to choose a new password.">
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email" class="sh-label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="sh-input" required autocomplete="username" autofocus>
        </div>
        <x-ui.button type="submit" class="w-full">Email me a reset link</x-ui.button>
    </form>

    <x-slot:footer>
        Remembered it? <a href="{{ route('login') }}" class="text-brand-300 hover:underline">Back to sign in</a>
    </x-slot:footer>
</x-auth-layout>
