{{-- Reklam alanının sekmeleri: Meta masası, Strateji öner, Kazananlar, Kütüphaneler. $current. Sayfanın ilk öğesi, başlığın üstünde (düzenin bölüm sekmeleriyle aynı görünüm). --}}
<nav class="no-scrollbar -mt-1 mb-6 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Reklam alanı">
    @foreach ([['operator.meta-desk', 'Meta masası'], ['operator.meta-strategy', 'Strateji öner'], ['operator.winners', 'Kazananlar'], ['operator.libraries', 'Kütüphaneler']] as [$route, $label])
        <a href="{{ route($route) }}" wire:navigate @if ($current === $route) aria-current="page" @endif @class(['tab', 'tab-active' => $current === $route])>{{ $label }}</a>
    @endforeach
</nav>
