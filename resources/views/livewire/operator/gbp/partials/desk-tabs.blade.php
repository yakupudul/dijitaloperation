@php
    // İşletme profilleri tabs: the first element of the page, above the title, the same look as the layout's section tabs.
    $deskTabs = [
        'operator.gbp-desk' => 'Durum ve ölçüm',
        'operator.gbp-posts' => 'Gönderiler',
        'operator.gbp-branch-pages' => 'Şube sayfaları',
        'operator.gbp-profile-fields' => 'Açıklama ve saatler',
        'operator.gbp-photos' => 'Fotoğraflar',
        'operator.gbp-reviews' => 'Yorumlar',
    ];
@endphp
<nav class="no-scrollbar -mt-1 mb-6 flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="İşletme profilleri">
    @foreach ($deskTabs as $routeName => $label)
        <a href="{{ route($routeName, array_filter(['marka' => $brandFilter ?? null])) }}" wire:navigate @if ($active === $routeName) aria-current="page" @endif
            @class(['tab', 'tab-active' => $active === $routeName])>{{ $label }}</a>
    @endforeach
</nav>
