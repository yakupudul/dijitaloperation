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
    <div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 p-3 text-xs text-amber-900 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20']) }} data-cluster-readiness="{{ $r['step'] ?? 'brand' }}">
        <span><span class="font-semibold">{{ $what }} boş:</span> {{ $text }}</span>
        @if ($link !== null)<a href="{{ $link }}" wire:navigate class="shrink-0 rounded-lg bg-amber-600 px-3 py-1.5 font-semibold text-white">{{ $label }} →</a>@endif
    </div>
@endif
