@php
    $sourceWorkspace = $dataSources ?? [];
    $sourceSummary = $sourceWorkspace['summary'] ?? [];
    $sourceProjection = $sourceWorkspace['projection'] ?? [];
    $sourceCollection = $sourceWorkspace['collection'] ?? null;
    $sourceGroups = $sourceWorkspace['groups'] ?? ['site' => [], 'measurement' => []];
    $sourceStateClasses = [
        'collected' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'configured' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20',
        'needs_attention' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'unavailable' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'not_configured' => 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-white/[0.05] dark:text-gray-300 dark:ring-gray-700',
    ];
@endphp

<div class="space-y-5">
    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-5 dark:border-gray-700 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-3xl">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.data_sources_workspace.title') }}</h2>
                    <span @class([
                        'rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset',
                        'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => ($sourceProjection['status'] ?? null) === 'completed',
                        'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20' => ($sourceProjection['status'] ?? null) === 'partial',
                        'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-white/[0.05] dark:text-gray-300 dark:ring-gray-700' => ! in_array(($sourceProjection['status'] ?? null), ['completed', 'partial'], true),
                    ])>
                        {{ __('operator.website.data_sources_workspace.unified_states.'.($sourceProjection['status'] ?? 'not_ready')) }}
                    </span>
                </div>
                <p class="mt-2 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('operator.website.data_sources_workspace.subtitle') }}</p>
                @if (($sourceProjection['period']['start'] ?? null) && ($sourceProjection['period']['end'] ?? null))
                    <p class="mt-2 text-xs text-gray-400">{{ __('operator.website.data_sources_workspace.period', ['start' => $sourceProjection['period']['start'], 'end' => $sourceProjection['period']['end']]) }}</p>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.data_sources_workspace.manage_sources') }}</a>
                <a href="{{ route('operator.integrations.website', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.website.data_sources_workspace.collection_console') }}</a>
            </div>
        </div>

        <div class="grid gap-px bg-gray-100 sm:grid-cols-2 xl:grid-cols-4 dark:bg-gray-700">
            @foreach ([
                ['key' => 'ready', 'value' => $sourceSummary['ready_count'] ?? null],
                ['key' => 'collected', 'value' => $sourceSummary['collected_count'] ?? null],
                ['key' => 'attention', 'value' => $sourceSummary['attention_count'] ?? null],
                ['key' => 'latest', 'value' => $sourceSummary['latest_watermark_human'] ?? null],
            ] as $item)
                <div class="bg-white px-5 py-4 dark:bg-gray-800">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.website.data_sources_workspace.summary.'.$item['key']) }}</p>
                    <p class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">{{ $item['value'] ?? '—' }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.data_sources_workspace.summary.'.$item['key'].'_hint', ['total' => $sourceSummary['source_count'] ?? 0]) }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @foreach (['site', 'measurement'] as $groupKey)
        <section class="space-y-3">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('operator.website.data_sources_workspace.groups.'.$groupKey.'.title') }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ __('operator.website.data_sources_workspace.groups.'.$groupKey.'.hint') }}</p>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @foreach ($sourceGroups[$groupKey] ?? [] as $source)
                    @php
                        $sourceKey = $source['key'];
                        $state = $source['state'];
                        $manageUrl = match ($sourceKey) {
                            'website' => route('operator.integrations.website', ['assetId' => $asset->id]),
                            'wordpress' => route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $asset->id]),
                            default => route('operator.asset.sources', ['assetId' => $asset->id]),
                        };
                        $detailTab = match ($sourceKey) {
                            'website' => 'content',
                            'wordpress' => 'infrastructure',
                            'pagespeed' => 'health',
                            'gsc' => 'visibility',
                            'ga4' => 'performance',
                        };
                    @endphp
                    <article class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                        <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                            <div class="min-w-0">
                                <div class="flex items-center gap-3">
                                    <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-xs font-bold text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ __('operator.website.data_sources_workspace.sources.'.$sourceKey.'.mark') }}</span>
                                    <div class="min-w-0">
                                        <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.data_sources_workspace.sources.'.$sourceKey.'.title') }}</h3>
                                        <p class="mt-0.5 truncate text-xs text-gray-500">{{ $source['display_name'] ?: __('operator.website.data_sources_workspace.no_resource') }}</p>
                                    </div>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset {{ $sourceStateClasses[$state] ?? $sourceStateClasses['not_configured'] }}">{{ __('operator.website.data_sources_workspace.states.'.$state) }}</span>
                        </div>

                        <div class="space-y-4 px-5 py-4">
                            <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('operator.website.data_sources_workspace.sources.'.$sourceKey.'.description') }}</p>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs text-gray-400">{{ __('operator.website.data_sources_workspace.connection') }}</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('operator.website.data_sources_workspace.connection_states.'.$source['connection_state']) }}</p>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                                    <p class="text-xs text-gray-400">{{ __('operator.website.data_sources_workspace.data') }}</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-gray-200">{{ __('operator.website.data_sources_workspace.data_states.'.$source['data_state']) }}</p>
                                    <p class="mt-1 text-xs text-gray-500">{{ $source['watermark_human'] ? __('operator.website.data_sources_workspace.updated', ['when' => $source['watermark_human']]) : __('operator.website.data_sources_workspace.never_updated') }}</p>
                                </div>
                            </div>

                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.website.data_sources_workspace.contributes') }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($source['contributes'] as $profile)
                                        <span class="rounded-md bg-brand-50 px-2 py-1 text-xs font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ __('operator.website.data_sources_workspace.profiles.'.$profile) }}</span>
                                    @endforeach
                                </div>
                            </div>

                            @if (collect($source['counts'])->contains(fn ($value) => $value !== null))
                                <dl class="grid gap-2 sm:grid-cols-2">
                                    @foreach ($source['counts'] as $countKey => $countValue)
                                        @if ($countValue !== null)
                                            <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2 text-sm dark:border-gray-700">
                                                <dt class="text-gray-500">{{ __('operator.website.data_sources_workspace.sources.'.$sourceKey.'.counts.'.$countKey) }}</dt>
                                                <dd class="font-semibold text-gray-900 dark:text-white">{{ number_format($countValue, 0, ',', '.') }}</dd>
                                            </div>
                                        @endif
                                    @endforeach
                                </dl>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 px-5 py-3 text-sm dark:border-gray-700">
                            <a href="{{ $manageUrl }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ __('operator.website.data_sources_workspace.manage') }}</a>
                            @if ($source['collected'])
                                <a href="{{ route('operator.website', ['assetId' => $asset->id, 'tab' => $detailTab]) }}" wire:navigate class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ __('operator.website.data_sources_workspace.inspect') }}</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.data_sources_workspace.collection.title') }}</h2>
            @if ($sourceCollection)
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ __('operator.website.data_sources_workspace.collection.state') }}</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ __('operator.website.data_sources_workspace.collection.states.'.($sourceCollection['state'] ?? 'unknown')) }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ __('operator.website.data_sources_workspace.collection.datasets') }}</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceCollection['datasets_completed'] }}/{{ $sourceCollection['datasets_total'] }}</p></div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ __('operator.website.data_sources_workspace.collection.last_activity') }}</p><p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceCollection['last_activity_human'] ?? '—' }}</p></div>
                </div>
                @if (($sourceCollection['datasets_failed'] ?? 0) > 0)
                    <p class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ __('operator.website.data_sources_workspace.collection.failed', ['count' => $sourceCollection['datasets_failed']]) }}</p>
                @endif
            @else
                <p class="mt-3 text-sm text-gray-500">{{ __('operator.website.data_sources_workspace.collection.empty') }}</p>
            @endif
        </section>

        <section class="rounded-xl bg-gray-50 p-5 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.02] dark:ring-gray-700">
            <h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.data_sources_workspace.boundaries.title') }}</h2>
            <ul class="mt-3 space-y-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                <li>• {{ __('operator.website.data_sources_workspace.boundaries.public_wordpress') }}</li>
                <li>• {{ __('operator.website.data_sources_workspace.boundaries.missing') }}</li>
                <li>• {{ __('operator.website.data_sources_workspace.boundaries.outcome') }}</li>
            </ul>
        </section>
    </div>
</div>
