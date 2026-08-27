@php
    $isTr = app()->getLocale() === 'tr';

    // Keep the existing Livewire tab keys for backward-compatible URLs/state.
    // The presentation labels below are the new Meta Ads product information architecture.
    $navTabs = [
        ['key' => 'overview', 'label' => $isTr ? 'Genel Bakış' : 'Overview', 'wire' => true],
        ['key' => 'funnel', 'label' => $isTr ? 'Performans' : 'Performance', 'wire' => true],
        ['key' => 'campaigns', 'label' => $isTr ? 'Kampanyalar' : 'Campaigns', 'wire' => true],
        ['key' => 'creatives', 'label' => $isTr ? 'Kreatifler' : 'Creatives', 'wire' => true],
        ['key' => 'audience', 'label' => $isTr ? 'Kitle & Dağıtım' : 'Audience & Delivery', 'wire' => true],
        ['key' => 'measurement', 'label' => $isTr ? 'Dönüşümler' : 'Conversions', 'wire' => true],
        ['key' => 'operations', 'label' => $isTr ? 'İçgörüler & Aksiyonlar' : 'Insights & Actions', 'wire' => true],
    ];

    $placeholderMetrics = [
        $isTr ? 'Harcama' : 'Spend',
        $isTr ? 'Sonuçlar' : 'Results',
        $isTr ? 'Sonuç Başına Maliyet' : 'Cost per Result',
        'ROAS',
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    {{-- Workspace header --}}
    <section class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-5 py-5 dark:border-gray-800 sm:px-6">
            <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                <div class="flex min-w-0 items-start gap-4">
                    <div class="relative shrink-0">
                        <x-demo.digital-asset-mark type="meta_ads" size="lg" />
                        <span class="absolute -bottom-1 -right-1 h-3.5 w-3.5 rounded-full border-2 border-white bg-emerald-500 dark:border-gray-900"></span>
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">Meta Ads</p>
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                {{ $identity['status'] ?? ($isTr ? 'Bağlı' : 'Connected') }}
                            </span>
                        </div>

                        <h1 class="mt-1 truncate text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">
                            {{ $identity['title'] ?? ($isTr ? 'Meta Ads Çalışma Alanı' : 'Meta Ads Workspace') }}
                        </h1>

                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                            @if (filled($identity['brand_id'] ?? null))
                                <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="font-semibold text-brand-600 hover:underline dark:text-brand-400">
                                    {{ $identity['brand_name'] ?? '—' }}
                                </a>
                            @elseif (filled($identity['brand_name'] ?? null))
                                <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $identity['brand_name'] }}</span>
                            @endif

                            <span class="hidden h-1 w-1 rounded-full bg-gray-300 sm:inline-block"></span>
                            <span>{{ $identity['freshness'] ?? ($isTr ? 'Veri tazeliği kontrol ediliyor' : 'Checking data freshness') }}</span>
                        </div>
                    </div>
                </div>

                <div class="shrink-0">
                    @include('livewire.demo.partials._meta-header-actions')
                </div>
            </div>
        </div>

        {{-- Account / date / comparison / health control bar --}}
        <div class="grid gap-3 bg-gray-50/70 px-5 py-4 dark:bg-white/[0.02] sm:grid-cols-2 sm:px-6 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Reklam Hesabı' : 'Ad Account' }}</p>
                <div class="mt-1 flex items-center justify-between gap-2">
                    <span class="truncate text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? 'Bağlı hesap' : 'Connected account' }}</span>
                    <svg class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m6 8 4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Tarih Aralığı' : 'Date Range' }}</p>
                <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? 'Seçili dönem' : 'Selected period' }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Karşılaştırma' : 'Comparison' }}</p>
                <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $isTr ? 'Önceki dönem' : 'Previous period' }}</p>
            </div>

            <div class="rounded-xl border border-gray-200 bg-white px-3.5 py-3 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ $isTr ? 'Veri Sağlığı' : 'Data Health' }}</p>
                <div class="mt-1 flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-200">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    <span>{{ $isTr ? 'Kaynak hazır' : 'Source ready' }}</span>
                </div>
            </div>
        </div>
    </section>

    {{-- Primary workspace navigation --}}
    <div class="rounded-2xl border border-gray-200 bg-white px-4 pt-1 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:px-5">
        @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])
    </div>

    @if ($showPeriodBar)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            @include('livewire.demo.partials.period-bar')
        </div>
    @endif

    {{-- OVERVIEW --}}
    @if ($tab === 'overview')
        <section class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($placeholderMetrics as $metric)
                    <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $metric }}</p>
                                <p class="mt-3 text-3xl font-bold tracking-tight text-gray-900 dark:text-white">—</p>
                            </div>
                            <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-gray-50 text-gray-400 dark:bg-white/[0.04]">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 14V9m4 5V6m4 8v-4m4 4V3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                            </span>
                        </div>
                        <div class="mt-4 flex items-center gap-2 text-xs text-gray-400">
                            <span class="rounded-full bg-gray-100 px-2 py-1 dark:bg-white/[0.05]">{{ $isTr ? 'Canlı veri bekleniyor' : 'Awaiting live data' }}</span>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,.8fr)]">
                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Hesap Performansı' : 'Account Performance' }}</p>
                            <h2 class="mt-1 text-lg font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Performans eğilimi' : 'Performance trend' }}</h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Harcama, sonuç ve verimlilik metrikleri burada birlikte izlenecek.' : 'Spend, results and efficiency metrics will be tracked together here.' }}</p>
                        </div>
                        <span class="inline-flex w-fit rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-500 dark:border-gray-700 dark:text-gray-400">{{ $isTr ? 'Günlük' : 'Daily' }}</span>
                    </div>

                    <div class="mt-6 flex h-64 items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50/70 dark:border-gray-700 dark:bg-white/[0.02]">
                        <div class="max-w-sm px-6 text-center">
                            <svg class="mx-auto h-8 w-8 text-gray-300 dark:text-gray-600" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="M5 23 11 16l5 4 7-11 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 27h22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            <p class="mt-3 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $isTr ? 'Grafik alanı hazır' : 'Chart area ready' }}</p>
                            <p class="mt-1 text-xs leading-5 text-gray-400">{{ $isTr ? 'Bir sonraki adımda çekilen Meta Ads günlük performans datasetleri buraya bağlanacak.' : 'Collected Meta Ads daily performance datasets will be connected here next.' }}</p>
                        </div>
                    </div>
                </article>

                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">MOXDOP</p>
                            <h2 class="mt-1 text-lg font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Dikkat Gerektirenler' : 'Needs Attention' }}</h2>
                        </div>
                        <span class="rounded-full bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-500 dark:bg-white/[0.05]">—</span>
                    </div>

                    <div class="mt-5 space-y-3">
                        @foreach (range(1, 3) as $row)
                            <div class="rounded-xl border border-dashed border-gray-200 p-4 dark:border-gray-800">
                                <div class="flex items-start gap-3">
                                    <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-gray-300 dark:bg-gray-700"></span>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ $isTr ? 'Analiz sinyali' : 'Analysis signal' }}</p>
                                        <p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Gerçek bulgu üretildiğinde burada önceliklendirilecek.' : 'Prioritized here when a real finding is generated.' }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                @foreach ([
                    [$isTr ? 'En İyi Kampanyalar' : 'Top Campaigns', $isTr ? 'Kampanya performansı ve verimlilik sıralaması.' : 'Campaign performance and efficiency ranking.'],
                    [$isTr ? 'Kreatif Nabzı' : 'Creative Pulse', $isTr ? 'Kazanan, yorulan ve test edilmesi gereken kreatifler.' : 'Winning, tiring and test-worthy creatives.'],
                    [$isTr ? 'Dönüşüm Sağlığı' : 'Conversion Health', $isTr ? 'Meta sonuçları ile ölçüm kaynaklarının tutarlılığı.' : 'Consistency between Meta results and measurement sources.'],
                ] as [$title, $description])
                    <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <h3 class="font-bold text-gray-900 dark:text-white">{{ $title }}</h3>
                        <p class="mt-1 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $description }}</p>
                        <div class="mt-5 rounded-xl border border-dashed border-gray-200 px-4 py-5 text-center dark:border-gray-800">
                            <p class="text-sm font-medium text-gray-400">{{ $isTr ? 'Veri alanı hazır' : 'Data slot ready' }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

    {{-- PERFORMANCE --}}
    @elseif ($tab === 'funnel')
        <section class="space-y-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Performans' : 'Performance' }}</p>
                    <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Hesap performansını tek yerde oku' : 'Read account performance in one place' }}</h2>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Hacim, maliyet ve verimlilik metrikleri; dönem karşılaştırması ve zaman eğrisiyle birlikte değerlendirilecek.' : 'Volume, cost and efficiency metrics will be evaluated with period comparison and time trends.' }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @foreach ([$isTr ? 'Sonuçlar' : 'Results', 'CPA', 'CTR', 'CPM', 'ROAS'] as $metric)
                        <span class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">{{ $metric }}</span>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([$isTr ? 'Harcama' : 'Spend', $isTr ? 'Gösterim' : 'Impressions', $isTr ? 'Tıklamalar' : 'Clicks', $isTr ? 'Sonuçlar' : 'Results', $isTr ? 'Sonuç Başına Maliyet' : 'Cost / Result'] as $metric)
                    <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $metric }}</p>
                        <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">—</p>
                        <p class="mt-2 text-[11px] text-gray-400">{{ $isTr ? 'Karşılaştırma bağlanacak' : 'Comparison pending' }}</p>
                    </div>
                @endforeach
            </div>

            <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Zaman içindeki performans' : 'Performance over time' }}</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Günlük datasetler burada çoklu metrik grafik olarak gösterilecek.' : 'Daily datasets will render here as a multi-metric chart.' }}</p>
                    </div>
                    <span class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-500 dark:bg-white/[0.05]">{{ $isTr ? 'Veri bekleniyor' : 'Awaiting data' }}</span>
                </div>
                <div class="mt-6 h-80 rounded-xl border border-dashed border-gray-300 bg-gray-50/70 dark:border-gray-700 dark:bg-white/[0.02]"></div>
            </article>
        </section>

    {{-- CAMPAIGNS --}}
    @elseif ($tab === 'campaigns')
        <section x-data="{ level: 'campaigns' }" class="space-y-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Yapı & Performans' : 'Structure & Performance' }}</p>
                    <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Kampanya yönetim görünümü' : 'Campaign management view' }}</h2>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kampanya, reklam seti ve reklam seviyelerini aynı analitik bağlamda incelemek için hazırlandı.' : 'Prepared to inspect campaign, ad set and ad levels in one analytical context.' }}</p>
                </div>

                <div class="inline-flex w-fit rounded-xl border border-gray-200 bg-gray-50 p-1 dark:border-gray-700 dark:bg-white/[0.03]">
                    <button type="button" @click="level = 'campaigns'" :class="level === 'campaigns' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-lg px-3 py-2 text-xs font-semibold transition">{{ $isTr ? 'Kampanyalar' : 'Campaigns' }}</button>
                    <button type="button" @click="level = 'adsets'" :class="level === 'adsets' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-lg px-3 py-2 text-xs font-semibold transition">{{ $isTr ? 'Reklam Setleri' : 'Ad Sets' }}</button>
                    <button type="button" @click="level = 'ads'" :class="level === 'ads' ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-800 dark:text-white' : 'text-gray-500 dark:text-gray-400'" class="rounded-lg px-3 py-2 text-xs font-semibold transition">{{ $isTr ? 'Reklamlar' : 'Ads' }}</button>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-4">
                @foreach ([$isTr ? 'Aktif' : 'Active', $isTr ? 'Harcama' : 'Spend', $isTr ? 'Sonuç' : 'Results', $isTr ? 'Ort. Sonuç Maliyeti' : 'Avg. Cost / Result'] as $metric)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $metric }}</p>
                        <p class="mt-2 text-xl font-bold text-gray-900 dark:text-white">—</p>
                    </div>
                @endforeach
            </div>

            <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h3 class="font-bold text-gray-900 dark:text-white" x-text="level === 'campaigns' ? '{{ $isTr ? 'Kampanyalar' : 'Campaigns' }}' : (level === 'adsets' ? '{{ $isTr ? 'Reklam Setleri' : 'Ad Sets' }}' : '{{ $isTr ? 'Reklamlar' : 'Ads' }}')"></h3>
                        <p class="mt-0.5 text-xs text-gray-400">{{ $isTr ? 'Sıralama, filtreleme ve detay drawer yapısı veri bağlamaya hazır.' : 'Sorting, filtering and detail drawer structure is ready for data.' }}</p>
                    </div>
                    <div class="flex gap-2">
                        <span class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-500 dark:border-gray-700">{{ $isTr ? 'Durum: Tümü' : 'Status: All' }}</span>
                        <span class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-500 dark:border-gray-700">{{ $isTr ? 'Filtreler' : 'Filters' }}</span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-left dark:divide-gray-800">
                        <thead class="bg-gray-50/80 dark:bg-white/[0.02]">
                            <tr class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">
                                <th class="px-5 py-3">{{ $isTr ? 'Ad' : 'Name' }}</th>
                                <th class="px-4 py-3">{{ $isTr ? 'Durum' : 'Status' }}</th>
                                <th class="px-4 py-3">{{ $isTr ? 'Harcama' : 'Spend' }}</th>
                                <th class="px-4 py-3">{{ $isTr ? 'Sonuç' : 'Results' }}</th>
                                <th class="px-4 py-3">CPA</th>
                                <th class="px-4 py-3">CTR</th>
                                <th class="px-4 py-3">CPM</th>
                                <th class="px-5 py-3 text-right">{{ $isTr ? 'Analiz' : 'Analysis' }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr>
                                <td colspan="8" class="px-5 py-14 text-center">
                                    <div class="mx-auto max-w-md">
                                        <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-gray-100 text-gray-400 dark:bg-white/[0.05]">
                                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 5h12M4 10h12M4 15h8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                                        </div>
                                        <p class="mt-3 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $isTr ? 'Tablo yapısı hazır' : 'Table structure ready' }}</p>
                                        <p class="mt-1 text-xs leading-5 text-gray-400">{{ $isTr ? 'Bir sonraki adımda Campaign / Ad Set / Ad snapshot ve günlük performans verileri bu seviyelere bağlanacak.' : 'Campaign / Ad Set / Ad snapshots and daily performance data will be connected to these levels next.' }}</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>

    {{-- CREATIVES --}}
    @elseif ($tab === 'creatives')
        <section class="space-y-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kreatif Analizi' : 'Creative Analysis' }}</p>
                <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Hangi kreatif gerçekten çalışıyor?' : 'Which creative is actually working?' }}</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Görsel/video, metin, format ve sonuç ilişkisini performans sinyalleriyle birlikte değerlendirmek için tasarlandı.' : 'Designed to evaluate visual/video, copy, format and result relationships with performance signals.' }}</p>
            </div>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_360px]">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach (range(1, 6) as $creative)
                        <article class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex aspect-[4/3] items-center justify-center bg-gray-50 dark:bg-white/[0.02]">
                                <svg class="h-8 w-8 text-gray-300 dark:text-gray-700" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Z" stroke="currentColor" stroke-width="1.5"/><path d="m7 16 3.5-4 2.5 2.5 1.5-2 2.5 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </div>
                            <div class="p-4">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kreatif' : 'Creative' }} #{{ $creative }}</p>
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold text-gray-400 dark:bg-white/[0.05]">—</span>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-gray-400">
                                    <span>{{ $isTr ? 'Harcama' : 'Spend' }}: —</span>
                                    <span>{{ $isTr ? 'Sonuç' : 'Result' }}: —</span>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                <article class="h-fit rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Kreatif Sinyalleri' : 'Creative Signals' }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Kazananlar, yorgunluk işaretleri ve test fırsatları.' : 'Winners, fatigue signals and test opportunities.' }}</p>
                    <div class="mt-5 space-y-3">
                        @foreach ([$isTr ? 'Kazanan kreatifler' : 'Winning creatives', $isTr ? 'Yorgunluk riski' : 'Fatigue risk', $isTr ? 'Yeni test fırsatları' : 'New test opportunities'] as $label)
                            <div class="rounded-xl border border-dashed border-gray-200 p-4 dark:border-gray-800">
                                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $label }}</p>
                                <p class="mt-2 text-xl font-bold text-gray-900 dark:text-white">—</p>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>

    {{-- AUDIENCE & DELIVERY --}}
    @elseif ($tab === 'audience')
        <section class="space-y-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Kitle & Dağıtım' : 'Audience & Delivery' }}</p>
                <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Bütçe kime, nerede ve ne zaman gidiyor?' : 'Who, where and when receives the budget?' }}</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Breakdown ve hourly datasetleri yaş, cinsiyet, ülke, placement, cihaz ve saat boyutlarında burada okunacak.' : 'Breakdown and hourly datasets will be read here across age, gender, country, placement, device and hour.' }}</p>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                @foreach ([
                    [$isTr ? 'Demografi' : 'Demographics', $isTr ? 'Yaş ve cinsiyet dağılımı' : 'Age and gender distribution'],
                    [$isTr ? 'Konum' : 'Location', $isTr ? 'Ülke / bölge performansı' : 'Country / region performance'],
                    ['Placement', $isTr ? 'Facebook, Instagram ve diğer placement dağılımları' : 'Facebook, Instagram and other placement distribution'],
                    [$isTr ? 'Saatlik Dağıtım' : 'Hourly Delivery', $isTr ? 'Gün ve saat bazında bütçe / sonuç yoğunluğu' : 'Budget / result density by day and hour'],
                ] as [$title, $description])
                    <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                        <h3 class="font-bold text-gray-900 dark:text-white">{{ $title }}</h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
                        <div class="mt-5 h-48 rounded-xl border border-dashed border-gray-300 bg-gray-50/70 dark:border-gray-700 dark:bg-white/[0.02]"></div>
                    </article>
                @endforeach
            </div>
        </section>

    {{-- CONVERSIONS --}}
    @elseif ($tab === 'measurement')
        <section class="space-y-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $isTr ? 'Dönüşümler' : 'Conversions' }}</p>
                <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Sonuçların kaynağını ve kalitesini doğrula' : 'Validate the source and quality of results' }}</h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Meta action türleri, conversion source bilgisi ve ölçüm tutarlılığı tek bir görünümde birleştirilecek.' : 'Meta action types, conversion source data and measurement consistency will be combined in one view.' }}</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([$isTr ? 'Toplam Sonuç' : 'Total Results', $isTr ? 'Lead' : 'Leads', $isTr ? 'Mesaj' : 'Messages', $isTr ? 'Satın Alma / Değer' : 'Purchase / Value'] as $metric)
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $metric }}</p>
                        <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">—</p>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-5 xl:grid-cols-2">
                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Dönüşüm Türleri' : 'Conversion Types' }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Typed Actions datasetinin dağılımı burada gösterilecek.' : 'Typed Actions dataset distribution will appear here.' }}</p>
                    <div class="mt-5 h-56 rounded-xl border border-dashed border-gray-300 bg-gray-50/70 dark:border-gray-700 dark:bg-white/[0.02]"></div>
                </article>
                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Ölçüm Tutarlılığı' : 'Measurement Consistency' }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Meta sonuçları ile bağlı ölçüm kaynakları arasındaki farklar burada analiz edilecek.' : 'Differences between Meta results and connected measurement sources will be analyzed here.' }}</p>
                    <div class="mt-5 space-y-3">
                        @foreach ([$isTr ? 'Meta Ads sonuçları' : 'Meta Ads results', $isTr ? 'Dönüşüm kaynağı' : 'Conversion source', $isTr ? 'Tutarlılık sinyali' : 'Consistency signal'] as $row)
                            <div class="flex items-center justify-between rounded-xl border border-gray-200 px-4 py-3 dark:border-gray-800">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $row }}</span>
                                <span class="text-sm font-bold text-gray-400">—</span>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>

    {{-- INSIGHTS & ACTIONS --}}
    @elseif ($tab === 'operations')
        <section class="space-y-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-gray-400">MOXDOP · Observe → Diagnose → Recommend</p>
                    <h2 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $isTr ? 'İçgörülerden aksiyona' : 'From insights to action' }}</h2>
                    <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Meta verisi burada bulguya, öneriye, göreve ve ölçülebilir sonuca dönüşecek.' : 'Meta data will turn into findings, recommendations, tasks and measurable outcomes here.' }}</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    [$isTr ? 'Bulgular' : 'Findings', $isTr ? 'Tespit edilen gerçek performans sorunları ve fırsatlar.' : 'Detected performance problems and opportunities.'],
                    [$isTr ? 'Öneriler' : 'Recommendations', $isTr ? 'Kanıta dayalı yapılması gerekenler.' : 'Evidence-based next actions.'],
                    [$isTr ? 'Görevler' : 'Tasks', $isTr ? 'Onaylanan önerilerin operasyonel takibi.' : 'Operational tracking of approved recommendations.'],
                    [$isTr ? 'Sonuçlar' : 'Outcomes', $isTr ? 'Uygulanan işlerin performans etkisi.' : 'Measured impact of executed work.'],
                ] as [$title, $description])
                    <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="font-bold text-gray-900 dark:text-white">{{ $title }}</h3>
                            <span class="text-2xl font-bold text-gray-300 dark:text-gray-700">—</span>
                        </div>
                        <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $description }}</p>
                    </article>
                @endforeach
            </div>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1.5fr)_minmax(320px,.8fr)]">
                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Öncelikli İçgörüler' : 'Priority Insights' }}</h3>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Etki ve güven düzeyine göre sıralanacak.' : 'Ranked by impact and confidence.' }}</p>
                        </div>
                        <span class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs text-gray-400 dark:border-gray-700">{{ $isTr ? 'Etki ↓' : 'Impact ↓' }}</span>
                    </div>

                    <div class="mt-5 space-y-3">
                        @foreach (range(1, 4) as $row)
                            <div class="rounded-xl border border-dashed border-gray-200 p-4 dark:border-gray-800">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-1 h-2.5 w-2.5 rounded-full bg-gray-300 dark:bg-gray-700"></span>
                                        <div>
                                            <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">{{ $isTr ? 'İçgörü alanı' : 'Insight slot' }}</p>
                                            <p class="mt-1 text-xs text-gray-400">{{ $isTr ? 'Gerçek Meta Ads kanıtı bağlandığında tanı ve öneri burada gösterilecek.' : 'Diagnosis and recommendation will appear here when real Meta Ads evidence is connected.' }}</p>
                                        </div>
                                    </div>
                                    <span class="text-xs font-semibold text-gray-300 dark:text-gray-700">—</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>

                <article class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 sm:p-6">
                    <h3 class="font-bold text-gray-900 dark:text-white">{{ $isTr ? 'Aksiyon Akışı' : 'Action Flow' }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bir sinyalin işletme değerine dönüşme yolu.' : 'How a signal becomes business value.' }}</p>

                    <div class="mt-6 space-y-0">
                        @foreach ([$isTr ? 'Bulgu' : 'Finding', $isTr ? 'Öneri' : 'Recommendation', $isTr ? 'Görev' : 'Task', $isTr ? 'Sonuç' : 'Outcome'] as $index => $step)
                            <div class="relative flex gap-3 pb-5 last:pb-0">
                                @if ($index < 3)
                                    <span class="absolute left-[9px] top-5 h-full w-px bg-gray-200 dark:bg-gray-800"></span>
                                @endif
                                <span class="relative z-10 mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-gray-300 bg-white text-[10px] font-bold text-gray-400 dark:border-gray-700 dark:bg-gray-900">{{ $index + 1 }}</span>
                                <div>
                                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">{{ $step }}</p>
                                    <p class="mt-0.5 text-xs text-gray-400">{{ $isTr ? 'Veri bağlandığında aktifleşecek' : 'Activates when data is connected' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            </div>
        </section>
    @endif

    <div class="flex flex-col gap-2 rounded-xl border border-dashed border-gray-200 bg-gray-50/60 px-4 py-3 text-xs text-gray-400 dark:border-gray-800 dark:bg-white/[0.02] sm:flex-row sm:items-center sm:justify-between">
        <span>{{ $isTr ? 'Bu sürüm yalnızca Meta Ads çalışma alanı tasarımını oluşturur; performans rakamı simüle edilmez.' : 'This version creates the Meta Ads workspace design only; no performance numbers are simulated.' }}</span>
        <span class="font-semibold">{{ $isTr ? 'Sonraki adım: gerçek datasetleri bağla' : 'Next: connect real datasets' }}</span>
    </div>
</div>
