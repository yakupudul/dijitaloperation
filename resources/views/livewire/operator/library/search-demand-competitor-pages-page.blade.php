@php
    $statusLabels = [
        'queued' => 'Kuyrukta', 'running' => 'Çalışıyor', 'completed' => 'Tamamlandı',
        'partial' => 'Kısmi', 'failed' => 'Başarısız', 'unchanged' => 'Değişmedi',
    ];
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600">Arama talebi · Faz 10</p>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Rakip Sayfa Toplama</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Onaylı rakiplerden küme başına sınırlı ve tekilleştirilmiş URL seçer. Yalnız bu URL’leri Public Discovery güvenlik sınırlarıyla alır; rakip sitesinin tamamını taramaz.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.library.search-demand-competitors', ['brand' => $selectedBrandId, 'website' => $selectedWebsiteId, 'cluster' => $selectedClusterId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Rakip kütüphanesi</a>
            <a href="{{ route('operator.library.search-demand-competitive-intelligence', ['brand' => $selectedBrandId, 'website' => $selectedWebsiteId, 'cluster' => $selectedClusterId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Competitive Intelligence</a>
            <a href="{{ route('operator.activity') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200">Activity</a>
        </div>
    </div>

    @if($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $messageTone === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' }}">{{ $message }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-3 lg:grid-cols-4">
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Marka</span><select wire:model.live="selectedBrandId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Marka seçin</option>@foreach($brands as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Website</span><select wire:model.live="selectedWebsiteId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Website seçin</option>@foreach($websites as $website)<option value="{{ $website->id }}">{{ $website->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">İçerik hedef kümesi</span><select wire:model.live="selectedClusterId" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"><option value="">Küme seçin</option>@foreach($clusters as $cluster)<option value="{{ $cluster->id }}">{{ $cluster->name }}</option>@endforeach</select></label>
            <label class="block"><span class="mb-1 block text-xs font-medium text-gray-500">Run URL sınırı</span><select wire:model.live="maxUrls" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">@foreach([5, 10, 15, 20] as $limit)<option value="{{ $limit }}">{{ $limit }} URL</option>@endforeach</select></label>
        </div>
        @error('selectedWebsiteId')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        @error('selectedClusterId')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <button wire:click="queueCollection" wire:loading.attr="disabled" type="button" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600 disabled:opacity-50">Seçili URL’leri kuyrukta topla</button>
            <p class="text-xs text-gray-500">Domain başına en fazla 3, run başına en fazla 20 URL. Sayfadaki bağlantılar saklanır ama takip edilmez.</p>
        </div>
    </section>

    @if($latestRun)
        <section class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-gray-950/40">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-semibold text-gray-500">Son toplama #{{ $latestRun->id }}</p><p class="mt-1 text-sm text-gray-800 dark:text-gray-200">{{ data_get($latestRun->metadata, 'phase_label', $latestRun->status) }}</p></div><span class="rounded px-2.5 py-1 text-xs font-semibold {{ in_array($latestRun->status, ['failed', 'partial']) ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' }}">{{ $statusLabels[$latestRun->status] ?? $latestRun->status }}</span></div>
            @if(data_get($latestRun->metadata, 'result_summary'))<p class="mt-2 text-xs text-gray-500">{{ data_get($latestRun->metadata, 'result_summary') }}</p>@endif
        </section>
    @endif

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="font-semibold text-gray-900 dark:text-white">Bu run için seçilecek URL’ler</h2><p class="mt-1 text-xs text-gray-500">SERP kaynağı ve en iyi gözlenen sıra önce gelir; URL hash’i ile tekilleştirilir.</p></div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse($preview as $index => $row)
                <div class="grid gap-2 px-5 py-3 text-sm md:grid-cols-[3rem_14rem_1fr_6rem]"><span class="text-gray-400">#{{ $index + 1 }}</span><span class="font-medium text-gray-800 dark:text-gray-200">{{ $row['competitor_name'] }}</span><span class="break-all text-gray-500">{{ $row['url'] }}</span><span class="text-gray-500">{{ $row['best_observed_rank'] ? 'SERP #'.$row['best_observed_rank'] : 'Sıra —' }}</span></div>
            @empty
                <div class="px-5 py-8 text-center text-sm text-gray-500">Küme için toplanabilir onaylı rakip URL’si yok.</div>
            @endforelse
        </div>
    </section>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="font-semibold text-gray-900 dark:text-white">Rakip sayfa gözlem geçmişi</h2><p class="mt-1 text-xs text-gray-500">Aynı içerik yeniden parse edilmez; yeni gözlem önceki içerik kaydını kullanır.</p></div>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse($observations as $row)
                @php($observation = $row['observation'])
                <article class="p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $row['competitor']?->display_name ?: 'Rakip' }}</h3><p class="mt-1 break-all text-xs text-gray-500">{{ $observation->final_url ?: $observation->requested_url }}</p></div><div class="text-right"><span class="rounded bg-gray-100 px-2 py-1 text-[11px] text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $statusLabels[$observation->status] ?? $observation->status }}</span><p class="mt-1 text-[11px] text-gray-400">{{ $observation->observed_at?->format('Y-m-d H:i') }} UTC · HTTP {{ $observation->http_status ?? '—' }}</p></div></div>
                    @if($observation->fetch_error)<p class="mt-3 text-xs text-red-600">{{ $observation->fetch_error }}</p>@else
                        <div class="mt-4 grid gap-4 text-xs lg:grid-cols-4"><div><p class="font-semibold text-gray-700 dark:text-gray-200">Title / H1</p><p class="mt-1 text-gray-500">{{ $row['title'] ?: '—' }}</p><p class="mt-1 text-gray-400">{{ $row['h1'] ?: 'H1 yok' }}</p></div><div><p class="font-semibold text-gray-700 dark:text-gray-200">Yapı</p><p class="mt-1 text-gray-500">{{ count($row['headings']) }} başlık · {{ count($row['internal_links']) }} iç · {{ count($row['external_links']) }} dış bağlantı</p><p class="mt-1 text-gray-400">Schema: {{ collect(data_get($row['schema'], 'types', []))->join(', ') ?: 'yok/okunamadı' }}</p></div><div><p class="font-semibold text-gray-700 dark:text-gray-200">Hizmet ifadeleri</p><p class="mt-1 text-gray-500">{{ collect($row['services'])->join(' · ') ?: 'eşleşme yok' }}</p></div><div><p class="font-semibold text-gray-700 dark:text-gray-200">Lokasyon ifadeleri</p><p class="mt-1 text-gray-500">{{ collect($row['locations'])->join(' · ') ?: 'eşleşme yok' }}</p></div></div>
                        <p class="mt-3 break-all text-[10px] text-gray-400">İçerik fingerprint: {{ $observation->content_fingerprint ?: '—' }}@if($observation->content_source_observation_id) · içerik kaynağı #{{ $observation->content_source_observation_id }}@endif</p>
                    @endif
                </article>
            @empty
                <div class="px-5 py-10 text-center text-sm text-gray-500">Bu kümede henüz rakip sayfa gözlemi yok.</div>
            @endforelse
        </div>
    </section>
</div>
