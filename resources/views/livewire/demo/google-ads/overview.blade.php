@php
    $isTr = app()->getLocale() === 'tr';
    $navTabs = [
        ['key' => 'overview', 'label' => $isTr ? 'Genel Bakış' : 'Overview', 'wire' => true],
        ['key' => 'campaigns', 'label' => $isTr ? 'Kampanyalar' : 'Campaigns', 'wire' => true],
        ['key' => 'search_demand', 'label' => $isTr ? 'Arama' : 'Search', 'wire' => true],
        ['key' => 'ads_assets', 'label' => $isTr ? 'Reklamlar' : 'Ads', 'wire' => true],
        ['key' => 'performance', 'label' => $isTr ? 'Performans' : 'Performance', 'wire' => true],
        ['key' => 'budget_bidding', 'label' => $isTr ? 'Bütçe & Teklif' : 'Budget & Bidding', 'wire' => true],
        ['key' => 'measurement', 'label' => $isTr ? 'Dönüşümler' : 'Conversions', 'wire' => true],
        ['key' => 'landing_pages', 'label' => 'Landing Pages', 'wire' => true],
        ['key' => 'optimization', 'label' => $isTr ? 'Optimizasyon' : 'Optimization', 'wire' => true],
        ['key' => 'changes', 'label' => $isTr ? 'Değişiklikler' : 'Changes', 'wire' => true],
        ['key' => 'data_connection', 'label' => $isTr ? 'Veri & Bağlantı' : 'Data & Connection', 'wire' => true],
    ];
    if (data_get($professional, 'capabilities.pmax')) {
        $navTabs[] = ['key' => 'pmax', 'label' => 'PMax', 'wire' => true];
    }
    if (data_get($professional, 'capabilities.shopping')) {
        $navTabs[] = ['key' => 'shopping', 'label' => 'Shopping', 'wire' => true];
    }
    if (data_get($professional, 'capabilities.video')) {
        $navTabs[] = ['key' => 'video', 'label' => 'Video', 'wire' => true];
    }
@endphp

<div class="space-y-4">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="google_ads" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $identity['eyebrow'] }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                    @if (($data['migration_mode'] ?? null) === 'real')
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $isTr ? 'Gerçek veri' : 'Real data' }}</span>
                    @endif
                </div>
                <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $identity['strategy_line'] }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                    <span><span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] }}</span> · {{ $identity['freshness'] }}</span>
                    @if (data_get($professional, 'history.first_activity_month'))
                        <span>{{ $isTr ? 'İlk reklam' : 'First ad activity' }}: <strong class="font-medium text-gray-700 dark:text-gray-300">{{ data_get($professional, 'history.first_activity_month') }}</strong></span>
                    @endif
                    @if (data_get($professional, 'history.last_activity_month'))
                        <span>{{ $isTr ? 'Son reklam' : 'Last ad activity' }}: <strong class="font-medium text-gray-700 dark:text-gray-300">{{ data_get($professional, 'history.last_activity_month') }}</strong></span>
                    @endif
                </div>
                @include('livewire.demo.partials._asset-scope-chip', ['assetType' => 'google_ads'])
            </div>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._google-ads-header-actions')
        </div>
    </div>

    <div class="overflow-x-auto">
        <div class="min-w-max">
            @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])
        </div>
    </div>

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    @if (! empty($professional['error']))
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
            {{ $isTr ? 'Profesyonel veri katmanının bir bölümü okunamadı:' : 'Part of the professional data layer could not be read:' }} {{ $professional['error'] }}
        </div>
    @endif

    @if ($tab === 'overview')
        @include('livewire.demo.google-ads.tabs.professional-summary')
        @include('livewire.demo.google-ads.tabs.overview')
    @elseif ($tab === 'campaigns')
        @include('livewire.demo.google-ads.tabs.campaigns')
    @elseif ($tab === 'search_demand')
        @include('livewire.demo.google-ads.tabs.search-demand')
    @elseif ($tab === 'ads_assets')
        @include('livewire.demo.google-ads.tabs.ads-assets')
    @elseif ($tab === 'performance')
        @include('livewire.demo.google-ads.tabs.performance')
    @elseif ($tab === 'budget_bidding')
        @include('livewire.demo.google-ads.tabs.budget-bidding')
    @elseif ($tab === 'measurement')
        @include('livewire.demo.google-ads.tabs.measurement')
    @elseif ($tab === 'landing_pages')
        @include('livewire.demo.google-ads.tabs.landing-pages')
    @elseif ($tab === 'optimization')
        @include('livewire.demo.google-ads.tabs.optimization')
    @elseif ($tab === 'changes')
        @include('livewire.demo.google-ads.tabs.changes')
    @elseif ($tab === 'data_connection')
        @include('livewire.demo.google-ads.tabs.data-connection')
    @elseif ($tab === 'pmax')
        @include('livewire.demo.google-ads.tabs.pmax')
    @elseif ($tab === 'shopping')
        @include('livewire.demo.google-ads.tabs.shopping')
    @elseif ($tab === 'video')
        @include('livewire.demo.google-ads.tabs.video')
    @endif
</div>
