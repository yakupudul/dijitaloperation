@props([
    'title' => 'Nothing here yet',
    'message' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-2xl border border-dashed border-gray-200 bg-gray-50 px-6 py-12 text-center dark:border-gray-800 dark:bg-white/[0.02]']) }}>
    <div class="flex items-center justify-center w-12 h-12 rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800">
        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9"/><path d="M3 15l4-4a2 2 0 0 1 2.8 0l4.2 4"/><path d="M14 14l1-1a2 2 0 0 1 2.8 0L21 16"/><circle cx="8" cy="9" r="1.5"/>
        </svg>
    </div>
    <h4 class="mt-4 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h4>
    @if ($message)
        <p class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
