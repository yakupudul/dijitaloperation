@php
    $isTr = app()->getLocale() === 'tr';
    $history = $professional['history'] ?? [];
    $health = collect($professional['data_health'] ?? []);
    $currency = $professional['currency'] ?? ($identity['currency'] ?? '');
    $connected = (bool) ($professional['connected'] ?? false);

    $containsAny = static function (string $haystack, array $needles): bool {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    };

    $isFailed = static fn (array $row): bool => $containsAny(strtolower((string) ($row['status'] ?? '')), ['fail', 'error', 'blocked', 'invalid']);
    $isPending = static fn (array $row): bool => $containsAny(strtolower((string) ($row['status'] ?? '')), ['pending', 'queued', 'running', 'collecting']);
    $isMissingCollection = static fn (array $row): bool => empty($row['last_collected_at']);

    $failed = $health->filter($isFailed);
    $partialRows = $health->filter(fn (array $row): bool => (bool) ($row['partial'] ?? false));
    $pendingRows = $health->filter($isPending);
    $missingCollectionRows = $health->filter($isMissingCollection);
    $healthyRows = $health->reject(fn (array $row): bool => $isFailed($row) || $isPending($row) || (bool) ($row['partial'] ?? false) || $isMissingCollection($row));

    $issues = $health->filter(fn (array $row): bool => $isFailed($row) || (bool) ($row['partial'] ?? false) || $isMissingCollection($row));
    $lastCollectedAt = $health->pluck('last_collected_at')->filter()->max();
    $coverageStart = $health->pluck('coverage_start')->filter()->min();
    $coverageEnd = $health->pluck('coverage_end')->filter()->max();

    $overallState = match (true) {
        ! $connected => 'disconnected',
        $failed->isNotEmpty() => 'critical',
        $partialRows->isNotEmpty() || $missingCollectionRows->isNotEmpty() => 'review',
        $health->isEmpty() => 'waiting',
        default => 'healthy',
    };
    $overallLabel = match ($overallState) {
        'healthy' => $isTr ? 'Sağlıklı' : 'Healthy',
        'review' => $isTr ? 'İnceleme gerekli' : 'Review needed',
        'critical' => $isTr ? 'Kritik veri sorunu' : 'Critical data issue',
        'disconnected' => $isTr ? 'Bağlı değil' : 'Not connected',
        default => $isTr ? 'Veri bekleniyor' : 'Waiting for data',
    };
    $overallClasses = match ($overallState) {
        'healthy' => 'bg-emerald-50 text-emerald-800 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20',
        'critical' => 'bg-rose-50 text-rose-800 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20',
        'review' => 'bg-amber-50 text-amber-800 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20',
        default => 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-white/[0.03] dark:text-gray-300 dark:ring-gray-800',
    };

    $datasetLabel = function (string $dataset) use ($isTr, $containsAny): string {
        $key = strtoupper($dataset);

        return match (true) {
            $containsAny($key, ['CONVERSION']) => $isTr ? 'Dönüşümler' : 'Conversions',
            $containsAny($key, ['SEARCH_TERM']) => $isTr ? 'Arama terimleri' : 'Search terms',
            $containsAny($key, ['KEYWORD']) => $isTr ? 'Anahtar kelimeler' : 'Keywords',
            $containsAny($key, ['AD_GROUP']) => $isTr ? 'Reklam grupları' : 'Ad groups',
            $containsAny($key, ['CHANGE']) => $isTr ? 'Değişiklik geçmişi' : 'Change history',
            $containsAny($key, ['LANDING']) => $isTr ? 'Açılış sayfası verisi (UI kapalı)' : 'Landing-page data (UI retired)',
            $containsAny($key, ['CAMPAIGN']) => $isTr ? 'Kampanyalar' : 'Campaigns',
            $containsAny($key, ['BIDDING', 'BID_']) => $isTr ? 'Teklif stratejileri' : 'Bidding strategies',
            $containsAny($key, ['BUDGET']) => $isTr ? 'Bütçeler' : 'Budgets',
            $containsAny($key, ['RECOMMENDATION']) => $isTr ? 'Google önerileri' : 'Google recommendations',
            $containsAny($key, ['AUDIENCE']) => $isTr ? 'Kitleler' : 'Audiences',
            $containsAny($key, ['LOCATION', 'GEO']) => $isTr ? 'Konum hedefleme' : 'Location targeting',
            $containsAny($key, ['PMAX', 'P_MAX', 'PERFORMANCE_MAX']) => 'Performance Max',
            $containsAny($key, ['SHOPPING']) => 'Shopping',
            $containsAny($key, ['VIDEO']) => 'Video',
            $containsAny($key, ['ACCOUNT', 'CUSTOMER']) => $isTr ? 'Hesap performansı' : 'Account performance',
            $containsAny($key, ['AD_', '_AD', 'ADS']) => $isTr ? 'Reklamlar' : 'Ads',
            default => $isTr ? 'Google Ads veri ailesi' : 'Google Ads dataset',
        };
    };

    $statusLabel = function (?string $status, bool $partial) use ($isTr, $containsAny): string {
        if ($partial) {
            return $isTr ? 'Kısmi' : 'Partial';
        }
        $value = strtolower((string) $status);

        return match (true) {
            $containsAny($value, ['fail', 'error', 'blocked', 'invalid']) => $isTr ? 'Hata' : 'Error',
            $containsAny($value, ['pending', 'queued']) => $isTr ? 'Bekliyor' : 'Pending',
            $containsAny($value, ['running', 'collecting']) => $isTr ? 'Toplanıyor' : 'Collecting',
            $containsAny($value, ['success', 'complete', 'ready', 'materialized']) => $isTr ? 'Hazır' : 'Ready',
            $status !== null && $status !== '' => (string) $status,
            default => $isTr ? 'Bilinmiyor' : 'Unknown',
        };
    };

    $statusClasses = function (array $row) use ($isFailed, $isPending, $isMissingCollection): string {
        return match (true) {
            $isFailed($row) => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
            (bool) ($row['partial'] ?? false) => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
            $isPending($row) => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
            $isMissingCollection($row) => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
            default => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        };
    };

    $priority = function (array $row) use ($isFailed, $isPending, $isMissingCollection): int {
        return match (true) {
            $isFailed($row) => 4,
            (bool) ($row['partial'] ?? false) => 3,
            $isMissingCollection($row) => 2,
            $isPending($row) => 1,
            default => 0,
        };
    };
    $displayHealth = $health->sortByDesc($priority)->values();
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-cyan-600 dark:text-cyan-300">{{ $isTr ? 'VERİ SAĞLIĞI MERKEZİ' : 'DATA HEALTH CENTER' }}</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Bu Google Ads verisine güvenebilir miyiz?' : 'Can we trust this Google Ads data?' }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bağlantı durumunu, hesap kimliğini, tarihsel kapsamı, son toplama zamanını ve her veri ailesinin materialization sağlığını tek yerde denetleyin.' : 'Audit connection state, account identity, historical coverage, collection freshness and materialization health for every data family in one place.' }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ url('/assets/'.$this->assetId.'/sources') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">{{ $isTr ? 'Veri Kaynakları' : 'Data Sources' }}</a>
            <a href="{{ route('operator.integrations.google-ads.connector') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-800 dark:hover:bg-brand-500/10">{{ $isTr ? 'Google Ads connector’ı' : 'Google Ads connector' }}</a>
            <button type="button" wire:click="refreshData" wire:loading.attr="disabled" wire:target="refreshData" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-wait disabled:opacity-60"><span wire:loading.remove wire:target="refreshData">{{ $isTr ? 'Veriyi yenile' : 'Refresh data' }}</span><span wire:loading wire:target="refreshData">{{ $isTr ? 'Başlatılıyor…' : 'Starting…' }}</span></button>
        </div>
    </div>

    <section class="rounded-2xl p-4 ring-1 ring-inset {{ $overallClasses }}">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold">{{ $isTr ? 'Bağlantı ve veri güveni' : 'Connection & data trust' }} · {{ $overallLabel }}</h3>@if($connected)<span class="rounded-full bg-white/70 px-2 py-0.5 text-[10px] font-bold uppercase dark:bg-black/10">{{ $isTr ? 'Bağlı' : 'Connected' }}</span>@endif</div>
                <p class="mt-1 text-sm opacity-90">{{ $isTr ? 'Bu durum performans puanı değildir; bağlantı, materialization ve toplama kayıtlarının gerçek durumundan türetilir.' : 'This is not a performance score; it is derived from actual connection, materialization and collection state.' }}</p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs font-medium">
                <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">{{ $isTr ? 'Sağlıklı' : 'Healthy' }}: {{ $healthyRows->count() }}</span>
                <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">{{ $isTr ? 'Kısmi' : 'Partial' }}: {{ $partialRows->count() }}</span>
                <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">{{ $isTr ? 'Hata' : 'Failed' }}: {{ $failed->count() }}</span>
                <span class="rounded-full bg-white/70 px-2.5 py-1 dark:bg-black/10">{{ $isTr ? 'Toplanıyor' : 'In progress' }}: {{ $pendingRows->count() }}</span>
            </div>
        </div>
    </section>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">Customer ID</p><p class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $identity['customer_id'] ?? '—' }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Saat dilimi' : 'Timezone' }}</p><p class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $identity['reporting_timezone'] ?? '—' }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Para birimi' : 'Currency' }}</p><p class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $currency ?: '—' }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Son başarılı toplama kaydı' : 'Latest collection record' }}</p><p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ $lastCollectedAt ?: '—' }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Veri kapsamı' : 'Data coverage' }}</p><p class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ $coverageStart ?: '—' }} → {{ $coverageEnd ?: '—' }}</p></div>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $isTr ? 'Veri ailesi' : 'Data families' }}</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $health->count() }}</p><p class="mt-1 text-xs {{ $issues->isNotEmpty() ? 'text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ $issues->count() }} {{ $isTr ? 'inceleme konusu' : 'items to review' }}</p></div>
    </div>

    @if ($issues->isNotEmpty())
        <section class="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/60 dark:border-amber-500/20 dark:bg-amber-500/5">
            <div class="border-b border-amber-200 px-4 py-3 dark:border-amber-500/20"><h3 class="font-semibold text-amber-950 dark:text-amber-100">{{ $isTr ? 'Veri güvenini etkileyen konular' : 'Items affecting data trust' }}</h3><p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/70">{{ $isTr ? 'Önce bu veri ailelerini kontrol edin. MOXDOP eksik veya hatalı dataset için metrik uydurmaz.' : 'Review these data families first. MOXDOP does not fabricate metrics for incomplete or failed datasets.' }}</p></div>
            <div class="grid gap-2 p-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($issues->take(9) as $row)
                    <div class="rounded-xl bg-white/80 p-3 ring-1 ring-inset ring-amber-100 dark:bg-black/10 dark:ring-amber-500/10">
                        <div class="flex items-start justify-between gap-2"><div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $datasetLabel((string) $row['dataset']) }}</p><p class="mt-1 font-mono text-[10px] text-gray-400">{{ $row['dataset'] }}</p></div><span class="rounded-full px-2 py-0.5 text-[10px] font-bold {{ $statusClasses($row) }}">{{ $statusLabel($row['status'] ?? null, (bool) ($row['partial'] ?? false)) }}</span></div>
                        <p class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">@if($isFailed($row)){{ $isTr ? 'Son materialization durumu hata işaret ediyor.' : 'Latest materialization state indicates an error.' }}@elseif($row['partial'] ?? false){{ $isTr ? 'Dataset kısmi işaretli; kapsam tamamlanmamış olabilir.' : 'Dataset is marked partial; coverage may be incomplete.' }}@else{{ $isTr ? 'Başarılı son toplama zamanı henüz kayıtlı değil.' : 'No successful collection timestamp is recorded yet.' }}@endif</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    <div class="grid gap-4 xl:grid-cols-[1fr_1.35fr]">
        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Hesap kapsamı' : 'Account coverage' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Google Ads hesabında keşfedilmiş gerçek reklam aktivitesi.' : 'Discovered real advertising activity in this Google Ads account.' }}</p>
            <dl class="mt-4 space-y-3 text-sm">
                <div class="flex items-center justify-between gap-3"><dt class="text-gray-500">{{ $isTr ? 'İlk reklam aktivitesi' : 'First ad activity' }}</dt><dd class="font-semibold text-gray-900 dark:text-white">{{ data_get($history,'first_activity_month','—') }}</dd></div>
                <div class="flex items-center justify-between gap-3"><dt class="text-gray-500">{{ $isTr ? 'Son reklam aktivitesi' : 'Last ad activity' }}</dt><dd class="font-semibold text-gray-900 dark:text-white">{{ data_get($history,'last_activity_month','—') }}</dd></div>
                <div class="flex items-center justify-between gap-3"><dt class="text-gray-500">{{ $isTr ? 'Aktif reklam ayı' : 'Active advertising months' }}</dt><dd class="font-semibold text-gray-900 dark:text-white">{{ data_get($history,'active_months','—') }}</dd></div>
                <div class="flex items-center justify-between gap-3"><dt class="text-gray-500">{{ $isTr ? 'Lifetime harcama' : 'Lifetime spend' }}</dt><dd class="font-semibold text-gray-900 dark:text-white">{{ is_numeric(data_get($history,'lifetime_spend')) ? number_format((float)data_get($history,'lifetime_spend'),2,',','.').' '.$currency : '—' }}</dd></div>
            </dl>
            @if (! empty($history['months']))
                <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800"><p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $isTr ? 'Aktivite zaman çizelgesi' : 'Activity timeline' }}</p><div class="mt-3 flex flex-wrap gap-1.5">@foreach ($history['months'] as $month)<div title="{{ $month['month'] }} · {{ number_format((float)$month['spend'],2,',','.') }} {{ $currency }}" @class(['h-5 w-5 rounded-md ring-1 ring-inset','bg-emerald-500 ring-emerald-500' => $month['active'],'bg-gray-100 ring-gray-200 dark:bg-white/5 dark:ring-gray-800' => ! $month['active']])></div>@endforeach</div></div>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Kaynak ve güven sınırları' : 'Source & trust boundaries' }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'Bu ekran bağlantıyı ve MOXDOP’a alınmış veriyi denetler; Google Ads hesabında doğrudan değişiklik yapmaz.' : 'This screen audits the connection and data materialized into MOXDOP; it does not mutate the Google Ads account.' }}</p>
            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded-xl bg-emerald-50 p-3 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200"><p class="text-sm font-semibold">{{ $isTr ? 'Sağlayıcı gerçeği' : 'Provider truth' }}</p><p class="mt-1 text-xs leading-5 opacity-80">{{ $isTr ? 'Google Ads API’den toplanmış performans, yapılandırma ve Change Event verileri yerel Data Pool’dan okunur.' : 'Performance, configuration and Change Event data collected from Google Ads API are read from the local Data Pool.' }}</p></div>
                <div class="rounded-xl bg-blue-50 p-3 text-blue-800 dark:bg-blue-500/10 dark:text-blue-200"><p class="text-sm font-semibold">{{ $isTr ? 'Render sırasında API çağrısı yok' : 'No provider call during render' }}</p><p class="mt-1 text-xs leading-5 opacity-80">{{ $isTr ? 'Sayfayı açmak Google Ads’e sorgu veya yazma işlemi göndermez; yenileme ayrı toplama akışını başlatır.' : 'Opening the page sends no provider query or mutation; refresh starts the separate collection workflow.' }}</p></div>
                <div class="rounded-xl bg-violet-50 p-3 text-violet-800 dark:bg-violet-500/10 dark:text-violet-200"><p class="text-sm font-semibold">{{ $isTr ? 'Çapraz kaynaklar' : 'Cross-source data' }}</p><p class="mt-1 text-xs leading-5 opacity-80">{{ $isTr ? 'GA4, Search Console, CRM veya diğer kaynaklar ancak kanonik olarak bağlandığında Google Ads yorumlarına dahil edilmelidir.' : 'GA4, Search Console, CRM and other sources should influence Google Ads decisions only after canonical linking.' }}</p></div>
                <div class="rounded-xl bg-gray-50 p-3 text-gray-700 dark:bg-white/[0.03] dark:text-gray-300"><p class="text-sm font-semibold">{{ $isTr ? 'Kimlik bilgileri korunur' : 'Credentials stay protected' }}</p><p class="mt-1 text-xs leading-5 opacity-80">{{ $isTr ? 'OAuth tokenları ve hassas kimlik bilgileri bu operatör ekranında gösterilmez.' : 'OAuth tokens and sensitive credentials are never exposed on this operator screen.' }}</p></div>
            </div>
        </section>
    </div>

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Veri ailesi sağlığı' : 'Dataset health' }}</h3><p class="mt-1 text-xs text-gray-500">{{ $isTr ? 'İşletme dili ana etiket; teknik dataset kimliği audit amacıyla küçük gösterilir. Sorunlu kayıtlar üstte sıralanır.' : 'Business-friendly labels lead; technical dataset IDs remain visible in small type for audit. Problematic records are sorted first.' }}</p></div><p class="text-xs text-gray-400">{{ $healthyRows->count() }}/{{ $health->count() }} {{ $isTr ? 'tam ve güncel toplama kaydı' : 'complete collection records' }}</p></div></div>
        <div class="overflow-x-auto">
            <table class="min-w-[1050px] w-full text-sm">
                <thead class="bg-gray-50 text-[11px] uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-4 py-2.5 text-left">{{ $isTr ? 'Veri ailesi' : 'Data family' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Durum' : 'Status' }}</th><th class="px-3 py-2.5 text-left">{{ $isTr ? 'Kapsam' : 'Coverage' }}</th><th class="px-3 py-2.5 text-right">{{ $isTr ? 'Satır' : 'Rows' }}</th><th class="px-4 py-2.5 text-left">{{ $isTr ? 'Son toplama' : 'Last collection' }}</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($displayHealth as $row)
                        <tr class="align-top"><td class="px-4 py-3"><p class="font-semibold text-gray-900 dark:text-white">{{ $datasetLabel((string) $row['dataset']) }}</p><p class="mt-1 font-mono text-[10px] text-gray-400">{{ $row['dataset'] }}</p></td><td class="px-3 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusClasses($row) }}">{{ $statusLabel($row['status'] ?? null, (bool) ($row['partial'] ?? false)) }}</span>@if(!empty($row['status']))<p class="mt-1 text-[10px] text-gray-400">{{ $row['status'] }}</p>@endif</td><td class="px-3 py-3 text-xs text-gray-500">{{ $row['coverage_start'] ?? '—' }} → {{ $row['coverage_end'] ?? '—' }}</td><td class="px-3 py-3 text-right tabular-nums">{{ array_key_exists('rows',$row) && is_numeric($row['rows']) ? number_format((int)$row['rows'],0,',','.') : '—' }}</td><td class="px-4 py-3 text-xs text-gray-500">{{ $row['last_collected_at'] ?? '—' }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-gray-400">{{ $isTr ? 'Bu Google Ads varlığı için Data Pool materialization kaydı henüz yok.' : 'No Data Pool materialization records are available for this Google Ads asset yet.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-800 ring-1 ring-inset ring-blue-100 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        <strong>{{ $isTr ? 'Toplama politikası:' : 'Collection policy:' }}</strong>
        {{ $isTr ? 'İlk bağlantıda lifetime aktivite aylık seviyede keşfedilir; aktif dönemler desteklenen granular pencere içinde detaylı backfill edilir. Normal güncellemelerde son dönem tekrar doğrulanır. Change History sağlayıcının tarihsel erişim sınırlarına tabidir.' : 'At first connection, lifetime activity is discovered monthly; active periods are backfilled in detail inside supported granular windows. Normal updates restate the recent period. Change History remains subject to provider history limits.' }}
    </div>
</div>
