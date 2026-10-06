<div>
    <x-ui.page-header title="Your account" description="Your own details, password, security and notifications. Only you can see this page." />
    @include('livewire.account._tabs')

    <form wire:submit="save">
        <x-ui.card title="Quiet hours" description="Emails wait until morning. In-app notifications still arrive quietly." class="mb-6 max-w-xl">
            <label class="flex items-center gap-2 text-sm font-medium text-ink">
                <input type="checkbox" wire:model.live="quietOn" class="size-4 rounded border-line-strong bg-surface-2 text-brand-500"> Hold emails overnight
            </label>
            @if ($quietOn)
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted">
                    <span>From</span>
                    <input type="time" wire:model="quietStart" class="sh-input w-32" aria-label="Quiet hours start">
                    <span>until</span>
                    <input type="time" wire:model="quietEnd" class="sh-input w-32" aria-label="Quiet hours end">
                </div>
                @error('quietEnd') <p class="mt-1 text-sm text-danger">{{ $message }}</p> @enderror
            @endif
        </x-ui.card>

        <x-ui.card :padding="false">
            <x-ui.table>
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        @foreach ($channels as $key => $channel)
                            <th scope="col" class="text-center">{{ $channel['label'] }}@unless ($channel['enabled'])<span class="block text-[10px] font-medium normal-case tracking-normal text-subtle">Coming soon</span>@endunless</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($events as $event)
                        <tr wire:key="pref-{{ $event->value }}">
                            <td><p class="font-medium text-ink">{{ $event->label() }}</p><p class="text-xs text-subtle">{{ $event->description() }}</p></td>
                            @foreach ($channels as $key => $channel)
                                <td class="text-center align-middle">
                                    @if ($channel['enabled'])
                                        <input type="checkbox" wire:model="preferences.{{ $event->name }}.{{ $key }}" class="size-5 rounded border-line-strong bg-surface-2 text-brand-500 focus:ring-brand-400" aria-label="{{ $channel['label'] }} for {{ $event->label() }}">
                                    @else
                                        <input type="checkbox" disabled class="size-5 rounded border-line bg-surface-3 opacity-40" aria-label="{{ $channel['label'] }} for {{ $event->label() }} (coming soon)">
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($channels) + 1 }}"><x-ui.empty-state icon="bell" title="Nothing to set up" description="Your role doesn't receive assignment or training notifications." /></td></tr>
                    @endforelse
                </tbody>
            </x-ui.table>
            <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-4">
                <p class="text-xs text-subtle">Emails go to {{ auth()->user()->email }}. Optional training is only announced in the app.</p>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save settings</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
