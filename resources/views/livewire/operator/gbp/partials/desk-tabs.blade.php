@php
    $deskTabs = [
        'operator.gbp-desk' => 'Durum ve ölçüm',
        'operator.gbp-posts' => 'Gönderiler',
        'operator.gbp-branch-pages' => 'Şube sayfaları',
        'operator.gbp-profile-fields' => 'Açıklama ve saatler',
        'operator.gbp-photos' => 'Fotoğraflar',
        'operator.gbp-reviews' => 'Yorumlar',
    ];
@endphp
<nav class="-mb-px flex gap-1 overflow-x-auto border-b border-gray-200 text-sm dark:border-gray-700" aria-label="İşletme profilleri">
    @foreach ($deskTabs as $routeName => $label)
        <a href="{{ route($routeName, array_filter(['marka' => $brandFilter ?? null])) }}" wire:navigate
            @class(['whitespace-nowrap border-b-2 px-3 py-2 font-medium', 'border-brand-500 text-brand-600 dark:text-brand-400' => $active === $routeName, 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' => ! ($active === $routeName)])>{{ $label }}</a>
    @endforeach
</nav>
