@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Sayfalama" class="flex items-center justify-between text-sm">
        <p class="text-gray-500 dark:text-gray-400">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / toplam {{ $paginator->total() }} görev</p>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="rounded-lg px-3 py-1.5 text-gray-300 ring-1 ring-inset ring-gray-200 dark:text-gray-600 dark:ring-gray-700">« Önceki</span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" class="rounded-lg px-3 py-1.5 text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">« Önceki</button>
            @endif
            <span class="px-2 text-gray-500">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" class="rounded-lg px-3 py-1.5 text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Sonraki »</button>
            @else
                <span class="rounded-lg px-3 py-1.5 text-gray-300 ring-1 ring-inset ring-gray-200 dark:text-gray-600 dark:ring-gray-700">Sonraki »</span>
            @endif
        </div>
    </nav>
@endif
