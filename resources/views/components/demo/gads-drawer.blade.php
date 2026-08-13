@props([
    'title' => '',
    'subtitle' => null,
    'severity' => null,
])

<div class="fixed inset-0 z-40 flex justify-end" role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <button type="button" class="absolute inset-0 bg-gray-900/40" wire:click="closeDrawers" aria-label="Close drawer"></button>
    <aside class="relative flex h-full w-full max-w-lg flex-col overflow-y-auto border-l border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900">
        <div class="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-gray-100 bg-white px-5 py-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="min-w-0">
                @if ($severity)
                    <x-ta.badge :color="match($severity) { 'Critical', 'critical' => 'error', 'High', 'high' => 'error', 'Medium', 'medium' => 'warning', default => 'info' }" size="sm">{{ $severity }}</x-ta.badge>
                @endif
                <h2 class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-gray-500">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" wire:click="closeDrawers" class="rounded-lg px-2 py-1 text-sm text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Close">✕</button>
        </div>
        <div class="space-y-4 px-5 py-4 text-sm">
            {{ $slot }}
        </div>
    </aside>
</div>
