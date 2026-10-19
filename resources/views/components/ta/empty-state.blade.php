@props([
    'title' => 'Nothing here yet',
    'message' => null,
    'size' => 'md',
])

@php
    // Quiet empty state: a small check mark and one line; "nothing to do" is good news, not a dashed warning box.
    $pad = $size === 'sm' ? 'px-4 py-6' : 'px-6 py-10';
@endphp

<div {{ $attributes->merge(['class' => "flex flex-col items-center justify-center rounded-xl bg-gray-50 text-center dark:bg-white/[0.02] $pad"]) }}>
    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400">
        <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>
    </div>
    <h4 class="mt-3 text-sm font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h4>
    @if ($message)
        <p class="mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
