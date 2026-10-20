{{-- Why a website cluster view is empty: one source (SiteScope::clusterReadiness) and the next step, for every tab. --}}
@props(['assetId', 'what' => 'Bu görünüm'])
@php
    $site = \App\Models\DigitalAsset::query()->find($assetId);
    $brand = $site !== null ? \App\Services\Site\SiteScope::brandOf($site) : null;
    $r = $brand !== null ? \App\Services\Site\SiteScope::clusterReadiness($brand, $site) : null;
    $queries = route('operator.library.queries', array_filter(['tab' => 'clusters', 'sector' => $r['sector_id'] ?? null]));
    [$text, $link, $label] = match ($r['step'] ?? 'brand') {
        'brand' => ['Site bir markaya bağlı değil.', null, null],
        'services' => ['Markada etkin hizmet yok.', route('operator.brand', ['brand' => $brand->id]), 'Markaya hizmet ekle'],
        'catalog' => ['Markanın '.$r['services'].' hizmeti sistem hizmet kataloğuna bağlı değil; kümeler hizmet üzerinden gelir.', route('operator.brand', ['brand' => $brand->id]), 'Hizmetleri bağla'],
        'clusters' => ['Markanın '.$r['linked'].' hizmetinde henüz küme yok. Otomatik pilot sorguları kümelediğinde burada görünür.', $queries, 'Sorgular › Kümeler'],
        'approve' => ['Markanın hizmetlerinde '.$r['clusters'].' küme var, hiçbiri onaylı değil. İçerik fikirleri\'nde listelenir, oradan onaylayabilirsin.', route('operator.website', ['assetId' => $assetId, 'tab' => 'sorgular', 'sub' => 'fikirler']), 'Kümeleri onayla'],
        'match' => [$r['approved'].' onaylı küme var, sayfalarla henüz eşleştirilmedi.', route('operator.website', ['assetId' => $assetId, 'tab' => 'sorgular', 'sub' => 'fikirler']), 'İçerik fikirleri › Eşleştir'],
        default => [null, null, null],
    };
@endphp
@if ($text !== null)
    <div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 py-2.5 text-xs text-gray-700 dark:border-gray-800 dark:text-gray-300']) }} data-cluster-readiness="{{ $r['step'] ?? 'brand' }}">
        <span class="flex items-baseline gap-2"><span class="h-1.5 w-1.5 shrink-0 translate-y-[-1px] rounded-full bg-warning-500" aria-hidden="true"></span><span><span class="font-medium text-warning-800 dark:text-warning-400">{{ $what }} boş:</span> {{ $text }}</span></span>
        @if ($link !== null)<a href="{{ $link }}" wire:navigate class="shrink-0 rounded-lg px-3 py-1.5 font-medium text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-white dark:ring-gray-600 dark:hover:bg-white/5">{{ $label }}</a>@endif
    </div>
@endif
