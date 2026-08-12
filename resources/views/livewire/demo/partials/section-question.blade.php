@php
    $question = $question ?? '';
    $hint = $hint ?? null;
@endphp

<div class="mb-3">
    <h2 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $question }}</h2>
    @if ($hint)
        <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $hint }}</p>
    @endif
</div>
