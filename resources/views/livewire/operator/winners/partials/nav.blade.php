{{-- Meta reklamları sekmeleri: Meta masası, Strateji öner, Metinler ve takvim (Kazananlar ekranı 2026-10-29'da kalktı; metinler ve takvim Strateji öner'in kaydettikleriyle burada). Sayfanın ilk öğesi, başlığın üstünde. --}}
@php
    $tabs = [['operator.meta-desk', 'Meta reklamları'], ['operator.meta-strategy', 'Strateji öner'], ['operator.libraries', 'Metinler ve takvim']];
@endphp
<nav class="no-scrollbar -mt-1 mb-6 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Bölüm">
    @foreach ($tabs as [$route, $label])
        <a href="{{ route($route) }}" wire:navigate @if ($current === $route) aria-current="page" @endif @class(['tab', 'tab-active' => $current === $route])>{{ $label }}</a>
    @endforeach
</nav>
