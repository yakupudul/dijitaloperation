@php
    $tr = app()->getLocale() === 'tr';
    $toneClasses = fn (string $tone): string => match ($tone) {
        'success' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
        'error' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
        'info' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
        default => 'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200',
    };
    $stateTone = fn (string $state): string => match ($state) {
        'completed' => 'success',
        'running' => 'info',
        'failed', 'attention' => 'error',
        'partial', 'connection_required', 'needs_setup' => 'warning',
        default => 'neutral',
    };
    $tabs = [
        'overview' => $tr ? 'Genel Bakış' : 'Overview',
        'sources' => $tr ? 'Veri Kaynakları' : 'Data Sources',
        'runs' => $tr ? 'Çekimler' : 'Runs',
        'data' => $tr ? 'Toplanan Veriler' : 'Collected Data',
        'settings' => $tr ? 'Ayarlar' : 'Settings',
    ];
@endphp

<div class="space-y-6" @if (($liveConsole['active'] ?? false) === true) wire:poll.2s @endif>
    @if ($selectedRow === null)
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-400">{{ $tr ? 'Entegrasyonlar' : 'Integrations' }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $tr ? 'Website veri kaynakları' : 'Website data sources' }}</h1>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-500 dark:text-gray-400">
                    {{ $tr ? 'Web sitelerini, bağlantı durumlarını ve son veri çekimlerini tek yerden yönetin.' : 'Manage websites, connection states, and latest collection runs in one place.' }}
                </p>
            </div>
            <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress']) }}" wire:navigate class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600">
                WordPress Connector
            </a>
        </div>

        @if ($message !== '')
            <div class="rounded-lg border px-4 py-3 text-sm {{ $toneClasses($messageTone) }}">{{ $message }}</div>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Collection summary">
            @foreach ([
                [$tr ? 'Website' : 'Websites', $stats['total']],
                [$tr ? 'Çekime hazır' : 'Ready to collect', $stats['collect_ready']],
                [$tr ? 'Devam eden' : 'In progress', $stats['running']],
                [$tr ? 'Dikkat gereken' : 'Needs attention', $stats['attention']],
            ] as [$label, $value])
                <div class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format((int) $value, 0, ',', '.') }}</p>
                </div>
            @endforeach
        </section>

        <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-wrap gap-3 border-b border-gray-100 p-4 dark:border-gray-800">
                <input wire:model.live.debounce.300ms="search" type="search" class="min-w-64 flex-1 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="{{ $tr ? 'Website, müşteri veya alan adı ara' : 'Search website, customer, or domain' }}">
                <select wire:model.live="filter" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950">
                    @foreach ($filters as $option)
                        <option value="{{ $option['key'] }}">{{ $option['label'] }} ({{ $option['count'] }})</option>
                    @endforeach
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50/80 text-left text-xs font-medium uppercase tracking-wide text-gray-400 dark:bg-white/[0.02]">
                        <tr>
                            <th class="px-5 py-3">Website</th>
                            <th class="px-5 py-3">{{ $tr ? 'Kaynaklar' : 'Sources' }}</th>
                            <th class="px-5 py-3">{{ $tr ? 'Son çekim' : 'Latest run' }}</th>
                            <th class="px-5 py-3">{{ $tr ? 'Temel kapsam' : 'Core coverage' }}</th>
                            <th class="px-5 py-3">{{ $tr ? 'Sonuç' : 'Result' }}</th>
                            <th class="px-5 py-3 text-right"><span class="sr-only">{{ $tr ? 'Aç' : 'Open' }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($rows as $row)
                            <tr class="transition hover:bg-gray-50/70 dark:hover:bg-white/[0.02]">
                                <td class="px-5 py-4">
                                    <a href="{{ route('operator.integrations.website', ['assetId' => $row['asset']->id]) }}" wire:navigate class="font-semibold text-gray-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $row['asset']->name }}</a>
                                    <p class="mt-1 max-w-72 truncate text-xs text-gray-500">{{ $row['asset']->domain ?: $row['asset']->primary_url ?: '—' }}</p>
                                    @if ($row['asset']->brand?->customer?->name)
                                        <p class="mt-1 text-xs text-gray-400">{{ $row['asset']->brand->customer->name }}</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-xs text-gray-600 dark:text-gray-300">{{ $row['source_summary'] }}</td>
                                <td class="px-5 py-4">
                                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium {{ $toneClasses($stateTone($row['overall_state'])) }}">{{ $row['run_status_label'] }}</span>
                                    <p class="mt-1 text-xs text-gray-400">{{ $row['last_run_at']?->diffForHumans() ?? '—' }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['required_completed'] }}/{{ $row['required_total'] }}</p>
                                    <p class="mt-1 text-xs text-gray-400">{{ $tr ? 'zorunlu kaynak' : 'required sources' }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ number_format((int) $row['latest_rows_written'], 0, ',', '.') }}</p>
                                    <p class="mt-1 text-xs text-gray-400">{{ $tr ? 'yazılan kayıt' : 'rows written' }}</p>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a href="{{ route('operator.integrations.website', ['assetId' => $row['asset']->id]) }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-500/30 dark:hover:bg-brand-500/10">{{ $tr ? 'Aç' : 'Open' }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-12 text-center text-sm text-gray-500">{{ $tr ? 'Eşleşen Website bulunamadı.' : 'No matching Website was found.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @else
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <a href="{{ route('operator.integrations.website') }}" wire:navigate class="text-sm font-medium text-brand-600 dark:text-brand-400">← {{ $tr ? 'Tüm web siteleri' : 'All websites' }}</a>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $selectedRow['asset']->name }}</h1>
                    <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium {{ $toneClasses($stateTone($selectedRow['overall_state'])) }}">{{ $selectedRow['run_status_label'] }}</span>
                </div>
                <a href="{{ $selectedRow['asset']->primary_url ?: ('https://'.$selectedRow['asset']->domain) }}" target="_blank" rel="noreferrer" class="mt-2 block truncate text-sm text-gray-500 hover:text-brand-600 dark:text-gray-400">{{ $selectedRow['asset']->primary_url ?: $selectedRow['asset']->domain }}</a>
            </div>
            <button type="button" wire:click="collectNow({{ $selectedRow['asset']->id }})" wire:loading.attr="disabled" @disabled(! $selectedRow['collectable'] || (($liveConsole['active'] ?? false) === true)) class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                <span wire:loading.remove wire:target="collectNow">{{ ($liveConsole['active'] ?? false) ? ($tr ? 'Veri çekimi sürüyor' : 'Collection in progress') : ($tr ? 'Veri çekimini başlat' : 'Start collection') }}</span>
                <span wire:loading wire:target="collectNow">{{ $tr ? 'Başlatılıyor…' : 'Starting…' }}</span>
            </button>
        </div>

        @if ($message !== '')
            <div class="rounded-lg border px-4 py-3 text-sm {{ $toneClasses($messageTone) }}">{{ $message }}</div>
        @endif

        <nav class="overflow-x-auto border-b border-gray-200 dark:border-gray-800" aria-label="Website sections">
            <div class="flex min-w-max gap-6">
                @foreach ($tabs as $key => $label)
                    <button type="button" wire:click="setTab('{{ $key }}')" @class([
                        'border-b-2 px-1 pb-3 text-sm font-medium transition',
                        'border-brand-500 text-brand-600 dark:text-brand-400' => $activeTab === $key,
                        'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white' => $activeTab !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </nav>

        @if ($liveConsole)
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                    <div>
                        <div class="flex items-center gap-2">
                            @if ($liveConsole['active'])
                                <span class="relative flex h-2.5 w-2.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-blue-400 opacity-75"></span><span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-blue-500"></span></span>
                            @endif
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Veri çekim konsolu' : 'Collection console' }}</h2>
                            <span class="inline-flex rounded-full border px-2.5 py-1 text-xs {{ $toneClasses($stateTone($liveConsole['state'])) }}">{{ $liveConsole['status_label'] }}</span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">#{{ $liveConsole['id'] }} · {{ $liveConsole['duration_label'] ?: '—' }} · {{ $tr ? 'Son hareket' : 'Last activity' }} {{ $liveConsole['last_activity_at']?->diffForHumans() ?? '—' }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xl font-semibold text-gray-900 dark:text-white">%{{ $liveConsole['progress_percent'] }}</p>
                        <p class="text-xs text-gray-400">{{ $liveConsole['datasets_completed'] }}/{{ $liveConsole['datasets_total'] }} dataset</p>
                    </div>
                </div>
                <div class="h-1 bg-gray-100 dark:bg-gray-800"><div class="h-full bg-brand-500 transition-all" style="width: {{ $liveConsole['progress_percent'] }}%"></div></div>
                <div class="grid gap-px bg-gray-100 sm:grid-cols-2 xl:grid-cols-4 dark:bg-gray-800">
                    @foreach ($liveConsole['stages'] as $stage)
                        <div class="bg-white p-4 dark:bg-gray-900">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $stage['label'] }}</p>
                                <span class="rounded-full border px-2 py-0.5 text-[11px] {{ $toneClasses($stateTone($stage['state'])) }}">{{ $stage['status_label'] }}</span>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">{{ $stage['datasets_completed'] }}/{{ $stage['datasets_total'] }} dataset · {{ number_format((int) $stage['rows_written'], 0, ',', '.') }} {{ $tr ? 'kayıt' : 'rows' }}</p>
                            @if ($stage['stage'])<p class="mt-1 truncate font-mono text-[11px] text-gray-400">{{ $stage['stage'] }}</p>@endif
                            @if ($stage['error'])<p class="mt-2 line-clamp-2 text-xs text-red-600 dark:text-red-400">{{ $stage['error'] }}</p>@endif
                        </div>
                    @endforeach
                </div>
                @if ($liveConsole['failure_summary'])
                    <div class="border-t border-red-100 bg-red-50 px-5 py-3 text-sm text-red-700 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-300">{{ $liveConsole['failure_summary'] }}</div>
                @endif
            </section>
        @endif

        @if ($activeTab === 'overview')
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    [$tr ? 'Keşfedilen URL' : 'Discovered URLs', $selectedRow['headline_metrics']['urls']],
                    [$tr ? 'HTTP gözlemi' : 'HTTP observations', $selectedRow['headline_metrics']['http']],
                    [$tr ? 'WordPress nesnesi' : 'WordPress objects', $selectedRow['headline_metrics']['wordpress_objects']],
                    [$tr ? 'Son çekim' : 'Latest collection', $selectedRow['headline_metrics']['last_run_at']?->diffForHumans() ?? '—'],
                ] as [$label, $value])
                    <div class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <p class="text-xs font-medium text-gray-500">{{ $label }}</p>
                        <p class="mt-2 text-xl font-semibold text-gray-900 dark:text-white">{{ is_numeric($value) ? number_format((int) $value, 0, ',', '.') : $value }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <section class="xl:col-span-2 rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Kaynak durumu' : 'Source status' }}</h2>
                            <p class="mt-1 text-sm text-gray-500">{{ $tr ? 'Her kaynak kendi bağlantısı ve dataset kapsamıyla izlenir.' : 'Each source is monitored with its own connection and dataset coverage.' }}</p>
                        </div>
                        <button type="button" wire:click="setTab('sources')" class="text-sm font-medium text-brand-600 dark:text-brand-400">{{ $tr ? 'Tümünü aç' : 'View all' }} →</button>
                    </div>
                    <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($selectedRow['data_sources'] as $source)
                            <div class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                                <div>
                                    <div class="flex items-center gap-2"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</p>@if ($source['optional'])<span class="rounded bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500 dark:bg-white/[0.05]">{{ $tr ? 'İsteğe bağlı' : 'Optional' }}</span>@endif</div>
                                    <p class="mt-1 text-xs text-gray-500">{{ $source['connection_label'] }} · {{ $source['completed'] }}/{{ $source['total'] }} dataset</p>
                                </div>
                                <span class="rounded-full border px-2.5 py-1 text-xs {{ $toneClasses($stateTone($source['state'])) }}">{{ $source['status_label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Son çekimde değişenler' : 'Latest run changes' }}</h2>
                    <div class="mt-4 grid grid-cols-2 gap-3">
                        @foreach ([
                            [$tr ? 'Yeni' : 'Inserted', $selectedRow['last_run_changes']['inserted']],
                            [$tr ? 'Güncellenen' : 'Updated', $selectedRow['last_run_changes']['updated']],
                            [$tr ? 'Değişmeyen' : 'Unchanged', $selectedRow['last_run_changes']['unchanged']],
                            [$tr ? 'Hatalı paket' : 'Failed batches', $selectedRow['last_run_changes']['failed_batches']],
                        ] as [$label, $value])
                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]"><p class="text-xs text-gray-500">{{ $label }}</p><p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format((int) $value, 0, ',', '.') }}</p></div>
                        @endforeach
                    </div>
                    <p class="mt-4 text-xs leading-5 text-gray-400">{{ $tr ? 'Bu ekran yalnızca veri toplama ve ham kayıt durumunu gösterir. Tespitler Website dijital varlığında üretilir.' : 'This screen only shows collection and raw record state. Findings are produced in the Website Digital Asset.' }}</p>
                </section>
            </div>
        @elseif ($activeTab === 'sources')
            <div class="space-y-4">
                @foreach ($selectedRow['data_sources'] as $source)
                    <article class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                            <div class="max-w-3xl">
                                <div class="flex items-center gap-2"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</h2>@if ($source['optional'])<span class="rounded bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500 dark:bg-white/[0.05]">{{ $tr ? 'İsteğe bağlı' : 'Optional' }}</span>@endif</div>
                                <p class="mt-1 text-sm leading-6 text-gray-500">{{ $source['description'] }}</p>
                            </div>
                            <div class="text-right"><span class="inline-flex rounded-full border px-2.5 py-1 text-xs {{ $toneClasses($stateTone($source['state'])) }}">{{ $source['status_label'] }}</span><p class="mt-1 text-xs text-gray-400">{{ $source['connection_label'] }}</p></div>
                        </div>

                        @if ($source['key'] === 'site_connector' && ! $selectedRow['wordpress_ready'])
                            <div class="border-b border-gray-100 bg-amber-50/60 px-5 py-3 text-sm dark:border-gray-800 dark:bg-amber-500/5">
                                <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $selectedRow['asset']->id]) }}" wire:navigate class="font-medium text-brand-600 dark:text-brand-400">{{ $tr ? 'WordPress paketini indir ve siteyi eşleştir →' : 'Download the WordPress package and pair the site →' }}</a>
                            </div>
                        @endif

                        <div class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($source['datasets'] as $dataset)
                                <div class="px-5 py-4">
                                    <div class="flex flex-wrap items-center justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2"><h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $dataset['label'] }}</h3><span class="rounded-full border px-2 py-0.5 text-[11px] {{ $toneClasses($stateTone($dataset['state'])) }}">{{ $dataset['status_label'] }}</span></div>
                                            <p class="mt-1 text-xs leading-5 text-gray-500">{{ $dataset['description'] }}</p>
                                        </div>
                                        <div class="flex items-center gap-5">
                                            <div class="text-right"><p class="text-sm font-semibold text-gray-900 dark:text-white">{{ number_format((int) $dataset['current_rows'], 0, ',', '.') }}</p><p class="text-[11px] text-gray-400">{{ $tr ? 'mevcut kayıt' : 'current rows' }}</p></div>
                                            <button type="button" wire:click="selectDataset('{{ $dataset['id'] }}')" class="rounded-lg px-3 py-2 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-500/30">{{ $tr ? 'Veriyi aç' : 'Open data' }}</button>
                                        </div>
                                    </div>
                                    <details class="mt-3 text-xs text-gray-500">
                                        <summary class="cursor-pointer font-medium text-gray-600 dark:text-gray-300">{{ $tr ? 'Çekim ve teknik ayrıntılar' : 'Collection and technical details' }}</summary>
                                        <div class="mt-3 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 xl:grid-cols-5 dark:bg-white/[0.03]">
                                            <div><span class="block text-gray-400">{{ $tr ? 'İşlenen' : 'Processed' }}</span><strong>{{ number_format((int) $dataset['processed_rows'], 0, ',', '.') }}</strong></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Yeni / güncel' : 'Inserted / updated' }}</span><strong>{{ $dataset['inserted_rows'] }} / {{ $dataset['updated_rows'] }}</strong></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Başarılı paket' : 'Committed batches' }}</span><strong>{{ $dataset['successful_batches'] }}</strong></div>
                                            <div><span class="block text-gray-400">{{ $tr ? 'Son veri' : 'Last data' }}</span><strong>{{ $dataset['last_collected_at']?->diffForHumans() ?? '—' }}</strong></div>
                                            <div><span class="block text-gray-400">Dataset ID</span><strong class="break-all font-mono">{{ $dataset['id'] }}</strong></div>
                                        </div>
                                    </details>
                                </div>
                            @endforeach
                        </div>
                    </article>
                @endforeach
            </div>
        @elseif ($activeTab === 'runs')
            <section class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Veri çekim geçmişi' : 'Collection history' }}</h2><p class="mt-1 text-sm text-gray-500">{{ $tr ? 'Son 10 Website çekimi; tetikleyici, süre, çıktı ve hata özetiyle birlikte.' : 'Latest 10 Website runs with trigger, duration, output, and failure summary.' }}</p></div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50/80 text-left text-xs text-gray-400 dark:bg-white/[0.02]"><tr><th class="px-5 py-3">ID</th><th class="px-5 py-3">{{ $tr ? 'Durum' : 'State' }}</th><th class="px-5 py-3">{{ $tr ? 'Tetikleyici' : 'Trigger' }}</th><th class="px-5 py-3">Dataset</th><th class="px-5 py-3">{{ $tr ? 'Yazılan' : 'Written' }}</th><th class="px-5 py-3">{{ $tr ? 'Süre' : 'Duration' }}</th><th class="px-5 py-3">{{ $tr ? 'Başlangıç' : 'Started' }}</th></tr></thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($history as $run)
                                <tr>
                                    <td class="px-5 py-4 font-mono text-xs">#{{ $run['id'] }}</td>
                                    <td class="px-5 py-4"><span class="rounded-full border px-2.5 py-1 text-xs {{ $toneClasses($stateTone($run['status'] === 'completed' ? 'completed' : ($run['status'] === 'partial' ? 'partial' : ($run['status'] === 'running' ? 'running' : ($run['status'] === 'failed' ? 'failed' : 'neutral')))) }}">{{ $run['status_label'] }}</span>@if ($run['failure_summary'])<p class="mt-2 max-w-72 text-xs text-red-600 dark:text-red-400">{{ $run['failure_summary'] }}</p>@endif</td>
                                    <td class="px-5 py-4"><p>{{ $run['trigger_label'] }}</p><p class="mt-1 text-xs text-gray-400">{{ $run['requested_by'] }}</p></td>
                                    <td class="px-5 py-4">{{ $run['datasets_completed'] }}/{{ $run['datasets_total'] }}@if ($run['datasets_failed'] > 0)<p class="mt-1 text-xs text-red-500">{{ $run['datasets_failed'] }} {{ $tr ? 'başarısız' : 'failed' }}</p>@endif</td>
                                    <td class="px-5 py-4">{{ number_format((int) $run['rows_written'], 0, ',', '.') }}</td>
                                    <td class="px-5 py-4">{{ $run['duration_label'] ?: '—' }}</td>
                                    <td class="px-5 py-4"><p>{{ $run['started_at']?->format('d.m.Y H:i') ?? '—' }}</p><p class="mt-1 text-xs text-gray-400">{{ $run['started_at']?->diffForHumans() ?? '—' }}</p></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-5 py-12 text-center text-gray-500">{{ $tr ? 'Henüz veri çekim geçmişi yok.' : 'No collection history yet.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @elseif ($activeTab === 'data')
            <div class="grid gap-5 xl:grid-cols-[280px_minmax(0,1fr)]">
                <aside class="self-start overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 xl:sticky xl:top-4 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800"><h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Datasetler' : 'Datasets' }}</h2><p class="mt-1 text-xs text-gray-500">{{ $availableDatasets->count() }} {{ $tr ? 'veri kümesi' : 'datasets' }}</p></div>
                    <div class="max-h-[640px] overflow-y-auto p-2">
                        @foreach ($availableDatasets as $dataset)
                            <button type="button" wire:click="selectDataset('{{ $dataset['id'] }}')" @class([
                                'mb-1 w-full rounded-lg px-3 py-2.5 text-left transition',
                                'bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => ($selectedDataset['id'] ?? null) === $dataset['id'],
                                'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]' => ($selectedDataset['id'] ?? null) !== $dataset['id'],
                            ])><span class="block text-sm font-medium">{{ $dataset['label'] }}</span><span class="mt-1 block text-xs opacity-70">{{ number_format((int) $dataset['current_rows'], 0, ',', '.') }} {{ $tr ? 'kayıt' : 'rows' }}</span></button>
                        @endforeach
                    </div>
                </aside>

                <section class="min-w-0 overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    @if ($selectedDataset)
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                            <div><div class="flex flex-wrap items-center gap-2"><h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $selectedDataset['label'] }}</h2><span class="rounded-full border px-2 py-0.5 text-[11px] {{ $toneClasses($stateTone($selectedDataset['state'])) }}">{{ $selectedDataset['status_label'] }}</span></div><p class="mt-1 text-sm text-gray-500">{{ $selectedDataset['description'] }}</p></div>
                            <div class="text-right"><p class="text-lg font-semibold text-gray-900 dark:text-white">{{ number_format((int) ($dataExplorer['total'] ?? 0), 0, ',', '.') }}</p><p class="text-xs text-gray-400">{{ $tr ? 'eşleşen kayıt' : 'matching rows' }}</p></div>
                        </div>
                        <div class="border-b border-gray-100 p-4 dark:border-gray-800">
                            <input wire:model.live.debounce.400ms="dataSearch" type="search" class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950" placeholder="{{ $tr ? 'Bu dataset içinde ara' : 'Search within this dataset' }}">
                        </div>

                        @if (($dataExplorer['state'] ?? 'unavailable') === 'available')
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-xs">
                                    <thead class="bg-gray-50/80 text-left font-medium text-gray-500 dark:bg-white/[0.02]"><tr>@foreach ($dataExplorer['columns'] as $column)<th class="whitespace-nowrap px-4 py-3">{{ $column['label'] }}</th>@endforeach</tr></thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">@foreach ($dataExplorer['rows'] as $record)<tr class="hover:bg-gray-50/60 dark:hover:bg-white/[0.02]">@foreach ($dataExplorer['columns'] as $column)<td class="max-w-80 truncate px-4 py-3 text-gray-700 dark:text-gray-300" title="{{ $record[$column['name']] ?? '—' }}">{{ $record[$column['name']] ?? '—' }}</td>@endforeach</tr>@endforeach</tbody>
                                </table>
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 text-sm dark:border-gray-800">
                                <p class="text-xs text-gray-500">{{ $dataExplorer['from'] }}–{{ $dataExplorer['to'] }} / {{ number_format((int) $dataExplorer['total'], 0, ',', '.') }}</p>
                                <div class="flex gap-2"><button type="button" wire:click="setDataPage({{ max(1, $dataExplorer['page'] - 1) }})" @disabled($dataExplorer['page'] <= 1) class="rounded-lg px-3 py-2 text-xs ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ $tr ? 'Önceki' : 'Previous' }}</button><button type="button" wire:click="setDataPage({{ min($dataExplorer['last_page'], $dataExplorer['page'] + 1) }})" @disabled($dataExplorer['page'] >= $dataExplorer['last_page']) class="rounded-lg px-3 py-2 text-xs ring-1 ring-inset ring-gray-300 disabled:opacity-40 dark:ring-gray-700">{{ $tr ? 'Sonraki' : 'Next' }}</button></div>
                            </div>
                        @elseif (($dataExplorer['state'] ?? '') === 'empty')
                            <div class="px-5 py-16 text-center"><p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $tr ? 'Eşleşen kayıt yok.' : 'No matching rows.' }}</p><p class="mt-1 text-xs text-gray-500">{{ $tr ? 'Aramayı temizleyin veya yeni bir veri çekimi başlatın.' : 'Clear the search or start a new collection.' }}</p></div>
                        @else
                            <div class="px-5 py-16 text-center"><p class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $tr ? 'Bu dataset için görüntülenebilir fiziksel tablo bulunamadı.' : 'No viewable physical table is available for this dataset.' }}</p></div>
                        @endif

                        <details class="border-t border-gray-100 px-5 py-4 text-xs dark:border-gray-800">
                            <summary class="cursor-pointer font-medium text-gray-600 dark:text-gray-300">{{ $tr ? 'Alanlar ve teknik bilgiler' : 'Fields and technical details' }}</summary>
                            <div class="mt-3 flex flex-wrap gap-2">@foreach ($selectedDataset['fields'] as $field)<span class="rounded bg-gray-100 px-2 py-1 text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $field['label'] }} · {{ $field['type'] }}</span>@endforeach @if ($selectedDataset['system_field_count'] > 0)<span class="rounded bg-gray-100 px-2 py-1 text-gray-500 dark:bg-white/[0.05]">+{{ $selectedDataset['system_field_count'] }} {{ $tr ? 'sistem alanı' : 'system fields' }}</span>@endif</div>
                            <p class="mt-3 break-all font-mono text-gray-400">{{ $selectedDataset['id'] }}@if ($selectedDataset['table']) · {{ $selectedDataset['table'] }}@endif</p>
                        </details>
                    @else
                        <div class="px-5 py-16 text-center text-sm text-gray-500">{{ $tr ? 'Görüntülenecek dataset bulunamadı.' : 'No dataset is available to display.' }}</div>
                    @endif
                </section>
            </div>
        @elseif ($activeTab === 'settings')
            <div class="grid gap-5 xl:grid-cols-2">
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Website kimliği' : 'Website identity' }}</h2>
                    <dl class="mt-4 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                        @foreach ([
                            [$tr ? 'Ad' : 'Name', $selectedRow['asset']->name],
                            [$tr ? 'Alan adı' : 'Domain', $selectedRow['asset']->domain ?: '—'],
                            ['URL', $selectedRow['asset']->primary_url ?: '—'],
                            ['CMS', $selectedRow['asset']->cms ?: ($tr ? 'Belirlenmedi' : 'Not detected')],
                        ] as [$label, $value])
                            <div class="grid gap-2 py-3 sm:grid-cols-3"><dt class="text-gray-500">{{ $label }}</dt><dd class="break-all font-medium text-gray-900 sm:col-span-2 dark:text-white">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Kaynak bağlantıları' : 'Source connections' }}</h2>
                    <div class="mt-4 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($selectedRow['data_sources'] as $source)
                            <div class="flex items-center justify-between gap-3 py-3 first:pt-0"><div><p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['label'] }}</p><p class="mt-1 text-xs text-gray-500">{{ $source['connection_label'] }}</p></div><span class="rounded-full border px-2.5 py-1 text-xs {{ $toneClasses($stateTone($source['state'])) }}">{{ $source['status_label'] }}</span></div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex flex-wrap gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <a href="{{ route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $selectedRow['asset']->id]) }}" wire:navigate class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white">WordPress Connector</a>
                        <a href="{{ route('operator.integrations') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-200 dark:ring-gray-700">{{ $tr ? 'Tüm entegrasyonlar' : 'All integrations' }}</a>
                    </div>
                </section>
            </div>
        @endif
    @endif
</div>
