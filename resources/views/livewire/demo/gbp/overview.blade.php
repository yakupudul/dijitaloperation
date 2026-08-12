@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'profile', 'label' => 'Profile', 'wire' => true],
        ['key' => 'visibility', 'label' => 'Visibility', 'wire' => true],
        ['key' => 'performance', 'label' => 'Performance', 'wire' => true],
        ['key' => 'reviews', 'label' => 'Reviews', 'wire' => true],
        ['key' => 'competitors', 'label' => 'Competitors', 'wire' => true],
        ['key' => 'operations', 'label' => 'Operations', 'wire' => true],
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $identity['eyebrow'] }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                @include('livewire.demo.partials.demo-badge')
            </div>
            <a href="{{ route('demo.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                {{ $identity['brand_name'] }}
            </a>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $identity['location_line'] }}</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $identity['locale'] }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] }}</span>
                · {{ $identity['freshness'] }}
                · Local rank tracking · Demo source
            </p>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._gbp-header-actions')
        </div>
    </div>

    @include('livewire.demo.partials.gbp-asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    <p class="text-xs text-gray-400">{{ $data['demo_boundary'] }}</p>

    @if ($tab === 'overview')
        @include('livewire.demo.gbp.tabs.overview')
    @elseif ($tab === 'profile')
        @include('livewire.demo.gbp.tabs.profile')
    @elseif ($tab === 'visibility')
        @include('livewire.demo.gbp.tabs.visibility')
    @elseif ($tab === 'performance')
        @include('livewire.demo.gbp.tabs.performance')
    @elseif ($tab === 'reviews')
        @include('livewire.demo.gbp.tabs.reviews')
    @elseif ($tab === 'competitors')
        @include('livewire.demo.gbp.tabs.competitors')
    @elseif ($tab === 'operations')
        @include('livewire.demo.gbp.tabs.operations')
    @endif
</div>
