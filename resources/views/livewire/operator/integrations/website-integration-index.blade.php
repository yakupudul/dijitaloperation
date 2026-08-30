@php
    $tr = app()->getLocale() === 'tr';
    $toneClasses = fn (string $tone): string => match ($tone) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
        'error' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
        'info' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
        default => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200',
    };
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-400">{{ $tr ? 'Entegrasyonlar' : 'Integrations' }}</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $tr ? 'Website veri kaynakları' : 'Website data sources' }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500 dark:text-gray-400">{{ $tr ? 'Bağlantı durumunu, veri çekim ilerlemesini, toplanan kayıtları ve ham gözlem geçmişini izleyin.' : 'Monitor connection state, collection progress, collected records, and raw observation history.' }}</p>
        </div>
        <a href="{{ route('operator.integrations.site-connector', array_filter(['connector' => 'wordpress', 'site' => $selectedRow ? $selectedRow['asset']->id : null])) }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">WordPress Connector</a>
    </div>

    @if ($message !== '')
        <div class="rounded-lg border px-4 py-3 text-sm {{ $toneClasses($messageTone) }}">{{ $message }}</div>
    @endif

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Collection summary">
        @foreach ([
            [$tr ? 'Website' : 'Websites', $stats['total']],
            [$tr ? 'Çekilebilir' : 'Collectable', $stats['collect_ready']],
            [$tr ? 'Tamamlanan çekim' : 'Completed runs', $stats['completed']],
            [$tr ? 'Henüz çekilmedi' : 'Never collected', $stats['never_collected']],
        ] as [$label, $value])
            <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <p class="text-xs font-medium text-gray-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format((int) $value, 0, ',', '.') }}</p>
            </div>
        @endforeach
    </section>

    <section class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-wrap gap-3">
            <input wire:model.live.debounce.300ms="search" type="search" class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="{{ $tr ? 'Website ara' : 'Search websites' }}">
            <select wire:model.live="filter" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                @foreach ($filters as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($rows as $row)
                <button type="button" wire:click="selectWebsite({{ $row['asset']->id }})" @class([
                    'rounded-lg border p-3 text-left',
                    'border-brand-300 bg-brand-50 dark:border-brand-700 dark:bg-brand-500/10' => $selectedRow && $selectedRow['asset']->id === $row['asset']->id,
                    'border-gray-200 dark:border-gray-700' => ! $selectedRow || $selectedRow['asset']->id !== $row['asset']->id,
                ])>
                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ $row['asset']->name }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ $row['asset']->domain ?: $row['asset']->primary_url }}</span>
                    <span class="mt-2 block text-xs text-gray-500">{{ $row['run_status_label'] }} · %{{ $row['coverage_percent'] }}</span>
                </button>
            @empty
                <p class="col-span-full py-5 text-sm text-gray-500">{{ $tr ? 'Eşleşen Website yok.' : 'No matching Website.' }}</p>
            @endforelse
        </div>
    </section>

    @if ($selectedRow)
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $selectedRow['asset']->name }}</h2>
                    <p class="text-sm text-gray-500">{{ $selectedRow['asset']->primary_url ?: $selectedRow['asset']->domain }}</p>
                </div>
                <button type="button" wire:click="collectNow({{ $selectedRow['asset']->id }})" @disabled(! $selectedRow['collectable']) class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-50">{{ $tr ? 'Veri çekimini başlat' : 'Start collection' }}</button>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $tr ? 'Son çekim' : 'Latest run' }}</p><p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $selectedRow['run_status_label'] }}</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $tr ? 'Toplayıcı kapsamı' : 'Collector coverage' }}</p><p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $selectedRow['completed_collectors'] }}/{{ $selectedRow['collector_total'] }}</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">{{ $tr ? 'Public kayıt' : 'Public records' }}</p><p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ number_format((int) $selectedRow['current_rows'], 0, ',', '.') }}</p></div>
                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-400">WordPress Connector</p><p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $selectedRow['wordpress_ready'] ? ($tr ? 'Bağlı' : 'Paired') : ($tr ? 'Bağlı değil' : 'Not paired') }}</p></div>
            </div>
        </section>

        <section class="space-y-4">
            @foreach ($selectedRow['data_sources'] as $source)
                <article class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</h2>
                            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $source['description'] }}</p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex rounded-full border px-2.5 py-1 text-xs {{ $toneClasses(match ($source['state']) { 'completed' => 'success', 'running' => 'info', 'partial', 'attention', 'connection_required' => 'warning', default => 'neutral' }) }}">{{ $source['status_label'] }}</span>
                            <p class="mt-1 text-xs text-gray-400">{{ $source['connection_label'] }}</p>
                        </div>
                    </div>

                    @if ($source['key'] === 'site_connector' && $selectedRow['wordpress_detected'] && ! $selectedRow['wordpress_ready'])
                        <div class="border-b border-gray-100 bg-gray-50/70 px-5 py-3 text-sm dark:border-gray-800 dark:bg-white/[0.02]">
                            <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $selectedRow['asset']->id]) }}" wire:navigate class="font-medium text-brand-600 dark:text-brand-400">{{ $tr ? 'Paketi indir ve eşleştir →' : 'Download and pair →' }}</a>
                        </div>
                    @endif

                    @if ($source['datasets']->isNotEmpty())
                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($source['datasets'] as $dataset)
                                <details class="group px-5 py-4">
                                    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $dataset['label'] }}</h3>
                                            <p class="mt-1 text-xs text-gray-500">{{ $dataset['description'] }}</p>
                                        </div>
                                        <div class="text-right text-xs">
                                            <p class="font-medium text-gray-900 dark:text-white">{{ $dataset['status_label'] }}</p>
                                            <p class="text-gray-400">{{ number_format((int) $dataset['current_rows'], 0, ',', '.') }} {{ $tr ? 'mevcut kayıt' : 'current records' }}</p>
                                        </div>
                                    </summary>
                                    <div class="mt-4 space-y-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                                        <p class="text-xs text-gray-500">{{ $dataset['result_detail'] }}</p>
                                        <div class="grid gap-3 text-xs sm:grid-cols-4">
                                            <div><span class="block text-gray-400">{{ $tr ? 'İşlenen' : 'Processed' }}</span><strong>{{ $dataset['processed_rows'] }}</strong></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Başarılı paket' : 'Committed batches' }}</span><strong>{{ $dataset['successful_batches'] }}</strong></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Başarısız paket' : 'Failed batches' }}</span><strong>{{ $dataset['failed_batches'] }}</strong></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Son veri' : 'Last collected' }}</span><strong>{{ $dataset['last_collected_at']?->diffForHumans() ?? '—' }}</strong></div>
                                        </div>
                                        @if (($dataset['preview']['state'] ?? '') === 'available')
                                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
                                                <table class="min-w-full text-xs">
                                                    <thead><tr class="bg-gray-50 dark:bg-white/[0.03]">@foreach ($dataset['preview']['columns'] as $column)<th class="px-3 py-2 text-left font-medium text-gray-500">{{ $column['label'] }}</th>@endforeach</tr></thead>
                                                    <tbody>@foreach ($dataset['preview']['rows'] as $previewRow)<tr class="border-t border-gray-100 dark:border-gray-800">@foreach ($dataset['preview']['columns'] as $column)<td class="max-w-64 truncate px-3 py-2 text-gray-700 dark:text-gray-300">{{ $previewRow[$column['name']] ?? '—' }}</td>@endforeach</tr>@endforeach</tbody>
                                                </table>
                                            </div>
                                        @endif
                                        <div class="flex flex-wrap gap-2 text-[11px] text-gray-400">
                                            @foreach ($dataset['collectors'] as $collector)<span class="rounded bg-gray-100 px-2 py-1 dark:bg-white/[0.05]">{{ $collector }}</span>@endforeach
                                            @if ($dataset['table'])<span class="rounded bg-gray-100 px-2 py-1 font-mono dark:bg-white/[0.05]">{{ $dataset['table'] }}</span>@endif
                                        </div>
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    @elseif ($source['key'] === 'google')
                        <div class="px-5 py-4 text-sm text-gray-500"><a href="{{ route('operator.integrations.google') }}" wire:navigate class="font-medium text-brand-600 dark:text-brand-400">{{ $tr ? 'Google entegrasyonunu aç →' : 'Open Google integration →' }}</a></div>
                    @endif
                </article>
            @endforeach
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Veri çekim geçmişi' : 'Collection history' }}</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="border-b border-gray-100 text-left text-xs text-gray-400 dark:border-gray-800"><th class="py-2 pr-4">ID</th><th class="py-2 pr-4">{{ $tr ? 'Durum' : 'State' }}</th><th class="py-2 pr-4">{{ $tr ? 'Başlangıç' : 'Started' }}</th><th class="py-2">{{ $tr ? 'Bitiş' : 'Finished' }}</th></tr></thead>
                    <tbody>@forelse ($history as $run)<tr class="border-b border-gray-50 dark:border-gray-800/60"><td class="py-2 pr-4 font-mono">#{{ $run->id }}</td><td class="py-2 pr-4">{{ $run->status?->value }}</td><td class="py-2 pr-4">{{ $run->started_at ?? $run->created_at }}</td><td class="py-2">{{ $run->finished_at ?? '—' }}</td></tr>@empty<tr><td colspan="4" class="py-4 text-gray-500">{{ $tr ? 'Henüz çekim geçmişi yok.' : 'No collection history yet.' }}</td></tr>@endforelse</tbody>
                </table>
            </div>
        </section>
    @endif
</div>
