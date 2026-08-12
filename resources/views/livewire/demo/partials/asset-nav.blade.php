@php
    $tabs = $tabs ?? [];
    $active = $active ?? null;
@endphp

<nav class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-800" aria-label="Asset workspace">
    @foreach ($tabs as $tab)
        @php
            $key = $tab['key'] ?? '';
            $label = $tab['label'] ?? $key;
            $isActive = $active === $key;
            $baseClass = 'inline-flex items-center rounded-t-lg px-3 py-2 text-sm font-medium transition';
            $activeClass = 'border-b-2 border-brand-500 text-brand-600 dark:text-brand-400';
            $inactiveClass = 'border-b-2 border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white/80';
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
