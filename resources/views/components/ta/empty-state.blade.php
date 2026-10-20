@props([
    'title' => 'Nothing here yet',
    'message' => null,
    'size' => 'md',
    /** check = "all good"; none for a missing-data state that is not a success. */
    'icon' => 'check',
])

@php
    // Quiet empty state: a gray check, the title and one gray line; sits inside a card like a row.
    $pad = $size === 'sm' ? 'px-4 py-3.5' : 'px-4 py-6';
@endphp

<div {{ $attributes->merge(['class' => "flex items-start gap-2.5 text-left $pad"]) }}>
    @if ($icon === 'check')
        <svg class="mt-0.5 h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
    @endif
    <div class="min-w-0">
        <h4 class="text-sm font-medium text-gray-900 dark:text-white/90">{{ $title }}</h4>
        @if ($message)
            <p class="mt-0.5 max-w-xl text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
        @endif
        @if (! $slot->isEmpty())
            <div class="mt-3">{{ $slot }}</div>
        @endif
    </div>
</div>
