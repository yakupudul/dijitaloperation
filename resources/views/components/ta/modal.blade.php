@props([
    'showCloseButton' => true,
])

{{-- Alpine modal, adapted from TailAdmin components/ui/modal.blade.php.
     Expects a parent Alpine scope exposing `open`. --}}
<template x-teleport="body">
    <div x-show="open" x-cloak @keydown.escape.window="open = false"
        class="fixed inset-0 z-99999 flex items-center justify-center overflow-y-auto p-5">
        <div @click="open = false" class="fixed inset-0 h-full w-full bg-gray-900/50 backdrop-blur-[2px]"
            x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"></div>

        <div @click.stop {{ $attributes->merge(['class' => 'relative w-full max-w-2xl rounded-3xl bg-white p-6 dark:bg-gray-900']) }}
            x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100">
            @if ($showCloseButton)
                <button @click="open = false"
                    class="absolute right-4 top-4 flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-400 hover:bg-gray-200 hover:text-gray-700 dark:bg-gray-800 dark:hover:bg-gray-700 dark:hover:text-white">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            @endif
            {{ $slot }}
        </div>
    </div>
</template>
