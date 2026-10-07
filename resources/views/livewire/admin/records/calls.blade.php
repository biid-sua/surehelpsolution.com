<div>
    <x-ui.page-header title="Calls" description="Every call on the platform, newest first. Times are {{ config('app.timezone') }}." />

    <x-admin.record-filters :businesses="$businesses" placeholder="Caller, phone, call ID or agent…">
        <div>
            <label for="rf-status" class="sr-only">Status</label>
            <select id="rf-status" wire:model.live="status" class="sh-input">
                <option value="">Any status</option>
                @foreach ($statuses as $s)<option value="{{ $s }}">{{ str($s)->headline() }}</option>@endforeach
            </select>
        </div>
    </x-admin.record-filters>

    <x-ui.card :padding="false">
        @if ($calls->isEmpty())
            <x-ui.empty-state icon="phone" title="No calls found" description="Try other filters." />
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Caller</th>
                        <th scope="col">Business</th>
                        <th scope="col" class="hidden md:table-cell">Reason</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="hidden lg:table-cell">Agent</th>
                        <th scope="col" class="text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($calls as $call)
                        <tr wire:key="c-{{ $call->id }}">
                            <td>
                                <button type="button" wire:click="$set('open', {{ $open === $call->id ? 'null' : $call->id }})" class="text-left font-medium text-ink hover:text-brand-300" aria-expanded="{{ $open === $call->id ? 'true' : 'false' }}">{{ \App\Models\CallLog::display($call->caller_name) }}</button>
                                <p class="text-xs text-subtle">{{ $call->caller_phone ? $call->caller_phone.' · ' : '' }}{{ $call->call_id }}</p>
                            </td>
                            <td class="text-muted">
                                @if ($call->organization)<a href="{{ route('admin.organizations.show', $call->organization) }}" class="hover:text-ink">{{ $call->organization->name }}</a>@else<span class="text-amber-300">Not attributed</span>@endif
                            </td>
                            <td class="hidden text-muted md:table-cell">{{ \App\Models\CallLog::REASONS[$call->reason_for_call] ?? str($call->reason_for_call)->headline() }}</td>
                            <td><x-ui.badge :tone="$call->statusTone()">{{ $call->statusLabel() }}</x-ui.badge></td>
                            <td class="hidden text-muted lg:table-cell">{{ \App\Models\CallLog::display($call->agent_name) }}</td>
                            <td class="whitespace-nowrap text-right text-muted" title="{{ $call->created_at->toDayDateTimeString() }}">{{ $call->created_at->format('M j, g:i A') }}</td>
                        </tr>
                        @if ($open === $call->id)
                            <tr wire:key="c-open-{{ $call->id }}">
                                <td colspan="6" class="bg-surface-2/40">
                                    <dl class="grid gap-3 text-sm sm:grid-cols-3">
                                        <div><dt class="text-xs text-subtle">Outcome</dt><dd class="text-ink">{{ str($call->call_outcome)->headline() }}</dd></div>
                                        <div><dt class="text-xs text-subtle">Customer</dt><dd class="text-ink">{{ $call->customer?->fullName() ?? '—' }}</dd></div>
                                        <div><dt class="text-xs text-subtle">Service</dt><dd class="text-ink">{{ $call->service_request ? 'Requested'.($call->service_date ? ' for '.$call->service_date->format('M j').' '.$call->service_window : '') : 'No' }}</dd></div>
                                        <div class="sm:col-span-3"><dt class="text-xs text-subtle">Notes</dt><dd class="whitespace-pre-line text-muted">{{ $call->notes ?: '—' }}</dd></div>
                                    </dl>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$calls" />
        @endif
    </x-ui.card>
</div>
