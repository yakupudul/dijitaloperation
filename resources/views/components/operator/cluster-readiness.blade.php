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
    {{-- One row: an amber dot, why the view is empty, the one next step on the right. Sits inside the caller's card. --}}
    <div {{ $attributes->class(['flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-gray-600 sm:flex-nowrap dark:text-gray-300']) }} data-cluster-readiness="{{ $r['step'] ?? 'brand' }}">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
        <span class="min-w-0 flex-1"><span class="font-medium text-gray-900 dark:text-white">{{ $what }} boş:</span> {{ $text }}</span>
        @if ($link !== null)<a href="{{ $link }}" wire:navigate class="btn w-full sm:w-auto">{{ $label }}</a>@endif
    </div>
@endif
