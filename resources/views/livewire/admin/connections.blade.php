<div>
    <x-ui.page-header title="Connections" description="Connect Claude or ChatGPT and the social networks from here: no code or server changes. Keys are saved encrypted and never shown again." />

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($groups as $group => $g)
            @php($connected = collect($g['fields'])->filter(fn ($f) => ($f['secret'] ?? false))->keys()->every(fn ($k) => $sources[$k] !== null))
            <x-ui.card wire:key="group-{{ $group }}" :title="$g['label']" :description="$g['help']">
                <x-slot:actions>
                    @if ($connected)<x-ui.badge tone="success">Set up</x-ui.badge>@else<x-ui.badge>Not set up</x-ui.badge>@endif
                </x-slot:actions>

                <form wire:submit="save('{{ $group }}')" class="space-y-4">
                    @foreach ($g['fields'] as $key => $field)
                        @php($id = 'f-'.str_replace('.', '-', $key))
                        <div wire:key="field-{{ $key }}">
                            @if (($field['type'] ?? 'text') === 'bool')
                                <label class="flex items-center gap-2 text-sm text-ink">
                                    <input type="checkbox" wire:model="values.{{ $key }}" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                    {{ $field['label'] }}
                                </label>
                            @else
                                <div class="flex items-baseline justify-between gap-2">
                                    <label for="{{ $id }}" class="sh-label">{{ $field['label'] }}</label>
                                    @if ($sources[$key] === 'env')<span class="text-xs text-subtle">from server settings</span>@endif
                                </div>
                                @if (($field['type'] ?? 'text') === 'select')
                                    <select id="{{ $id }}" wire:model="values.{{ $key }}" class="sh-input">
                                        @foreach ($field['options'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                    </select>
                                @elseif ($field['secret'] ?? false)
                                    <div class="flex gap-2">
                                        <input id="{{ $id }}" type="password" wire:model="values.{{ $key }}" class="sh-input font-mono" autocomplete="off"
                                            placeholder="{{ $masked[$key] ? 'Saved: '.$masked[$key].' (type to replace)' : ($field['placeholder'] ?? '') }}">
                                        @if ($sources[$key] === 'saved')
                                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="remove('{{ $key }}')" wire:confirm="Remove this {{ strtolower($field['label']) }}? Anything using it stops working.">Remove</x-ui.button>
                                        @endif
                                    </div>
                                @elseif (($field['type'] ?? 'text') === 'model')
                                    @php($found = collect($models[$field['provider']] ?? [])->filter(fn ($m) => ($field['images'] ?? false) ? str_contains($m, 'image') : ! str_contains($m, 'image') && ! str_contains($m, 'embedding') && ! str_contains($m, 'tts') && ! str_contains($m, 'whisper') && ! str_contains($m, 'dall-e')))
                                    <input id="{{ $id }}" type="text" wire:model="values.{{ $key }}" class="sh-input font-mono" list="{{ $id }}-list" autocomplete="off"
                                        placeholder="{{ $found->isEmpty() ? 'Press Test above to load your models' : 'Choose or type a model' }}">
                                    <datalist id="{{ $id }}-list">@foreach ($found as $m)<option value="{{ $m }}">@endforeach</datalist>
                                @else
                                    <input id="{{ $id }}" type="text" wire:model="values.{{ $key }}" class="sh-input font-mono" autocomplete="off">
                                @endif
                            @endif
                            @error('values.'.$key)<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                            @if ($key === 'anthropic_api_key' || $key === 'openai_api_key')
                                <x-ui.button type="button" size="sm" variant="secondary" class="mt-2" wire:click="testAi('{{ $key === 'openai_api_key' ? 'openai' : 'claude' }}')">
                                    Test and load models
                                </x-ui.button>
                            @endif
                        </div>
                    @endforeach

                    @if ($group === 'ai')
                        <p class="text-xs text-subtle">Pictures in social posts need ChatGPT: Claude writes text only. You can keep Claude for writing and still connect ChatGPT for pictures.</p>
                    @endif

                    @isset($callbacks[$group])
                        <div class="rounded-lg bg-surface-2 p-3 text-xs text-muted">
                            <p class="font-medium text-ink">Redirect URLs to add in the {{ $g['label'] }} app</p>
                            <p class="mt-1 break-all font-mono">{{ $callbacks[$group]['surehelp'] }}</p>
                            <p class="break-all font-mono">{{ $callbacks[$group]['business'] }}</p>
                            <p class="mt-1">The first is for SureHelp's own accounts, the second for businesses'.</p>
                        </div>
                    @endisset

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit">Save {{ $g['label'] }}</x-ui.button>
                        @if ($group === 'meta')
                            <x-ui.button type="button" variant="secondary" wire:click="testMeta">Test</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.card>
        @endforeach
    </div>
</div>
