<div>
    <x-agent.company-bar :company="$company" active="tasks" />

    <x-ui.card :padding="false">
        @if ($tasks->isEmpty())
            <x-ui.empty-state icon="check-circle" title="No open tasks" description="Call-backs and follow-ups for {{ $company->name }} appear here." />
        @else
            <x-ui.table>
                <thead><tr><th scope="col">Task</th><th scope="col">Customer</th><th scope="col">Due</th><th scope="col">Priority</th>@if ($canUpdate)<th scope="col" class="text-right"><span class="sr-only">Actions</span></th>@endif</tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($tasks as $task)
                        <tr wire:key="t-{{ $task->ulid }}">
                            <td><p class="font-medium text-ink">{{ $task->title }}</p><p class="text-xs text-subtle">{{ $task->type->label() }} · {{ $task->status->label() }}</p></td>
                            <td class="text-muted">@if ($task->customer)<a class="underline" href="{{ route('agent.businesses.customers.show', [$company->ulid, $task->customer->ulid]) }}">{{ $task->customer->fullName() }}</a>@else — @endif</td>
                            <td @class(['whitespace-nowrap', 'text-danger' => $task->isOverdue(), 'text-muted' => ! $task->isOverdue()])>{{ $task->due_at?->setTimezone($timezone)->format('D j M, g:i A') ?? '—' }}</td>
                            <td><x-ui.badge :tone="$task->priority->tone()">{{ $task->priority->label() }}</x-ui.badge></td>
                            @if ($canUpdate)
                                <td class="whitespace-nowrap text-right">
                                    @if ($task->status->value === 'open')<x-ui.button size="sm" variant="ghost" wire:click="setStatus('{{ $task->ulid }}', 'in_progress')">Start</x-ui.button>@endif
                                    <x-ui.button size="sm" variant="secondary" wire:click="setStatus('{{ $task->ulid }}', 'completed')">Done</x-ui.button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <x-ui.pagination :paginator="$tasks" />
        @endif
    </x-ui.card>
</div>
