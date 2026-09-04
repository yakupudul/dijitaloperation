@php
    $number = static fn ($value, int $decimals = 0): string => $value === null ? '—' : number_format((float) $value, $decimals, ',', '.');
    $money = static fn ($value): string => $value === null ? '—' : '$'.number_format((float) $value, 4, ',', '.');
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi · Faz 7</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">DataForSEO / SERP Zenginleştirmesi</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Seçilen hizmet veya kümenin sorgularına lokasyon, dil ve cihaz bağlamında organik SERP, marka konumu ve tahmini arama hacmi ekler. Hiçbir çağrı marka oluşturulurken otomatik yapılmaz.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-demand-clusters', ['brand' => $website?->brand_id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Sorgu kümeleri</a>
            <a href="{{ route('operator.library.search-demand-visibility', ['website' => $website?->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Görünürlük haritası</a>
            <a href="{{ route('operator.library.search-demand-ownership', ['website' => $website?->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">URL sahipliği</a>
            <a href="{{ route('operator.library.search-demand-competitors', ['brand' => $website?->brand_id, 'website' => $website?->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Rakip kütüphanesi</a>
        </div>
    </div>

    @if ($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $messageTone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $message }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 lg:grid-cols-6">
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">Website</span><select wire:model.live="selectedWebsiteId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website seçin</option>@foreach($websites as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ $option->brand?->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Toplu kapsam</span><select wire:model.live="scopeType" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="cluster">Küme</option><option value="service">Hizmet</option></select></label>
            <label class="block lg:col-span-2"><span class="mb-1 block text-xs font-medium text-gray-500">{{ $scopeType === 'cluster' ? 'Küme' : 'Hizmet' }}</span><select wire:model.live="scopeId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Seçin</option>@if($scopeType === 'cluster')@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }} · {{ $cluster->memberships_count }} sorgu</option>@endforeach @else @foreach($services as $service)<option value="{{ $service->id }}">{{ $service->primaryName?->raw_label ?: '#'.$service->id }}</option>@endforeach @endif</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Cihaz</span><select wire:model.live="device" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="desktop">Masaüstü</option><option value="mobile">Mobil</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Organik derinlik</span><select wire:model.live="depth" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="10">İlk 10</option><option value="20">İlk 20</option></select></label>
            @if($website)<div class="rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500 lg:col-span-2 dark:bg-white/[0.03]"><span class="block font-medium text-gray-700 dark:text-gray-200">Pazar bağlamı</span>{{ $website->seo_market_location_name ?: $website->seo_market_location_code ?: '—' }} · {{ $website->seo_market_language_name ?: $website->seo_market_language_code ?: '—' }}</div>@endif
        </div>

        @if($planError)<p class="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-800">{{ $planError }}</p>@endif
        @if($plan)
            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><p class="text-xs text-gray-500">Sorgu</p><p class="mt-1 text-xl font-semibold">{{ $plan['query_count'] }}</p></div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><p class="text-xs text-gray-500">SERP cache / yeni</p><p class="mt-1 text-xl font-semibold">{{ $plan['serp_cache_hits'] }} / {{ $plan['serp_misses'] }}</p></div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><p class="text-xs text-gray-500">Hacim cache / yeni / destek dışı</p><p class="mt-1 text-xl font-semibold">{{ $plan['metric_cache_hits'] }} / {{ $plan['metric_misses'] }} / {{ $plan['metric_unsupported'] }}</p></div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><p class="text-xs text-gray-500">Ücretli POST üst sınırı</p><p class="mt-1 text-xl font-semibold">{{ $plan['estimate']['provider_request_count'] }}</p></div>
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700"><p class="text-xs text-gray-500">Yapılandırma tahmini</p><p class="mt-1 text-xl font-semibold">{{ $money($plan['estimate']['estimated_cost_usd']) }}</p></div>
            </div>
            <p class="mt-3 text-xs text-gray-400">Cache {{ $plan['freshness_days'] }} gün geçerlidir. Para tahmini sağlayıcı teklifi değildir; oranlar yapılandırılmamışsa “—” gösterilir. Kesin değer yalnız DataForSEO’nun yanıtında raporlanan maliyettir.</p>
            <label class="mt-3 flex items-start gap-3 rounded-lg border border-gray-200 p-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200"><input wire:model.live="includeExpansion" type="checkbox" class="mt-1 rounded border-gray-300 text-brand-500" /><span><strong>İlgili sorgu adayları da üret.</strong> Bu seçenek ayrı bir ücretli Keyword Ideas isteği açabilir. Sonuçlar doğrudan portföye yazılmaz; insan onay kuyruğuna gelir. @if($includeExpansion && $plan['expansion_cache_hit'])<em class="text-emerald-700">Taze expansion cache kullanılacak.</em>@endif</span></label>
            @if(!$plan['readiness']['configured'])<p class="mt-3 rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">{{ $plan['readiness']['message'] }}</p>@endif
            <label class="mt-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900"><input wire:model="paidConsent" type="checkbox" class="mt-1 rounded border-amber-300 text-amber-600" /><span>Bu çalışmanın cache dışındaki SERP ve arama hacmi isteklerinin ücretli olabileceğini, otomatik tekrar yapılmayacağını ve raporlanan maliyetin çağrıdan sonra kesinleşeceğini onaylıyorum.</span></label>
            @error('paidConsent')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            <button wire:click="queueEnrichment" wire:loading.attr="disabled" @disabled(!$plan['readiness']['configured']) type="button" class="mt-3 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Ücretli çalışmayı kuyruğa al</button>
        @endif
    </section>

    @if($website)
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-base font-semibold text-gray-900 dark:text-white">Zenginleştirme çalışmaları</h2><p class="mt-1 text-sm text-gray-500">Paid POST’lar bir kez denenir; belirsiz sonuç otomatik tekrarlanmaz.</p></div><select wire:change="openRun($event.target.value)" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Çalışma seçin</option>@foreach($runs as $option)<option value="{{ $option->id }}" @selected($run?->id === $option->id)>#{{ $option->id }} · {{ $option->scope_type }} · {{ $option->status }}</option>@endforeach</select></div>
            @if($run)
                <div @if(in_array($run->status, ['queued', 'running'], true)) wire:poll.5s="refreshRun" @endif class="mt-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <div class="flex flex-wrap justify-between gap-3 text-sm"><p><strong>Çalışma #{{ $run->id }}</strong> · {{ $run->status }} · {{ $run->query_count }} sorgu</p><p class="text-xs text-gray-500">{{ $run->location_name ?: $run->location_code }} · {{ $run->language_name ?: $run->language_code }} · {{ $run->device }} · ilk {{ $run->depth }}</p></div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4 text-xs"><p>Tahmini: <strong>{{ $money($run->estimated_cost_usd) }}</strong></p><p>Raporlanan: <strong>{{ $money($run->reported_cost_usd) }}</strong></p><p>Yeni provider POST: <strong>{{ $run->provider_request_count }}</strong></p><p>Cache: <strong>{{ $run->serp_cache_hits }} SERP / {{ $run->metric_cache_hits }} hacim</strong></p></div>
                    @if(in_array($run->status, ['failed', 'charge_unknown'], true) || $run->error_code)<p class="mt-3 rounded {{ $run->status === 'charge_unknown' ? 'bg-amber-50 text-amber-900' : 'bg-red-50 text-red-800' }} px-3 py-2 text-xs">{{ $run->error_code }} · {{ $run->error_summary }}</p>@endif
                    @if($run->items->isNotEmpty())<div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead class="text-gray-500"><tr><th class="px-2 py-2">Sorgu</th><th class="px-2 py-2">SERP</th><th class="px-2 py-2">Hacim</th><th class="px-2 py-2">Marka konumu</th><th class="px-2 py-2">Son kontrol</th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach($run->items as $item)<tr><td class="px-2 py-2 font-medium">{{ $item->query_text }}</td><td class="px-2 py-2">{{ $item->serp_status }}</td><td class="px-2 py-2">{{ $item->metric_status }} · {{ $number($item->keywordMetricSnapshot?->search_volume) }}</td><td class="px-2 py-2">{{ $item->serpSnapshot?->brand_rank ?: '—' }}</td><td class="px-2 py-2">{{ $item->serpSnapshot?->retrieved_at?->format('Y-m-d H:i') ?: $item->keywordMetricSnapshot?->retrieved_at?->format('Y-m-d H:i') ?: '—' }}</td></tr>@endforeach</tbody></table></div>@endif
                </div>
            @endif
        </section>

        @if($run?->clusterReviews?->isNotEmpty())
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">SERP örtüşmesi · insan kararı</h2><p class="mt-1 text-sm text-gray-500">Öneri ilk organik URL’lerin gözlemlenen Jaccard örtüşmesidir. Küme durumuna ancak onayla uygulanır; üyelik veya URL sahipliği değiştirilmez.</p>
                <div class="mt-4 grid gap-3 lg:grid-cols-2">@foreach($run->clusterReviews as $review)<article class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"><div class="flex justify-between gap-3"><p class="font-medium text-gray-900 dark:text-white">{{ $review->cluster?->name }}</p><span class="rounded bg-gray-100 px-2 py-1 text-[10px] dark:bg-gray-800">{{ $review->status }}</span></div><p class="mt-2 text-xs text-gray-500">{{ $review->rationale }}</p><p class="mt-2 text-xs">Öneri: <strong>{{ $review->recommended_status }}</strong> · ortalama örtüşme {{ $review->mean_url_overlap === null ? '—' : $number((float)$review->mean_url_overlap * 100, 1).'%' }}</p>@if($review->status === 'pending')<div class="mt-3 flex gap-2"><button wire:click="reviewCluster({{ $review->id }}, 'approve')" type="button" class="rounded bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white">Onayla</button><button wire:click="reviewCluster({{ $review->id }}, 'reject')" type="button" class="rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white">Reddet</button></div>@endif</article>@endforeach</div>
            </section>
        @endif

        @if($run?->expansionCandidates?->isNotEmpty())
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">İlgili sorgu adayları</h2><p class="mt-1 text-sm text-gray-500">DataForSEO genişletme sonuçları yalnız adaydır. Onay, sorguyu marka portföyüne ekler ve seçili Website için etkinleştirir; kümeye otomatik atama yapmaz.</p>
                <div class="mt-4 overflow-x-auto"><table class="min-w-full text-left text-xs"><thead class="text-gray-500"><tr><th class="px-2 py-2">Sorgu</th><th class="px-2 py-2">Tahmini hacim</th><th class="px-2 py-2">Rekabet</th><th class="px-2 py-2">Durum</th><th class="px-2 py-2"></th></tr></thead><tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach($run->expansionCandidates as $candidate)<tr><td class="px-2 py-2 font-medium">{{ $candidate->keyword }}</td><td class="px-2 py-2">{{ $number($candidate->search_volume) }}</td><td class="px-2 py-2">{{ $candidate->competition ?: '—' }}</td><td class="px-2 py-2">{{ $candidate->status }}</td><td class="px-2 py-2">@if($candidate->status === 'pending')<div class="flex gap-2"><button wire:click="reviewExpansion({{ $candidate->id }}, 'approve')" type="button" class="rounded bg-emerald-600 px-2 py-1 text-white">Onayla</button><button wire:click="reviewExpansion({{ $candidate->id }}, 'reject')" type="button" class="rounded bg-red-600 px-2 py-1 text-white">Reddet</button></div>@endif</td></tr>@endforeach</tbody></table></div>
            </section>
        @endif

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">Son gözlemler</h2><p class="mt-1 text-sm text-gray-500">Arama hacmi ve trend sağlayıcı tahminidir; GSC gösterim/tıklama gerçeğiyle birleştirilmez. Eksik değer sıfır değildir.</p>
            @if($latestRows->isEmpty())<p class="mt-4 text-sm text-gray-500">Bu Website için SERP gözlemi henüz yok.</p>@else<div class="mt-4 space-y-3">@foreach($latestRows as $row)@php($snapshot = $row['snapshot'])@php($metric = $row['metric'])@php($trend = collect($metric?->monthly_searches)->take(4)->map(fn($point) => data_get($point, 'year', '?').'-'.str_pad((string)data_get($point, 'month', '?'), 2, '0', STR_PAD_LEFT).': '.$number(data_get($point, 'search_volume')))->join(' · '))<article class="rounded-lg border border-gray-200 p-4 dark:border-gray-700"><div class="flex flex-wrap justify-between gap-3"><div><p class="font-medium text-gray-900 dark:text-white">{{ $snapshot->query_text }}</p><p class="mt-1 text-xs text-gray-500">{{ $snapshot->cluster?->name ?: 'Kümesiz' }} · {{ $snapshot->device }} · {{ $snapshot->retrieved_at->format('Y-m-d H:i') }}</p></div><div class="text-right text-xs"><p>Hacim: <strong>{{ $number($metric?->search_volume) }}</strong> (tahmin)</p><p>Marka: <strong>{{ $snapshot->brand_rank ? '#'.$snapshot->brand_rank : '—' }}</strong></p></div></div><p class="mt-2 text-xs text-gray-500">Trend: {{ $trend ?: '—' }} · SERP özellikleri: {{ collect($snapshot->serp_features)->join(', ') ?: '—' }}</p><ol class="mt-3 grid gap-2 lg:grid-cols-2">@foreach($snapshot->results->take(4) as $result)<li class="min-w-0 text-xs"><span class="font-semibold">{{ $result->rank_group ?: '—' }}.</span> <a href="{{ $result->url }}" target="_blank" rel="noopener noreferrer" class="text-brand-600 underline">{{ $result->title ?: $result->domain ?: $result->url }}</a><span class="ml-1 text-gray-400">{{ $result->domain }}</span></li>@endforeach</ol></article>@endforeach</div>@endif
        </section>
    @endif
</div>
