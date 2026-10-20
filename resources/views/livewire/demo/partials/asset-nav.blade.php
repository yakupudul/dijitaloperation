@php
    $tabs = $tabs ?? [];
    $active = $active ?? null;
@endphp

{{-- Section tabs of a data screen (Search Console, Analytics): underline tabs; on a phone the row scrolls instead of wrapping. --}}
<nav class="-mx-1 flex gap-1 overflow-x-auto border-b border-gray-200 px-1 no-scrollbar dark:border-gray-800" aria-label="Sekmeler">
    @foreach ($tabs as $tab)
        @php
            $key = $tab['key'] ?? '';
            $label = $tab['label'] ?? $key;
            $class = 'tab h-10 shrink-0 '.($active === $key ? 'tab-active' : '');
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
