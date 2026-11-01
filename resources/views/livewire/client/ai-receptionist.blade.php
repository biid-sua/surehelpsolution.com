<div>
    <x-ui.page-header title="AI receptionist" description="An AI voice that answers your calls when nobody can: it answers questions from your business details, books jobs, takes messages and puts urgent calls through to you." />

    @unless ($aiReady)
        <x-ui.alert tone="warning" class="mb-6">The AI isn't connected yet, so the receptionist can't answer calls. Ask SureHelp to set it up.</x-ui.alert>
    @endunless
    @if ($lines->isEmpty())
        <x-ui.alert tone="info" class="mb-6">You don't have a SureHelp phone line yet. The receptionist answers calls to your SureHelp line once it's set up. You can practice below already.</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-5">
        <form wire:submit="save" class="space-y-6 lg:col-span-3">
            <x-ui.card title="When it answers">
                <div class="space-y-3">
                    @foreach ($modes as $value => $label)
                        <label class="flex items-start gap-3 rounded-lg border border-line p-3 text-sm has-[:checked]:border-brand-400 has-[:checked]:bg-brand-500/10">
                            <input type="radio" wire:model.live="form.mode" value="{{ $value }}" class="mt-0.5 size-4 border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                            <span>
                                <span class="block font-medium text-ink">{{ $label }}</span>
                                <span class="block text-xs text-muted">
                                    @switch($value)
                                        @case('off') Calls go to your agents, then to you or voicemail, as now. @break
                                        @case('backup') If no agent is free or nobody picks up, and when you're closed, the AI answers instead of voicemail. @break
                                        @case('after_hours') While you're closed, the AI answers. During your hours, nothing changes. @break
                                        @default The AI answers every call straight away and puts callers through to you when they need a person.
                                    @endswitch
                                </span>
                            </span>
                        </label>
                    @endforeach
                    @error('form.mode')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                </div>
            </x-ui.card>

            <x-ui.card title="How it sounds">
                <div class="space-y-4">
                    <div>
                        <label for="ar-greeting" class="sh-label">First thing it says</label>
                        <textarea id="ar-greeting" rows="2" wire:model="form.greeting" class="sh-input" @disabled(! $canManage)
                            placeholder="Thanks for calling {business}. I'm the virtual assistant. How can I help you today?"></textarea>
                        <p class="mt-1 text-xs text-subtle">Leave empty for the default. {business} becomes your business name. It must say callers are talking to a virtual or AI assistant.</p>
                        @error('form.greeting')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="ar-voice" class="sh-label">Voice</label>
                            <select id="ar-voice" wire:model="form.voice" class="sh-input" @disabled(! $canManage)>
                                @foreach ($voices as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label for="ar-minutes" class="sh-label">Longest call (minutes)</label>
                            <input id="ar-minutes" type="number" min="2" max="20" wire:model="form.max_minutes" class="sh-input" @disabled(! $canManage)>
                            @error('form.max_minutes')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card title="What it may do" description="It always answers from your business details, services, hours and knowledge base, and follows your rules.">
                <div class="space-y-3 text-sm text-ink">
                    <label class="flex items-start gap-2">
                        <input type="checkbox" wire:model="form.can_book" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                        <span>Book appointments in your calendar<span class="block text-xs text-muted">Only free times, with the same rules as your agents. In Results they count under "AI assistant and receptionist".</span></span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" wire:model="form.transfer_to_owner" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500" @disabled(! $canManage)>
                        <span>Put callers through to you when they ask for a person or it's urgent
                            <span class="block text-xs text-muted">
                                @if ($lines->whereNotNull('transfer_number')->isNotEmpty())
                                    To {{ \App\Models\PhoneNumber::pretty($lines->whereNotNull('transfer_number')->first()->transfer_number) }}, your line's transfer number.
                                @else
                                    Set a transfer number on your phone line first. Until then it takes their details and the team calls back.
                                @endif
                            </span>
                        </span>
                    </label>
                </div>
            </x-ui.card>

            @if ($canManage)
                <x-ui.button type="submit">Save</x-ui.button>
            @endif
        </form>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Try it" description="Type as if you were calling. Nothing is booked or saved." :padding="false">
                <div class="max-h-96 space-y-3 overflow-y-auto p-5" aria-live="polite">
                    @foreach ($practice as $i => $turn)
                        <div wire:key="practice-{{ $i }}" @class(['flex', 'justify-end' => $turn['role'] === 'user'])>
                            <p @class([
                                'max-w-[85%] rounded-2xl px-3 py-2 text-sm',
                                'bg-brand-500 text-white' => $turn['role'] === 'user',
                                'bg-surface-2 text-ink ring-1 ring-line' => $turn['role'] !== 'user',
                            ])>{{ $turn['text'] }}</p>
                        </div>
                    @endforeach
                    <div wire:loading.flex wire:target="sendPractice" class="text-xs text-subtle">Thinking…</div>
                    @if ($practiceEnded)
                        <p class="text-center text-xs text-subtle">The call ended here.</p>
                    @endif
                </div>
                <form wire:submit="sendPractice" class="flex gap-2 border-t border-line p-3">
                    <label for="ar-try" class="sr-only">What you'd say</label>
                    <input id="ar-try" type="text" wire:model="practiceInput" class="sh-input flex-1" autocomplete="off"
                        placeholder="{{ $practiceEnded ? 'Start again to try another call' : 'Hi, do you do emergency repairs?' }}" @disabled(! $canManage || $practiceEnded)>
                    @if ($practiceEnded)
                        <x-ui.button type="button" variant="secondary" wire:click="restartPractice">Start again</x-ui.button>
                    @else
                        <x-ui.button type="submit" :disabled="! $canManage">Say</x-ui.button>
                    @endif
                </form>
                @error('practiceInput')<p class="px-3 pb-3 text-sm text-danger">{{ $message }}</p>@enderror
            </x-ui.card>

            <x-ui.card title="Recent calls it answered" :padding="false">
                <ul class="divide-y divide-line">
                    @forelse ($recent as $call)
                        <li wire:key="ai-call-{{ $call->id }}" class="flex items-center justify-between gap-3 px-5 py-3 text-sm">
                            <span class="min-w-0">
                                <span class="block truncate text-ink">{{ $call->fromDisplay() }}</span>
                                <span class="block text-xs text-subtle">{{ $call->started_at->timezone($timezone)->format('M j, g:i A') }} · {{ $call->ai_turns }} {{ \Illuminate\Support\Str::plural('reply', $call->ai_turns) }}</span>
                            </span>
                            @if ($call->aiConversation)
                                <a href="{{ route('app.inbox.show', $call->aiConversation) }}" wire:navigate class="shrink-0 text-sm font-medium text-brand-300 hover:underline">Read the call</a>
                            @endif
                        </li>
                    @empty
                        <li class="px-5 py-4 text-sm text-muted">No calls yet. Each call it answers appears here and in your Inbox.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>
    </div>
</div>
