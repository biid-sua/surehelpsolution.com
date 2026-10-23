{{-- Conditions (D80, D81): the automation's "Only if", or a flow's "If" step. $key is the wire path, $path the step's path ('' for "Only if"). --}}
@php($idp = 'r'.str_replace('.', '-', $path))
@foreach ($rules as $i => $rule)
    @php($field = $conditionFields[$rule['field'] ?? ''] ?? null)
    <div class="mb-2 flex flex-wrap items-center gap-2" wire:key="{{ $idp }}-rule-{{ $i }}">
        <label for="{{ $idp }}-field-{{ $i }}" class="sr-only">Condition</label>
        <select id="{{ $idp }}-field-{{ $i }}" wire:model.live="{{ $key }}.{{ $i }}.field" class="sh-input w-auto">
            @foreach ($conditionFields as $k => $f)
                @if ($branch || ! ($f['branch_only'] ?? false))<option value="{{ $k }}">{{ $f['label'] }}</option>@endif
            @endforeach
        </select>
        <label for="{{ $idp }}-op-{{ $i }}" class="sr-only">Test</label>
        <select id="{{ $idp }}-op-{{ $i }}" wire:model="{{ $key }}.{{ $i }}.op" class="sh-input w-auto">
            @foreach ($field['ops'] ?? [] as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
        </select>
        @if (($field['value'] ?? null) !== null)
            <label for="{{ $idp }}-val-{{ $i }}" class="sr-only">Value</label>
            <select id="{{ $idp }}-val-{{ $i }}" wire:model="{{ $key }}.{{ $i }}.value" class="sh-input w-auto">
                <option value="">Choose…</option>
                @foreach (['tag' => $tags->pluck('name', 'id')->all(), 'status' => $statuses, 'outcome' => $outcomes][$field['value']] as $k => $label)
                    <option value="{{ $k }}">{{ $label }}</option>
                @endforeach
            </select>
        @endif
        <button type="button" wire:click="removeRule({{ $i }}, '{{ $path }}')" class="text-sm text-muted hover:text-danger" aria-label="Remove this condition">Remove</button>
        @error($key.'.'.$i.'.value') <p class="w-full text-sm text-danger">{{ $message }}</p> @enderror
    </div>
@endforeach
@if (count($rules) < 5)
    <button type="button" wire:click="addRule('{{ $path }}')" class="text-sm text-brand-300 hover:underline">+ {{ $rules === [] ? 'Add a condition' : 'And…' }}</button>
@endif
