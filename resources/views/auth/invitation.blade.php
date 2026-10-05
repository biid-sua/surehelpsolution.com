@if (! $invitation)
    <x-auth-layout title="Invitation not valid" description="This invitation has expired, was already used, or was replaced by a newer one. Ask the business owner to invite you again.">
        <x-ui.button :href="route('login')" variant="secondary" class="mt-6 w-full">Go to sign in</x-ui.button>
    </x-auth-layout>
@elseif ($signedInAs)
    <x-auth-layout title="You're signed in as someone else" description="This invitation creates a new account for {{ $invitation->email }}. Sign out first, then open the link again.">
        <x-ui.button :href="route('auth.logout')" class="mt-6 w-full">Sign out</x-ui.button>
    </x-auth-layout>
@else
    <x-auth-layout title="Join {{ $invitation->organization->name }}"
        description="{{ $invitation->inviter?->name ?? 'The business owner' }} invited you to join {{ $invitation->organization->name }} on SureHelp as {{ mb_strtolower($invitation->roleLabel()) }}.">
        <form method="POST" action="{{ route('invitations.accept', $token) }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <p class="sh-label">Email</p>
                <p class="text-sm text-ink">{{ $invitation->email }}</p>
            </div>
            <div>
                <label for="name" class="sh-label">Your full name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" class="sh-input" required autocomplete="name" autofocus>
            </div>
            <div>
                <label for="password" class="sh-label">Choose a password</label>
                <input id="password" name="password" type="password" class="sh-input" required minlength="8" autocomplete="new-password">
                <p class="mt-1 text-xs text-subtle">At least 8 characters.</p>
            </div>
            <div>
                <label for="password_confirmation" class="sh-label">Repeat password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="sh-input" required minlength="8" autocomplete="new-password">
            </div>
            <label class="flex items-start gap-3 text-sm text-ink">
                <input type="checkbox" name="accept" value="1" required class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                <span>I accept the
                    @foreach ($documents as $doc)
                        <a href="{{ route($doc['route']) }}" target="_blank" rel="noopener" class="text-brand-300 hover:underline">{{ $doc['label'] }}</a>{{ $loop->remaining > 1 ? ',' : ($loop->remaining === 1 ? ' and' : '.') }}
                    @endforeach
                </span>
            </label>
            <x-ui.button type="submit" class="w-full">Join the team</x-ui.button>
        </form>
    </x-auth-layout>
@endif
