{{-- The phone call behind a call log (D60): recording, AI summary and transcript, for people allowed to hear recordings. --}}
@props(['call'])
@php
    $voice = $call->voiceCall;
    $organization = $voice ? \App\Models\Organization::query()->find($voice->organization_id) : null;
    $canHear = $voice && $organization && auth()->user()?->hasPermissionIn('calls.recording.view', $organization);
@endphp
@if ($voice)
    <x-ui.card title="Phone call" {{ $attributes }}>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-subtle">Result</dt><dd><x-ui.badge :tone="$voice->statusTone()">{{ $voice->statusLabel() }}</x-ui.badge></dd></div>
            <div><dt class="text-xs text-subtle">From</dt><dd class="text-ink">{{ $voice->fromDisplay() }}</dd></div>
            <div><dt class="text-xs text-subtle">Waited</dt><dd class="text-ink tabular-nums">{{ $voice->wait_seconds !== null ? $voice->wait_seconds.'s' : '—' }}</dd></div>
            <div><dt class="text-xs text-subtle">Talk time</dt><dd class="text-ink tabular-nums">{{ \App\Models\VoiceCall::clock($voice->duration_seconds) }}</dd></div>
        </dl>
        @if ($voice->transferred_to)
            <p class="mt-2 text-xs text-muted">Sent to {{ \App\Models\PhoneNumber::pretty($voice->transferred_to) }}{{ $voice->transferred_at ? ' at '.$voice->transferred_at->timezone($organization->timezoneOrDefault())->format('g:i A') : '' }}.</p>
        @endif

        @if ($canHear)
            @if ($voice->hasRecording())
                <audio controls preload="none" class="mt-4 w-full" src="{{ route('calls.recording', $voice) }}">Your browser can't play this recording.</audio>
            @else
                <p class="mt-4 text-sm text-muted">No recording for this call.</p>
            @endif
            @if ($voice->summary)
                <div class="mt-4">
                    <p class="flex items-center gap-1.5 text-xs font-semibold text-brand-300"><x-ui.icon name="sparkles" class="size-4" /> AI summary</p>
                    <p class="mt-1 text-sm text-ink">{{ $voice->summary }}</p>
                </div>
            @endif
            @if (! empty($voice->transcript))
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm font-medium text-brand-300">Transcript ({{ count($voice->transcript) }} lines)</summary>
                    <ol class="mt-2 max-h-80 space-y-1.5 overflow-y-auto rounded-xl bg-surface-2 p-3 text-sm">
                        @foreach ($voice->transcript as $line)
                            <li><span class="font-medium {{ $line['speaker'] === 'agent' ? 'text-brand-300' : 'text-ink' }}">{{ $line['speaker'] === 'agent' ? 'Agent' : 'Caller' }}:</span> <span class="text-muted">{{ $line['text'] }}</span></li>
                        @endforeach
                    </ol>
                </details>
            @endif
        @endif
    </x-ui.card>
@endif
