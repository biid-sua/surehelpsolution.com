<div>
    <x-ui.page-header title="Call quality" :description="$canReview ? 'Score calls against the scorecard and coach your team. Agents read your feedback on their own page.' : 'How your calls are scored, with feedback from your supervisor.'" />

    @if (count($tabs) > 1)
        <div class="mb-5 flex flex-wrap gap-2" role="tablist" aria-label="Call quality">
            @foreach ($tabs as $value => $label)
                <button type="button" role="tab" wire:click="$set('tab', '{{ $value }}')" aria-selected="{{ $current === $value ? 'true' : 'false' }}" @class([
                    'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                    'bg-brand-600 text-white' => $current === $value,
                    'bg-surface-2 text-muted hover:text-ink' => $current !== $value,
                ])>{{ $label }}@if ($value === 'queue' && $pendingCount) <span class="ml-1 rounded-full bg-white/20 px-1.5 text-xs">{{ $pendingCount }}</span>@endif</button>
            @endforeach
        </div>
    @endif

    @if ($current === 'mine')
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat label="Average score (30 days)" icon="trend-up" :value="$summary['average'] === null ? '—' : $summary['average'].'%'" :hint="'Pass mark '.$passScore.'%'" />
            <x-ui.stat label="Passed" icon="check-circle" :value="$summary['pass_rate'] === null ? '—' : $summary['pass_rate'].'%'" />
            <x-ui.stat label="Calls reviewed" icon="phone" :value="number_format($summary['count'])" />
        </div>

        <x-ui.card class="mt-6" :padding="false" title="Your reviewed calls">
            @if ($reviews->isEmpty())
                <x-ui.empty-state icon="check-circle" title="No reviews yet" description="Each day a few calls are picked at random for review. Your feedback shows up here, and we'll let you know." />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($reviews as $item)
                        <li wire:key="mine-{{ $item->id }}">
                            <button type="button" wire:click="open('{{ $item->ulid }}')" class="flex w-full items-center gap-4 px-5 py-3 text-left hover:bg-surface-2">
                                <x-ui.badge :tone="$item->scoreTone()">{{ $item->score }}%</x-ui.badge>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-ink">{{ \App\Models\CallLog::display($item->call?->caller_name) }} · {{ $item->organization?->name }}</span>
                                    <span class="block truncate text-xs text-subtle">{{ $item->call?->call_id }} · reviewed {{ $item->reviewed_at->diffForHumans() }}</span>
                                </span>
                                @unless ($item->acknowledged_at)<x-ui.badge tone="brand">New</x-ui.badge>@endunless
                            </button>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :paginator="$reviews" />
            @endif
        </x-ui.card>
    @elseif ($current === 'queue')
        <x-ui.card title="Review a call" description="The queue fills each morning with random calls from the day before. You can also pick one yourself.">
            <form wire:submit="pick" class="flex flex-wrap items-start gap-3">
                <div class="min-w-48 flex-1">
                    <label for="qa-call" class="sr-only">Call ID</label>
                    <input id="qa-call" type="text" wire:model="callId" class="sh-input" placeholder="Call ID, e.g. CL-20261014-0001" autocomplete="off">
                    @error('callId') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <x-ui.button type="submit">Review this call</x-ui.button>
                <x-ui.button type="button" variant="secondary" wire:click="random">Pick one at random</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card class="mt-6" :padding="false" title="Waiting for review">
            @if ($reviews->isEmpty())
                <x-ui.empty-state icon="check-circle" title="All caught up" description="New calls are added each morning." />
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Call</th>
                            <th scope="col">Agent</th>
                            <th scope="col" class="hidden md:table-cell">Business</th>
                            <th scope="col" class="hidden md:table-cell">Picked</th>
                            <th scope="col"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($reviews as $item)
                            <tr wire:key="queue-{{ $item->id }}">
                                <td>
                                    <p class="font-medium text-ink">{{ \App\Models\CallLog::display($item->call?->caller_name) }}</p>
                                    <p class="text-xs text-subtle">{{ $item->call?->call_id }} · {{ $item->call?->created_at?->diffForHumans() }}</p>
                                </td>
                                <td class="text-muted">{{ $item->agent->name ?? '—' }}</td>
                                <td class="hidden text-muted md:table-cell">{{ $item->organization->name ?? '—' }}</td>
                                <td class="hidden text-muted md:table-cell">{{ $item->source === 'sample' ? 'At random' : 'By hand' }}</td>
                                <td class="text-right"><x-ui.button size="sm" variant="secondary" wire:click="open('{{ $item->ulid }}')">Score</x-ui.button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <x-ui.pagination :paginator="$reviews" />
            @endif
        </x-ui.card>
    @else
        <x-ui.card :padding="false" title="By agent" description="Last 30 days, lowest average first, so the people who most need coaching are on top.">
            @if ($agents->isEmpty())
                <x-ui.empty-state icon="users" title="No reviews in the last 30 days" description="Scored calls appear here." />
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Agent</th>
                            <th scope="col" class="text-right">Reviews</th>
                            <th scope="col" class="text-right">Average</th>
                            <th scope="col" class="text-right">Passed</th>
                            <th scope="col"><span class="sr-only">Filter</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($agents as $row)
                            @php($average = (int) round((float) $row->getAttribute('average')))
                            <tr wire:key="agent-{{ $row->agent_user_id }}">
                                <td class="font-medium text-ink">{{ $row->agent->name ?? 'Former agent' }}</td>
                                <td class="text-right text-muted">{{ $row->getAttribute('reviews') }}</td>
                                <td class="text-right"><x-ui.badge :tone="$average >= $passScore ? 'success' : ($average >= 60 ? 'warning' : 'danger')">{{ $average }}%</x-ui.badge></td>
                                <td class="text-right text-muted">{{ (int) round(100 * (int) $row->getAttribute('passes') / max(1, (int) $row->getAttribute('reviews'))) }}%</td>
                                <td class="text-right"><button type="button" wire:click="$set('agent', '{{ $row->agent_user_id }}')" class="text-sm text-brand-300 hover:underline">Show calls</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card class="mt-6" :padding="false" title="Scored calls">
            @if ($agent !== '')
                <x-slot:actions><x-ui.button size="sm" variant="ghost" wire:click="$set('agent', '')">Show everyone</x-ui.button></x-slot:actions>
            @endif
            @if ($reviews->isEmpty())
                <x-ui.empty-state icon="phone" title="No scored calls yet" description="Calls you and other reviewers score appear here." />
            @else
                <x-ui.table>
                    <thead>
                        <tr>
                            <th scope="col">Score</th>
                            <th scope="col">Call</th>
                            <th scope="col">Agent</th>
                            <th scope="col" class="hidden md:table-cell">Reviewer</th>
                            <th scope="col" class="hidden md:table-cell">Read</th>
                            <th scope="col" class="text-right">Reviewed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($reviews as $item)
                            <tr wire:key="done-{{ $item->id }}" class="cursor-pointer hover:bg-surface-2" wire:click="open('{{ $item->ulid }}')">
                                <td><x-ui.badge :tone="$item->scoreTone()">{{ $item->score }}%</x-ui.badge></td>
                                <td>
                                    <p class="font-medium text-ink">{{ \App\Models\CallLog::display($item->call?->caller_name) }}</p>
                                    <p class="text-xs text-subtle">{{ $item->call?->call_id }} · {{ $item->organization->name ?? '—' }}</p>
                                </td>
                                <td class="text-muted">{{ $item->agent->name ?? '—' }}</td>
                                <td class="hidden text-muted md:table-cell">{{ $item->reviewer->name ?? '—' }}</td>
                                <td class="hidden md:table-cell">@if ($item->acknowledged_at)<x-ui.badge tone="success">Read</x-ui.badge>@else<x-ui.badge>Not yet</x-ui.badge>@endif</td>
                                <td class="whitespace-nowrap text-right text-muted">{{ $item->reviewed_at->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
                <x-ui.pagination :paginator="$reviews" />
            @endif
        </x-ui.card>
    @endif

    @if ($open)
        @php($call = $open->call)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="qa-title" x-data x-on:keydown.escape.window="$wire.close()">
            <div class="fixed inset-0 bg-black/60" wire:click="close"></div>
            <div tabindex="-1" x-init="$nextTick(() => $el.focus())" class="relative max-h-[90vh] focus:outline-none w-full max-w-2xl overflow-y-auto rounded-2xl border border-line-strong bg-surface p-6 shadow-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 id="qa-title" class="text-lg font-semibold text-ink">Call {{ $call?->call_id }}</h2>
                        <p class="mt-1 text-sm text-muted">
                            {{ $open->agent->name ?? 'Former agent' }} for {{ $open->organization->name ?? 'a business' }}
                            @if ($call) · {{ $call->created_at->timezone($open->organization?->timezoneOrDefault() ?? config('app.timezone'))->format('D, M j, g:i A') }}@endif
                        </p>
                    </div>
                    @if ($open->isCompleted())<x-ui.badge :tone="$open->scoreTone()">{{ $open->score }}% · {{ $open->passed ? 'Passed' : 'Below the bar' }}</x-ui.badge>@endif
                </div>

                @if ($call)
                    <dl class="mt-4 grid gap-x-6 gap-y-2 rounded-xl bg-surface-2 p-4 text-sm sm:grid-cols-2">
                        @foreach (\App\Livewire\Agent\Quality::callFacts($call) as $label => $value)
                            @if (filled($value))
                                <div><dt class="text-xs text-subtle">{{ $label }}</dt><dd class="text-ink">{{ $value }}</dd></div>
                            @endif
                        @endforeach
                        @if (filled($call->notes))
                            <div class="sm:col-span-2"><dt class="text-xs text-subtle">Agent notes</dt><dd class="whitespace-pre-line text-ink">{{ $call->notes }}</dd></div>
                        @endif
                    </dl>
                @endif

                @if ($canEdit)
                    <form wire:submit="save" class="mt-5 space-y-4">
                        @foreach ($scorecard as $key => $criterion)
                            <fieldset wire:key="crit-{{ $key }}">
                                <legend class="text-sm font-medium text-ink">{{ $criterion['label'] }}@if ($criterion['critical'] ?? false) <x-ui.badge tone="danger">Must pass</x-ui.badge>@endif</legend>
                                <p class="text-xs text-subtle">{{ $criterion['description'] }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($markLabels as $value => $label)
                                        @continue($value === 'na' && ! $criterion['optional'])
                                        <label class="cursor-pointer">
                                            <input type="radio" class="peer sr-only" wire:model="marks.{{ $key }}" name="mark-{{ $key }}" value="{{ $value }}">
                                            <span class="inline-block rounded-full border border-line-strong px-3 py-1 text-sm text-muted peer-checked:border-brand-500 peer-checked:bg-brand-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-400">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('marks.'.$key) <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                            </fieldset>
                        @endforeach

                        <div>
                            <label for="qa-strengths" class="sh-label">What went well</label>
                            <textarea id="qa-strengths" wire:model="strengths" rows="2" class="sh-input" maxlength="2000"></textarea>
                            @error('strengths') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="qa-improvements" class="sh-label">What to do differently</label>
                            <textarea id="qa-improvements" wire:model="improvements" rows="2" class="sh-input" maxlength="2000"></textarea>
                            @error('improvements') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost" wire:click="close">Cancel</x-ui.button>
                            <x-ui.button type="submit">{{ $open->isCompleted() ? 'Update review' : 'Save review' }}</x-ui.button>
                        </div>
                    </form>
                @else
                    @if ($open->isCompleted())
                        <ul class="mt-5 divide-y divide-line rounded-xl border border-line">
                            @foreach ($open->scores ?? [] as $key => $criterion)
                                <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                                    <span class="text-ink">{{ $criterion['label'] }}</span>
                                    <x-ui.badge :tone="['met' => 'success', 'partly' => 'warning', 'missed' => 'danger'][$criterion['mark']] ?? 'neutral'">{{ $markLabels[$criterion['mark']] ?? $criterion['mark'] }}</x-ui.badge>
                                </li>
                            @endforeach
                        </ul>
                        @if ($open->strengths)
                            <h3 class="mt-5 text-sm font-semibold text-ink">What went well</h3>
                            <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $open->strengths }}</p>
                        @endif
                        @if ($open->improvements)
                            <h3 class="mt-4 text-sm font-semibold text-ink">What to do differently</h3>
                            <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ $open->improvements }}</p>
                        @endif
                        <p class="mt-4 text-xs text-subtle">Reviewed by {{ $open->reviewer->name ?? 'a supervisor' }} {{ $open->reviewed_at?->diffForHumans() }}.</p>
                    @endif
                    <div class="mt-5 flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" wire:click="close">Close</x-ui.button>
                        @if ($open->agent_user_id === auth()->id() && ! $open->acknowledged_at)
                            <x-ui.button type="button" wire:click="acknowledge">Got it</x-ui.button>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
