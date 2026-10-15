<div>
    <x-ui.page-header title="Phone lines" description="Each business's SureHelp number. Calls ring the agents who serve the business; if nobody answers they go to the owner's phone, then to voicemail." />

    @if ($provider->name() === 'fake')
        <x-ui.alert tone="info" class="mb-6" title="Demo phone mode">Twilio isn't switched on yet, so lines get demo numbers and agents try calls with "Your phone › Try a call" in the agent workspace. Set TELEPHONY_DRIVER=twilio and the Twilio keys to take real calls (docs/telephony.md).</x-ui.alert>
    @elseif (! $provider->isConfigured())
        <x-ui.alert tone="warning" class="mb-6" title="Twilio keys missing">Add TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_API_KEY and TWILIO_API_SECRET to the server settings.</x-ui.alert>
    @endif

    {{-- How calls are going --}}
    <div class="mb-3 flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold text-ink">Calls to all lines</h2>
        <select wire:model.live="days" class="sh-input w-auto py-1 text-sm" aria-label="Period">
            <option value="1">Last 24 hours</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
        </select>
    </div>
    <div class="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-5">
        <x-ui.stat label="Calls" :value="$overall['total']" icon="phone" />
        <x-ui.stat label="Answered by agents" :value="$overall['answer_rate'] === null ? '—' : $overall['answer_rate'].'%'" :hint="$overall['answered'].' of '.$overall['total']" icon="check-circle" />
        <x-ui.stat label="Missed or voicemail" :value="$overall['missed'] + $overall['voicemail']" :hint="$overall['voicemail'].' left a voicemail'" icon="phone-x" />
        <x-ui.stat label="Average wait" :value="$overall['avg_wait'] === null ? '—' : $overall['avg_wait'].'s'" hint="Ringing until answered" icon="clock" />
        <x-ui.stat label="Average call" :value="\App\Models\VoiceCall::clock($overall['avg_talk'])" icon="chart" />
    </div>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
        <x-ui.card title="Lines" :padding="false">
            @if ($lines->isEmpty())
                <x-ui.empty-state title="No phone lines yet" description="Add a line for a business to start taking its calls." icon="phone" />
            @else
                <x-ui.table>
                    <thead><tr><th>Business</th><th>Number</th><th>If nobody answers</th><th class="text-right">Answered</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($lines as $line)
                            @php $s = $lineStats[$line->id]; @endphp
                            <tr wire:key="line-{{ $line->ulid }}" @class(['opacity-60' => $line->status !== 'active'])>
                                <td>
                                    <span class="font-medium text-ink">{{ $line->organization->name ?? 'Deleted business' }}</span>
                                    @if ($line->label)<span class="block text-xs text-subtle">{{ $line->label }}</span>@endif
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="font-mono text-sm text-ink">{{ $line->display() }}</span>
                                    <span class="mt-0.5 flex gap-1">
                                        @if ($line->status !== 'active')<x-ui.badge>Released</x-ui.badge>@endif
                                        @if ($line->record_calls)<x-ui.badge tone="info">Recorded</x-ui.badge>@endif
                                    </span>
                                </td>
                                <td class="text-sm text-muted">{{ $line->transfer_number ? 'Owner '.\App\Models\PhoneNumber::pretty($line->transfer_number).', then voicemail' : 'Voicemail' }}</td>
                                <td class="text-right text-sm tabular-nums text-ink">{{ $s['answer_rate'] === null ? '—' : $s['answer_rate'].'%' }}<span class="block text-xs text-subtle">{{ $s['total'] }} {{ str('call')->plural($s['total']) }}</span></td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($canManage && $line->status === 'active')
                                        <x-ui.button size="sm" variant="ghost" wire:click="edit('{{ $line->ulid }}')">Edit</x-ui.button>
                                        <x-ui.confirm id="release-{{ $line->ulid }}" title="Release {{ $line->display() }}?" confirm-label="Release number" action="release('{{ $line->ulid }}')">
                                            <x-slot:trigger><x-ui.button size="sm" variant="ghost">Release</x-ui.button></x-slot:trigger>
                                            The number goes back to Twilio and calls to it stop reaching SureHelp. If the business forwards its phone here, they must change that first. You may not get the same number back.
                                        </x-ui.confirm>
                                    @endif
                                </td>
                            </tr>
                            @if ($editing === $line->ulid)
                                <tr wire:key="edit-{{ $line->ulid }}">
                                    <td colspan="5">
                                        <form wire:submit="update" class="grid gap-3 rounded-xl bg-surface-2 p-4 sm:grid-cols-2">
                                            <div><label for="e-label" class="sh-label">Label</label><input id="e-label" wire:model="editLabel" class="sh-input" maxlength="80"></div>
                                            <div>
                                                <label for="e-transfer" class="sh-label">Owner's phone (transfers and missed calls)</label>
                                                <input id="e-transfer" type="tel" wire:model="editTransfer" class="sh-input" placeholder="(512) 555-0100">
                                                @error('editTransfer')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                                            </div>
                                            <label class="flex items-center gap-2 text-sm text-ink sm:col-span-2"><input type="checkbox" wire:model="editRecord" class="rounded text-brand-500"> Record and transcribe calls (callers hear a short notice first)</label>
                                            <div class="flex gap-2 sm:col-span-2"><x-ui.button type="submit">Save line</x-ui.button><x-ui.button variant="ghost" wire:click="$set('editing', null)">Cancel</x-ui.button></div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <div class="space-y-6">
            @if ($canManage)
                <x-ui.card title="Add a line" description="A local number for a business. They forward their own phone to it, or publish it.">
                    <form wire:submit="add" class="space-y-4">
                        <div>
                            <label for="l-org" class="sh-label">Business</label>
                            <select id="l-org" wire:model="organization" class="sh-input">
                                <option value="">Choose a business…</option>
                                @foreach ($businesses as $business)<option value="{{ $business->ulid }}">{{ $business->name }}</option>@endforeach
                            </select>
                            @error('organization')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                        <fieldset class="flex flex-wrap gap-4 text-sm text-ink">
                            <legend class="sr-only">Number</legend>
                            <label class="flex items-center gap-2"><input type="radio" value="buy" wire:model.live="mode" class="text-brand-500"> Get a new number</label>
                            <label class="flex items-center gap-2"><input type="radio" value="connect" wire:model.live="mode" class="text-brand-500"> Use one already in Twilio</label>
                        </fieldset>
                        @if ($mode === 'buy')
                            <div>
                                <label for="l-area" class="sh-label">Area code</label>
                                <div class="flex gap-2">
                                    <input id="l-area" wire:model="areaCode" inputmode="numeric" maxlength="3" class="sh-input w-28" placeholder="512">
                                    <x-ui.button type="button" variant="secondary" wire:click="search">Find numbers</x-ui.button>
                                </div>
                                @error('areaCode')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                            @if ($found)
                                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Available numbers">
                                    @foreach ($found as $option)
                                        <label wire:key="n-{{ $option['number'] }}" @class(['flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm', 'border-brand-400 ring-1 ring-brand-400' => $number === $option['number'], 'border-line-strong' => $number !== $option['number']])>
                                            <input type="radio" value="{{ $option['number'] }}" wire:model.live="number" class="sr-only">
                                            <span class="font-mono text-ink">{{ \App\Models\PhoneNumber::pretty($option['number']) }}</span>
                                            <span class="text-xs text-subtle">{{ $option['locality'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('number')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            @endif
                        @else
                            <div>
                                <label for="l-sid" class="sh-label">Number SID (PN…)</label>
                                <input id="l-sid" wire:model="sid" class="sh-input font-mono" placeholder="PN0123456789abcdef0123456789abcdef">
                                @error('sid')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <div><label for="l-label" class="sh-label">Label (optional)</label><input id="l-label" wire:model="label" class="sh-input" maxlength="80" placeholder="Main line"></div>
                        <div>
                            <label for="l-transfer" class="sh-label">Owner's phone (optional)</label>
                            <input id="l-transfer" type="tel" wire:model="transferNumber" class="sh-input" placeholder="(512) 555-0100">
                            <p class="mt-1 text-xs text-subtle">Agents can transfer callers here, and calls nobody answers try it before voicemail.</p>
                            @error('transferNumber')<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                        <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="recordCalls" class="rounded text-brand-500"> Record and transcribe calls (callers hear a short notice first)</label>
                        <x-ui.button type="submit">{{ $mode === 'buy' ? 'Buy number and add line' : 'Add line' }}</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            <x-ui.card title="Twilio settings" description="Set on each number automatically. Shown here for checking.">
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-xs text-subtle">Calls come in to</dt><dd class="break-all font-mono text-xs text-ink">{{ $urls['voice'] }}</dd></div>
                    <div><dt class="text-xs text-subtle">Call status updates</dt><dd class="break-all font-mono text-xs text-ink">{{ $urls['status'] }}</dd></div>
                </dl>
            </x-ui.card>
        </div>
    </div>

    <x-ui.card title="Latest calls" class="mt-6" :padding="false">
        @if ($recent->isEmpty())
            <x-ui.empty-state title="No calls yet" description="Calls to the lines above show here as they happen." icon="phone" />
        @else
            <x-ui.table>
                <thead><tr><th>When</th><th>Business</th><th>Caller</th><th>Result</th><th>Agent</th><th class="text-right">Wait</th><th class="text-right">Length</th></tr></thead>
                <tbody>
                    @foreach ($recent as $call)
                        <tr wire:key="call-{{ $call->ulid }}">
                            <td class="whitespace-nowrap text-sm text-muted">{{ $call->started_at->diffForHumans() }}</td>
                            <td class="text-sm text-ink">{{ $call->organization->name ?? '—' }}</td>
                            <td class="whitespace-nowrap font-mono text-sm text-ink">{{ $call->fromDisplay() }}</td>
                            <td><x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge></td>
                            <td class="text-sm text-muted">{{ $call->answeredBy->name ?? '—' }}</td>
                            <td class="text-right text-sm tabular-nums text-muted">{{ $call->wait_seconds !== null ? $call->wait_seconds.'s' : '—' }}</td>
                            <td class="text-right text-sm tabular-nums text-muted">{{ \App\Models\VoiceCall::clock($call->duration_seconds) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
