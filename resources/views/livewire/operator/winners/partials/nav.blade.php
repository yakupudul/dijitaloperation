{{-- Reklam alanının üst bağlantıları: Meta masası · Strateji öner · Kazananlar · Kütüphaneler. $current --}}
<nav class="flex flex-wrap gap-1 text-sm" aria-label="Reklam alanı">
    @foreach ([['operator.meta-desk', 'Meta masası'], ['operator.meta-strategy', 'Strateji öner'], ['operator.winners', 'Kazananlar'], ['operator.libraries', 'Kütüphaneler']] as [$route, $label])
        <a href="{{ route($route) }}" wire:navigate @class(['rounded-lg px-3 py-1.5 font-medium',
            'bg-[#14171f] text-[#e9cf8a] dark:bg-white/10' => $current === $route,
            'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5' => $current !== $route])>{{ $label }}</a>
    @endforeach
</nav>
