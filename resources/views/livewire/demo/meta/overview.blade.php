@php
    $isTr = app()->getLocale() === 'tr';
    $navTabs = [
        ['key' => 'overview', 'label' => $isTr ? 'Genel Bakış' : 'Overview', 'wire' => true],
        ['key' => 'funnel', 'label' => $isTr ? 'Performans' : 'Performance', 'wire' => true],
        ['key' => 'campaigns', 'label' => $isTr ? 'Kampanyalar' : 'Campaigns', 'wire' => true],
        ['key' => 'creatives', 'label' => $isTr ? 'Kreatifler' : 'Creatives', 'wire' => true],
        ['key' => 'audience', 'label' => $isTr ? 'Kitle & Dağıtım' : 'Audience & Delivery', 'wire' => true],
        ['key' => 'measurement', 'label' => $isTr ? 'Dönüşümler' : 'Conversions', 'wire' => true],
        ['key' => 'operations', 'label' => $isTr ? 'İçgörüler & Aksiyonlar' : 'Insights & Actions', 'wire' => true],
    ];

    $health = $professional['health'] ?? ['state' => 'unavailable', 'usable' => 0, 'total' => 0];
    $healthState = $health['state'] ?? 'unavailable';
    $healthClasses = match ($healthState) {
        'healthy' => 'bg-emerald-500',
        'partial' => 'bg-amber-500',
        default => 'bg-rose-500',
    };
    $healthLabel = match ($healthState) {
        'healthy' => $isTr ? 'Veriler kullanılabilir' : 'Data available',
        'partial' => $isTr ? 'Bazı veriler eksik veya eski' : 'Some data is limited',
        default => $isTr ? 'Veri kontrolü gerekli' : 'Data check required',
    };

    $periodStartRaw = $professional['period_start'] ?? $data['period_start'] ?? null;
    $periodEndRaw = $professional['period_end'] ?? $data['period_end'] ?? null;
    $periodDisplay = '—';
    if (filled($periodStartRaw) && filled($periodEndRaw)) {
        if ($isTr) {
            $periodDisplay = \Carbon\CarbonImmutable::parse($periodStartRaw)->locale('tr')->translatedFormat('j M Y')
                .' → '
                .\Carbon\CarbonImmutable::parse($periodEndRaw)->locale('tr')->translatedFormat('j M Y');
        } else {
            $periodDisplay = \Carbon\CarbonImmutable::parse($periodStartRaw)->format('M j, Y')
                .' → '
                .\Carbon\CarbonImmutable::parse($periodEndRaw)->format('M j, Y');
        }
    }
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-5 py-5 dark:border-gray-800 sm:px-6">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                <div class="flex min-w-0 items-start gap-4">
                    <div class="relative shrink-0"><x-demo.digital-asset-mark type="meta_ads" size="lg" /><span class="absolute -bottom-1 -right-1 h-3.5 w-3.5 rounded-full border-2 border-white {{ ($professional['available'] ?? false) ? 'bg-emerald-500' : 'bg-gray-400' }} dark:border-gray-900"></span></div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2"><p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Meta Ads</p><span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ ($professional['available'] ?? false) ? ($isTr ? 'Bağlı' : 'Connected') : ($identity['status'] ?? '—') }}</span></div>
                        <h1 class="mt-1 truncate text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">{{ $identity['title'] ?? 'Meta Ads' }}</h1>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">@if (filled($identity['brand_id'] ?? null))<a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] ?? '—' }}</a>@endif @if (filled($professional['timezone'] ?? null))<span class="hidden h-1 w-1 rounded-full bg-gray-300 sm:inline-block"></span><span>{{ $professional['timezone'] }}</span>@endif @if (filled($professional['currency'] ?? null))<span class="hidden h-1 w-1 rounded-full bg-gray-300 sm:inline-block"></span><span>{{ $professional['currency'] }}</span>@endif</div>
                    </div>
                </div>
                <div class="shrink-0">@include('livewire.demo.partials._meta-header-actions')</div>
            </div>
        </div>

        <div class="grid gap-3 bg-gray-50/70 px-5 py-4 dark:bg-white/[0.02] sm:grid-cols-2 sm:px-6 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Reklam Hesabı' : 'Ad Account' }}</p><p class="mt-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $identity['ad_account'] ?? $professional['act_id'] ?? '—' }}</p></div>
            <div class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Tarih Aralığı' : 'Date Range' }}</p><p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $periodDisplay }}</p></div>
            <div class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Karşılaştırma' : 'Comparison' }}</p><p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $metaCompareLabel ?? ($isTr ? 'Önceki dönem' : 'Previous period') }}</p></div>
            <details class="group rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900">
                <summary class="cursor-pointer list-none"><p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Veri Durumu' : 'Data Status' }}</p><div class="mt-1 flex items-center justify-between gap-2"><span class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-200"><span class="h-2 w-2 rounded-full {{ $healthClasses }}"></span>{{ $healthLabel }}</span><span class="text-xs text-gray-400">{{ $health['usable'] ?? 0 }}/{{ $health['total'] ?? 0 }}</span></div></summary>
                <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $isTr ? (($health['usable'] ?? 0).' / '.($health['total'] ?? 0).' veri grubundan seçili dönem için kullanılabilir bilgi geliyor.') : (($health['usable'] ?? 0).' of '.($health['total'] ?? 0).' data groups are usable for the selected period.') }}</p>
                    @if (! empty($health['issues']))
                        <details class="mt-3"><summary class="cursor-pointer text-[11px] font-semibold text-gray-400">{{ $isTr ? 'Uzman için teknik ayrıntılar' : 'Technical details for experts' }}</summary><div class="mt-2 space-y-2">@foreach (array_slice($health['issues'], 0, 8) as $issue)<div class="text-[11px] text-gray-500 dark:text-gray-400"><p class="font-semibold text-gray-700 dark:text-gray-300">{{ $issue['label'] }}</p><p class="font-mono text-[10px]">{{ $issue['freshness_state'] }} · {{ $issue['coverage_state'] }} · {{ $issue['integrity_status'] ?? '—' }}</p></div>@endforeach</div></details>
                    @endif
                </div>
            </details>
        </div>
    </section>

    <div class="rounded-2xl border border-gray-200 bg-white px-4 pt-1 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:px-5">@include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])</div>

    @if ($showPeriodBar)<div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">@include('livewire.demo.partials.period-bar')</div>@endif

    @if (! ($professional['available'] ?? false))
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200"><p class="font-semibold">{{ $isTr ? 'Bu Meta Ads hesabı için gösterilebilecek doğrulanmış veri bulunamadı.' : 'No verified data is available for this Meta Ads account.' }}</p><p class="mt-1 text-xs opacity-80">{{ $professional['error'] ?? ($isTr ? 'Bağlantı ve veri kapsamını kontrol edin. Eksik bilgi sıfırmış gibi gösterilmez.' : 'Check connection and data coverage. Missing data is never shown as zero.') }}</p></div>
    @endif

    @if ($tab === 'overview') @include('livewire.demo.meta.tabs.overview')
    @elseif ($tab === 'funnel') @include('livewire.demo.meta.tabs.funnel')
    @elseif ($tab === 'campaigns') @include('livewire.demo.meta.tabs.campaigns')
    @elseif ($tab === 'creatives') @include('livewire.demo.meta.tabs.creatives')
    @elseif ($tab === 'audience') @include('livewire.demo.meta.tabs.audience')
    @elseif ($tab === 'measurement') @include('livewire.demo.meta.tabs.measurement')
    @elseif ($tab === 'operations') @include('livewire.demo.meta.tabs.operations')
    @endif

    <details class="rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-4 py-3 text-xs text-gray-400 dark:border-gray-800 dark:bg-white/[0.02]"><summary class="cursor-pointer font-medium">{{ $isTr ? 'Veri kaynağı hakkında teknik bilgi' : 'Technical data-source information' }}</summary><div class="mt-2 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between"><span>{{ $isTr ? 'Veriler daha önce toplanmış gerçek Meta Ads kayıtlarından okunur. Sayfa açılırken Meta API çağrısı yapılmaz; eksik bilgi 0 kabul edilmez.' : 'Data is read from previously collected real Meta Ads records. No Meta API call occurs on page render; missing data is not treated as zero.' }}</span><span class="font-semibold">{{ $professional['metric_source'] ?? 'Data Pool' }}</span></div></details>
</div>
