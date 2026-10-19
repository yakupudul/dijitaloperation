{{-- Reklam alanının sekmeleri: Meta masası · Strateji öner · Kazananlar · Kütüphaneler. $current. Sayfa başlığının altında durur (Google Ads sekmeleri gibi). --}}
<nav class="-mb-px flex gap-1 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-800" aria-label="Reklam alanı">
    @foreach ([['operator.meta-desk', 'Meta masası'], ['operator.meta-strategy', 'Strateji öner'], ['operator.winners', 'Kazananlar'], ['operator.libraries', 'Kütüphaneler']] as [$route, $label])
        <a href="{{ route($route) }}" wire:navigate @if ($current === $route) aria-current="page" @endif @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium',
            'border-brand-500 text-brand-600 dark:text-brand-400' => $current === $route,
            'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => $current !== $route])>{{ $label }}</a>
    @endforeach
</nav>
