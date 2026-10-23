{{-- The flow builder (D81), one list of steps. $list is the list's path ('' for the main one), $depth how deep it is. --}}
<ol class="space-y-3" role="list">
    @foreach ($steps as $i => $step)
        @php($path = $list === '' ? (string) $i : $list.'.'.$i)
        @php($m = 'form.steps.'.$path)
        @php($idp = 's'.str_replace('.', '-', $path))
        <li class="relative rounded-xl border p-3 {{ ['wait' => 'border-dashed border-line bg-surface', 'branch' => 'border-warning/40 bg-surface', 'action' => 'border-line bg-surface-2/40'][$step['type']] ?? 'border-line' }}" wire:key="step-{{ $path }}-{{ $step['type'] }}">
            <div class="mb-2 flex items-center gap-2">
                <span class="grid size-6 place-items-center rounded-full bg-surface-2 text-xs font-semibold text-muted ring-1 ring-line">{{ $i + 1 }}</span>
                <span class="flex-1 text-sm font-medium text-ink">{{ ['wait' => 'Wait', 'branch' => 'If…', 'action' => 'Do'][$step['type']] ?? 'Step' }}</span>
                <button type="button" wire:click="moveStep('{{ $path }}', -1)" @disabled($i === 0) class="rounded px-1.5 text-muted hover:text-ink disabled:opacity-30" aria-label="Move step {{ $i + 1 }} up">↑</button>
                <button type="button" wire:click="moveStep('{{ $path }}', 1)" @disabled($i === count($steps) - 1) class="rounded px-1.5 text-muted hover:text-ink disabled:opacity-30" aria-label="Move step {{ $i + 1 }} down">↓</button>
                <button type="button" wire:click="removeStep('{{ $path }}')" class="rounded px-1.5 text-sm text-muted hover:text-danger" aria-label="Remove step {{ $i + 1 }}">Remove</button>
            </div>

            @if ($step['type'] === 'wait')
                <div class="flex flex-wrap items-center gap-2">
                    <label for="{{ $idp }}-amount" class="sr-only">Wait for</label>
                    <input id="{{ $idp }}-amount" type="number" min="1" wire:model="{{ $m }}.amount" class="sh-input w-24">
                    <label for="{{ $idp }}-unit" class="sr-only">Unit</label>
                    <select id="{{ $idp }}-unit" wire:model="{{ $m }}.unit" class="sh-input w-auto">
                        @foreach ($units as $u)<option value="{{ $u }}">{{ $u }}</option>@endforeach
                    </select>
                    <span class="text-xs text-subtle">then carry on.</span>
                </div>
                @error($m.'.amount') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            @elseif ($step['type'] === 'branch')
                @include('livewire.client.business._automation-rules', ['rules' => $step['rules'] ?? [], 'key' => $m.'.rules', 'path' => $path, 'branch' => true])
                @error($m.'.rules') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                <div class="mt-3 grid gap-3 xl:grid-cols-2">
                    @foreach (['then' => 'Yes: do this', 'else' => 'Otherwise'] as $side => $label)
                        <div class="rounded-lg border-l-2 {{ $side === 'then' ? 'border-success/60' : 'border-line-strong' }} pl-3">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-subtle">{{ $label }}</p>
                            @include('livewire.client.business._automation-steps', ['steps' => $step[$side] ?? [], 'list' => $path.'.'.$side, 'depth' => $depth + 1])
                        </div>
                    @endforeach
                </div>
            @else
                <div class="space-y-3">
                    @include('livewire.client.business._automation-action', ['step' => $step, 'm' => $m, 'idp' => $idp, 'trigger' => $form['trigger'] ?? ''])
                </div>
            @endif
        </li>
    @endforeach
</ol>
@if ($stepCount < $maxSteps)
    <div class="mt-2 flex flex-wrap gap-2 text-sm">
        <button type="button" wire:click="addStep('{{ $list }}', 'action')" class="rounded-lg border border-line px-2.5 py-1 text-brand-300 hover:bg-surface-2">+ Action</button>
        <button type="button" wire:click="addStep('{{ $list }}', 'wait')" class="rounded-lg border border-line px-2.5 py-1 text-brand-300 hover:bg-surface-2">+ Wait</button>
        @if ($depth < $maxDepth)
            <button type="button" wire:click="addStep('{{ $list }}', 'branch')" class="rounded-lg border border-line px-2.5 py-1 text-brand-300 hover:bg-surface-2">+ If / otherwise</button>
        @endif
    </div>
@endif
