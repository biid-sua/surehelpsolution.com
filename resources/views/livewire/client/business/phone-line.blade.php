<div>
    <x-ui.page-header title="Business" description="Your SureHelp phone number and where your calls go." />
    @include('livewire.client.business._tabs')

    @if (! $line)
        <x-ui.card>
            <x-ui.empty-state icon="phone" title="No phone line yet" description="Your SureHelp number is set up by our team. Ask us under Support and we'll add one, usually with a local area code." />
        </x-ui.card>
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="space-y-6">
                <x-ui.card title="Your SureHelp number">
                    <p class="font-mono text-3xl font-semibold tracking-tight text-ink">{{ $line->display() }}</p>
                    <p class="mt-2 text-sm text-muted">Calls to this number ring our agents who know your business. Send your calls here by forwarding your own phone, or publish this number on your website and listings.</p>

                    <div class="mt-5 rounded-xl bg-surface-2 p-4 text-sm">
                        <h3 class="font-semibold text-ink">Forward your business phone here</h3>
                        <ul class="mt-2 space-y-2 text-muted">
                            <li><span class="font-medium text-ink">All calls, most landlines and Verizon:</span> dial <span class="font-mono text-ink">*72 {{ preg_replace('/\D/', '', substr($line->number, 2)) }}</span> and wait for the tone. Turn off with <span class="font-mono text-ink">*73</span>.</li>
                            <li><span class="font-medium text-ink">Only calls you don't answer, Verizon:</span> <span class="font-mono text-ink">*71 {{ preg_replace('/\D/', '', substr($line->number, 2)) }}</span>.</li>
                            <li><span class="font-medium text-ink">AT&amp;T and T-Mobile mobiles:</span> all calls <span class="font-mono text-ink">**21*{{ substr($line->number, 2) }}#</span>, only unanswered <span class="font-mono text-ink">**61*{{ substr($line->number, 2) }}#</span>. Turn off with <span class="font-mono text-ink">##21#</span> or <span class="font-mono text-ink">##61#</span>.</li>
                            <li><span class="font-medium text-ink">Office or internet phones:</span> set "call forwarding" in your phone provider's settings.</li>
                        </ul>
                        <p class="mt-3 text-xs text-subtle">Codes differ between carriers and plans. If one doesn't work, ask your carrier for "call forwarding" to {{ $line->display() }}. Call your own number afterwards to check it reaches us.</p>
                    </div>
                </x-ui.card>

                <x-ui.card title="Last 30 days">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div><p class="text-xs text-subtle">Calls</p><p class="text-2xl font-semibold tabular-nums text-ink">{{ $stats['total'] }}</p></div>
                        <div><p class="text-xs text-subtle">Answered by us</p><p class="text-2xl font-semibold tabular-nums text-ink">{{ $stats['answer_rate'] === null ? '—' : $stats['answer_rate'].'%' }}</p></div>
                        <div><p class="text-xs text-subtle">Missed or voicemail</p><p class="text-2xl font-semibold tabular-nums text-ink">{{ $stats['missed'] + $stats['voicemail'] }}</p></div>
                        <div><p class="text-xs text-subtle">Average wait</p><p class="text-2xl font-semibold tabular-nums text-ink">{{ $stats['avg_wait'] === null ? '—' : $stats['avg_wait'].'s' }}</p></div>
                    </div>
                </x-ui.card>

                <x-ui.card title="Recent calls" :padding="false">
                    @if ($calls->isEmpty())
                        <x-ui.empty-state icon="phone" title="No calls yet" description="Calls to your SureHelp number show here as they happen." />
                    @else
                        <x-ui.table>
                            <thead><tr><th>When</th><th>Caller</th><th>Result</th><th>Handled by</th><th class="text-right">Length</th></tr></thead>
                            <tbody>
                                @foreach ($calls as $call)
                                    <tr wire:key="vc-{{ $call->ulid }}">
                                        <td class="whitespace-nowrap text-sm text-muted">{{ $call->started_at->timezone($timezone)->format('M j, g:i A') }}@if ($call->after_hours)<span class="block text-xs text-subtle">after hours</span>@endif</td>
                                        <td class="text-sm text-ink">
                                            @if ($call->isOutbound())<span class="text-xs text-subtle">Call to</span> @endif{{ $call->customer?->fullName() ?: $call->otherParty() }}
                                            @if ($call->customer)<span class="block text-xs text-subtle">{{ $call->otherParty() }}</span>@endif
                                        </td>
                                        <td><x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge></td>
                                        <td class="text-sm text-muted">
                                            @php $who = $call->answeredBy ?? $call->placedBy; @endphp
                                            {{ $who ? str($who->name)->before(' ') : ($call->status === \App\Models\VoiceCall::TRANSFERRED ? 'Your phone' : '—') }}
                                            @if ($call->callLog)<a href="{{ route('app.calls.show', $call->callLog->call_id) }}" class="block text-xs text-brand-300 hover:text-brand-200">View call</a>@endif
                                        </td>
                                        <td class="text-right text-sm tabular-nums text-muted">{{ \App\Models\VoiceCall::clock($call->duration_seconds) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ui.table>
                    @endif
                </x-ui.card>
            </div>

            <x-ui.card title="Where your calls go">
                <form wire:submit="save" class="space-y-5">
                    <div>
                        <label for="pl-transfer" class="sh-label">Your phone</label>
                        <input id="pl-transfer" type="tel" wire:model="transferNumber" class="sh-input" placeholder="(512) 555-0100" @disabled(! $canManage)>
                        <p class="mt-1 text-xs text-subtle">Agents can transfer callers to it, and calls nobody here can take try it before voicemail. Leave empty to go straight to voicemail.</p>
                        @error('transferNumber')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="pl-after" class="sh-label">When you're closed</label>
                        <select id="pl-after" wire:model="afterHours" class="sh-input" @disabled(! $canManage)>
                            @foreach (\App\Models\PhoneNumber::AFTER_HOURS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                        <p class="mt-1 text-xs text-subtle">Uses your <a href="{{ route('app.business.hours') }}" class="underline underline-offset-2">opening hours</a>, holidays and closures.</p>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="recordCalls" class="mt-0.5 rounded text-brand-500" @disabled(! $canManage)>
                        <span>Record and transcribe calls<span class="block text-xs text-subtle">Callers first hear "This call may be recorded". Some states require everyone on a call to agree, so check what applies to you.</span></span>
                    </label>
                    <div>
                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input type="checkbox" wire:model.live="textBack" class="mt-0.5 rounded text-brand-500" @disabled(! $canManage)>
                            <span>Text callers we miss<span class="block text-xs text-subtle">A text goes to anyone whose call nobody could answer. Their reply lands in your Inbox.{{ $smsReady ? '' : ' Starts once texting is switched on for your number.' }}</span></span>
                        </label>
                        @if ($textBack)
                            <textarea wire:model="textBackMessage" rows="3" maxlength="320" class="sh-input mt-2" placeholder="{{ \App\Models\PhoneNumber::DEFAULT_TEXT_BACK }}" aria-label="Text message" @disabled(! $canManage)></textarea>
                            <p class="mt-1 text-xs text-subtle">{business} becomes your business name. People who text STOP never get texts again.</p>
                            @error('textBackMessage')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        @endif
                    </div>
                    @if ($canManage)
                        <x-ui.button type="submit">Save</x-ui.button>
                    @else
                        <p class="text-sm text-muted">Only the owner and managers can change these.</p>
                    @endif
                </form>
            </x-ui.card>
        </div>
    @endif
</div>
