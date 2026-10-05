@php
    $keys = array_keys($steps);
    $pct = (int) round($count['done'] / max(1, $count['total']) * 100);
    $err = fn (string $key) => $errors->first($key);
@endphp
<div>
    <x-ui.page-header title="{{ $organization->isSetUp() ? 'Your setup' : 'Set up SureHelp' }}"
        description="{{ $organization->isSetUp() ? 'Everything you told us when you started. Change anything here or on the Business pages.' : 'A few short steps so our receptionists can answer for '.$organization->name.' like your own team would. Your progress is saved after every step.' }}" />

    <div class="grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
        {{-- Steps --}}
        <nav aria-label="Setup steps">
            <div class="mb-4">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium text-ink">{{ $count['done'] }} of {{ $count['total'] }} steps</span>
                    <span class="text-subtle">{{ $pct }}%</span>
                </div>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-2" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" aria-label="Setup progress">
                    <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $pct }}%"></div>
                </div>
            </div>
            <ol class="space-y-1">
                @foreach ($steps as $key => [$title, $hint, $optional])
                    @php $state = $progress->state($organization, $key); @endphp
                    <li>
                        <button type="button" wire:click="go('{{ $key }}')" @class([
                            'flex w-full items-start gap-3 rounded-xl px-3 py-2.5 text-left transition-colors',
                            'bg-brand-500/15 ring-1 ring-inset ring-brand-500/30' => $step === $key,
                            'hover:bg-surface-2' => $step !== $key,
                        ]) @if ($step === $key) aria-current="step" @endif>
                            <span @class([
                                'mt-0.5 grid size-6 shrink-0 place-items-center rounded-full text-xs font-semibold',
                                'bg-emerald-500 text-white' => $state === 'done',
                                'bg-surface-3 text-subtle' => $state === 'skipped',
                                'bg-brand-600 text-white' => ! $state && $step === $key,
                                'bg-surface-2 text-muted ring-1 ring-line' => ! $state && $step !== $key,
                            ])>
                                @if ($state === 'done')<x-ui.icon name="check-circle" class="size-4" />@else{{ $loop->iteration }}@endif
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-ink">{{ $title }}</span>
                                <span class="block text-xs text-subtle">{{ $state === 'skipped' ? 'Skipped: you can come back any time' : ($optional ? 'Optional' : 'Required') }}</span>
                            </span>
                        </button>
                    </li>
                @endforeach
            </ol>
        </nav>

        {{-- Current step --}}
        <x-ui.card :title="$steps[$step][0]" :description="$steps[$step][1]">
            @if ($step === 'business')
                <form wire:submit="saveBusiness" class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="b-name" class="sh-label">Business name</label>
                        <input id="b-name" type="text" wire:model="business.name" class="sh-input">
                        @error('business.name') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-industry" class="sh-label">Industry</label>
                        <select id="b-industry" wire:model="business.industry" class="sh-input">
                            <option value="">Choose…</option>
                            @foreach ($industries as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-subtle">We'll suggest common services and questions for your industry.</p>
                        @error('business.industry') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-tz" class="sh-label">Timezone</label>
                        <select id="b-tz" wire:model="business.timezone" class="sh-input">
                            <option value="">Choose…</option>
                            @foreach (['America/New_York' => 'Eastern', 'America/Chicago' => 'Central', 'America/Denver' => 'Mountain', 'America/Phoenix' => 'Arizona', 'America/Los_Angeles' => 'Pacific', 'America/Anchorage' => 'Alaska', 'Pacific/Honolulu' => 'Hawaii'] as $zone => $label)
                                <option value="{{ $zone }}">{{ $label }} ({{ $zone }})</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-subtle">Your hours, bookings and reports use this time.</p>
                        @error('business.timezone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-phone" class="sh-label">Business phone</label>
                        <input id="b-phone" type="tel" wire:model="business.phone" class="sh-input" placeholder="(512) 555-0142">
                        @error('business.phone') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-email" class="sh-label">Business email</label>
                        <input id="b-email" type="email" wire:model="business.email" class="sh-input">
                        @error('business.email') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-web" class="sh-label">Website (optional)</label>
                        <input id="b-web" type="url" wire:model="business.website" class="sh-input" placeholder="https://">
                        @error('business.website') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="b-desc" class="sh-label">What should callers know about you? (optional)</label>
                        <textarea id="b-desc" wire:model="business.description" rows="3" class="sh-input" placeholder="Family-owned plumbing company serving Austin since 1998. Upfront pricing, no overtime charges."></textarea>
                        @error('business.description') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="b-value" class="sh-label">Average job value, $ (optional)</label>
                        <input id="b-value" type="text" inputmode="decimal" wire:model="business.job_value" class="sh-input" placeholder="250">
                        <p class="mt-1 text-xs text-subtle">Used to estimate the revenue from bookings we make for you. Only you see it.</p>
                        @error('business.job_value') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex items-end justify-end sm:col-span-1"><x-ui.button type="submit">Save and continue</x-ui.button></div>
                </form>

            @elseif ($step === 'services')
                @if ($existingServices->isNotEmpty())
                    <p class="text-sm font-medium text-ink">Already set up</p>
                    <ul class="mt-2 mb-5 flex flex-wrap gap-2">
                        @foreach ($existingServices as $s)
                            <li><x-ui.badge tone="success">{{ $s->name }} · {{ $s->duration_minutes }} min</x-ui.badge></li>
                        @endforeach
                    </ul>
                @endif
                <form wire:submit="saveServices" class="space-y-3">
                    @if ($services)
                        <p class="text-sm text-muted">Suggested for your industry. Untick what you don't do, and adjust times and prices.</p>
                        <div class="space-y-2">
                            @foreach ($services as $i => $s)
                                <div class="grid items-center gap-2 rounded-xl bg-surface-2 p-3 ring-1 ring-line sm:grid-cols-[auto_minmax(0,1fr)_6rem_10rem_6rem]" wire:key="svc-{{ $i }}">
                                    <input type="checkbox" wire:model="services.{{ $i }}.selected" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500" aria-label="Offer {{ $s['name'] }}">
                                    <div>
                                        <label class="sr-only" for="s-name-{{ $i }}">Service name</label>
                                        <input id="s-name-{{ $i }}" type="text" wire:model="services.{{ $i }}.name" class="sh-input py-1.5">
                                        @error("services.$i.name") <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="sr-only" for="s-min-{{ $i }}">Minutes</label>
                                        <div class="flex items-center gap-1"><input id="s-min-{{ $i }}" type="number" min="5" max="1440" wire:model="services.{{ $i }}.minutes" class="sh-input py-1.5"><span class="text-xs text-subtle">min</span></div>
                                        @error("services.$i.minutes") <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="sr-only" for="s-type-{{ $i }}">Price type</label>
                                        <select id="s-type-{{ $i }}" wire:model.live="services.{{ $i }}.type" class="sh-input py-1.5">
                                            @foreach ($priceTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select>
                                    </div>
                                    <div>
                                        @if (in_array($s['type'], ['fixed', 'starting_from'], true))
                                            <label class="sr-only" for="s-price-{{ $i }}">Price</label>
                                            <div class="flex items-center gap-1"><span class="text-xs text-subtle">$</span><input id="s-price-{{ $i }}" type="text" inputmode="decimal" wire:model="services.{{ $i }}.price" class="sh-input py-1.5"></div>
                                            @error("services.$i.price") <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($existingServices->isEmpty())
                        <p class="text-sm text-muted">Add your services on the <a href="{{ route('app.business.services') }}" class="text-brand-300 hover:underline">Services page</a>, then come back, or skip for now.</p>
                    @else
                        <p class="text-sm text-muted">Need more? Add them any time on the <a href="{{ route('app.business.services') }}" class="text-brand-300 hover:underline">Services page</a>.</p>
                    @endif
                    @error('services') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                    <div class="flex justify-between gap-2 pt-2">
                        <x-ui.button variant="ghost" wire:click="skip">Skip for now</x-ui.button>
                        <x-ui.button type="submit">Save and continue</x-ui.button>
                    </div>
                </form>

            @elseif ($step === 'hours')
                <form wire:submit="saveHours" class="space-y-5">
                    <div class="flex flex-wrap gap-2">
                        <span class="text-sm text-muted">Quick start:</span>
                        <x-ui.button size="sm" variant="secondary" wire:click="applyHoursPreset('weekdays')">Mon–Fri 8–5</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" wire:click="applyHoursPreset('six')">Mon–Sat</x-ui.button>
                        <x-ui.button size="sm" variant="secondary" wire:click="applyHoursPreset('always')">24/7</x-ui.button>
                    </div>
                    <div class="divide-y divide-line rounded-xl ring-1 ring-line">
                        @foreach ($dayNames as $day => $name)
                            <div class="flex flex-wrap items-center gap-3 px-4 py-2.5" wire:key="day-{{ $day }}">
                                <label class="flex w-36 items-center gap-2 text-sm font-medium text-ink">
                                    <input type="checkbox" wire:model.live="days.{{ $day }}.open" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> {{ $name }}
                                </label>
                                @if ($days[$day]['open'] ?? false)
                                    <input type="time" wire:model="days.{{ $day }}.opens" class="sh-input w-32 py-1.5" aria-label="{{ $name }} opens">
                                    <span class="text-subtle">to</span>
                                    <input type="time" wire:model="days.{{ $day }}.closes" class="sh-input w-32 py-1.5" aria-label="{{ $name }} closes">
                                @else
                                    <span class="text-sm text-subtle">Closed</span>
                                @endif
                                @error("days.$day") <p class="w-full text-xs text-danger">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    @error('days') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                    <p class="text-xs text-subtle">Lunch breaks, split shifts and holidays: add them on the <a href="{{ route('app.business.hours') }}" class="text-brand-300 hover:underline">Hours page</a> after setup.</p>

                    <div class="rounded-xl bg-surface-2 p-4 ring-1 ring-line">
                        <label class="flex items-center gap-2 text-sm font-medium text-ink">
                            <input type="checkbox" wire:model.live="emergencyAvailable" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                            We take emergency calls outside our hours
                        </label>
                        @if ($emergencyAvailable)
                            <label for="h-emerg" class="sh-label mt-3">What counts as an emergency, and what should agents do?</label>
                            <textarea id="h-emerg" wire:model="emergencyInstructions" rows="3" class="sh-input" placeholder="e.g. Burst pipe or flooding: call Mike on (512) 555-0199 right away."></textarea>
                            @error('emergencyInstructions') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        @endif
                    </div>

                    <div>
                        <label for="h-zips" class="sh-label">Service area: ZIP codes you visit (optional)</label>
                        <textarea id="h-zips" wire:model="zips" rows="2" class="sh-input" placeholder="78701, 78702, 78703"></textarea>
                        <p class="mt-1 text-xs text-subtle">Agents won't book visits outside these ZIP codes. Leave empty if you don't do visits or work anywhere.</p>
                        @error('zips') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end"><x-ui.button type="submit">Save and continue</x-ui.button></div>
                </form>

            @elseif ($step === 'calls')
                <form wire:submit="saveCalls" class="space-y-5">
                    <div>
                        <label for="c-greet" class="sh-label">How should we answer the phone?</label>
                        <textarea id="c-greet" wire:model="greeting" rows="2" class="sh-input"></textarea>
                        @error('greeting') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                    </div>
                    <fieldset>
                        <legend class="sh-label">Always ask for, before booking</legend>
                        <div class="flex flex-wrap gap-4">
                            @foreach ($detailOptions as $value => $label)
                                <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" value="{{ $value }}" wire:model="details" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> {{ ucfirst($label) }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div>
                        <p class="sh-label">Questions callers often ask</p>
                        <p class="mb-2 text-xs text-subtle">Agents can read these answers to callers.</p>
                        <div class="space-y-3">
                            @foreach ($faqs as $i => $faq)
                                <div class="grid gap-2 rounded-xl bg-surface-2 p-3 ring-1 ring-line" wire:key="faq-{{ $i }}">
                                    <div class="flex gap-2">
                                        <label class="sr-only" for="faq-q-{{ $i }}">Question</label>
                                        <input id="faq-q-{{ $i }}" type="text" wire:model="faqs.{{ $i }}.q" class="sh-input py-1.5" placeholder="Question">
                                        <x-ui.button size="sm" variant="ghost" wire:click="removeFaq({{ $i }})" aria-label="Remove this question"><x-ui.icon name="x" class="size-4" /></x-ui.button>
                                    </div>
                                    <label class="sr-only" for="faq-a-{{ $i }}">Answer</label>
                                    <textarea id="faq-a-{{ $i }}" wire:model="faqs.{{ $i }}.a" rows="2" class="sh-input" placeholder="Your answer"></textarea>
                                    @error("faqs.$i.a") <p class="text-xs text-danger">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                        <x-ui.button size="sm" variant="secondary" class="mt-2" wire:click="addFaq">Add a question</x-ui.button>
                    </div>
                    <div>
                        <label for="c-dos" class="sh-label">Do's and don'ts for our agents (one per line)</label>
                        <textarea id="c-dos" wire:model="dos" rows="3" class="sh-input" placeholder="Never quote a final price for installations: offer a free estimate.&#10;Always mention our 10% senior discount."></textarea>
                    </div>
                    <div>
                        <label for="c-esc" class="sh-label">When should we call or text you right away?</label>
                        <textarea id="c-esc" wire:model="escalation" rows="2" class="sh-input" placeholder="Unhappy customers and anything about an active job: text me on (512) 555-0199."></textarea>
                    </div>
                    <div class="flex justify-between gap-2">
                        <x-ui.button variant="ghost" wire:click="skip">Skip for now</x-ui.button>
                        <x-ui.button type="submit">Save and continue</x-ui.button>
                    </div>
                </form>

            @elseif ($step === 'calendar')
                <div class="space-y-4">
                    <p class="text-sm text-muted">Bookings we make appear in the calendar you already use, and your busy times stop us double-booking you.</p>
                    @forelse ($connections as $c)
                        <x-ui.alert tone="success">{{ $c->provider === 'google' ? 'Google Calendar' : 'Microsoft Outlook' }} connected{{ $c->account_email ? ' ('.$c->account_email.')' : '' }}.</x-ui.alert>
                    @empty
                        @if ($calendarProviders)
                            <div class="flex flex-wrap gap-2">
                                @foreach ($calendarProviders as $key => $label)
                                    <x-ui.button variant="secondary" :href="route('app.integrations.calendar.connect', ['provider' => $key, 'from' => 'setup'])" icon="calendar">Connect {{ $label }}</x-ui.button>
                                @endforeach
                            </div>
                        @else
                            <x-ui.alert tone="info">Calendar connections are being finished by SureHelp. We'll let you know when you can connect yours. You can skip this step.</x-ui.alert>
                        @endif
                    @endforelse
                    <div class="flex justify-end pt-2">
                        <x-ui.button :variant="$connections->isEmpty() ? 'secondary' : 'primary'" wire:click="continueCalendar">{{ $connections->isEmpty() ? 'Skip for now' : 'Continue' }}</x-ui.button>
                    </div>
                </div>

            @elseif ($step === 'team')
                <div class="space-y-4">
                    <p class="text-sm text-muted">Invite anyone who should see calls, messages and bookings. You can do this later under Team.</p>
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($members as $m)<li><x-ui.badge tone="success">{{ $m->name }}</x-ui.badge></li>@endforeach
                        @foreach ($invitations as $inv)<li><x-ui.badge tone="info">{{ $inv->email }} · invited</x-ui.badge></li>@endforeach
                    </ul>
                    <form wire:submit="invite" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-end">
                        <div>
                            <label for="t-email" class="sh-label">Email</label>
                            <input id="t-email" type="email" wire:model="inviteEmail" class="sh-input" placeholder="name@business.com">
                            @error('inviteEmail') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="t-role" class="sh-label">Role</label>
                            <select id="t-role" wire:model="inviteRole" class="sh-input"><option value="staff">Staff</option><option value="manager">Manager</option></select>
                        </div>
                        <x-ui.button type="submit" variant="secondary">Invite</x-ui.button>
                    </form>
                    <p class="text-xs text-subtle">Choose who gets emails about calls and bookings under <a href="{{ route('app.settings.notifications') }}" class="text-brand-300 hover:underline">Notifications</a>.</p>
                    <div class="flex justify-end pt-2"><x-ui.button wire:click="continueTeam">Continue</x-ui.button></div>
                </div>

            @else
                <div class="space-y-4">
                    @if ($organization->isSetUp())
                        <x-ui.alert tone="success" title="Setup finished {{ $organization->setup_completed_at->diffForHumans() }}">You can still change anything on the Business pages.</x-ui.alert>
                    @endif
                    <ul class="divide-y divide-line rounded-xl ring-1 ring-line" role="list">
                        @foreach (array_slice($keys, 0, -1) as $key)
                            @php $state = $progress->state($organization, $key); @endphp
                            <li class="flex items-center justify-between gap-3 px-4 py-3">
                                <span class="text-sm text-ink">{{ $steps[$key][0] }}</span>
                                <span class="flex items-center gap-2">
                                    <x-ui.badge :tone="$state === 'done' ? 'success' : ($state === 'skipped' ? 'neutral' : 'warning')">{{ $state === 'done' ? 'Done' : ($state === 'skipped' ? 'Skipped' : 'Not yet') }}</x-ui.badge>
                                    <button type="button" wire:click="go('{{ $key }}')" class="text-sm text-brand-300 hover:underline">{{ $state ? 'Review' : 'Do it now' }}</button>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    <x-ui.alert tone="info" title="What happens next">
                        SureHelp checks your details, assigns your receptionists and sets up your phone line with you. We'll email you when calls are being answered.
                    </x-ui.alert>
                    @error('finish') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                    @unless ($organization->isSetUp())
                        <div class="flex justify-end"><x-ui.button wire:click="finish" :disabled="$missing !== []">Finish setup</x-ui.button></div>
                    @endunless
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
