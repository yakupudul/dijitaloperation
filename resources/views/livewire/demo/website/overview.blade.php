@php
    $navTabs = [
        ['key' => 'overview', 'label' => __('operator.website.tabs.overview'), 'wire' => true],
        ['key' => 'health', 'label' => __('operator.website.tabs.health'), 'wire' => true],
        ['key' => 'visibility', 'label' => __('operator.website.tabs.visibility'), 'wire' => true],
        ['key' => 'content', 'label' => __('operator.website.tabs.content'), 'wire' => true],
        ['key' => 'performance', 'label' => __('operator.website.tabs.performance'), 'wire' => true],
        ['key' => 'infrastructure', 'label' => __('operator.website.tabs.infrastructure'), 'wire' => true],
        ['key' => 'operations', 'label' => __('operator.website.tabs.operations'), 'wire' => true],
        ['key' => 'setup', 'label' => __('operator.website.tabs.setup'), 'wire' => true],
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="website" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                </div>
                <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                    {{ $identity['brand_name'] }}
                </a>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <span>{{ $identity['domain'] }}</span>
                    <a href="{{ $identity['primary_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-400" aria-label="Open website in new tab">
                        {{ __('operator.chrome.open_site') }}
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                </p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $identity['cms'] }} · {{ $identity['languages'] }} · {{ $identity['market'] }}</p>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] }}</span>
                    · {{ $identity['status_note'] }}
                    · {{ __('operator.chrome.last_data_refresh') }} {{ $identity['last_refresh'] }}
                </p>
                @include('livewire.demo.partials._asset-scope-chip', ['assetType' => 'website'])
            </div>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._website-header-actions')
        </div>
    </div>

    @include('livewire.demo.partials.website-asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    <p class="text-xs text-gray-400">{{ $data['demo_boundary'] }}</p>

    @if ($tab === 'overview')
        @include('livewire.demo.website.tabs.overview')
    @elseif ($tab === 'health')
        @include('livewire.demo.website.tabs.health')
    @elseif ($tab === 'visibility')
        @include('livewire.demo.website.tabs.visibility')
    @elseif ($tab === 'content')
        @include('livewire.demo.website.tabs.content')
    @elseif ($tab === 'performance')
        @include('livewire.demo.website.tabs.performance')
    @elseif ($tab === 'infrastructure')
        @include('livewire.demo.website.tabs.infrastructure')
    @elseif ($tab === 'operations')
        @include('livewire.demo.website.tabs.operations')
    @elseif ($tab === 'setup')
        @include('livewire.demo.website.tabs.setup')
    @endif
</div>
