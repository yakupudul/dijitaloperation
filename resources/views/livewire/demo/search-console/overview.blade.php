@php
    $navTabs = [
        ['key' => 'overview', 'label' => 'Overview', 'wire' => true],
        ['key' => 'performance', 'label' => 'Search Performance', 'wire' => true],
        ['key' => 'demand', 'label' => 'Queries & Demand', 'wire' => true],
        ['key' => 'pages', 'label' => 'Pages', 'wire' => true],
        ['key' => 'indexing', 'label' => 'Indexing', 'wire' => true],
        ['key' => 'operations', 'label' => 'Operations', 'wire' => true],
    ];
@endphp

<div class="space-y-4">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-3 border-b border-gray-200 pb-4 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="gsc" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $identity['eyebrow'] ?? 'Google Search Console' }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                    @include('livewire.demo.partials.demo-badge')
                </div>
                <a href="{{ route('demo.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ $identity['relationship_line'] ?? 'Observes · Website' }}
                    ·
                    <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">Open Website</a>
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-gray-700 dark:text-gray-300">{{ $identity['property_label'] ?? '—' }}</span>
                    · {{ $identity['property_type'] ?? 'Domain property' }}
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    <span class="font-medium text-emerald-700 dark:text-emerald-400">{{ $identity['status'] ?? 'Connected' }}</span>
                    · {{ $identity['freshness'] }}
                </p>
                @include('livewire.demo.partials._asset-scope-chip', ['assetType' => 'gsc'])
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($data['freshness'] ?? [] as $chip)
                        <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-600 dark:bg-white/5 dark:text-gray-300" title="{{ $chip['detail'] ?? '' }}">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $chip['source'] }} · {{ $chip['age'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="shrink-0">
            @include('livewire.demo.partials._search-console-header-actions')
        </div>
    </div>

    @include('livewire.demo.partials.asset-nav', ['tabs' => $navTabs, 'active' => $tab])

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
    @endif

    <p class="text-xs text-gray-400">{{ $data['demo_boundary'] }}</p>

    @if ($tab === 'overview')
        @include('livewire.demo.search-console.tabs.overview')
    @elseif ($tab === 'performance')
        @include('livewire.demo.search-console.tabs.performance')
    @elseif ($tab === 'demand')
        @include('livewire.demo.search-console.tabs.demand')
    @elseif ($tab === 'pages')
        @include('livewire.demo.search-console.tabs.pages')
    @elseif ($tab === 'indexing')
        @include('livewire.demo.search-console.tabs.indexing')
    @elseif ($tab === 'operations')
        @include('livewire.demo.search-console.tabs.operations')
    @endif
</div>

@if ($selectedAttention)
    <x-demo.gads-drawer :title="$selectedAttention['title']" :subtitle="$selectedAttention['scope'] ?? null" :severity="$selectedAttention['severity'] ?? null">
        <div>
            <p class="text-xs text-gray-400">What happened</p>
            <p class="font-medium text-gray-900 dark:text-white">{{ $selectedAttention['metric'] }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Why this matters</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['why'] ?? 'Organic demand signal requiring agency review.' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Recommended next action</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedAttention['action'] }}@if (! empty($selectedAttention['tab'])) in {{ str_replace('_', ' ', $selectedAttention['tab']) }}@endif.</p>
        </div>
        @if (! empty($selectedAttention['finding_id']))
            <button type="button" wire:click="openFinding('{{ $selectedAttention['finding_id'] }}')" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open related Finding</button>
        @endif
        @if (! empty($selectedAttention['tab']))
            <button type="button" wire:click="setTab('{{ $selectedAttention['tab'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Go to workspace</button>
        @endif
    </x-demo.gads-drawer>
@endif

@if ($selectedCluster)
    <x-demo.gads-drawer :title="$selectedCluster['name']" :subtitle="$selectedCluster['intent'] ?? null" :severity="match($selectedCluster['trend'] ?? '') { 'declining' => 'High', 'ctr_review' => 'Medium', default => null }">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">Clicks</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedCluster['clicks'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Impressions</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedCluster['impressions'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">CTR</p>
                <p class="font-semibold tabular-nums">{{ $selectedCluster['ctr'] ?? '—' }}%</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Avg position</p>
                <p class="font-semibold tabular-nums" title="Average position ≠ global rank">{{ $selectedCluster['position'] ?? '—' }}</p>
            </div>
        </div>
        <div>
            <p class="text-xs text-gray-400">Primary page</p>
            <p class="font-medium text-gray-900 dark:text-white">{{ $selectedCluster['primary_page'] ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs text-gray-400">Ownership</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $selectedCluster['ownership_state'] ?? '—' }}</p>
        </div>
        @if (! empty($selectedCluster['queries']))
            <div>
                <p class="text-xs text-gray-400">Sample queries</p>
                <ul class="mt-1 space-y-0.5 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($selectedCluster['queries'] as $q)
                        <li>{{ $q }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <button type="button" wire:click="setDemandSub('queries')" class="rounded-lg px-3 py-2 text-xs font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Open query explorer</button>
        <p class="text-[11px] text-gray-400">Search Console · cluster aggregates · Demo Mode</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedPage)
    <x-demo.gads-drawer :title="$selectedPage['path'] ?? 'Page'" :subtitle="$selectedPage['title'] ?? $selectedPage['content_role'] ?? null" :severity="$selectedPage['state'] ?? $selectedPage['website_attention'] ?? null">
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">Clicks</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedPage['clicks'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Impressions</p>
                <p class="font-semibold tabular-nums">{{ number_format($selectedPage['impressions'] ?? 0) }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Content role</p>
                <p class="font-semibold">{{ $selectedPage['content_role'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Offering</p>
                <p class="font-semibold">{{ $selectedPage['offering'] ?? '—' }}</p>
            </div>
        </div>
        @if (! empty($selectedPage['clusters']))
            <div>
                <p class="text-xs text-gray-400">Related clusters</p>
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ implode(' · ', $selectedPage['clusters']) }}</p>
            </div>
        @endif
        @if (! empty($selectedPage['website_attention']))
            <div>
                <p class="text-xs text-gray-400">Website attention</p>
                <p class="text-amber-700 dark:text-amber-400">{{ $selectedPage['website_attention'] }}</p>
            </div>
        @endif
        @if (! empty($selectedPage['ga4_context']))
            <div>
                <p class="text-xs text-gray-400">GA4 context (page-level)</p>
                <p class="text-sm tabular-nums text-gray-700 dark:text-gray-300">
                    {{ number_format($selectedPage['ga4_context']['sessions'] ?? 0) }} sessions
                    · {{ $selectedPage['ga4_context']['engagement_rate'] ?? '—' }}% engaged
                    · {{ number_format($selectedPage['ga4_context']['mapped_actions'] ?? 0) }} mapped actions
                </p>
                <p class="mt-1 text-[11px] text-blue-700 dark:text-blue-300">{{ $selectedPage['ga4_context']['note'] ?? $data['pages']['attribution_note'] ?? '' }}</p>
            </div>
        @endif
        <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-xs font-medium text-white">Open Website</a>
        <p class="text-[11px] text-gray-400">Search Console · page aggregates · Demo Mode</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedUrl)
    <x-demo.gads-drawer :title="$selectedUrl['path'] ?? 'URL'" :subtitle="$selectedUrl['role'] ?? null" :severity="$selectedUrl['attention'] ?? $selectedUrl['index_state'] ?? null">
        <div>
            <p class="text-xs text-gray-400">URL</p>
            <p class="break-all text-sm font-medium text-gray-900 dark:text-white">{{ $selectedUrl['url'] ?? '—' }}</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-400">Google index state</p>
                <p class="font-semibold">{{ $selectedUrl['index_state'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Sitemap</p>
                <p class="font-semibold">{{ $selectedUrl['sitemap'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Canonical</p>
                <p class="font-semibold">{{ $selectedUrl['canonical'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Search visibility</p>
                <p class="font-semibold">{{ $selectedUrl['search_visibility'] ?? '—' }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-400">Last crawl</p>
                <p class="font-semibold">{{ $selectedUrl['last_crawl'] ?? '—' }}</p>
            </div>
        </div>
        @if (($selectedUrl['canonical'] ?? '') === 'Mismatch')
            <div>
                <p class="text-xs text-gray-400">Canonical detail</p>
                <p class="text-sm text-amber-700 dark:text-amber-400">
                    User: {{ $selectedUrl['user_canonical'] ?? '—' }}
                    · Google: {{ $selectedUrl['google_canonical'] ?? '—' }}
                </p>
            </div>
        @endif
        @if (! empty($selectedUrl['attention']))
            <div>
                <p class="text-xs text-gray-400">Attention</p>
                <p class="text-amber-700 dark:text-amber-400">{{ $selectedUrl['attention'] }}</p>
            </div>
        @endif
        <p class="text-[11px] text-gray-400">{{ $data['indexing']['inspection_note'] ?? 'Google index state · read-only observation.' }}</p>
    </x-demo.gads-drawer>
@endif

@if ($selectedFinding)
    <x-demo.gads-drawer :title="$selectedFinding['title']" :subtitle="$selectedFinding['category'] ?? null" :severity="$selectedFinding['severity'] ?? null">
        @foreach (['what' => 'What happened', 'why' => 'Why this matters', 'scope' => 'Scope', 'evidence' => 'Evidence', 'next' => 'Recommended next action', 'outcome' => 'Outcome'] as $key => $label)
            @if (! empty($selectedFinding[$key]))
                <div>
                    <p class="text-xs text-gray-400">{{ $label }}</p>
                    <p class="text-gray-800 dark:text-white/90">{{ $selectedFinding[$key] }}</p>
                </div>
            @endif
        @endforeach
        <p class="text-[11px] text-gray-400">Tasks are not auto-created. No Search Console write actions.</p>
    </x-demo.gads-drawer>
@endif
