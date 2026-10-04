<div wire:poll.60s>
    <x-ui.page-header title="Escalations" description="Unresolved escalations at every business. Phone the owner if an urgent one sits unanswered." />

    @if ($urgentWaiting > 0)
        <x-ui.alert tone="danger" class="mb-4" title="{{ $urgentWaiting }} urgent {{ \Illuminate\Support\Str::plural('escalation', $urgentWaiting) }} not acknowledged">
            The business has been alerted in the app and by email.
        </x-ui.alert>
    @endif

    <x-ui.card :padding="false">
        @if ($escalations->isEmpty())
            <x-ui.empty-state icon="shield" title="No open escalations" description="Every escalation has been resolved." />
        @else
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Business</th>
                        <th scope="col">Escalation</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-right">Waiting</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($escalations as $escalation)
                        <tr wire:key="ae-{{ $escalation->id }}">
                            <td>
                                @if ($escalation->organization)
                                    <a href="{{ route('admin.organizations.show', $escalation->organization) }}" class="font-medium text-ink hover:text-brand-300">{{ $escalation->organization->name }}</a>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-ui.badge :tone="$escalation->priority->tone()">{{ $escalation->priority->label() }}</x-ui.badge>
                                    <span class="text-ink">{{ $escalation->type->label() }}</span>
                                </div>
                                <p class="mt-1 text-xs text-subtle">{{ \Illuminate\Support\Str::limit($escalation->reason, 120) }}@if ($escalation->call) · {{ $escalation->call->call_id }}@endif</p>
                            </td>
                            <td>
                                <x-ui.badge :tone="$escalation->status->tone()">{{ $escalation->status->label() }}</x-ui.badge>
                                @if ($escalation->acknowledgedBy)<p class="mt-1 text-xs text-subtle">{{ $escalation->acknowledgedBy->name }}</p>@endif
                            </td>
                            <td class="whitespace-nowrap text-right text-muted" title="{{ $escalation->created_at->toDayDateTimeString() }} UTC">{{ $escalation->created_at->diffForHumans(null, true) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$escalations" />
        @endif
    </x-ui.card>
</div>
