<x-auth-layout title="Check your email" description="We sent a confirmation link to {{ $user->email }}. Open it to start using SureHelp.">
    <p class="mt-5 text-sm text-muted">Can't find it? Check your spam folder, or send a new link. Wrong address? Sign out and sign up again with the right one.</p>
    <form method="POST" action="{{ route('account.verification.send') }}" class="mt-6">
        @csrf
        <x-ui.button type="submit" class="w-full">Send a new link</x-ui.button>
    </form>

    <x-slot:footer>
        <a href="{{ route('auth.logout') }}" class="text-brand-300 hover:underline">Sign out</a>
    </x-slot:footer>
</x-auth-layout>
