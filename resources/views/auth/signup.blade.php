@if (! $enabled)
    <x-auth-layout title="Sign-ups are closed for now" description="We're not taking new sign-ups online at the moment. Tell us about your business and we'll set you up.">
        <x-ui.button :href="route('home').'#contact'" class="mt-6 w-full">Contact us</x-ui.button>
        <x-slot:footer>
            Already with SureHelp? <a href="{{ route('login') }}" class="text-brand-300 hover:underline">Sign in</a>
        </x-slot:footer>
    </x-auth-layout>
@else
    <x-auth-layout title="Start your free trial" description="Create your business account in a minute. We'll help you set up your phone line and go live." width="max-w-lg">
        <form method="POST" action="{{ route('signup.store') }}" class="mt-6 space-y-4">
            @csrf
            @if ($plans->isNotEmpty())
                <fieldset>
                    <legend class="sh-label">Plan</legend>
                    <div class="space-y-2">
                        @foreach ($plans as $plan)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-line p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-500/10">
                                <input type="radio" name="plan" value="{{ $plan->slug }}" @checked($selected === $plan->slug) required class="mt-1 size-4 border-line-strong bg-surface-2 text-brand-500">
                                <span class="min-w-0 flex-1">
                                    <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <span class="font-medium text-ink">{{ $plan->name }}</span>
                                        <span class="text-sm text-ink">{{ $plan->priceLabel() }}</span>
                                    </span>
                                    @if ($plan->tagline)
                                        <span class="mt-0.5 block text-sm text-muted">{{ $plan->tagline }}</span>
                                    @elseif ($plan->highlights())
                                        <span class="mt-0.5 block text-sm text-muted">{{ implode(' · ', array_slice($plan->highlights(), 0, 3)) }}</span>
                                    @endif
                                    @if ($plan->trial_days > 0)<span class="mt-1 block text-xs text-brand-300">{{ $plan->trial_days }}-day free trial</span>@endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1.5 text-xs text-subtle">Nothing is charged during the free trial. You can change plan any time.</p>
                </fieldset>
            @endif
            <div>
                <label for="business" class="sh-label">Business name</label>
                <input id="business" name="business" type="text" value="{{ old('business') }}" class="sh-input" required maxlength="120" autocomplete="organization" autofocus>
            </div>
            <div>
                <label for="name" class="sh-label">Your full name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" class="sh-input" required autocomplete="name">
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="email" class="sh-label">Email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" class="sh-input" required autocomplete="email">
                </div>
                <div>
                    <label for="phone" class="sh-label">Phone <span class="font-normal text-subtle">(optional)</span></label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" class="sh-input" maxlength="30" autocomplete="tel">
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="password" class="sh-label">Choose a password</label>
                    <input id="password" name="password" type="password" class="sh-input" required minlength="8" autocomplete="new-password">
                    <p class="mt-1 text-xs text-subtle">At least 8 characters.</p>
                </div>
                <div>
                    <label for="password_confirmation" class="sh-label">Repeat password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="sh-input" required minlength="8" autocomplete="new-password">
                </div>
            </div>
            {{-- Left empty by people; bots that fill every field give themselves away. --}}
            <div class="hidden" aria-hidden="true">
                <label for="{{ \App\Http\Controllers\Account\SignupController::TRAP }}">Website</label>
                <input id="{{ \App\Http\Controllers\Account\SignupController::TRAP }}" name="{{ \App\Http\Controllers\Account\SignupController::TRAP }}" type="text" tabindex="-1" autocomplete="off">
            </div>
            <label class="flex items-start gap-3 text-sm text-ink">
                <input type="checkbox" name="accept" value="1" required class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                <span>I accept the
                    @foreach ($documents as $doc)
                        <a href="{{ route($doc['route']) }}" target="_blank" rel="noopener" class="text-brand-300 hover:underline">{{ $doc['label'] }}</a>{{ $loop->remaining > 1 ? ',' : ($loop->remaining === 1 ? ' and' : '.') }}
                    @endforeach
                </span>
            </label>
            <x-ui.button type="submit" class="w-full">Create my account</x-ui.button>
        </form>

        <x-slot:footer>
            Already with SureHelp? <a href="{{ route('login') }}" class="text-brand-300 hover:underline">Sign in</a>
        </x-slot:footer>
    </x-auth-layout>
@endif
