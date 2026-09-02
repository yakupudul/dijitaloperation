@php
    $tabs = [
        'overview' => __('operator.website.tabs.overview'),
        'content' => __('operator.website.tabs.content'),
        'health' => __('operator.website.tabs.health'),
        'search_console' => __('operator.website.tabs.search_console'),
        'visibility' => __('operator.website.tabs.visibility'),
        'performance' => __('operator.website.tabs.performance'),
        'ga4_analysis' => __('operator.website.tabs.ga4_analysis'),
        'infrastructure' => __('operator.website.tabs.infrastructure'),
        'operations' => __('operator.website.tabs.operations'),
        'setup' => __('operator.website.tabs.setup'),
    ];
    $projectionCompletedAt = match ($tab) {
        'content' => data_get($pagesContent, 'projection.completed_at'),
        'health' => data_get($technicalHealth, 'projection.completed_at'),
        'search_console' => data_get($gscAnalysis, 'coverage.last_collected_at'),
        'ga4_analysis' => data_get($ga4Analysis, 'coverage.last_collected_at'),
        'infrastructure' => data_get($infrastructure, 'projection.completed_at'),
        'setup' => data_get($dataSources, 'projection.completed_at'),
        default => null,
    };
    $headerLastUpdatedHuman = $projectionCompletedAt
        ? \Carbon\CarbonImmutable::parse($projectionCompletedAt)->diffForHumans()
        : ($data['last_updated_human'] ?? null);
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 xl:flex-row xl:items-start xl:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="website" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $customer?->name }} · {{ $brand?->name }}</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $asset->name }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ $asset->domain ?: __('operator.website.header.domain_unspecified') }}</span>
                    @if ($asset->primary_url)
                        <a href="{{ $asset->primary_url }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">{{ __('operator.website.actions.open_site') }} ↗</a>
                    @endif
                    <span>{{ $data['connection_health'] ?: __('operator.website.header.needs_attention_connect') }}</span>
                    <span>{{ $headerLastUpdatedHuman ? __('operator.website.header.last_data', ['when' => $headerLastUpdatedHuman]) : __('operator.website.header.last_data_none') }}</span>
                </div>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.actions.refresh_data') }}</button>
            <details class="relative">
                <summary class="cursor-pointer list-none rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.website.actions.more_actions') }} ⌄</summary>
                <div class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-xl bg-white py-1 shadow-xl ring-1 ring-black/5 dark:bg-gray-900 dark:ring-white/10">
                    <button type="button" wire:click="runDiagnosis" class="block w-full px-4 py-2.5 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]">{{ __('operator.website.actions.technical_check') }}</button>
                    <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]">{{ __('operator.website.actions.data_sources') }}</a>
                    <a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="block px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]">{{ __('operator.website.actions.public_discovery') }}</a>
                </div>
            </details>
        </div>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Website workspace">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($showPeriodBar)
        @include('livewire.demo.partials.period-bar')
        <p class="text-xs text-gray-400">{{ __('operator.website.period.selected_note', ['label' => $this->appliedPeriodLabel()]) }}</p>
    @endif

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1 ring-inset',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $messageTone === 'success',
            'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    @if ($tab === 'overview')
        @if (! $data['has_performance_data'])
            <section class="rounded-xl bg-amber-50 p-5 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h2 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('operator.website.empty.performance_title') }}</h2>
                        <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">{{ __('operator.website.empty.performance_body') }}</p>
                    </div>
                    <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="shrink-0 rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">{{ __('operator.website.actions.bind_sources') }}</a>
                </div>
            </section>
        @elseif (! ($data['period_has_data'] ?? true))
            <section class="rounded-xl bg-amber-50 p-5 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20" data-period-unavailable>
                <h2 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('operator.website.period.no_overlap_title') }}</h2>
                <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">{{ __('operator.website.period.no_overlap_body') }}</p>
            </section>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @forelse ($data['kpis'] as $kpi)
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-start justify-between gap-3"><p class="text-sm font-medium text-gray-500">{{ $kpi['label'] }}</p><span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700">{{ $kpi['source'] }}</span></div>
                    <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</p>
                    @if ($kpi['delta_label'])<p class="mt-2 text-xs text-gray-400">{{ $kpi['delta_label'] }}</p>@endif
                </section>
            @empty
                @foreach (['Organic Search', 'GA4', 'Findings', 'Recommendations'] as $label)
                    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-sm font-medium text-gray-500">{{ $label }}</p><p class="mt-3 text-2xl font-bold text-gray-300 dark:text-gray-600">—</p><p class="mt-2 text-xs text-gray-400">{{ __('operator.website.empty.awaiting_real') }}</p></section>
                @endforeach
            @endforelse
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator.website.cards.needs_attention') }}</h2><p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ $data['findings']['counts']['high'] }}</p><p class="mt-1 text-sm text-gray-500">{{ __('operator.website.cards.needs_attention_hint') }}</p></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator.website.cards.opportunities') }}</h2><p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ count($data['seo_opportunities'] ?? []) }}</p><p class="mt-1 text-sm text-gray-500">{{ __('operator.website.cards.opportunities_hint') }}</p></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('operator.website.cards.inventory') }}</h2><p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ __('operator.website.cards.inventory_body') }}</p><a href="{{ route('operator.integrations.site-connectors') }}" wire:navigate class="mt-3 inline-flex text-sm font-medium text-brand-600 hover:underline">{{ __('operator.website.setup_sections.connector') }} →</a></section>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700"><div><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.empty.open_findings') }}</h2><p class="mt-1 text-xs text-gray-400">{{ __('operator.website.empty.open_findings_hint') }}</p></div><a href="{{ route('operator.findings', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-medium text-brand-600">{{ __('operator.website.empty.all') }}</a></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">@forelse ($data['findings']['open']->take(5) as $finding)<div class="px-5 py-4"><div class="flex justify-between gap-3"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><span class="text-xs font-semibold uppercase text-rose-600">{{ $finding->severity }}</span></div><p class="mt-1 text-sm text-gray-500">{{ $finding->summary }}</p></div>@empty<div class="px-5 py-8 text-sm text-gray-500">{{ __('operator.website.empty.no_open_findings') }}</div>@endforelse</div>
            </section>
            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700"><div><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.empty.recommendations') }}</h2><p class="mt-1 text-xs text-gray-400">{{ __('operator.website.empty.recommendations_hint') }}</p></div><a href="{{ route('operator.recommendations', ['asset' => $asset->id]) }}" wire:navigate class="text-xs font-medium text-brand-600">{{ __('operator.website.empty.all') }}</a></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">@forelse ($data['recommendations']->take(5) as $recommendation)<div class="px-5 py-4"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><p class="mt-1 text-sm text-gray-500">{{ $recommendation->action }}</p></div>@empty<div class="px-5 py-8 text-sm text-gray-500">{{ __('operator.website.empty.no_recommendations') }}</div>@endforelse</div>
            </section>
        </div>
    @elseif ($tab === 'content')
        @include('livewire.operator.website.tabs.pages-content')
    @elseif ($tab === 'health')
        @include('livewire.operator.website.tabs.technical-health')
    @elseif ($tab === 'search_console')
        @include('livewire.operator.website.tabs.search-console')
    @elseif ($tab === 'visibility')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><div class="flex items-center justify-between"><h2 class="font-semibold text-gray-900 dark:text-white">Organic Search</h2><button type="button" wire:click="refreshSeoIntelligence" class="text-sm font-medium text-brand-600">{{ __('operator.website.panels.refresh_seo') }}</button></div><p class="mt-2 text-sm text-gray-500">{{ __('operator.website.panels.visibility_hint') }}</p><div class="mt-4 space-y-2">@forelse (array_slice($data['seo_opportunities'] ?? [], 0, 8) as $row)<div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">{{ is_array($row) ? ($row['query'] ?? $row['title'] ?? json_encode($row)) : $row }}</div>@empty<p class="text-sm text-gray-500">{{ __('operator.website.panels.no_seo_opportunities') }}</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.panels.public_discovery_competitors') }}</h2><p class="mt-2 text-sm text-gray-500">{{ __('operator.website.panels.public_discovery_hint') }}</p><a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-violet-600">{{ __('operator.website.panels.open_public_discovery') }}</a></section>
        </div>
    @elseif ($tab === 'performance')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.tabs.performance') }}</h2><p class="mt-1 text-sm text-gray-500">{{ __('operator.website.panels.performance_hint') }}</p><div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@forelse ($data['kpis'] as $kpi)<div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $kpi['label'] }}</p><p class="mt-2 text-xl font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</p><p class="mt-1 text-xs text-gray-400">{{ strtoupper($kpi['source']) }}</p></div>@empty<p class="text-sm text-gray-500 sm:col-span-2">{{ __('operator.website.panels.no_provider_evidence') }}</p>@endforelse</div></section>
    @elseif ($tab === 'ga4_analysis')
        @include('livewire.operator.website.tabs.ga4-analysis')
    @elseif ($tab === 'infrastructure')
        @include('livewire.operator.website.tabs.infrastructure-wordpress')
    @elseif ($tab === 'operations')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.empty.open_findings') }}</h2><div class="mt-4 space-y-3">@forelse ($data['findings']['open']->take(10) as $finding)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $finding->severity }} · {{ $finding->status }}</p></div>@empty<p class="text-sm text-gray-500">{{ __('operator.website.panels.no_open_findings') }}</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.empty.recommendations') }}</h2><div class="mt-4 space-y-3">@forelse ($data['recommendations']->take(10) as $recommendation)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $recommendation->priority }} · {{ $recommendation->status }}</p></div>@empty<p class="text-sm text-gray-500">{{ __('operator.website.panels.no_recommendations') }}</p>@endforelse</div></section>
        </div>
    @elseif ($tab === 'setup')
        @include('livewire.operator.website.tabs.data-sources')
    @endif
</div>
