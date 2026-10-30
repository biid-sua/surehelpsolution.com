{{-- A photo, or a video's first frame with a play badge, from the media library (D104). --}}
@props(['asset', 'alt' => null])
@if ($asset->isVideo())
    <span {{ $attributes->merge(['class' => 'relative block overflow-hidden bg-surface-2']) }}>
        <video src="{{ $asset->url() }}#t=0.5" preload="metadata" muted playsinline class="size-full object-cover" aria-label="{{ $alt ?? $asset->alt_text ?? 'Video' }}"></video>
        <span class="absolute inset-0 flex items-center justify-center" aria-hidden="true">
            <span class="rounded-full bg-black/60 p-2 text-white"><x-ui.icon name="video" class="size-5" /></span>
        </span>
        @if ($asset->duration_seconds)<span class="absolute bottom-1 right-1 rounded bg-black/70 px-1 text-[0.6875rem] text-white">{{ gmdate($asset->duration_seconds >= 3600 ? 'G:i:s' : 'i:s', $asset->duration_seconds) }}</span>@endif
    </span>
@else
    <img src="{{ $asset->url() }}" alt="{{ $alt ?? $asset->alt_text }}" {{ $attributes }} loading="lazy">
@endif
