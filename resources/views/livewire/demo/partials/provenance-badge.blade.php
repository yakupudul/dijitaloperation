@php
    $label = $label ?? 'Detected';
@endphp

<x-ta.badge color="light" size="sm" class="font-normal text-gray-500 dark:text-gray-400">
    {{ $label }}
</x-ta.badge>
