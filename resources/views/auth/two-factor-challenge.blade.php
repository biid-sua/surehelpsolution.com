<x-auth-layout title="Two-step sign-in" description="Open your authenticator app and enter the 6-digit code for SureHelp.">
    <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-6 space-y-4" x-data="{ recovery: false }">
        @csrf
        <div x-show="!recovery">
            <label for="code" class="sh-label">Code</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" class="sh-input text-center font-mono text-lg tracking-[0.4em]"
                autocomplete="one-time-code" autofocus :disabled="recovery" :required="!recovery">
        </div>
        <div x-show="recovery" x-cloak>
            <label for="recovery_code" class="sh-label">Recovery code</label>
            <input id="recovery_code" name="recovery_code" type="text" class="sh-input font-mono" placeholder="abcde-12345" autocomplete="off" :disabled="!recovery" :required="recovery">
            <p class="mt-1 text-xs text-subtle">Each recovery code works once.</p>
        </div>
        <x-ui.button type="submit" class="w-full">Continue</x-ui.button>
        <button type="button" class="w-full text-center text-sm text-brand-300 hover:underline" x-on:click="recovery = !recovery"
            x-text="recovery ? 'Use a code from my app instead' : 'Lost your phone? Use a recovery code'"></button>
    </form>

    <x-slot:footer>
        No phone and no recovery codes? Ask a SureHelp admin to reset your two-step sign-in. · <a href="{{ route('login') }}" class="text-brand-300 hover:underline">Start over</a>
    </x-slot:footer>
</x-auth-layout>
