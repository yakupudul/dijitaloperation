@php
    $isTr = app()->getLocale() === 'tr';
    $effectiveTab = $tab === 'landing_pages' ? 'overview' : $tab;
    $providerConnected = (bool) ($professional['connected'] ?? false);
    $rawTitle = (string) ($identity['title'] ?? 'Google Ads');
    if ($providerConnected) {
        $baseTitle = preg_replace('/\s+—\s+(read error|not connected)$/iu', '', $rawTitle) ?: $rawTitle;
        $displayTitle = str_contains($baseTitle, '— Google Ads') ? $baseTitle : $baseTitle.' — Google Ads';
    } else {
        $displayTitle = $rawTitle;
    }
    $rawStatus = (string) ($identity['status'] ?? '');
    $statusLabel = match ($rawStatus) {
        'Connected' => $isTr ? 'Bağlı' : 'Connected',
        'Error' => $providerConnected ? ($isTr ? 'Bağlı · veri okuma sorunu' : 'Connected · data read issue') : ($isTr ? 'Hata' : 'Error'),
        'Action required' => $isTr ? 'İşlem gerekli' : 'Action required',
        'Not connected' => $isTr ? 'Bağlı değil' : 'Not connected',
        default => $rawStatus,
    };
    $rawFreshness = (string) ($identity['freshness'] ?? '');
    $freshnessLabel = match ($rawFreshness) {
        'Not collected' => $providerConnected ? ($isTr ? 'Ana görünüm yeniden okunuyor' : 'Main read pending') : ($isTr ? 'Henüz veri yok' : 'Not collected'),
        'Read issue' => $isTr ? 'Veri okuma sorunu' : 'Read issue',
        default => $rawFreshness,
    };
    $strategyLine = (string) ($identity['strategy_line'] ?? '');
    if ($providerConnected && str_contains(strtolower($strategyLine), 'not connected')) {
        $strategyLine = $isTr
            ? 'Google Ads hesabı bağlı. Ana veri katmanının bir bölümü okunamadığında mevcut sağlayıcı verisi korunur.'
            : 'Google Ads account is connected. Existing provider data remains available if part of the main read layer fails.';
    }
    if ($isTr && preg_match('/^Runs ads for\s*·\s*(.+)$/iu', $strategyLine, $matches) === 1) {
        $strategyLine = 'Reklam hesabı · '.trim((string) ($matches[1] ?? ''));
    }
    $navTabs = [
        ['key' => 'overview', 'label' => $isTr ? 'Genel Bakış' : 'Overview', 'wire' => true],
        ['key' => 'campaigns', 'label' => $isTr ? 'Kampanyalar' : 'Campaigns', 'wire' => true],
        ['key' => 'search_demand', 'label' => $isTr ? 'Arama' : 'Search', 'wire' => true],
        ['key' => 'performance', 'label' => $isTr ? 'Performans' : 'Performance', 'wire' => true],
        ['key' => 'budget_bidding', 'label' => $isTr ? 'Bütçe & Teklif' : 'Budget & Bidding', 'wire' => true],
        ['key' => 'measurement', 'label' => $isTr ? 'Dönüşümler' : 'Conversions', 'wire' => true],
        ['key' => 'optimization', 'label' => $isTr ? 'Optimizasyon' : 'Optimization', 'wire' => true],
        ['key' => 'changes', 'label' => $isTr ? 'Değişiklikler' : 'Changes', 'wire' => true],
        ['key' => 'data_connection', 'label' => $isTr ? 'Veri & Bağlantı' : 'Data & Connection', 'wire' => true],
    ];
    if (data_get($professional, 'capabilities.pmax')) {
        $navTabs[] = ['key' => 'pmax', 'label' => 'PMax', 'wire' => true];
    }
    if (data_get($professional, 'capabilities.shopping')) {
        $navTabs[] = ['key' => 'shopping', 'label' => $isTr ? 'Alışveriş' : 'Shopping', 'wire' => true];
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
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Google Ads</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $displayTitle }}</h1>
                    @if (($data['migration_mode'] ?? null) === 'real' || $providerConnected)
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $isTr ? 'Gerçek veri' : 'Real data' }}</span>
                    @endif
                </div>
                @if (! empty($identity['brand_id']))
                    <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                @endif
                @if ($strategyLine !== '')
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $strategyLine }}</p>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                    <span><span class="font-medium {{ $providerConnected || $rawStatus === 'Connected' ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }}">{{ $statusLabel }}</span>@if($freshnessLabel !== '') · {{ $freshnessLabel }} @endif</span>
                    @if (! empty($identity['customer_id']))
                        <span>{{ $isTr ? 'Müşteri ID' : 'Customer ID' }} <strong class="font-medium text-gray-700 dark:text-gray-300">{{ $identity['customer_id'] }}</strong></span>
                    @endif
                    @if (! empty($identity['reporting_timezone']))
                        <span>{{ $identity['reporting_timezone'] }}</span>
                    @endif
                    @if (! empty($identity['currency']))
                        <span>{{ $identity['currency'] }}</span>
                    @endif
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
            @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $effectiveTab])
        </div>
    </div>

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    @if (! empty($professional['error']))
        <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20">
            <strong>{{ $isTr ? 'Sağlayıcı veri katmanında geçici okuma sorunu.' : 'Temporary provider data read issue.' }}</strong>
            {{ $isTr ? 'Hesap bağlantısı korunuyor; Veri & Bağlantı sekmesinden veri seti durumunu kontrol edebilirsiniz.' : 'The account remains connected; inspect dataset state under Data & Connection.' }}
        </div>
    @endif

    @if ($effectiveTab === 'overview')
        @include('livewire.demo.google-ads.tabs.professional-summary')
        @include('livewire.demo.google-ads.tabs.overview')
    @elseif ($effectiveTab === 'campaigns')
        @include('livewire.demo.google-ads.tabs.campaigns')
    @elseif ($effectiveTab === 'search_demand')
        @if ($searchExpertWorkspace ?? false)
            @include('livewire.demo.google-ads.tabs.search-expert-live')
        @else
            @include('livewire.demo.google-ads.tabs.search-demand')
            @include('livewire.demo.google-ads.tabs.search-negatives')
        @endif
    @elseif ($effectiveTab === 'performance')
        @include('livewire.demo.google-ads.tabs.performance')
    @elseif ($effectiveTab === 'budget_bidding')
        @include('livewire.demo.google-ads.tabs.budget-bidding')
    @elseif ($effectiveTab === 'measurement')
        @include('livewire.demo.google-ads.tabs.measurement')
    @elseif ($effectiveTab === 'optimization')
        @include('livewire.demo.google-ads.tabs.optimization')
    @elseif ($effectiveTab === 'changes')
        @include('livewire.demo.google-ads.tabs.changes')
    @elseif ($effectiveTab === 'data_connection')
        @include('livewire.demo.google-ads.tabs.data-connection')
    @elseif ($effectiveTab === 'pmax')
        @include('livewire.demo.google-ads.tabs.pmax')
    @elseif ($effectiveTab === 'shopping')
        @include('livewire.demo.google-ads.tabs.shopping')
    @elseif ($effectiveTab === 'video')
        @include('livewire.demo.google-ads.tabs.video')
    @endif
</div>
