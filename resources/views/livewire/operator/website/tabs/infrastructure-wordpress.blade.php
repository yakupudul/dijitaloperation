@php
    $coverageState = data_get($infrastructure, 'coverage.state', 'not_collected');
    $site = $infrastructure['site'];
    $connection = $infrastructure['connection'];
    $extensions = $infrastructure['extensions'];
    $taxonomies = $infrastructure['taxonomies'];
    $publicInfrastructure = $infrastructure['public_infrastructure'];
    $pagination = $extensions['pagination'];
@endphp

<div class="space-y-5">
    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.title') }}</h2>
                    <span @class([
                        'inline-flex rounded-full border px-2.5 py-1 text-xs font-medium',
                        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $coverageState === 'collected',
                        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300' => in_array($coverageState, ['not_collected', 'projection_failed'], true),
                        'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300' => $coverageState === 'not_configured',
                    ])>{{ __('operator.website.pages_content.states.'.$coverageState) }}</span>
                </div>
                <p class="mt-2 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ __('operator.website.infrastructure_wordpress.subtitle') }}</p>
                <p class="mt-2 text-xs text-gray-400">
                    {{ data_get($infrastructure, 'coverage.watermark') ? __('operator.website.infrastructure_wordpress.last_collection', ['when' => \Carbon\CarbonImmutable::parse(data_get($infrastructure, 'coverage.watermark'))->diffForHumans()]) : __('operator.website.infrastructure_wordpress.no_collection') }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $asset->id]) }}" wire:navigate class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ __('operator.website.infrastructure_wordpress.manage_connector') }}</a>
                <button type="button" wire:click="refreshData" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">{{ __('operator.website.actions.refresh_data') }}</button>
            </div>
        </div>
    </section>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-sm font-medium text-gray-500">{{ __('operator.website.infrastructure_wordpress.cards.connector') }}</p>
            <p class="mt-3 text-2xl font-semibold text-gray-900 dark:text-white">{{ $connection['paired'] && $connection['enabled'] ? __('operator.website.infrastructure_wordpress.connection.paired') : __('operator.website.infrastructure_wordpress.connection.not_paired') }}</p>
            <p class="mt-2 text-xs text-gray-400">{{ $connection['plugin_version'] ? __('operator.website.infrastructure_wordpress.cards.connector_version', ['version' => $connection['plugin_version']]) : __('operator.website.infrastructure_wordpress.cards.connector_hint') }}</p>
        </section>
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-sm font-medium text-gray-500">{{ __('operator.website.infrastructure_wordpress.cards.wordpress') }}</p>
            <p class="mt-3 text-2xl font-semibold text-gray-900 dark:text-white">{{ $site['wordpress_version'] ?: '—' }}</p>
            <p class="mt-2 text-xs {{ $site['core_update_available'] === true ? 'text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ $site['core_update_available'] === true ? __('operator.website.infrastructure_wordpress.cards.core_update', ['version' => $site['available_wordpress_version'] ?: '—']) : ($site['available'] ? __('operator.website.infrastructure_wordpress.cards.core_current') : __('operator.website.infrastructure_wordpress.not_collected')) }}</p>
        </section>
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-sm font-medium text-gray-500">{{ __('operator.website.infrastructure_wordpress.cards.extensions') }}</p>
            <p class="mt-3 text-2xl font-semibold text-gray-900 dark:text-white">{{ $extensions['count'] !== null ? number_format($extensions['count'], 0, ',', '.') : '—' }}</p>
            <p class="mt-2 text-xs {{ ($extensions['update_count'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-300' : 'text-gray-400' }}">{{ $extensions['update_count'] !== null ? __('operator.website.infrastructure_wordpress.cards.extension_updates', ['count' => $extensions['update_count']]) : __('operator.website.infrastructure_wordpress.not_collected') }}</p>
        </section>
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <p class="text-sm font-medium text-gray-500">{{ __('operator.website.infrastructure_wordpress.cards.taxonomies') }}</p>
            <p class="mt-3 text-2xl font-semibold text-gray-900 dark:text-white">{{ $taxonomies['count'] !== null ? number_format($taxonomies['count'], 0, ',', '.') : '—' }}</p>
            <p class="mt-2 text-xs text-gray-400">{{ $taxonomies['available'] ? __('operator.website.infrastructure_wordpress.cards.taxonomy_types', ['count' => count($taxonomies['by_taxonomy'])]) : __('operator.website.infrastructure_wordpress.not_collected') }}</p>
        </section>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.site.title') }}</h3>
                <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.site.hint') }}</p>
            </div>
            <dl class="grid gap-px bg-gray-100 sm:grid-cols-2 dark:bg-gray-800">
                @foreach ([
                    __('operator.website.infrastructure_wordpress.site.site_url') => $site['site_url'] ?: data_get($infrastructure, 'asset.primary_url'),
                    __('operator.website.infrastructure_wordpress.site.home_url') => $site['home_url'],
                    __('operator.website.infrastructure_wordpress.site.php') => $site['php_version'],
                    __('operator.website.infrastructure_wordpress.site.locale') => $site['locale'],
                    __('operator.website.infrastructure_wordpress.site.timezone') => $site['timezone'],
                    __('operator.website.infrastructure_wordpress.site.multisite') => $site['is_multisite'] === null ? null : ($site['is_multisite'] ? __('operator.website.infrastructure_wordpress.yes') : __('operator.website.infrastructure_wordpress.no')),
                ] as $label => $value)
                    <div class="min-w-0 bg-white px-5 py-4 dark:bg-gray-900"><dt class="text-xs text-gray-400">{{ $label }}</dt><dd class="mt-1 break-all text-sm font-medium text-gray-900 dark:text-white">{{ filled($value) ? $value : '—' }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                <div><h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.connection.title') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.connection.hint') }}</p></div>
                <span @class([
                    'rounded-full border px-2.5 py-1 text-xs font-medium',
                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $connection['paired'] && $connection['enabled'],
                    'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300' => ! ($connection['paired'] && $connection['enabled']),
                ])>{{ $connection['paired'] && $connection['enabled'] ? __('operator.website.infrastructure_wordpress.connection.paired') : __('operator.website.infrastructure_wordpress.connection.not_paired') }}</span>
            </div>
            <dl class="grid gap-px bg-gray-100 sm:grid-cols-2 dark:bg-gray-800">
                <div class="bg-white px-5 py-4 dark:bg-gray-900"><dt class="text-xs text-gray-400">{{ __('operator.website.infrastructure_wordpress.connection.plugin_version') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $connection['plugin_version'] ?: '—' }}</dd></div>
                <div class="bg-white px-5 py-4 dark:bg-gray-900"><dt class="text-xs text-gray-400">{{ __('operator.website.infrastructure_wordpress.connection.last_success') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $connection['last_success_human'] ?: '—' }}</dd></div>
                <div class="bg-white px-5 py-4 dark:bg-gray-900"><dt class="text-xs text-gray-400">REST API</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $site['rest_state'] ?: '—' }}</dd></div>
                <div class="bg-white px-5 py-4 dark:bg-gray-900"><dt class="text-xs text-gray-400">WP-Cron</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $site['cron_state'] ?: '—' }}</dd></div>
            </dl>
            @if ($connection['last_error'])
                <p class="border-t border-rose-100 bg-rose-50 px-5 py-3 text-xs text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">{{ __('operator.website.infrastructure_wordpress.connection.last_error', ['error' => $connection['last_error']]) }}</p>
            @endif
        </section>
    </div>

    <div class="grid gap-5 xl:grid-cols-3">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex items-start justify-between gap-3"><div><h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.theme.title') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.theme.hint') }}</p></div><span class="rounded bg-gray-100 px-2 py-1 text-[11px] text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $site['active_theme'] ?: '—' }}</span></div>
            <p class="mt-5 text-lg font-semibold text-gray-900 dark:text-white">{{ $site['active_theme_name'] ?: $site['active_theme'] ?: '—' }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ $site['active_theme_version'] ? 'v'.$site['active_theme_version'] : '—' }}</p>
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.health.title') }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.health.hint') }}</p>
            <dl class="mt-5 grid grid-cols-3 gap-3 text-center">
                <div class="rounded-lg bg-emerald-50 p-3 dark:bg-emerald-500/10"><dt class="text-xs text-emerald-700 dark:text-emerald-300">{{ __('operator.website.infrastructure_wordpress.health.good') }}</dt><dd class="mt-2 text-xl font-semibold text-emerald-800 dark:text-emerald-200">{{ $site['site_health']['good'] ?? '—' }}</dd></div>
                <div class="rounded-lg bg-amber-50 p-3 dark:bg-amber-500/10"><dt class="text-xs text-amber-700 dark:text-amber-300">{{ __('operator.website.infrastructure_wordpress.health.recommended') }}</dt><dd class="mt-2 text-xl font-semibold text-amber-800 dark:text-amber-200">{{ $site['site_health']['recommended'] ?? '—' }}</dd></div>
                <div class="rounded-lg bg-rose-50 p-3 dark:bg-rose-500/10"><dt class="text-xs text-rose-700 dark:text-rose-300">{{ __('operator.website.infrastructure_wordpress.health.critical') }}</dt><dd class="mt-2 text-xl font-semibold text-rose-800 dark:text-rose-200">{{ $site['site_health']['critical'] ?? '—' }}</dd></div>
            </dl>
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.features.title') }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.features.hint') }}</p>
            <div class="mt-5 flex flex-wrap gap-2">
                @foreach (['polylang' => 'Polylang', 'litespeed_cache' => 'LiteSpeed Cache'] as $key => $label)
                    <span @class([
                        'rounded-full border px-2.5 py-1 text-xs font-medium',
                        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' => $site['features'][$key] === true,
                        'border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' => $site['features'][$key] !== true,
                    ])>{{ $label }} · {{ $site['features'][$key] === true ? __('operator.website.infrastructure_wordpress.detected') : ($site['features'][$key] === false ? __('operator.website.infrastructure_wordpress.not_detected') : __('operator.website.infrastructure_wordpress.unknown')) }}</span>
                @endforeach
                @foreach ($infrastructure['seo_providers'] as $provider)
                    <span class="rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300">SEO · {{ $provider }}</span>
                @endforeach
            </div>
        </section>
    </div>

    <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800 xl:flex-row xl:items-end xl:justify-between">
            <div><h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.extensions.title') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.extensions.hint') }}</p></div>
            <div class="grid gap-2 sm:grid-cols-[minmax(220px,1fr)_180px]">
                <input wire:model.live.debounce.400ms="infrastructureSearch" type="search" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="{{ __('operator.website.infrastructure_wordpress.extensions.search') }}">
                <select wire:model.live="infrastructureFilter" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    @foreach (['all', 'plugin', 'theme', 'active', 'inactive', 'updates'] as $filter)
                        <option value="{{ $filter }}">{{ __('operator.website.infrastructure_wordpress.extensions.filters.'.$filter) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div wire:loading.flex wire:target="infrastructureSearch,infrastructureFilter,setInfrastructurePage" class="items-center gap-2 border-b border-gray-100 px-5 py-3 text-xs text-gray-500 dark:border-gray-800">{{ __('operator.website.infrastructure_wordpress.extensions.loading') }}</div>

        @if ($extensions['available'])
            <div class="hidden overflow-x-auto md:block">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50/80 text-left text-xs font-medium text-gray-500 dark:bg-white/[0.02]"><tr><th class="px-5 py-3">{{ __('operator.website.infrastructure_wordpress.extensions.name') }}</th><th class="px-5 py-3">{{ __('operator.website.infrastructure_wordpress.extensions.type') }}</th><th class="px-5 py-3">{{ __('operator.website.infrastructure_wordpress.extensions.version') }}</th><th class="px-5 py-3">{{ __('operator.website.infrastructure_wordpress.extensions.status') }}</th><th class="px-5 py-3">{{ __('operator.website.infrastructure_wordpress.extensions.update') }}</th><th class="px-5 py-3">{{ __('operator.website.infrastructure_wordpress.extensions.auto_update') }}</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($extensions['rows'] as $row)
                            <tr class="hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">
                                <td class="max-w-80 px-5 py-3"><p class="truncate font-medium text-gray-900 dark:text-white" title="{{ $row['name'] ?: $row['id'] }}">{{ $row['name'] ?: $row['id'] }}</p><p class="mt-1 truncate font-mono text-[11px] text-gray-400" title="{{ $row['id'] }}">{{ $row['id'] }}</p></td>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ __('operator.website.infrastructure_wordpress.extensions.types.'.$row['type']) }}</td>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $row['version'] ?: '—' }}</td>
                                <td class="px-5 py-3"><span class="rounded-full border px-2 py-0.5 text-xs {{ $row['status'] === 'active' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300' : 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300' }}">{{ __('operator.website.infrastructure_wordpress.extensions.states.'.($row['status'] ?: 'unknown')) }}</span></td>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $row['update_available'] === true ? __('operator.website.infrastructure_wordpress.extensions.update_to', ['version' => $row['available_version'] ?: '—']) : __('operator.website.infrastructure_wordpress.extensions.current') }}</td>
                                <td class="px-5 py-3 text-gray-600 dark:text-gray-300">{{ $row['auto_update'] === null ? '—' : ($row['auto_update'] ? __('operator.website.infrastructure_wordpress.on') : __('operator.website.infrastructure_wordpress.off')) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">{{ __('operator.website.infrastructure_wordpress.extensions.no_results') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="divide-y divide-gray-100 md:hidden dark:divide-gray-800">
                @forelse ($extensions['rows'] as $row)
                    <article class="space-y-3 p-4"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><h4 class="truncate font-medium text-gray-900 dark:text-white">{{ $row['name'] ?: $row['id'] }}</h4><p class="mt-1 truncate font-mono text-[11px] text-gray-400">{{ $row['id'] }}</p></div><span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ __('operator.website.infrastructure_wordpress.extensions.types.'.$row['type']) }}</span></div><dl class="grid grid-cols-2 gap-3 text-xs"><div><dt class="text-gray-400">{{ __('operator.website.infrastructure_wordpress.extensions.version') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $row['version'] ?: '—' }}</dd></div><div><dt class="text-gray-400">{{ __('operator.website.infrastructure_wordpress.extensions.status') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ __('operator.website.infrastructure_wordpress.extensions.states.'.($row['status'] ?: 'unknown')) }}</dd></div><div><dt class="text-gray-400">{{ __('operator.website.infrastructure_wordpress.extensions.update') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $row['update_available'] === true ? ($row['available_version'] ?: __('operator.website.infrastructure_wordpress.yes')) : __('operator.website.infrastructure_wordpress.no') }}</dd></div><div><dt class="text-gray-400">{{ __('operator.website.infrastructure_wordpress.extensions.auto_update') }}</dt><dd class="mt-1 text-gray-700 dark:text-gray-300">{{ $row['auto_update'] === null ? '—' : ($row['auto_update'] ? __('operator.website.infrastructure_wordpress.on') : __('operator.website.infrastructure_wordpress.off')) }}</dd></div></dl></article>
                @empty
                    <p class="p-8 text-center text-sm text-gray-500">{{ __('operator.website.infrastructure_wordpress.extensions.no_results') }}</p>
                @endforelse
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 text-sm dark:border-gray-800">
                <p class="text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.extensions.pagination', ['total' => $pagination['total'], 'from' => $pagination['from'], 'to' => $pagination['to']]) }}</p>
                <div class="flex gap-2"><button type="button" wire:click="setInfrastructurePage({{ max(1, $pagination['page'] - 1) }})" @disabled($pagination['page'] <= 1) class="rounded-lg px-3 py-2 text-xs ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.previous') }}</button><button type="button" wire:click="setInfrastructurePage({{ min($pagination['last_page'], $pagination['page'] + 1) }})" @disabled($pagination['page'] >= $pagination['last_page']) class="rounded-lg px-3 py-2 text-xs ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ __('operator.website.pages_content.next') }}</button></div>
            </div>
        @else
            <div class="px-5 py-10 text-center"><h4 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.extensions.empty_title') }}</h4><p class="mt-2 text-sm text-gray-500">{{ __('operator.website.infrastructure_wordpress.extensions.empty_body') }}</p></div>
        @endif
    </section>

    <div class="grid gap-5 xl:grid-cols-2">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.settings.title') }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.settings.hint') }}</p>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                @foreach ([
                    __('operator.website.infrastructure_wordpress.settings.visibility') => $site['settings']['blog_public'] === null ? null : ($site['settings']['blog_public'] ? __('operator.website.infrastructure_wordpress.settings.public') : __('operator.website.infrastructure_wordpress.settings.private')),
                    __('operator.website.infrastructure_wordpress.settings.permalinks') => $site['settings']['permalink_structure'],
                    __('operator.website.infrastructure_wordpress.settings.front_page') => $site['settings']['show_on_front'],
                    __('operator.website.infrastructure_wordpress.settings.posts_per_page') => $site['settings']['posts_per_page'],
                    __('operator.website.infrastructure_wordpress.settings.memory') => $site['settings']['memory_limit'],
                    __('operator.website.infrastructure_wordpress.settings.max_upload') => $site['settings']['max_upload_size'] !== null ? number_format($site['settings']['max_upload_size'] / 1048576, 1, ',', '.').' MB' : null,
                ] as $label => $value)
                    <div><dt class="text-xs text-gray-400">{{ $label }}</dt><dd class="mt-1 break-all text-sm font-medium text-gray-900 dark:text-white">{{ filled($value) ? $value : '—' }}</dd></div>
                @endforeach
            </dl>
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.taxonomies.title') }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.taxonomies.hint') }}</p>
            @if ($taxonomies['available'])
                <div class="mt-5 space-y-3">
                    @foreach (array_slice($taxonomies['by_taxonomy'], 0, 8, true) as $taxonomy => $count)
                        <div class="flex items-center justify-between gap-3"><span class="truncate text-sm text-gray-700 dark:text-gray-300">{{ $taxonomy }}</span><span class="text-sm font-semibold text-gray-900 dark:text-white">{{ number_format($count, 0, ',', '.') }}</span></div>
                    @endforeach
                </div>
                @if ($taxonomies['by_language'] !== [])
                    <div class="mt-5 border-t border-gray-100 pt-4 dark:border-gray-800"><p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.website.infrastructure_wordpress.taxonomies.languages') }}</p><div class="mt-3 flex flex-wrap gap-2">@foreach ($taxonomies['by_language'] as $language => $count)<span class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $language }} · {{ $count }}</span>@endforeach</div></div>
                @endif
            @else
                <p class="mt-5 text-sm text-gray-500">{{ __('operator.website.infrastructure_wordpress.taxonomies.no_data') }}</p>
            @endif
        </section>
    </div>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <div><h3 class="font-semibold text-gray-900 dark:text-white">{{ __('operator.website.infrastructure_wordpress.public.title') }}</h3><p class="mt-1 text-xs text-gray-500">{{ __('operator.website.infrastructure_wordpress.public.hint') }}</p><dl class="mt-5 grid gap-4 sm:grid-cols-2"><div><dt class="text-xs text-gray-400">Domain</dt><dd class="mt-1 break-all text-sm font-medium text-gray-900 dark:text-white">{{ data_get($infrastructure, 'asset.domain') ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">Primary URL</dt><dd class="mt-1 break-all text-sm font-medium text-gray-900 dark:text-white">{{ data_get($infrastructure, 'asset.primary_url') ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">Hosting</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ data_get($infrastructure, 'asset.hosting_context') ?: __('operator.website.infrastructure_wordpress.not_configured') }}</dd></div><div><dt class="text-xs text-gray-400">SSL / TLS</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $publicInfrastructure['tls_present'] === null ? '—' : ($publicInfrastructure['tls_present'] ? __('operator.website.infrastructure_wordpress.present') : __('operator.website.infrastructure_wordpress.missing')) }}</dd></div></dl></div>
            <div class="rounded-lg bg-gray-50 p-4 dark:bg-white/[0.03]"><p class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __('operator.website.infrastructure_wordpress.public.certificate') }}</p><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-xs text-gray-400">Host</dt><dd class="mt-1 break-all font-medium text-gray-800 dark:text-white">{{ $publicInfrastructure['host'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.infrastructure_wordpress.public.issuer') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white">{{ $publicInfrastructure['issuer'] ?: '—' }}</dd></div><div><dt class="text-xs text-gray-400">{{ __('operator.website.infrastructure_wordpress.public.valid_to') }}</dt><dd class="mt-1 font-medium text-gray-800 dark:text-white">{{ $publicInfrastructure['valid_to'] ?: '—' }}</dd></div></dl></div>
        </div>
    </section>

    <p class="rounded-xl bg-blue-50 px-4 py-3 text-xs text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20">{{ __('operator.website.infrastructure_wordpress.fact_note') }}</p>
</div>
