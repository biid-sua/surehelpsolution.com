<div>
    <x-agent.company-bar :company="$company" active="training" />

    <x-ui.page-header title="Company training" :description="'What '.$company->name.' asks of the agents who serve it.'" />

    @if (session('status'))
        <x-ui.alert tone="danger" class="mb-6">{{ session('status') }}</x-ui.alert>
    @endif

    @if ($requiredCount > 0)
        @if ($requiredDone === $requiredCount)
            <x-ui.alert tone="success" class="mb-6" title="Training ready">You've completed every required course for {{ $company->name }}.</x-ui.alert>
        @else
            <x-ui.alert tone="warning" class="mb-6" title="Training required">
                {{ $requiredCount - $requiredDone }} of {{ $requiredCount }} required {{ \Illuminate\Support\Str::plural('course', $requiredCount) }} for {{ $company->name }} still to complete.
            </x-ui.alert>
        @endif
    @endif

    @if ($rules->isEmpty() && $other->isEmpty())
        <x-ui.card><x-ui.empty-state icon="sparkles" title="No company training yet" description="When this company's supervisor publishes training for it, you'll find it here." /></x-ui.card>
    @endif

    @if ($rules->isNotEmpty())
        <x-ui.card title="Required and assigned" :padding="false" class="mb-6">
            <x-ui.table>
                <thead><tr><th scope="col">Course</th><th scope="col">Type</th><th scope="col">Due</th><th scope="col">Status</th><th scope="col" class="text-right"><span class="sr-only">Open</span></th></tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rules as $rule)
                        @php($a = $mine->get($rule->course_id))
                        <tr wire:key="r-{{ $rule->id }}">
                            <td>
                                <p class="font-medium text-ink">{{ $rule->course->title }}</p>
                                @if ($rule->course->summary)<p class="text-xs text-subtle">{{ $rule->course->summary }}</p>@endif
                            </td>
                            <td class="text-sm text-muted">{{ $rule->is_required ? 'Required' : 'Optional' }}</td>
                            <td class="text-sm text-muted">{{ $a?->due_at?->format('j M Y') ?? '–' }}</td>
                            <td>@if ($a)<x-ui.badge :tone="$a->tone()">{{ $a->label() }}</x-ui.badge>@else<x-ui.badge>Not started</x-ui.badge>@endif</td>
                            <td class="text-right"><x-ui.button size="sm" variant="secondary" :href="route('agent.university.course', $rule->course->ulid)">{{ $a?->isDone() ? 'Review' : ($a?->started_at ? 'Continue' : 'Open') }}</x-ui.button></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>
    @endif

    @if ($other->isNotEmpty())
        <h2 class="mb-3 text-base font-semibold text-ink">More {{ $company->name }} courses</h2>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($other as $course)
                <x-university.course-card :course="$course" :assignment="$mine->get($course->id)" wire:key="o-{{ $course->id }}" />
            @endforeach
        </div>
    @endif
</div>
