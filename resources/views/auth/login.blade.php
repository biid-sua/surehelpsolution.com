<x-auth-layout title="Sign in" description="Business owners, agents and SureHelp staff all sign in here.">
    @if ($notice)
        <x-ui.alert tone="info" class="mt-5">{{ $notice }}</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('auth.login') }}" class="mt-6 space-y-4">
        @csrf
        <div>
            <label for="email" class="sh-label">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="sh-input" required autocomplete="username" autofocus>
        </div>
        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="sh-label">Password</label>
                <a href="{{ route('password.request') }}" class="mb-1.5 text-sm text-brand-300 hover:underline">Forgot password?</a>
            </div>
            <input id="password" name="password" type="password" class="sh-input" required autocomplete="current-password">
        </div>
        <x-ui.button type="submit" class="w-full">Sign in</x-ui.button>
    </form>

    <x-slot:footer>
        New to SureHelp? <a href="{{ route('home') }}#contact" class="text-brand-300 hover:underline">Talk to us</a>
    </x-slot:footer>
</x-auth-layout>
