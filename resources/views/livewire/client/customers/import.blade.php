<div>
    <x-ui.page-header title="Import customers" description="Bring your customer list from a spreadsheet. Save it as CSV first (File › Download or Save as › CSV).">
        <x-slot:actions><x-ui.button variant="ghost" :href="route('app.customers.index')">Back to customers</x-ui.button></x-slot:actions>
    </x-ui.page-header>

    @if ($result)
        <x-ui.alert tone="success" class="mb-6" title="Import finished">
            {{ $result['created'] }} added, {{ $result['updated'] }} updated, {{ $result['skipped'] }} skipped.
            @if ($result['problems'])
                <ul class="mt-2 list-disc pl-5 text-xs">@foreach ($result['problems'] as $p)<li>{{ $p }}</li>@endforeach</ul>
                @if ($result['skipped'] > count($result['problems']))<p class="mt-1 text-xs">…and {{ $result['skipped'] - count($result['problems']) }} more.</p>@endif
            @endif
            <a href="{{ route('app.customers.index') }}" class="mt-2 inline-block font-semibold underline">See your customers</a>
        </x-ui.alert>
    @endif

    <x-ui.card title="1. Choose the file">
        <input type="file" wire:model="file" accept=".csv,text/csv" aria-label="CSV file" class="block text-sm text-muted file:mr-3 file:rounded-lg file:border-0 file:bg-surface-2 file:px-3 file:py-1.5 file:text-ink">
        <p class="mt-2 text-xs text-subtle">CSV, up to {{ number_format($maxRows) }} customers. The first row should be column names. Each customer needs a name, phone or email.</p>
        <div wire:loading wire:target="file" class="mt-2 text-sm text-muted">Reading the file…</div>
        @error('file') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
    </x-ui.card>

    @if ($headers)
        <x-ui.card class="mt-6" :padding="false" title="2. Match the columns" :description="number_format($rowCount).' '.str('customer')->plural($rowCount).' in the file. The first rows are shown so you can check.'">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-line">
                            @foreach ($headers as $i => $h)
                                <th scope="col" class="min-w-44 px-3 py-3 text-left align-top font-normal">
                                    <span class="block text-xs font-semibold text-ink">{{ $h ?: 'Column '.($i + 1) }}</span>
                                    <label for="map-{{ $i }}" class="sr-only">Field for {{ $h }}</label>
                                    <select id="map-{{ $i }}" wire:model="mapping.{{ $i }}" class="sh-input mt-1 py-1 text-xs">
                                        <option value="">Don't import</option>
                                        @foreach ($fields as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                                    </select>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($preview as $row)
                            <tr>@foreach ($headers as $i => $h)<td class="max-w-56 truncate px-3 py-2 text-muted">{{ $row[$i] ?? '' }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="space-y-4 border-t border-line px-5 py-4">
                @error('mapping') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model="fillExisting" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                    <span><span class="font-medium text-ink">Fill in missing details for customers you already have</span>
                        <span class="block text-xs text-subtle">Matched by phone or email. Only empty fields are filled; nothing you have is overwritten. Off: they're skipped.</span></span>
                </label>
                <p class="text-xs text-subtle">Permission to text or email can't be imported. Record it on each customer when they give it.</p>
                <x-ui.button wire:click="import" wire:loading.attr="disabled" wire:target="import">Import {{ number_format($rowCount) }} {{ str('customer')->plural($rowCount) }}</x-ui.button>
                <span wire:loading wire:target="import" class="ml-2 text-sm text-muted">Importing…</span>
            </div>
        </x-ui.card>
    @endif
</div>
