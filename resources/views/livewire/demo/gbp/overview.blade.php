@php
    $tabs = [
        'overview' => __('operator_gbp.tabs.overview'),
        'profile' => __('operator_gbp.tabs.profile'),
        'setup' => __('operator_gbp.tabs.setup'),
    ];
    $connection = $data['connection'] ?? [];
    $coverage = $data['profile_coverage'] ?? [];
    $profile = $data['profile'] ?? [];
    $bound = (bool) ($connection['bound'] ?? false);
    $real = ($data['migration_mode'] ?? '') === 'real';
@endphp

<div class="space-y-5">
    @include('livewire.demo.partials.flash')

    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <x-demo.digital-asset-mark type="gbp" size="lg" class="mt-0.5" />
            <div class="min-w-0">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Google Business Profile</p>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $identity['title'] }}</h1>
                <a href="{{ route('operator.brand', ['brand' => $identity['brand_id']]) }}" wire:navigate class="mt-1 inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">{{ $identity['brand_name'] }}</a>
                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                    <span>{{ $identity['location_line'] ?: '—' }}</span>
                    <span>·</span>
                    <span>{{ __('operator_gbp.last_refresh') }}: {{ $identity['last_refresh'] ?: '—' }}</span>
                    <span @class([
                        'font-semibold text-emerald-600 dark:text-emerald-400' => $real,
                        'font-semibold text-amber-600 dark:text-amber-400' => $bound && ! $real,
                        'font-semibold text-gray-500' => ! $bound,
                    ])>
                        {{ $real ? __('operator_gbp.connected') : ($bound ? __('operator_gbp.needs_collection') : __('operator_gbp.not_connected')) }}
                    </span>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="refreshData" @disabled(! $bound) class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">{{ __('operator_gbp.refresh') }}</button>
            <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30">{{ __('operator_runtime.sources.title') }}</a>
        </div>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Google Business Profile workspace">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium',
                'border-brand-500 text-brand-600 dark:text-brand-400' => $tab === $key,
                'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        @if (! $bound)
            <section class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/50 dark:bg-amber-950/20">
                <h2 class="font-semibold text-amber-900 dark:text-amber-200">{{ __('operator_gbp.connect_title') }}</h2>
                <p class="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">{{ __('operator_gbp.connect_body') }}</p>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="mt-3 inline-flex rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white">{{ __('operator_gbp.connect_action') }}</a>
            </section>
        @elseif (! $real)
            <section class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900/50 dark:bg-blue-950/20">
                <h2 class="font-semibold text-blue-900 dark:text-blue-200">{{ __('operator_gbp.collect_title') }}</h2>
                <p class="mt-1 text-sm text-blue-800/80 dark:text-blue-300/80">{{ __('operator_gbp.collect_body') }}</p>
            </section>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-400">{{ __('operator_gbp.fields_present') }}</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $coverage['present'] ?? 0 }}/{{ $coverage['total_reviewed'] ?? 0 }}</p></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-400">{{ __('operator_gbp.needs_attention') }}</p><p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $coverage['need_attention'] ?? 0 }}</p></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-400">{{ __('operator_gbp.last_run') }}</p><p class="mt-2 text-lg font-bold text-gray-900 dark:text-white">{{ $connection['last_run_status'] ?? '—' }}</p><p class="mt-1 text-xs text-gray-400">{{ $connection['last_run_human'] ?? '—' }}</p></section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700"><p class="text-xs text-gray-400">{{ __('operator_gbp.bound_resource') }}</p><p class="mt-2 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $connection['resource_name'] ?? '—' }}</p></section>
        </div>

        <section class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700"><h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_gbp.collected_profile') }}</h2></div>
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse (($profile['fields'] ?? []) as $field)
                    <div class="grid gap-2 px-5 py-4 sm:grid-cols-3 sm:items-center">
                        <p class="text-sm font-medium text-gray-500">{{ __('operator_gbp.fields.'.($field['key'] ?? '')) }}</p>
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $field['value'] }}</p>
                        <p class="text-xs text-gray-400 sm:text-right">{{ __('operator_gbp.states.'.($field['state'] ?? 'missing')) }}</p>
                    </div>
                @empty
                    <div class="px-5 py-8 text-sm text-gray-500">{{ __('operator_gbp.no_profile_data') }}</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-white/[0.02]">
            <h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_gbp.not_available_title') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('operator_gbp.not_available_body') }}</p>
            <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                @foreach (['reviews', 'performance', 'local_visibility', 'media'] as $capability)
                    <div class="rounded-lg bg-white px-3 py-3 text-sm ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                        <p class="font-medium text-gray-800 dark:text-gray-200">{{ __('operator_gbp.capabilities.'.$capability) }}</p>
                        <p class="mt-1 text-xs text-gray-400">{{ __('operator_gbp.not_collected') }}</p>
                    </div>
                @endforeach
            </div>
        </section>

    @elseif ($tab === 'profile')
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_gbp.profile_fields') }}</h2>
                <dl class="mt-4 space-y-4">
                    @foreach (($profile['fields'] ?? []) as $field)
                        <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.fields.'.($field['key'] ?? '')) }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $field['value'] }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_gbp.location') }}</h2>
                <dl class="mt-4 space-y-4">
                    <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.primary_category') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ data_get($profile, 'categories.primary', '—') }}</dd></div>
                    <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.address') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ data_get($profile, 'location.address', '—') }}</dd></div>
                    <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.website') }}</dt><dd class="mt-1 break-all text-sm font-medium text-gray-900 dark:text-white">{{ data_get($profile, 'location.website_location_page', '—') }}</dd></div>
                </dl>
            </section>
        </div>

    @elseif ($tab === 'setup')
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ __('operator_gbp.connection') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('operator_gbp.connection_body') }}</p>
                </div>
                <a href="{{ route('operator.asset.sources', ['assetId' => $assetId]) }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:underline">{{ __('operator_runtime.sources.title') }} →</a>
            </div>
            <dl class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.bound_resource') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $connection['resource_name'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.external_id') }}</dt><dd class="mt-1 break-all text-sm font-medium text-gray-900 dark:text-white">{{ $connection['external_id'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.integration') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $connection['integration_name'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.last_run') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $connection['last_run_status'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.last_refresh') }}</dt><dd class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $connection['last_run_human'] ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-400">{{ __('operator_gbp.last_error') }}</dt><dd class="mt-1 text-sm font-medium text-rose-600 dark:text-rose-300">{{ $connection['last_error'] ?? '—' }}</dd></div>
            </dl>
        </section>
    @endif
</div>
