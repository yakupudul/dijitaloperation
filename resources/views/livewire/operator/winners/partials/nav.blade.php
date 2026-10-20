{{-- Sekmeler, $current'a göre iki küme (yakup 2026-10-20: Kazananlar yalnız menüde, Meta'nın sekmesi değil): Meta reklamları + Strateji öner; Kazananlar + Metinler ve takvim. Sayfanın ilk öğesi, başlığın üstünde. --}}
@php
    $tabs = in_array($current, ['operator.winners', 'operator.libraries'], true)
        ? [['operator.winners', 'Kazananlar'], ['operator.libraries', 'Metinler ve takvim']]
        : [['operator.meta-desk', 'Meta reklamları'], ['operator.meta-strategy', 'Strateji öner']];
@endphp
<nav class="no-scrollbar -mt-1 mb-6 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Bölüm">
    @foreach ($tabs as [$route, $label])
        <a href="{{ route($route) }}" wire:navigate @if ($current === $route) aria-current="page" @endif @class(['tab', 'tab-active' => $current === $route])>{{ $label }}</a>
    @endforeach
</nav>
