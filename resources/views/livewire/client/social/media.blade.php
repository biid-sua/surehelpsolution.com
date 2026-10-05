<div>
    <x-ui.page-header title="Social media" description="Photos for your posts: your work, your team, your premises. Real photos get far more engagement than stock images." />
    @include('livewire.client.social._tabs')

    @if ($canManage)
        <x-ui.card class="mb-6">
            <label for="media-upload" class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-line-strong px-6 py-8 text-center hover:border-brand-400">
                <x-ui.icon name="photo" class="size-8 text-subtle" />
                <span class="font-medium text-ink">Add photos</span>
                <span class="text-sm text-muted">JPG, PNG or WebP, up to {{ round(config('social.media.max_kb') / 1024) }} MB each, 10 at a time</span>
                <input id="media-upload" type="file" wire:model="uploads" multiple accept="image/jpeg,image/png,image/webp" class="sr-only">
            </label>
            <div wire:loading wire:target="uploads" class="mt-3 text-sm text-muted">Uploading…</div>
            @error('uploads') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
            @error('uploads.*') <p class="mt-2 text-sm text-danger">{{ $message }}</p> @enderror
        </x-ui.card>
    @endif

    @if ($assets->isEmpty())
        <x-ui.card><x-ui.empty-state icon="photo" title="No photos yet" description="Upload photos once and use them in any post." /></x-ui.card>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($assets as $asset)
                <x-ui.card :padding="false" wire:key="m-{{ $asset->ulid }}" class="overflow-hidden">
                    <img src="{{ $asset->url() }}" alt="{{ $asset->alt_text }}" class="aspect-square w-full bg-surface-2 object-cover" loading="lazy">
                    <div class="space-y-2 p-3">
                        <p class="truncate text-xs text-subtle" title="{{ $asset->original_name }}">{{ $asset->original_name }}@if ($asset->width) · {{ $asset->width }}×{{ $asset->height }}@endif</p>
                        @if ($canManage)
                            <form wire:submit="saveAlt('{{ $asset->ulid }}')" class="space-y-2">
                                <label for="alt-{{ $asset->ulid }}" class="sr-only">Description</label>
                                <input id="alt-{{ $asset->ulid }}" type="text" wire:model="alt.{{ $asset->ulid }}" class="sh-input py-1.5 text-sm" placeholder="Describe the photo" maxlength="500">
                                <div class="flex justify-between">
                                    <x-ui.button type="submit" size="sm" variant="secondary">Save</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="delete('{{ $asset->ulid }}')" wire:confirm="Delete this photo?">Delete</x-ui.button>
                                </div>
                            </form>
                        @elseif ($asset->alt_text)
                            <p class="text-sm text-muted">{{ $asset->alt_text }}</p>
                        @endif
                    </div>
                </x-ui.card>
            @endforeach
        </div>
        <div class="mt-4"><x-ui.pagination :paginator="$assets" /></div>
    @endif
</div>
