@php
    $tabs = [
        'overview' => __('operator.website.tabs.overview'),
        'health' => __('operator.website.tabs.health'),
        'visibility' => __('operator.website.tabs.visibility'),
        'content' => __('operator.website.tabs.content'),
        'performance' => __('operator.website.tabs.performance'),
        'infrastructure' => __('operator.website.tabs.infrastructure'),
        'operations' => __('operator.website.tabs.operations'),
        'setup' => __('operator.website.tabs.setup'),
    ];
    $findingCounts = data_get($data, 'findings.counts', []);
    $openFindings = data_get($data, 'findings.open', collect());
    $recommendations = $data['recommendations'] ?? collect();
    $seoOpportunities = is_array($data['seo_opportunities'] ?? null) ? $data['seo_opportunities'] : [];
    $pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];
    $diagnosisChecks = (int) data_get($data, 'diagnosis.checks_evaluated', 0);
@endphp

<div class="space-y-5">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 xl:flex-row xl:items-start xl:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="website" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $customer?->name }} · {{ $brand?->name }}</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $asset->name }}</h1>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ $asset->domain ?: __('operator_runtime.website.domain_missing') }}</span>
                    @if ($asset->primary_url)
                        <a href="{{ $asset->primary_url }}" target="_blank" rel="noopener" class="font-medium text-brand-600 hover:underline">{{ __('operator_runtime.website.open_site') }} ↗</a>
                    @endif
                    <span>{{ $data['connection_health'] ?: __('operator_runtime.website.needs_source') }}</span>
                    <span>{{ __('operator_runtime.website.last_data') }}: {{ $data['last_updated_human'] ?: __('operator_runtime.website.never') }}</span>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator_runtime.website.refresh') }}</button>
            <button type="button" wire:click="runDiagnosis" class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ __('operator_runtime.website.diagnose') }}</button>
            <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-white px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:bg-gray-900 dark:text-brand-300 dark:ring-brand-500/30">{{ __('operator_runtime.website.sources') }}</a>
            <a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700">{{ __('operator_runtime.website.public_discovery') }}</a>
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
        <p class="text-xs text-gray-400">{{ __('operator_runtime.website.period_note') }}</p>
    @endif

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1 ring-inset',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $messageTone === 'success',
            'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    @if ($tab === 'overview')
        @if (! ($data['has_performance_data'] ?? false))
            <section class="rounded-xl bg-amber-50 p-5 ring-1 ring-inset ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-500/20">
                <h2 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('operator_runtime.website.no_performance_title') }}</h2>
                <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">{{ __('operator_runtime.website.no_performance_body') }}</p>
            </section>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (($data['kpis'] ?? []) as $kpi)
                    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <p class="text-sm font-medium text-gray-500">{{ $kpi['label'] }}</p>
                        <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ strtoupper($kpi['source']) }}</p>
                    </section>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-3">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-sm font-medium text-gray-500">{{ __('operator_runtime.website.attention') }}</p>
                <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ (int) ($findingCounts['high'] ?? 0) }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ __('operator_runtime.website.high_findings') }}</p>
            </section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-sm font-medium text-gray-500">{{ __('operator_runtime.website.opportunities') }}</p>
                <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ count($seoOpportunities) }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ __('operator_runtime.website.observed_opportunities') }}</p>
            </section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-sm font-medium text-gray-500">{{ __('operator_runtime.website.site_inventory') }}</p>
                <p class="mt-3 text-3xl font-bold text-gray-900 dark:text-white">{{ count($pages) }}</p>
                <p class="mt-1 text-xs text-gray-400">{{ __('operator_runtime.website.site_inventory_help') }}</p>
            </section>
        </div>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.findings') }}</h2></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse (collect($openFindings)->take(5) as $finding)
                        <div class="px-5 py-4"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $finding->severity }} · {{ $finding->status }}</p></div>
                    @empty
                        <div class="px-5 py-8 text-sm text-gray-500">{{ __('operator_runtime.website.no_findings') }}</div>
                    @endforelse
                </div>
            </section>
            <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.recommendations') }}</h2></div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse (collect($recommendations)->take(5) as $recommendation)
                        <div class="px-5 py-4"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $recommendation->priority }} · {{ $recommendation->status }}</p></div>
                    @empty
                        <div class="px-5 py-8 text-sm text-gray-500">{{ __('operator_runtime.website.no_recommendations') }}</div>
                    @endforelse
                </div>
            </section>
        </div>

    @elseif ($tab === 'health')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-3">
                <div><h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.health_title') }}</h2><p class="mt-1 text-sm text-gray-500">{{ __('operator_runtime.website.health_body') }}</p></div>
                <button type="button" wire:click="runDiagnosis" class="text-sm font-medium text-brand-600">{{ __('operator_runtime.website.run_again') }}</button>
            </div>
            <p class="mt-5 text-2xl font-bold text-gray-900 dark:text-white">{{ $diagnosisChecks }} {{ __('operator_runtime.website.checks') }}</p>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ data_get($data, 'diagnosis.summary') ?: __('operator_runtime.website.no_diagnosis') }}</p>
        </section>

    @elseif ($tab === 'visibility')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-3"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.organic_search') }}</h2><button type="button" wire:click="refreshSeoIntelligence" class="text-sm font-medium text-brand-600">{{ __('operator_runtime.website.seo_refresh') }}</button></div>
                <div class="mt-4 space-y-2">@forelse (array_slice($seoOpportunities, 0, 8) as $row)<div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]">{{ is_array($row) ? ($row['query'] ?? $row['title'] ?? json_encode($row)) : $row }}</div>@empty<p class="text-sm text-gray-500">{{ __('operator_runtime.website.no_seo_opportunities') }}</p>@endforelse</div>
            </section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.discovery_competitors') }}</h2><p class="mt-2 text-sm text-gray-500">{{ __('operator_runtime.website.discovery_body') }}</p><a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-violet-600">{{ __('operator_runtime.website.open_discovery') }} →</a></section>
        </div>

    @elseif ($tab === 'content')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.site_inventory') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator_runtime.website.site_inventory_help') }}</p>
            <div class="mt-5 divide-y divide-gray-100 dark:divide-gray-700">
                @forelse (array_slice($pages, 0, 20) as $page)
                    <div class="py-3 text-sm text-gray-800 dark:text-gray-200">{{ is_array($page) ? ($page['page'] ?? $page['url'] ?? $page['title'] ?? json_encode($page)) : $page }}</div>
                @empty
                    <p class="py-4 text-sm text-gray-500">{{ __('operator_runtime.website.site_inventory_empty') }}</p>
                @endforelse
            </div>
        </section>

    @elseif ($tab === 'performance')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.performance_title') }}</h2><p class="mt-1 text-sm text-gray-500">{{ __('operator_runtime.website.performance_body') }}</p>@if (($data['kpis'] ?? []) !== [])<div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">@foreach ($data['kpis'] as $kpi)<div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $kpi['label'] }}</p><p class="mt-2 text-xl font-bold text-gray-900 dark:text-white">{{ $kpi['value'] }}</p><p class="mt-1 text-xs text-gray-400">{{ strtoupper($kpi['source']) }}</p></div>@endforeach</div>@else<p class="mt-5 text-sm text-gray-500">{{ __('operator_runtime.website.no_period_data') }}</p>@endif</section>

    @elseif ($tab === 'infrastructure')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.tabs.infrastructure') }}</h2>
            <p class="mt-2 text-sm text-gray-500">{{ __('operator_runtime.website.infrastructure_boundary') }}</p>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">Domain</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $asset->domain ?: '—' }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">DNS</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ __('operator_runtime.website.not_collected') }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">Hosting</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $asset->hosting_context ?: __('operator_runtime.website.not_collected') }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">SSL / TLS</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ __('operator_runtime.website.not_collected') }}</dd></div>
                <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><dt class="text-xs text-gray-400">CMS</dt><dd class="mt-1 font-medium text-gray-900 dark:text-white">{{ $asset->cms ?: __('operator_runtime.website.not_collected') }}</dd></div>
            </dl>
        </section>

    @elseif ($tab === 'operations')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.findings') }}</h2><div class="mt-4 space-y-3">@forelse (collect($openFindings)->take(10) as $finding)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $finding->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $finding->severity }} · {{ $finding->status }}</p></div>@empty<p class="text-sm text-gray-500">{{ __('operator_runtime.website.no_findings') }}</p>@endforelse</div></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.recommendations') }}</h2><div class="mt-4 space-y-3">@forelse (collect($recommendations)->take(10) as $recommendation)<div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $recommendation->title }}</p><p class="mt-1 text-xs text-gray-500">{{ $recommendation->priority }} · {{ $recommendation->status }}</p></div>@empty<p class="text-sm text-gray-500">{{ __('operator_runtime.website.no_recommendations') }}</p>@endforelse</div></section>
        </div>

    @elseif ($tab === 'setup')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex items-center justify-between gap-3"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_runtime.website.setup_title') }}</h2><a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="text-sm font-medium text-brand-600">{{ __('operator_runtime.website.manage_all_sources') }} →</a></div>
            <div class="mt-4 space-y-3">@foreach (($data['connections'] ?? []) as $source)<div class="flex items-center justify-between rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><div><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $source['display_name'] ?: ($source['subtitle'] ?? '—') }}</p></div><span class="text-xs font-semibold {{ $source['connected'] ? 'text-emerald-600' : 'text-gray-400' }}">{{ $source['connected'] ? __('operator_runtime.website.connected') : __('operator_runtime.website.not_connected') }}</span></div>@endforeach</div>
        </section>
    @endif
</div>
