@php
    $navTabs = [
        ['key' => 'overview', 'label' => __('operator.gsc.tabs.overview'), 'wire' => true],
        ['key' => 'performance', 'label' => __('operator.gsc.tabs.performance'), 'wire' => true],
        ['key' => 'demand', 'label' => __('operator.gsc.tabs.demand'), 'wire' => true],
        ['key' => 'pages', 'label' => __('operator.gsc.tabs.pages'), 'wire' => true],
        ['key' => 'indexing', 'label' => __('operator.gsc.tabs.indexing'), 'wire' => true],
        ['key' => 'operations', 'label' => __('operator.gsc.tabs.operations'), 'wire' => true],
    ];

    // The data layer speaks English status words; the screen shows plain Turkish (panel concept 2026-10-20).
    $words = [
        'critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük',
        'growing' => 'Büyüyor', 'stable' => 'Sabit', 'declining' => 'Düşüyor', 'review' => 'Bakılmalı', 'new' => 'Yeni', 'lost' => 'Kayboldu',
        'ctr review' => 'Tıklama oranı düşük', 'striking distance' => 'İlk sayfaya yakın',
        'indexed' => 'Dizinde', 'not indexed' => 'Dizinde değil', 'unavailable' => 'Veri yok', 'unknown' => 'Bilinmiyor', 'excluded' => 'Hariç',
        'success' => 'Başarılı', 'has errors' => 'Hatalı', 'mismatch' => 'Uyuşmuyor', 'match' => 'Uyuşuyor', 'matches' => 'Uyuşuyor', 'yes' => 'Var', 'no' => 'Yok',
        'improvement observed' => 'İyileşme görüldü', 'still observed' => 'Sürüyor', 'insufficient evidence' => 'Kanıt yetersiz',
        'connected' => 'Bağlı', 'not connected' => 'Bağlı değil', 'action required' => 'Bir adım gerekiyor', 'error' => 'Okuma hatası',
        'healthy' => 'Sağlıklı', 'needs attention' => 'Bakılmalı', 'interrupted' => 'Kesildi', 'linked' => 'Bağlı', 'derived' => 'Türetilmiş',
        'open' => 'Açık', 'in progress' => 'Sürüyor', 'done' => 'Bitti', 'proposed' => 'Önerildi', 'accepted' => 'Kabul edildi', 'planned' => 'Planlandı', 'blocked' => 'Takıldı', 'resolved' => 'Çözüldü', 'monitoring' => 'İzleniyor', 'draft' => 'Taslak',
        'completed' => 'Tamamlandı', 'visibility' => 'Görünürlük', 'indexing' => 'Dizin', 'ownership' => 'Sahiplik', 'ctr' => 'Tıklama oranı', 'freshness' => 'Güncellik', 'performance' => 'Performans', 'content' => 'İçerik', 'technical' => 'Teknik',
        'present' => 'Var', 'missing' => 'Yok', 'observed' => 'Görülüyor', 'pending' => 'Bekliyor', 'no observed impressions' => 'Gösterim yok', 'not in sitemap' => 'Haritada yok',
        'home' => 'Ana sayfa', 'service / product' => 'Hizmet / ürün', 'conversion / contact' => 'İletişim', 'content / blog' => 'Blog', 'team / expert' => 'Ekip', 'legacy' => 'Eski sayfa', 'location' => 'Konum',
        'service pages' => 'Hizmet sayfaları', 'location pages' => 'Konum sayfaları', 'articles' => 'Yazılar', 'faq' => 'SSS',
        'inspect' => 'İncele', 'note' => 'Not', 'open demand' => 'Aramalara bak', 'open indexing' => 'Dizine bak', 'open pages' => 'Sayfalara bak',
        'mobile' => 'Mobil', 'desktop' => 'Masaüstü', 'tablet' => 'Tablet',
        'domain property' => 'Alan adı mülkü', 'url-prefix property' => 'URL ön eki mülkü',
    ];
    $say = function (?string $value) use ($words): string {
        $value = trim((string) $value);
        $key = mb_strtolower(str_replace('_', ' ', $value));

        return $value === '' ? '—' : ($words[$key] ?? str_replace('_', ' ', $value));
    };
    // Button words (a verb, not a status): "Review" on a button is "Gözden geçir".
    $doWords = ['inspect' => 'İncele', 'review' => 'Gözden geçir', 'note' => 'Not', 'open' => 'Aç', 'map action' => 'Eşle', 'open demand' => 'Aramalara bak', 'open indexing' => 'Dizine bak', 'open pages' => 'Sayfalara bak'];
    $sayAction = fn (?string $value): string => $doWords[mb_strtolower(trim((string) $value))] ?? $say($value);
    // What a status means for yakup: red only for lost traffic, amber for "bak", green for fine, gray for the rest.
    $toneOf = fn (?string $value): string => match (mb_strtolower(str_replace('_', ' ', trim((string) $value)))) {
        'critical', 'high', 'declining', 'lost', 'error', 'has errors' => 'critical',
        'medium', 'review', 'ctr review', 'mismatch', 'needs attention', 'action required', 'interrupted', 'still observed', 'insufficient evidence', 'not indexed' => 'look',
        'growing', 'indexed', 'success', 'healthy', 'connected', 'improvement observed', 'linked', 'resolved', 'done', 'completed' => 'ok',
        default => 'info',
    };
    // Provider dates read the Turkish way: "Jul 16" → "16 Tem".
    $months = ['Jan' => 'Oca', 'Feb' => 'Şub', 'Mar' => 'Mar', 'Apr' => 'Nis', 'May' => 'May', 'Jun' => 'Haz', 'Jul' => 'Tem', 'Aug' => 'Ağu', 'Sep' => 'Eyl', 'Oct' => 'Eki', 'Nov' => 'Kas', 'Dec' => 'Ara'];
    $trDay = fn (string $text): string => (string) preg_replace_callback('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{1,2})\b/', fn (array $m): string => $m[2].' '.$months[$m[1]], $text);
    // Glance cell: the change ("−6.8%") goes next to the number, the rest of the provider line becomes a short Turkish hint.
    $phrase = fn (string $text): string => trim(strtr($trDay($text), [
        ' · impressions (not search volume)' => '', ' · sum(clicks)/sum(impressions)' => '',
        'vs previous period' => 'önceki döneme göre', 'vs year-ago period' => 'geçen yıla göre', 'vs previous' => 'öncekine göre',
        'avg pos' => 'ort. sıra', 'Needs attention' => 'dikkat istiyor', 'Unavailable' => 'Veri yok', 'unavailable' => 'yok', ' items' => ' konu', ' · ' => ', ',
        'no_active_search_console_binding' => 'Mülk bağlı değil', 'binding_scope_incomplete' => 'İzin eksik', 'query_error' => 'Okuma hatası',
    ]), " ,·");
    // Provider numbers read the Turkish way: "18,420" → "18.420", "18.4K" → "18,4 B", "2.19%" → "%2,19", "-0.4pp" → "-0,4 puan".
    $trNumber = static function (string $text): string {
        $comma = static fn (string $digits): string => str_replace('.', ',', $digits);
        return match (true) {
            preg_match('/^\d{1,3}(?:,\d{3})+$/', $text) === 1 => str_replace(',', '.', $text),
            preg_match('/^([+\-−]?)(\d+(?:\.\d+)?)\s?%$/u', $text, $m) === 1 => $m[1].'%'.$comma($m[2]),
            preg_match('/^([+\-−]?)(\d+(?:\.\d+)?)\s?pp$/u', $text, $m) === 1 => $m[1].$comma($m[2]).' puan',
            preg_match('/^(\d+(?:\.\d+)?)K$/', $text, $m) === 1 => $comma($m[1]).' B',
            preg_match('/^(\d+(?:\.\d+)?)M$/', $text, $m) === 1 => $comma($m[1]).' Mn',
            preg_match('/^\d+\.\d+$/', $text) === 1 => $comma($text),
            default => $text,
        };
    };
    $glanceCell = function (?array $cell, bool $lossWhenDown = true) use ($phrase, $trNumber): array {
        $cell ??= [];
        $secondary = (string) ($cell['secondary'] ?? '');
        $delta = null;
        if (preg_match('/^\s*([+\-−]?\d[\d.,]*\s?(?:%|pp))\s*(.*)$/u', $secondary, $m) === 1) {
            [$delta, $secondary] = [$m[1], $m[2]];
        }
        $falling = $delta !== null && (str_starts_with($delta, '-') || str_starts_with($delta, '−'));
        $tone = $delta === null ? 'neutral' : ($falling ? ($lossWhenDown ? 'negative' : 'look') : 'positive');
        $value = $phrase((string) ($cell['value'] ?? '—'));

        return ['value' => $value === '' ? '—' : $trNumber($value), 'delta' => $delta === null ? null : $trNumber($delta), 'tone' => $tone, 'hint' => $phrase($secondary), 'muted' => in_array($value, ['', '—'], true), 'note' => $cell['note'] ?? null];
    };

    $status = (string) ($identity['status'] ?? '');
    $connected = ! in_array($status, ['Not connected', 'Action required', 'Error'], true);
    $title = preg_replace('/ — (not connected|read error)$/', '', (string) $identity['title']);

    // Trend chart: the component's series, drawn in the panel's quiet style (near-black line, hairline grid).
    $metricNames = ['clicks' => 'Tıklama', 'impressions' => 'Gösterim', 'ctr' => 'Tıklama oranı (%)', 'position' => 'Ortalama sıra'];
    $chart = $performanceChartOptions;
    $chart['series'][0]['name'] = $metricNames[$metric] ?? 'Tıklama';
    $chart['colors'] = ['#191918'];
    $chart['chart']['fontFamily'] = 'Inter, ui-sans-serif, system-ui, sans-serif';
    $chart['grid'] = ['borderColor' => '#ebebe9', 'strokeDashArray' => 3, 'padding' => ['left' => 4, 'right' => 8]];
    $chart['xaxis']['categories'] = array_map(fn (mixed $label): string => $trDay((string) $label), $chart['xaxis']['categories'] ?? []);
    $chart['responsive'] = [['breakpoint' => 640, 'options' => ['xaxis' => ['tickAmount' => 3]]]];
    $chart['xaxis'] += ['tickAmount' => 6, 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false], 'labels' => ['rotate' => 0, 'hideOverlappingLabels' => true, 'style' => ['colors' => '#787774', 'fontSize' => '12px']]];
    $chart['yaxis'] = [['reversed' => $metric === 'position', 'labels' => ['style' => ['colors' => '#787774', 'fontSize' => '12px']]]];
    $chart['tooltip'] = ['x' => ['show' => true]];
    $hasTrend = ($chart['series'][0]['data'] ?? []) !== [];
    $metricTabs = ['clicks' => 'Tıklama', 'impressions' => 'Gösterim', 'ctr' => 'Tıklama oranı', 'position' => 'Sıra'];
    $series = $data['metric_series'] ?? [];
    $dec = fn (mixed $value, int $decimals = 1): string => is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : (string) ($value ?? '—');
    $spark = fn (string $key): array => array_map('floatval', array_slice($series[$key] ?? [], -28));
@endphp

<div class="min-w-0 space-y-8">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="$title">
        <x-slot:mark><x-demo.digital-asset-mark type="gsc" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>
            @if (filled($identity['property_label'] ?? null))
                <span class="break-all">{{ $identity['property_label'] }}</span>, {{ $say($identity['property_type'] ?? 'Domain property') }}
            @elseif ($connected)
                {{ $identity['relationship_line'] ?? '' }}
            @endif
            @if (filled($identity['website_asset_id'] ?? null))
                <a href="{{ route('operator.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="ml-2 whitespace-nowrap link-neutral" title="{{ $identity['relationship_line'] ?? '' }}">{{ __('operator.chrome.open_website') }}</a>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            @if ($showPeriodBar)
                <x-operator.date-range-picker :range="$this->pickerRange()" :last-day="$this->periodPickerMaxDate()" note="Search Console verisi 2–3 gün gecikmeli." class="w-full sm:w-auto" />
            @endif
            @include('livewire.demo.partials._search-console-header-actions')
        </x-slot:actions>
        <x-slot:tabs>
            @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])
        </x-slot:tabs>
    </x-operator.asset-context>

    @unless ($connected)
        {{-- Without a bound property there is nothing to read: say so first, with the one way out. --}}
        <div class="card" data-gsc-connect>
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ match ($status) { 'Error' => 'Search Console verisi okunamadı', 'Action required' => 'Search Console bağlantısı bir adım bekliyor', default => 'Search Console bağlı değil' } }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $status === 'Error' ? 'Bir okuma hatası oldu, bu yüzden hiçbir rakam gösterilmiyor. Biraz sonra verileri yenile.' : 'Bir Search Console mülkü bağlanınca tıklamalar, aramalar ve sayfalar burada görünür.' }}</p>
                </div>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="btn-primary w-full sm:w-auto">{{ $status === 'Not connected' ? 'Kaynağı bağla' : 'Kaynağa bak' }}</a>
            </div>
        </div>
    @endunless

    @if ($tab === 'overview')
        @include('livewire.demo.search-console.tabs.overview')
    @elseif ($tab === 'performance')
        @include('livewire.demo.search-console.tabs.performance')
    @elseif ($tab === 'demand')
        @include('livewire.demo.search-console.tabs.demand')
    @elseif ($tab === 'pages')
        @include('livewire.demo.search-console.tabs.pages')
    @elseif ($tab === 'indexing')
        @include('livewire.demo.search-console.tabs.indexing')
    @elseif ($tab === 'operations')
        @include('livewire.demo.search-console.tabs.operations')
    @endif

    @if ($connected)
        <p class="text-xs text-gray-500 dark:text-gray-400" data-gsc-boundary>{{ strtr((string) $data['demo_boundary'], ['Search Console workspace uses collected data. Unbacked cards stay empty. Live API calls are not made on page render.' => 'Rakamlar toplanan Search Console verisinden gelir; verisi olmayan bölümler boş kalır. Sayfa açılırken Google\'a canlı istek atılmaz.']) }}</p>
    @endif
</div>

@if ($selectedAttention)
    <x-demo.gads-drawer :title="$selectedAttention['title']" :subtitle="$selectedAttention['scope'] ?? null" :severity="$selectedAttention['severity'] ?? null">
        <div>
            <p class="text-xs text-gray-500">Ne oldu</p>
            <p class="font-medium text-gray-900 dark:text-white">{{ $selectedAttention['metric'] }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Neden önemli</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['why'] ?? 'Organik aramada ajansın bakması gereken bir işaret.' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Önerilen adım</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $sayAction($selectedAttention['action'] ?? '') }}{{ ! empty($selectedAttention['tab']) ? ', '.(collect($navTabs)->firstWhere('key', $selectedAttention['tab'])['label'] ?? str_replace('_', ' ', $selectedAttention['tab'])).' sekmesinde' : '' }}.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if (! empty($selectedAttention['finding_id']))
                <button type="button" wire:click="openFinding('{{ $selectedAttention['finding_id'] }}')" class="btn-primary">İlgili bulguyu aç</button>
            @endif
            @if (! empty($selectedAttention['tab']))
                <button type="button" wire:click="setTab('{{ $selectedAttention['tab'] }}')" class="btn">Çalışma alanına git</button>
            @endif
        </div>
    </x-demo.gads-drawer>
@endif

@if ($selectedCluster)
    <x-demo.gads-drawer :title="$selectedCluster['name']" :subtitle="$selectedCluster['intent'] ?? null" :severity="match($selectedCluster['trend'] ?? '') { 'declining' => 'High', 'ctr_review' => 'Medium', default => null }">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-500">Tıklama</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedCluster['clicks'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Gösterim</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedCluster['impressions'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Tıklama oranı</p>
                <p class="font-semibold tabular-nums">{{ $selectedCluster['ctr'] ?? '—' }}%</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Ort. sıra</p>
                <p class="font-semibold tabular-nums" title="Gösterimlere göre ağırlıklı ortalama, tek bir genel sıra değil">{{ $selectedCluster['position'] ?? '—' }}</p>
            </div>
        </div>
        <div>
            <p class="text-xs text-gray-500">Ana sayfa (URL)</p>
            <p class="font-medium text-gray-900 dark:text-white">{{ $selectedCluster['primary_page'] ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-500">Sahiplik</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedCluster['ownership_state'] ?? '—' }}</p>
        </div>
        @if (! empty($selectedCluster['queries']))
            <div>
                <p class="text-xs text-gray-500">Örnek aramalar</p>
                <ul class="mt-1 space-y-0.5 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($selectedCluster['queries'] as $q)
                        <li>{{ $q }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <button type="button" wire:click="setDemandSub('queries')" class="btn">Arama gezginini aç</button>
        <p class="text-xs text-gray-500">Search Console, küme toplamları</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedPage)
    <x-demo.gads-drawer :title="$selectedPage['path'] ?? 'Sayfa'" :subtitle="$selectedPage['title'] ?? $selectedPage['content_role'] ?? null" :severity="$selectedPage['state'] ?? $selectedPage['website_attention'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-500">Tıklama</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedPage['clicks'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Gösterim</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedPage['impressions'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">İçerik rolü</p>
                <p class="font-semibold">{{ $say($selectedPage['content_role'] ?? null) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Hizmet</p>
                <p class="font-semibold">{{ $selectedPage['offering'] ?? '—' }}</p>
            </div>
        </div>
        @if (! empty($selectedPage['clusters']))
            <div>
                <p class="text-xs text-gray-500">İlgili kümeler</p>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ implode(', ', $selectedPage['clusters']) }}</p>
            </div>
        @endif
        @if (! empty($selectedPage['website_attention']))
            <div>
                <p class="text-xs text-gray-500">Site uyarıları</p>
                <p class="text-warning-700 dark:text-warning-400">{{ $selectedPage['website_attention'] }}</p>
            </div>
        @endif
        @if (! empty($selectedPage['ga4_context']))
            <div>
                <p class="text-xs text-gray-500">Analytics (sayfa düzeyi)</p>
                <p class="text-sm tabular-nums text-gray-700 dark:text-gray-300">
                    {{ number_format($selectedPage['ga4_context']['sessions'] ?? 0, 0, ',', '.') }} oturum, %{{ $selectedPage['ga4_context']['engagement_rate'] ?? '—' }} etkileşim, {{ number_format($selectedPage['ga4_context']['mapped_actions'] ?? 0, 0, ',', '.') }} eşlenen eylem
                </p>
                <p class="mt-1 text-xs text-gray-500">{{ $selectedPage['ga4_context']['note'] ?? $data['pages']['attribution_note'] ?? '' }}</p>
            </div>
        @endif
        @if (filled($identity['website_asset_id'] ?? null))
            <a href="{{ route('operator.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="btn-primary">{{ __('operator.chrome.open_website') }}</a>
        @endif
        <p class="text-xs text-gray-500">Search Console, sayfa toplamları</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedUrl)
    <x-demo.gads-drawer :title="$selectedUrl['path'] ?? 'URL'" :subtitle="$selectedUrl['role'] ?? null" :severity="$selectedUrl['attention'] ?? $selectedUrl['index_state'] ?? null">
        <div>
            <p class="text-xs text-gray-500">URL</p>
            <p class="break-all text-sm font-medium text-gray-900 dark:text-white">{{ $selectedUrl['url'] ?? '—' }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-500">Google dizin durumu</p>
                <p class="font-semibold">{{ $say($selectedUrl['index_state'] ?? null) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Site haritası</p>
                <p class="font-semibold">{{ $say($selectedUrl['sitemap'] ?? null) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Kanonik</p>
                <p class="font-semibold">{{ $say($selectedUrl['canonical'] ?? null) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Arama görünürlüğü</p>
                <p class="font-semibold">{{ $say($selectedUrl['search_visibility'] ?? null) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Son tarama</p>
                <p class="font-semibold">{{ $selectedUrl['last_crawl'] ?? '—' }}</p>
            </div>
        </div>
        @if (($selectedUrl['canonical'] ?? '') === 'Mismatch')
            <div>
                <p class="text-xs text-gray-500">Kanonik ayrıntı</p>
                <p class="text-sm text-warning-700 dark:text-warning-400">
                    Site: {{ $selectedUrl['user_canonical'] ?? '—' }}, Google: {{ $selectedUrl['google_canonical'] ?? '—' }}
                </p>
            </div>
        @endif
        @if (! empty($selectedUrl['attention']))
            <div>
                <p class="text-xs text-gray-500">Dikkat</p>
                <p class="text-warning-700 dark:text-warning-400">{{ $selectedUrl['attention'] }}</p>
            </div>
        @endif
        <p class="text-xs text-gray-500">{{ $data['indexing']['inspection_note'] ?? 'Google dizin durumu, yalnız okunur.' }}</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedFinding)
    <x-demo.gads-drawer :title="$selectedFinding['title']" :subtitle="$selectedFinding['category'] ?? null" :severity="$selectedFinding['severity'] ?? null">
        @foreach (['what' => 'Ne oldu', 'why' => 'Neden önemli', 'scope' => 'Kapsam', 'evidence' => 'Kanıt', 'next' => 'Önerilen adım', 'outcome' => 'Sonuç'] as $key => $label)
            @if (! empty($selectedFinding[$key]))
                <div>
                    <p class="text-xs text-gray-500">{{ $label }}</p>
                    <p class="text-gray-800 dark:text-white/90">{{ $selectedFinding[$key] }}</p>
                </div>
            @endif
        @endforeach
        <p class="text-xs text-gray-500">Görev kendiliğinden açılmaz; Search Console'a hiçbir şey yazılmaz.</p>
    </x-demo.gads-drawer>
@endif
