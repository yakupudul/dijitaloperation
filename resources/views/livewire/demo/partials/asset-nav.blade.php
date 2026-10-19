@php
    $tabs = $tabs ?? [];
    $active = $active ?? null;
@endphp

<nav class="flex flex-wrap items-center gap-x-5 border-b border-gray-200 dark:border-gray-800" aria-label="Sekmeler">
    @foreach ($tabs as $tab)
        @php
            $key = $tab['key'] ?? '';
            $label = $tab['label'] ?? $key;
            $isActive = $active === $key;
            // Same tab row as every asset page (Web sitesi): gray underline, never orange.
            $baseClass = '-mb-px inline-flex h-10 items-center whitespace-nowrap border-b-2 text-sm transition';
            $activeClass = 'border-gray-900 font-semibold text-gray-900 dark:border-white dark:text-white';
            $inactiveClass = 'border-transparent font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
            $class = $baseClass.' '.($isActive ? $activeClass : $inactiveClass);
        @endphp

        @if (! empty($tab['url']))
            <a href="{{ $tab['url'] }}" @class([$class]) wire:navigate>{{ $label }}</a>
        @elseif (! empty($tab['wire']))
            <button type="button" wire:click="setTab('{{ $key }}')" @class([$class])>{{ $label }}</button>
        @else
            <span @class([$class, 'cursor-default'])>{{ $label }}</span>
        @endif
    @endforeach
</nav>
