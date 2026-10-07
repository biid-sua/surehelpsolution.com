<x-ui.card :padding="false" title="Features" description="Paid features this business has now. Override the plan to give or take one away.">
    <form wire:submit="save">
        <ul class="divide-y divide-line" role="list">
            @foreach ($catalog as $key => $feature)
                <li class="flex flex-wrap items-center gap-3 px-5 py-3" wire:key="org-feature-{{ $key }}">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-ink">{{ $feature['label'] }}</p>
                        <p class="text-xs text-subtle">{{ $modes[$key] === 'everyone' ? 'Open to every business' : 'Needs a plan or add-on' }}</p>
                    </div>
                    <x-ui.badge :tone="$effective[$key] ? 'success' : 'neutral'">{{ $effective[$key] ? 'Has it' : 'Not included' }}</x-ui.badge>
                    <label for="org-feature-{{ $key }}" class="sr-only">{{ $feature['label'] }}</label>
                    <select id="org-feature-{{ $key }}" wire:model="choices.{{ $key }}" class="sh-input w-40 py-1.5 text-sm" @disabled(! $canManage)>
                        <option value="plan">Follow the plan</option>
                        <option value="on">Always on</option>
                        <option value="off">Always off</option>
                    </select>
                </li>
            @endforeach
        </ul>
        @if ($canManage)
            <div class="flex justify-end border-t border-line px-5 py-3"><x-ui.button type="submit" size="sm">Save</x-ui.button></div>
        @endif
    </form>
</x-ui.card>
