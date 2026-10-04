{{-- Tasks linked to a customer or call. Expects $cardTasks, $timezone and optionally $addUrl. --}}
<x-ui.card title="Tasks">
    @if ($cardTasks->isEmpty())
        <p class="text-sm text-muted">{{ $emptyText ?? 'Nothing waiting.' }}</p>
    @else
        <ul class="space-y-3" role="list">
            @foreach ($cardTasks as $cardTask)
                @php($overdue = $cardTask->isOverdue())
                <li>
                    <a href="{{ route('app.tasks.index', ['task' => $cardTask->ulid]) }}" @class(['text-sm font-medium hover:text-brand-300', 'text-ink' => $cardTask->status->isOpen(), 'text-muted line-through' => ! $cardTask->status->isOpen()])>{{ $cardTask->title }}</a>
                    <p class="mt-0.5 text-xs text-subtle">
                        @if (! $cardTask->status->isOpen())
                            {{ $cardTask->status->label() }}{{ $cardTask->completed_at ? ' '.$cardTask->completed_at->diffForHumans() : '' }}
                        @elseif ($cardTask->due_at)
                            <span @class(['font-semibold text-danger' => $overdue])>{{ $overdue ? 'Overdue · was due' : 'Due' }} {{ $cardTask->due_at->setTimezone($timezone)->calendar() }}</span>
                        @else
                            {{ $cardTask->status->label() }}
                        @endif
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
    @if (! empty($addUrl))
        <x-ui.button class="mt-4 w-full" variant="secondary" size="sm" icon="check-circle" :href="$addUrl">Add task</x-ui.button>
    @endif
</x-ui.card>
