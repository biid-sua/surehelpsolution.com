<div>
    <x-ui.page-header title="Business" description="Emails your customers get about their appointments, sent in your name. Replies come to you." />
    @include('livewire.client.business._tabs')

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
        <x-ui.card :padding="false">
            <ul class="divide-y divide-line" role="list">
                @foreach ($templates as $key => $t)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4" wire:key="tpl-{{ $key }}">
                        <div class="min-w-0">
                            <p class="font-medium text-ink">{{ $t['label'] }}
                                @if ($t['current']['custom'])<x-ui.badge tone="brand">Your wording</x-ui.badge>@endif
                            </p>
                            <p class="text-xs text-subtle">{{ $t['when'] }}{{ $key === 'appointment_reminder' ? ' Currently '.$t['current']['lead_hours'].' hours before.' : '' }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($canEdit)
                                <label class="flex items-center gap-2 text-sm text-muted">
                                    <input type="checkbox" @checked($t['current']['is_active']) wire:click="toggle('{{ $key }}')" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> On
                                </label>
                                <x-ui.button size="sm" variant="secondary" wire:click="edit('{{ $key }}')">Edit</x-ui.button>
                            @else
                                <x-ui.badge :tone="$t['current']['is_active'] ? 'success' : 'neutral'">{{ $t['current']['is_active'] ? 'On' : 'Off' }}</x-ui.badge>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            <p class="border-t border-line px-5 py-3 text-xs text-subtle">Emails go to customers with an email address on file. Each one is noted on the customer's timeline.</p>
        </x-ui.card>

        <x-ui.card class="lg:col-start-1" title="Customers change or cancel online" description="Confirmation, reminder and time-change emails get a button that opens a page where the customer can pick a new free time or cancel. No account needed; you're notified of every change.">
            <form wire:submit="saveSelfService" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="ss-hours" class="sh-label">Allow it until</label>
                    <select id="ss-hours" wire:model="changeHours" class="sh-input w-64" @disabled(! $canEdit)>
                        <option value="off">Off: customers call us</option>
                        @foreach ($hourOptions as $h)<option value="{{ $h }}">{{ $h }} hours before the appointment</option>@endforeach
                    </select>
                </div>
                @if ($canEdit)<x-ui.button type="submit" size="sm">Save</x-ui.button>@endif
            </form>
        </x-ui.card>

        <div>
            @if ($editing)
                <x-ui.card :title="$templates[$editing]['label']">
                    <form wire:submit="save" class="space-y-4">
                        <div>
                            <label for="t-subject" class="sh-label">Subject</label>
                            <input id="t-subject" type="text" wire:model.live.debounce.400ms="draft.subject" class="sh-input">
                            @error('draft.subject') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="t-body" class="sh-label">Message</label>
                            <textarea id="t-body" wire:model.live.debounce.400ms="draft.body" rows="10" class="sh-input font-mono text-sm"></textarea>
                            @error('draft.body') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
                        </div>
                        @if ($editing === 'appointment_reminder')
                            <div>
                                <label for="t-lead" class="sh-label">Send it</label>
                                <select id="t-lead" wire:model="draft.lead_hours" class="sh-input">
                                    @foreach ([2, 4, 12, 24, 48] as $h)<option value="{{ $h }}">{{ $h }} hours before</option>@endforeach
                                </select>
                            </div>
                        @endif
                        <details class="text-xs text-muted">
                            <summary class="cursor-pointer text-brand-300">Placeholders you can use</summary>
                            <ul class="mt-2 space-y-1">@foreach ($placeholders as $p => $label)<li><code class="text-ink">{{ '{'.$p.'}' }}</code> {{ $label }}</li>@endforeach</ul>
                        </details>
                        <div class="rounded-xl bg-white p-4 text-sm text-gray-900">
                            <p class="text-xs uppercase tracking-wide text-gray-500">Preview</p>
                            <p class="mt-1 font-semibold">{{ $preview['subject'] }}</p>
                            <p class="mt-2 whitespace-pre-line">{{ $preview['body'] }}</p>
                        </div>
                        <div class="flex flex-wrap justify-between gap-2">
                            <div class="flex gap-1">
                                <x-ui.button size="sm" variant="ghost" wire:click="restoreDefault">Use our wording</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="sendTest">Send me a test</x-ui.button>
                            </div>
                            <div class="flex gap-2">
                                <x-ui.button variant="secondary" wire:click="cancel">Cancel</x-ui.button>
                                <x-ui.button type="submit">Save</x-ui.button>
                            </div>
                        </div>
                    </form>
                </x-ui.card>
            @else
                <x-ui.card>
                    <p class="text-sm text-muted">Choose an email to change its wording. The preview shows exactly what customers receive.</p>
                </x-ui.card>
            @endif
        </div>
    </div>
</div>
