@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'campaigns', 'label' => 'Campaigns', 'wire' => true],
        ['key' => 'search_demand', 'label' => 'Search & Demand', 'wire' => true],
        ['key' => 'ads_assets', 'label' => 'Ads & Assets', 'wire' => true],
        ['key' => 'landing_pages', 'label' => 'Landing Pages', 'wire' => true],
        ['key' => 'measurement', 'label' => 'Measurement', 'wire' => true],
        ['key' => 'operations', 'label' => 'Operations', 'wire' => true],
    ];
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
                    @include('livewire.demo.partials.demo-badge')
                </div>
                <a href="{{ route('demo.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $identity['strategy_line'] }}</p>
                <p class="mt-2 text-xs text-gray-500">
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] }}</span>
                    · {{ $identity['freshness'] }}
                </p>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($data['freshness'] as $chip)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/5 dark:text-gray-300" title="{{ $chip['detail'] }}">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $chip['source'] }} · {{ $chip['age'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._google-ads-header-actions')
        </div>
    </div>

    @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    <p class="text-xs text-gray-400">{{ $data['demo_boundary'] }}</p>

    @if ($tab === 'overview')
        @include('livewire.demo.google-ads.tabs.overview')
    @elseif ($tab === 'campaigns')
        @include('livewire.demo.google-ads.tabs.campaigns')
    @elseif ($tab === 'search_demand')
        @include('livewire.demo.google-ads.tabs.search-demand')
    @elseif ($tab === 'ads_assets')
        @include('livewire.demo.google-ads.tabs.ads-assets')
    @elseif ($tab === 'landing_pages')
        @include('livewire.demo.google-ads.tabs.landing-pages')
    @elseif ($tab === 'measurement')
        @include('livewire.demo.google-ads.tabs.measurement')
    @elseif ($tab === 'operations')
        @include('livewire.demo.google-ads.tabs.operations')
    @endif
</div>
