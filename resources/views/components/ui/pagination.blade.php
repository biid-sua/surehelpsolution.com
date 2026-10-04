@props(['paginator'])

{{-- Server-side pagination for Livewire tables (spec §47). Uses Livewire's WithPagination actions. --}}
@if ($paginator->total() > 0)
    <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3 text-sm" aria-label="Pagination">
        <p class="text-muted">
            Showing <span class="font-medium text-ink">{{ number_format($paginator->firstItem()) }}</span>–<span class="font-medium text-ink">{{ number_format($paginator->lastItem()) }}</span>
            of <span class="font-medium text-ink">{{ number_format($paginator->total()) }}</span>
        </p>
        @if ($paginator->hasPages())
            <div class="flex items-center gap-1">
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" @disabled($paginator->onFirstPage())
                    class="rounded-lg p-2 text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-40" aria-label="Previous page">
                    <x-ui.icon name="chevron-left" class="size-4" />
                </button>
                <span class="px-2 text-muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" @disabled(! $paginator->hasMorePages())
                    class="rounded-lg p-2 text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-40" aria-label="Next page">
                    <x-ui.icon name="chevron-right" class="size-4" />
                </button>
            </div>
        @endif
    </nav>
@endif
