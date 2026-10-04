<div>
    <x-ui.page-header title="Call review"
        description="Calls from before organizations existed that couldn't be matched to a business with certainty. Clients can't see a call until it belongs to their business." />

    <x-ui.card :padding="false">
        @if ($calls->isEmpty())
            <x-ui.empty-state icon="check-circle" title="Nothing to review"
                description="Every call belongs to a business. New calls are always attributed when an agent logs them." />
        @else
            <ul class="divide-y divide-line">
                @foreach ($calls as $call)
                    <li class="grid gap-4 px-5 py-4 lg:grid-cols-[1fr_auto] lg:items-center" wire:key="review-{{ $call->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-ink">{{ \App\Models\CallLog::display($call->caller_name) }}</p>
                                @if ($call->ownership_source === \App\Enums\CallOwnershipSource::EmailMatch)
                                    <x-ui.badge tone="warning">Matched by caller email</x-ui.badge>
                                @else
                                    <x-ui.badge tone="danger">No business</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-muted">
                                {{ $call->call_id }} · {{ $call->created_at->format('M j, Y g:i a') }} UTC ·
                                {{ \App\Models\CallLog::display($call->caller_email) }} · {{ \App\Models\CallLog::display($call->caller_phone) }}
                            </p>
                            <p class="mt-1 text-sm text-subtle">
                                {{ \Illuminate\Support\Str::headline($call->reason_for_call) }} — logged by {{ \App\Models\CallLog::display($call->agent_name) }}
                                @if ($call->client_id) · legacy client id {{ $call->client_id }} @endif
                            </p>
                        </div>

                        <div class="flex flex-wrap items-end gap-2">
                            @if ($call->organization)
                                <x-ui.button size="sm" wire:click="confirm({{ $call->id }})" icon="check-circle">Confirm {{ $call->organization->name }}</x-ui.button>
                                <span class="self-center text-xs text-subtle">or</span>
                            @endif
                            <div>
                                <label for="assign-{{ $call->id }}" class="sr-only">Assign call {{ $call->call_id }} to a business</label>
                                <select id="assign-{{ $call->id }}" wire:model="assignTo.{{ $call->id }}" class="sh-input py-1.5">
                                    <option value="">Choose business…</option>
                                    @foreach ($organizations as $organization)
                                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-ui.button size="sm" variant="secondary" wire:click="assign({{ $call->id }})">Assign</x-ui.button>
                            @error("assignTo.$call->id") <p class="w-full text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                    </li>
                @endforeach
            </ul>
            <x-ui.pagination :paginator="$calls" />
        @endif
    </x-ui.card>
</div>
