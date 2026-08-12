@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'health', 'label' => 'Health', 'wire' => true],
        ['key' => 'visibility', 'label' => 'Visibility', 'wire' => true],
        ['key' => 'content', 'label' => 'Content', 'wire' => true],
        ['key' => 'performance', 'label' => 'Performance', 'wire' => true],
        ['key' => 'connections', 'label' => 'Connections', 'wire' => true],
        ['key' => 'activity', 'label' => 'Activity', 'wire' => true],
        ['key' => 'settings', 'label' => 'Settings', 'wire' => true],
    ];
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                @include('livewire.demo.partials.demo-badge')
            </div>
            <a href="{{ route('demo.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                {{ $identity['brand_name'] }}
            </a>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <span>{{ $identity['domain'] }}</span>
                <a href="{{ $identity['primary_url'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs text-brand-600 hover:underline dark:text-brand-400" aria-label="Open website in new tab">
                    Open site
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
            </p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $identity['cms'] }} · {{ $identity['languages'] }} · {{ $identity['market'] }}</p>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] }}</span>
                · {{ $identity['status_note'] }}
                · Last data refresh {{ $identity['last_refresh'] }}
            </p>
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
    @elseif ($tab === 'connections')
        @include('livewire.demo.website.tabs.connections')
    @elseif ($tab === 'activity')
        @include('livewire.demo.website.tabs.activity')
    @elseif ($tab === 'settings')
        @include('livewire.demo.website.tabs.settings')
    @endif
</div>
