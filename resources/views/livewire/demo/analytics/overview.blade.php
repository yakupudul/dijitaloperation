@php
    $navTabs = [
        ['key' => 'overview', 'label' => __('operator.ga4.tabs.overview'), 'wire' => true],
        ['key' => 'measurement', 'label' => __('operator.ga4.tabs.measurement'), 'wire' => true],
        ['key' => 'acquisition', 'label' => __('operator.ga4.tabs.acquisition'), 'wire' => true],
        ['key' => 'behavior', 'label' => __('operator.ga4.tabs.behavior'), 'wire' => true],
        ['key' => 'journeys', 'label' => __('operator.ga4.tabs.journeys'), 'wire' => true],
        ['key' => 'operations', 'label' => __('operator.ga4.tabs.operations'), 'wire' => true],
    ];

    // The data layer speaks English status words; the screen shows plain Turkish (panel concept 2026-10-20).
    $words = [
        'critical' => 'Kritik', 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük',
        'healthy' => 'Sağlıklı', 'review' => 'Bakılmalı', 'needs review' => 'Bakılmalı', 'review candidate' => 'Bakılmalı', 'not mapped' => 'Eşlenmedi', 'mapped' => 'Eşlendi',
        'unavailable' => 'Veri yok', 'observed' => 'Görülüyor', 'broken' => 'Bozuk', 'partial' => 'Kısmi', 'funnel only' => 'Yalnız huni', 'measured' => 'Ölçülüyor',
        'receiving' => 'Veri geliyor', 'interrupted' => 'Kesildi', 'stale' => 'Eski', 'pass' => 'Geçti', 'fail' => 'Kaldı', 'no data' => 'Veri yok',
        'configured' => 'Kurulu', 'incomplete measurement' => 'Ölçüm eksik', 'missing' => 'Yok', 'incomplete' => 'Eksik', 'not configured' => 'Kurulmadı',
        'improvement observed' => 'İyileşme görüldü', 'still observed' => 'Sürüyor', 'insufficient evidence' => 'Kanıt yetersiz',
        'connected' => 'Bağlı', 'not connected' => 'Bağlı değil', 'action required' => 'Bir adım gerekiyor', 'error' => 'Okuma hatası', 'linked' => 'Bağlı', 'needs attention' => 'Bakılmalı',
        'open' => 'Açık', 'in progress' => 'Sürüyor', 'done' => 'Bitti', 'completed' => 'Tamamlandı', 'proposed' => 'Önerildi', 'accepted' => 'Kabul edildi', 'pending' => 'Bekliyor', 'planned' => 'Planlandı', 'blocked' => 'Takıldı', 'resolved' => 'Çözüldü', 'monitoring' => 'İzleniyor',
        'primary' => 'Birincil', 'secondary' => 'İkincil', 'supporting' => 'Destekleyici', 'web' => 'Web',
        'organic search' => 'Organik arama', 'paid search' => 'Ücretli arama', 'paid social' => 'Ücretli sosyal', 'organic social' => 'Organik sosyal', 'direct' => 'Doğrudan',
        'referral' => 'Yönlendirme', 'email' => 'E-posta', 'unassigned' => 'Atanmamış', 'organic maps' => 'Organik harita', 'display' => 'Görüntülü reklam', 'cross-network' => 'Çapraz ağ',
        'mobile' => 'Mobil', 'desktop' => 'Masaüstü', 'tablet' => 'Tablet',
        'engagement rate' => 'Etkileşim oranı', 'avg engagement time' => 'Ort. etkileşim süresi', 'views / session' => 'Oturum başına görüntüleme', 'appointment completion' => 'Randevu tamamlama',
        'measurement' => 'Ölçüm', 'acquisition' => 'Trafik kaynağı', 'behavior' => 'Davranış', 'data quality' => 'Veri kalitesi', 'attribution' => 'İlişkilendirme', 'journeys' => 'Yollar',
        'home' => 'Ana sayfa', 'service / product' => 'Hizmet / ürün', 'conversion / contact' => 'İletişim', 'content / blog' => 'Blog', 'team / expert' => 'Ekip', 'legacy' => 'Eski sayfa', 'location' => 'Konum',
    ];
    $say = function (?string $value) use ($words): string {
        $value = trim((string) $value);
        $key = mb_strtolower(str_replace('_', ' ', $value));

        return $value === '' ? '—' : ($words[$key] ?? str_replace('_', ' ', $value));
    };
    $doWords = ['inspect' => 'İncele', 'review' => 'Gözden geçir', 'map action' => 'Eşle', 'note' => 'Not', 'open' => 'Aç'];
    $sayAction = fn (?string $value): string => $doWords[mb_strtolower(trim((string) $value))] ?? $say($value);
    // What a status means for yakup: red only for lost signal / money, amber for "bak", green for fine, gray for the rest.
    $toneOf = fn (?string $value): string => match (mb_strtolower(str_replace('_', ' ', trim((string) $value)))) {
        'critical', 'high', 'broken', 'fail', 'interrupted', 'error' => 'critical',
        'medium', 'review', 'needs review', 'review candidate', 'not mapped', 'partial', 'stale', 'action required', 'still observed', 'insufficient evidence', 'incomplete measurement', 'needs attention' => 'look',
        'healthy', 'pass', 'receiving', 'connected', 'improvement observed', 'linked', 'measured', 'configured', 'mapped', 'done', 'completed', 'resolved' => 'ok',
        default => 'info',
    };
    // Provider dates read the Turkish way: "Jul 16" → "16 Tem".
    $months = ['Jan' => 'Oca', 'Feb' => 'Şub', 'Mar' => 'Mar', 'Apr' => 'Nis', 'May' => 'May', 'Jun' => 'Haz', 'Jul' => 'Tem', 'Aug' => 'Ağu', 'Sep' => 'Eyl', 'Oct' => 'Eki', 'Nov' => 'Kas', 'Dec' => 'Ara'];
    $trDay = fn (string $text): string => (string) preg_replace_callback('/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (\d{1,2})\b/', fn (array $m): string => $m[2].' '.$months[$m[1]], $text);
    $phrase = fn (string $text): string => trim(strtr($trDay($text), [
        'vs previous period' => 'önceki döneme göre', 'vs year-ago period' => 'geçen yıla göre', 'vs previous' => 'öncekine göre',
        'Unavailable · not additive' => 'Veri yok, günler toplanamaz', 'No business-action mapping store' => 'İş aksiyonu eşlemesi yok',
        'New users (additive)' => 'Yeni kullanıcı', 'Key events' => 'Anahtar etkinlik', 'Conversions' => 'Dönüşüm', 'totalRevenue' => 'Gelir',
        'Partial · debt present' => 'Kısmi', 'Healthy' => 'sağlıklı', 'Not mapped' => 'eşlenmedi', 'Review' => 'bakılmalı', 'mapped' => 'eşlendi',
        'Unavailable' => 'Veri yok', 'unavailable' => 'yok', ' · ' => ', ',
        'no_active_ga4_binding' => 'Mülk bağlı değil', 'binding_scope_incomplete' => 'İzin eksik', 'query_error' => 'Okuma hatası',
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
        $value = in_array($value, ['', 'Veri yok'], true) ? '—' : $value;

        return ['value' => $trNumber($value), 'delta' => $delta === null ? null : $trNumber($delta), 'tone' => $tone, 'hint' => $phrase($secondary), 'muted' => $value === '—', 'note' => $cell['note'] ?? null];
    };
    $num = fn (float|int|null $value): string => number_format((float) ($value ?? 0), 0, ',', '.');
    // Missing is not zero: a count the provider did not send prints "—".
    $count = fn (array $row, string $key): string => array_key_exists($key, $row) && $row[$key] !== null ? number_format((float) $row[$key], 0, ',', '.') : '—';

    $status = (string) ($identity['status'] ?? '');
    $connected = ! in_array($status, ['Not connected', 'Action required', 'Error'], true);
    $title = preg_replace('/ — (not connected|read error)$/', '', (string) $identity['title']);

    // Trend chart: sessions in the panel's second color (Ayarlar › Görünüm), business actions near-black.
    $chart = $performanceChartOptions;
    foreach ($chart['series'] as $i => $serie) {
        $chart['series'][$i]['name'] = ['Sessions' => 'Oturum', 'Business actions' => 'İş aksiyonu'][$serie['name']] ?? $serie['name'];
    }
    $chart['colors'] = [(string) (app(\App\Services\Operator\AgencySettingService::class)->theme()['second'] ?? '#1e7a78'), '#191918'];
    $chart['chart']['fontFamily'] = 'Inter, ui-sans-serif, system-ui, sans-serif';
    $chart['grid'] = ['borderColor' => '#ebebe9', 'strokeDashArray' => 3, 'padding' => ['left' => 4, 'right' => 8]];
    $chart['legend'] = ['show' => count($chart['series']) > 1, 'position' => 'top', 'horizontalAlign' => 'left', 'fontSize' => '13px', 'labels' => ['colors' => '#5f5e5b'], 'markers' => ['size' => 4]];
    $chart['xaxis']['categories'] = array_map(fn (mixed $label): string => $trDay((string) $label), $chart['xaxis']['categories'] ?? []);
    $chart['responsive'] = [['breakpoint' => 640, 'options' => ['xaxis' => ['tickAmount' => 3]]]];
    $chart['xaxis'] += ['tickAmount' => 6, 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false], 'labels' => ['rotate' => 0, 'hideOverlappingLabels' => true, 'style' => ['colors' => '#787774', 'fontSize' => '12px']]];
    $axisLabels = ['style' => ['colors' => '#787774', 'fontSize' => '12px']];
    $chart['yaxis'] = count($chart['series']) > 1 ? [['labels' => $axisLabels], ['opposite' => true, 'labels' => $axisLabels]] : [['labels' => $axisLabels]];
    $hasTrend = ($chart['series'][0]['data'] ?? []) !== [];
@endphp

<div class="min-w-0 space-y-8">
    @include('livewire.demo.partials.flash')
    <x-operator.asset-context :asset-id="$assetId" :title="$title" :refresh="false">
        <x-slot:mark><x-demo.digital-asset-mark type="ga4" size="lg" class="mt-0.5 shrink-0" /></x-slot:mark>
        <x-slot:meta>
            @if ($connected)
                {{ strtr((string) ($identity['relationship_line'] ?? $identity['measures_line'] ?? ''), ['Measures · ' => 'Ölçtüğü site: ']) }}
            @endif
            @if (filled($identity['website_asset_id'] ?? null))
                <a href="{{ route('operator.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="ml-2 whitespace-nowrap link-neutral">{{ __('operator.chrome.open_website') }}</a>
            @endif
        </x-slot:meta>
        <x-slot:actions>
            @if ($showPeriodBar)
                <x-operator.date-range-picker :range="$this->pickerRange()" :last-day="$this->periodPickerMaxDate()" note="Analytics verisi 1–2 gün gecikmeli." class="w-full sm:w-auto" />
            @endif
            @include('livewire.demo.partials._analytics-header-actions')
        </x-slot:actions>
        <x-slot:tabs>
            @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])
        </x-slot:tabs>
    </x-operator.asset-context>

    @unless ($connected)
        {{-- Without a bound property there is nothing to read: say so first, with the one way out. --}}
        <div class="card" data-ga4-connect>
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ match ($status) { 'Error' => 'Analytics verisi okunamadı', 'Action required' => 'Analytics bağlantısı bir adım bekliyor', default => 'Google Analytics bağlı değil' } }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $status === 'Error' ? 'Bir okuma hatası oldu, bu yüzden hiçbir rakam gösterilmiyor. Biraz sonra verileri yenile.' : 'Bir Analytics mülkü bağlanınca oturumlar, trafik kaynakları ve iş aksiyonları burada görünür.' }}</p>
                </div>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="btn-primary w-full sm:w-auto">{{ $status === 'Not connected' ? 'Kaynağı bağla' : 'Kaynağa bak' }}</a>
            </div>
        </div>
    @endunless

    @if ($tab === 'overview')
        @include('livewire.demo.analytics.tabs.overview')
    @elseif ($tab === 'measurement')
        @include('livewire.demo.analytics.tabs.measurement')
    @elseif ($tab === 'acquisition')
        @include('livewire.demo.analytics.tabs.acquisition')
    @elseif ($tab === 'behavior')
        @include('livewire.demo.analytics.tabs.behavior')
    @elseif ($tab === 'journeys')
        @include('livewire.demo.analytics.tabs.journeys')
    @elseif ($tab === 'operations')
        @include('livewire.demo.analytics.tabs.operations')
    @endif

    @if ($connected)
        <p class="text-xs text-gray-500 dark:text-gray-400" data-ga4-boundary>{{ strtr((string) $data['demo_boundary'], ['GA4 workspace uses collected data. Unbacked cards stay empty. Live API calls are not made on page render.' => 'Rakamlar toplanan Analytics verisinden gelir; verisi olmayan bölümler boş kalır. Sayfa açılırken Google\'a canlı istek atılmaz.']) }}</p>
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
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['why'] ?? 'Ölçümde ajansın bakması gereken bir işaret.' }}</p>
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

@if ($selectedLanding)
    <x-demo.gads-drawer :title="$selectedLanding['path'] ?? 'Giriş sayfası'" :subtitle="$selectedLanding['title'] ?? $selectedLanding['content_role'] ?? null" :severity="$selectedLanding['attention'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-500">Oturum</p>
                <p class="font-semibold tabular-nums">{{ $num($selectedLanding['sessions'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Etkileşim oranı</p>
                <p class="font-semibold tabular-nums">{{ isset($selectedLanding['engaged_rate']) ? '%'.$selectedLanding['engaged_rate'] : ($selectedLanding['engagement'] ?? '—') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Eşlenen eylemler</p>
                <p class="font-semibold tabular-nums">{{ $count($selectedLanding, 'mapped_actions') }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">İçerik rolü</p>
                <p class="font-semibold">{{ $say($selectedLanding['content_role'] ?? null) }}</p>
            </div>
        </div>
        @if (! empty($selectedLanding['attention']))
            <div>
                <p class="text-xs text-gray-500">Site uyarıları</p>
                <p class="text-warning-700 dark:text-warning-400">{{ $selectedLanding['attention'] }}</p>
            </div>
        @endif
        @if (filled($selectedLanding['website_asset_id'] ?? $identity['website_asset_id'] ?? null))
            <a href="{{ route('operator.website', ['assetId' => $selectedLanding['website_asset_id'] ?? $identity['website_asset_id']]) }}" wire:navigate class="btn-primary">{{ __('operator.chrome.open_website') }}</a>
        @endif
        <p class="text-xs text-gray-500">Analytics, sitede ölçülen davranış</p>
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
        <p class="text-xs text-gray-500">Görev kendiliğinden açılmaz; Analytics'e hiçbir şey yazılmaz.</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedEvent)
    <x-demo.gads-drawer :title="$selectedEvent['event'] ?? $selectedEvent['name'] ?? 'Etkinlik'" :subtitle="$selectedEvent['mapped_action'] ?? null" :severity="$selectedEvent['state'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-500">Adet</p>
                <p class="font-semibold tabular-nums">
                    @if (array_key_exists('count', $selectedEvent) && $selectedEvent['count'] !== null)
                        {{ $num($selectedEvent['count']) }}
                    @else
                        <span class="text-gray-400">Yok</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Eşlenen eylem</p>
                <p class="font-semibold">{{ $say($selectedEvent['mapped_action'] ?? 'Not mapped') }}</p>
            </div>
        </div>
        <p class="text-xs text-gray-500">Gelmeyen etkinlik sıfır demek değildir; sinyal yoktur.</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedAction)
    <x-demo.gads-drawer :title="$selectedAction['action'] ?? 'İş aksiyonu'" :subtitle="$say($selectedAction['role'] ?? null)" :severity="$selectedAction['state'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-500">Analytics etkinliği</p>
                <p class="font-semibold">{{ $selectedAction['ga4_event'] ?? 'Veri yok' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Olay sayısı</p>
                <p class="font-semibold tabular-nums">
                    @if (array_key_exists('event_count', $selectedAction) && $selectedAction['event_count'] !== null)
                        {{ $num($selectedAction['event_count']) }}
                    @else
                        <span class="text-gray-400">Yok</span>
                    @endif
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Eşleme</p>
                <p class="font-semibold">{{ $say($selectedAction['mapping'] ?? $selectedAction['state'] ?? null) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">Rol</p>
                <p class="font-semibold">{{ $say($selectedAction['role'] ?? null) }}</p>
            </div>
        </div>
        @if (! empty($selectedAction['note']))
            <div>
                <p class="text-xs text-gray-500">Not</p>
                <p class="text-gray-700 dark:text-gray-300">{{ $selectedAction['note'] }}</p>
            </div>
        @endif
        <button type="button" wire:click="setMeasSub('business_actions')" class="btn">Ölçüm eşlemesini aç</button>
        <p class="text-xs text-gray-500">Eşlenmemiş ya da gelmeyen sinyal sıfır demek değildir.</p>
    </x-demo.gads-drawer>
@endif
