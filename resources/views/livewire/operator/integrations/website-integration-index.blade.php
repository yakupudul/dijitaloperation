<div class="space-y-5">
    @php
        $tr = app()->getLocale() === 'tr';
        $statusClasses = fn (string $state) => match ($state) {
            'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900/50',
            'running' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/30 dark:text-blue-300 dark:ring-blue-900/50',
            'partial', 'attention', 'connection_required', 'needs_setup' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900/50',
            'failed' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/30 dark:text-rose-300 dark:ring-rose-900/50',
            'managed_elsewhere' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/30 dark:text-blue-300 dark:ring-blue-900/50',
            default => 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-white/[0.05] dark:text-gray-300 dark:ring-gray-700',
        };
        $dotClasses = fn (string $state) => match ($state) {
            'completed' => 'bg-emerald-500',
            'running' => 'bg-blue-500',
            'partial', 'attention', 'connection_required', 'needs_setup' => 'bg-amber-400',
            'failed' => 'bg-rose-500',
            default => 'bg-gray-300 dark:bg-gray-600',
        };
    @endphp

    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <a href="{{ route('operator.integrations') }}" wire:navigate class="font-medium text-brand-600 hover:underline dark:text-brand-400">
                    {{ $tr ? 'Entegrasyonlar' : 'Integrations' }}
                </a>
                <span class="text-gray-300 dark:text-gray-700">/</span>
                <span>{{ $tr ? 'Web Sitesi' : 'Website' }}</span>
            </div>

            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">
                {{ $tr ? 'Web Sitesi Veri Kaynakları' : 'Website Data Sources' }}
            </h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                {{ $tr
                    ? 'Her web sitesi için veri kaynağını, kaynak altındaki datasetleri, toplanan alanları ve gerçek veri örneklerini aynı hiyerarşide inceleyin.'
                    : 'Inspect each website data source, its datasets, collected fields, and real data samples in one hierarchy.' }}
            </p>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2">
            <x-ta.button :href="route('operator.assets')" size="sm" variant="outline">
                {{ $tr ? 'Web Sitesi Varlıkları' : 'Website Assets' }}
            </x-ta.button>
            <x-ta.button :href="route('operator.asset.create')" size="sm">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 5v14M5 12h14"></path>
                </svg>
                {{ $tr ? 'Web Sitesi Ekle' : 'Add Website' }}
            </x-ta.button>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-lg border px-4 py-3 text-sm',
            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-300' => $messageTone === 'error',
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-300' => $messageTone === 'success',
            'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-300' => ! in_array($messageTone, ['error', 'success'], true),
        ])>{{ $message }}</div>
    @endif

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="grid divide-y divide-gray-200 sm:grid-cols-2 sm:divide-x sm:divide-y-0 xl:grid-cols-5 dark:divide-gray-800">
            @foreach ([
                ['label' => $tr ? 'Toplam web sitesi' : 'Total websites', 'value' => $stats['total'], 'detail' => $tr ? 'Portföyde kayıtlı' : 'In portfolio'],
                ['label' => $tr ? 'Veri çekimine hazır' : 'Ready to collect', 'value' => $stats['collect_ready'], 'detail' => $tr ? 'URL veya domain mevcut' : 'URL or domain available'],
                ['label' => $tr ? 'Son çekim başarılı' : 'Latest run successful', 'value' => $stats['completed'], 'detail' => $tr ? 'Çekim işlemi başarıyla bitti' : 'Collection run succeeded'],
                ['label' => $tr ? 'Dikkat gereken' : 'Needs attention', 'value' => $stats['attention'], 'detail' => $tr ? 'Kısmi, başarısız veya eksik kurulum' : 'Partial, failed, or incomplete setup'],
                ['label' => $tr ? 'Henüz veri çekilmedi' : 'Never collected', 'value' => $stats['never_collected'], 'detail' => $tr ? 'İlk çekim bekleniyor' : 'Waiting for first run'],
            ] as $metric)
                <div class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</p>
                    <div class="mt-1 flex items-end gap-2">
                        <p class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $metric['value'] }}</p>
                        <p class="pb-0.5 text-[11px] leading-4 text-gray-400">{{ $metric['detail'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-col gap-3 border-b border-gray-200 p-4 lg:flex-row lg:items-center lg:justify-between dark:border-gray-800">
            <div class="relative w-full max-w-xl">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
                </svg>
                <input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    placeholder="{{ $tr ? 'Web sitesi, domain, marka veya müşteri ara…' : 'Search website, domain, brand, or customer…' }}"
                    class="h-10 w-full rounded-lg border border-gray-300 bg-white pl-9 pr-3 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
                >
            </div>

            <div class="flex flex-wrap gap-1.5">
                @foreach ($filters as $item)
                    @php $active = $filter === $item['key']; @endphp
                    <button
                        type="button"
                        wire:click="$set('filter', '{{ $item['key'] }}')"
                        @class([
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-xs font-medium transition',
                            'bg-brand-50 text-brand-700 ring-1 ring-inset ring-brand-200 dark:bg-brand-950/30 dark:text-brand-300 dark:ring-brand-900/50' => $active,
                            'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]' => ! $active,
                        ])
                    >
                        {{ $item['label'] }}
                        <span @class([
                            'rounded px-1.5 py-0.5 text-[10px]',
                            'bg-brand-100 text-brand-700 dark:bg-brand-900/50 dark:text-brand-300' => $active,
                            'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400' => ! $active,
                        ])>{{ $item['count'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        @if ($rows->isEmpty())
            <div class="px-6 py-14 text-center">
                <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/[0.05]">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                </div>
                <h2 class="mt-3 text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Eşleşen web sitesi bulunamadı' : 'No matching website found' }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $tr ? 'Arama veya filtreyi değiştirin.' : 'Change the search or filter.' }}</p>
            </div>
        @else
            <div class="grid min-h-[680px] xl:grid-cols-[330px_minmax(0,1fr)]">
                <aside class="border-b border-gray-200 xl:border-b-0 xl:border-r dark:border-gray-800">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Web Siteleri' : 'Websites' }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $rows->count() }} {{ $tr ? 'kayıt gösteriliyor' : 'items shown' }}</p>
                    </div>

                    <div class="max-h-[820px] overflow-y-auto">
                        @foreach ($rows as $row)
                            @php
                                $asset = $row['asset'];
                                $selected = (int) ($selectedRow['asset']->id ?? 0) === (int) $asset->id;
                                $targetUrl = $asset->primary_url ?: $asset->domain;
                                $runState = $row['run']?->status?->value ?? 'never';
                                $runDot = match ($runState) {
                                    'completed' => 'bg-emerald-500',
                                    'queued', 'running', 'retrying' => 'bg-blue-500',
                                    'partial' => 'bg-amber-400',
                                    'failed', 'cancelled' => 'bg-rose-500',
                                    default => 'bg-gray-300 dark:bg-gray-600',
                                };
                            @endphp

                            <button
                                type="button"
                                wire:key="website-row-{{ $asset->id }}"
                                wire:click="selectWebsite({{ $asset->id }})"
                                @class([
                                    'block w-full border-b border-gray-100 px-4 py-4 text-left transition last:border-b-0 dark:border-gray-800',
                                    'bg-brand-50/60 dark:bg-brand-950/15' => $selected,
                                    'hover:bg-gray-50 dark:hover:bg-white/[0.025]' => ! $selected,
                                ])
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $asset->name }}</p>
                                        <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $targetUrl ?: ($tr ? 'URL tanımlanmamış' : 'URL not defined') }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs font-semibold text-gray-700 dark:text-gray-200">%{{ $row['coverage_percent'] }}</span>
                                </div>

                                <p class="mt-2 truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $asset->brand?->customer?->name ?: '—' }}
                                    <span class="mx-1 text-gray-300 dark:text-gray-700">·</span>
                                    {{ $asset->brand?->name ?: '—' }}
                                </p>

                                <div class="mt-3 flex items-center gap-2 text-[11px] text-gray-600 dark:text-gray-300">
                                    <span class="h-1.5 w-1.5 rounded-full {{ $runDot }}"></span>
                                    <span>{{ $tr ? 'Son çekim:' : 'Latest run:' }} {{ $row['run_status_label'] }}</span>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between gap-3 text-[11px] text-gray-500 dark:text-gray-400">
                                    <span>{{ $row['completed_collectors'] }}/{{ $row['collector_total'] }} {{ $tr ? 'genel web işlemi hazır' : 'public web collectors ready' }}</span>
                                    <span>{{ $row['last_run_at']?->diffForHumans() ?: ($tr ? 'Henüz çekilmedi' : 'Never collected') }}</span>
                                </div>
                            </button>
                        @endforeach
                    </div>
                </aside>

                <div class="min-w-0 bg-gray-50/40 dark:bg-white/[0.01]">
                    @php
                        $asset = $selectedRow['asset'];
                        $run = $selectedRow['run'];
                        $targetUrl = $asset->primary_url ?: $asset->domain;
                    @endphp

                    <div class="border-b border-gray-200 bg-white px-5 py-5 dark:border-gray-800 dark:bg-gray-900 sm:px-6">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $asset->name }}</h2>
                                    @if ($asset->cms)
                                        <span class="rounded-md bg-gray-100 px-2 py-1 text-[10px] font-medium uppercase text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">{{ $asset->cms }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $asset->brand?->customer?->name ?: '—' }}
                                    <span class="mx-1 text-gray-300 dark:text-gray-700">·</span>
                                    {{ $asset->brand?->name ?: '—' }}
                                </p>
                                @if ($targetUrl)
                                    <a href="{{ $targetUrl }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1.5 break-all text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                        {{ $targetUrl }}
                                        <svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3h7v7M10 14 21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"></path></svg>
                                    </a>
                                @endif
                            </div>

                            <div class="flex shrink-0 flex-wrap gap-2">
                                <button
                                    type="button"
                                    wire:click="collectNow({{ $asset->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="collectNow({{ $asset->id }})"
                                    @disabled(! $selectedRow['collectable'])
                                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-45"
                                >
                                    <svg wire:loading.remove wire:target="collectNow({{ $asset->id }})" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 12a8 8 0 1 1-2.34-5.66"></path><path d="M20 4v6h-6"></path></svg>
                                    <span wire:loading.remove wire:target="collectNow({{ $asset->id }})">{{ $tr ? 'Veri Çek' : 'Collect Data' }}</span>
                                    <span wire:loading wire:target="collectNow({{ $asset->id }})">{{ $tr ? 'Başlatılıyor…' : 'Starting…' }}</span>
                                </button>
                                <x-ta.button :href="route('operator.asset.sources', ['assetId' => $asset->id])" size="sm" variant="outline">
                                    {{ $tr ? 'Kaynakları Yönet' : 'Manage Sources' }}
                                </x-ta.button>
                                <x-ta.button :href="route('operator.website', ['assetId' => $asset->id])" size="sm" variant="outline">
                                    {{ $tr ? 'Web Sitesini Aç' : 'Open Website' }}
                                </x-ta.button>
                            </div>
                        </div>

                        <div class="mt-5 grid overflow-hidden rounded-lg border border-gray-200 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800">
                            <div class="border-b border-gray-200 px-4 py-3 sm:border-b-0 sm:border-r dark:border-gray-800">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Son çekim sonucu' : 'Latest run result' }}</p>
                                <div class="mt-1 flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full {{ $dotClasses($selectedRow['overall_state']) }}"></span>
                                    <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $selectedRow['run_status_label'] }}</p>
                                </div>
                            </div>
                            <div class="border-b border-gray-200 px-4 py-3 sm:border-b-0 xl:border-r dark:border-gray-800">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Genel web dataset kapsamı' : 'Public web dataset coverage' }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">
                                    {{ $selectedRow['public_dataset_completed'] }}/{{ $selectedRow['public_dataset_total'] }}
                                    <span class="ml-1 text-xs font-medium text-gray-400">%{{ $selectedRow['public_dataset_coverage_percent'] }}</span>
                                </p>
                            </div>
                            <div class="border-b border-gray-200 px-4 py-3 sm:border-b-0 sm:border-r dark:border-gray-800">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Mevcut kayıt tahmini' : 'Current row estimate' }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ number_format((int) $selectedRow['current_rows'], 0, ',', '.') }}</p>
                            </div>
                            <div class="px-4 py-3">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Son veri çekimi' : 'Latest collection' }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ $run?->updated_at?->diffForHumans() ?: '—' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-5 p-5 sm:p-6">
                        <section>
                            <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Veri Kaynakları' : 'Data Sources' }}</h3>
                                    <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                        {{ $tr
                                            ? 'Bir kaynağı açarak datasetleri; datasetin içinden de özet, alan şeması ve gerçek veri örneklerini inceleyin.'
                                            : 'Expand a source to inspect datasets, then open a dataset for summary, schema fields, and real data samples.' }}
                                    </p>
                                </div>
                                @if ($run)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $tr ? 'Çekim' : 'Run' }} #{{ $run->id }}</span>
                                @endif
                            </div>

                            <div class="space-y-3">
                                @foreach ($selectedRow['data_sources'] as $source)
                                    @php
                                        $sourceDatasets = collect($source['datasets']);
                                        $sourceExpandable = $source['key'] !== 'google' && $sourceDatasets->isNotEmpty();
                                        $sourceStatusClass = $statusClasses($source['state']);
                                    @endphp

                                    <article
                                        wire:key="source-{{ $asset->id }}-{{ $source['key'] }}"
                                        x-data="{ open: {{ $source['key'] === 'public_web' ? 'true' : 'false' }} }"
                                        class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"
                                    >
                                        <button
                                            type="button"
                                            @if ($sourceExpandable) @click="open = !open" @endif
                                            class="flex w-full items-start gap-3 px-4 py-4 text-left sm:px-5"
                                        >
                                            <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/[0.05] dark:text-gray-400">
                                                @if ($source['key'] === 'google')
                                                    <span class="text-xs font-bold">G</span>
                                                @elseif ($source['key'] === 'public_web')
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"></path></svg>
                                                @else
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 12h8M12 8v8"></path><rect x="4" y="4" width="16" height="16" rx="4"></rect></svg>
                                                @endif
                                            </div>

                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                    <div>
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</h4>
                                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold ring-1 ring-inset {{ $sourceStatusClass }}">
                                                                {{ $source['status_label'] }}
                                                            </span>
                                                        </div>
                                                        <p class="mt-1 max-w-3xl text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $source['description'] }}</p>
                                                    </div>

                                                    <div class="flex shrink-0 items-center gap-3">
                                                        @if ($source['key'] === 'public_web')
                                                            <div class="text-right">
                                                                <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $source['completed'] }}/{{ $source['total'] }} dataset</p>
                                                                <p class="mt-0.5 text-[10px] text-gray-400">%{{ $source['coverage_percent'] }} {{ $tr ? 'kapsam' : 'coverage' }}</p>
                                                            </div>
                                                        @endif
                                                        @if ($sourceExpandable)
                                                            <svg class="h-4 w-4 shrink-0 text-gray-400 transition" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"></path></svg>
                                                        @endif
                                                    </div>
                                                </div>

                                                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-gray-500 dark:text-gray-400">
                                                    <span>{{ $tr ? 'Bağlantı:' : 'Connection:' }} <strong class="font-medium text-gray-700 dark:text-gray-300">{{ $source['connection_label'] }}</strong></span>
                                                    @if ($source['key'] === 'public_web')
                                                        <span>{{ $tr ? 'Ücretli entegrasyon gerektirmez' : 'No paid integration required' }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </button>

                                        @if ($source['key'] === 'google')
                                            <div class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 sm:px-5 dark:border-gray-800 dark:bg-white/[0.02]">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                                        {{ $tr ? 'GA4 ve Search Console hazır entegrasyonlardır; bu ekran yalnızca onların kaynak yönetimine referans verir.' : 'GA4 and Search Console are existing integrations; this screen only references their source management.' }}
                                                    </p>
                                                    <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">
                                                        {{ $tr ? 'Google kaynaklarını yönet' : 'Manage Google sources' }}
                                                    </a>
                                                </div>
                                            </div>
                                        @endif

                                        @if ($sourceExpandable)
                                            <div x-show="open" x-collapse class="border-t border-gray-200 dark:border-gray-800">
                                                <div class="bg-gray-50 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:px-5 dark:bg-white/[0.025] dark:text-gray-400">
                                                    {{ $tr ? 'Datasetler' : 'Datasets' }}
                                                </div>

                                                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                                                    @foreach ($sourceDatasets as $dataset)
                                                        @php
                                                            $datasetStatusClass = $statusClasses($dataset['state']);
                                                            $preview = $dataset['preview'];
                                                        @endphp

                                                        <div
                                                            wire:key="dataset-{{ $asset->id }}-{{ $source['key'] }}-{{ $dataset['id'] }}"
                                                            x-data="{ open: false, tab: 'summary' }"
                                                            class="bg-white dark:bg-gray-900"
                                                        >
                                                            <button type="button" @click="open = !open" class="flex w-full items-start gap-3 px-4 py-4 text-left sm:px-5">
                                                                <div class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center">
                                                                    @if ($dataset['state'] === 'completed')
                                                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m5 12 4 4L19 6"></path></svg>
                                                                        </span>
                                                                    @elseif ($dataset['state'] === 'running')
                                                                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-blue-200 border-t-blue-600 dark:border-blue-900 dark:border-t-blue-300"></span>
                                                                    @elseif (in_array($dataset['state'], ['partial', 'connection_required', 'needs_setup'], true))
                                                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">!</span>
                                                                    @elseif ($dataset['state'] === 'failed')
                                                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">×</span>
                                                                    @else
                                                                        <span class="h-2.5 w-2.5 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                                                    @endif
                                                                </div>

                                                                <div class="min-w-0 flex-1">
                                                                    <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                                                                        <div>
                                                                            <div class="flex flex-wrap items-center gap-2">
                                                                                <h5 class="text-sm font-medium text-gray-900 dark:text-white">{{ $dataset['label'] }}</h5>
                                                                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset {{ $datasetStatusClass }}">{{ $dataset['status_label'] }}</span>
                                                                            </div>
                                                                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $dataset['description'] }}</p>
                                                                        </div>

                                                                        <div class="flex shrink-0 items-center gap-4">
                                                                            <div class="text-right">
                                                                                <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                                                                                    {{ number_format((int) $dataset['current_rows'], 0, ',', '.') }} {{ $tr ? 'kayıt' : 'rows' }}
                                                                                </p>
                                                                                <p class="mt-0.5 text-[10px] text-gray-400">{{ $tr ? 'mevcut veri' : 'current data' }}</p>
                                                                            </div>
                                                                            <svg class="h-4 w-4 text-gray-400 transition" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"></path></svg>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </button>

                                                            <div x-show="open" x-collapse class="border-t border-gray-100 bg-gray-50/60 dark:border-gray-800 dark:bg-white/[0.018]">
                                                                <div class="flex gap-1 border-b border-gray-200 px-4 pt-3 sm:px-5 dark:border-gray-800">
                                                                    @foreach ([
                                                                        'summary' => $tr ? 'Özet' : 'Summary',
                                                                        'fields' => $tr ? 'Alanlar' : 'Fields',
                                                                        'data' => $tr ? 'Veriler' : 'Data',
                                                                    ] as $tabKey => $tabLabel)
                                                                        <button
                                                                            type="button"
                                                                            @click="tab = '{{ $tabKey }}'"
                                                                            :class="tab === '{{ $tabKey }}' ? 'border-brand-500 text-brand-600 dark:text-brand-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                                                            class="border-b-2 px-3 pb-2.5 text-xs font-semibold transition"
                                                                        >{{ $tabLabel }}</button>
                                                                    @endforeach
                                                                </div>

                                                                <div x-show="tab === 'summary'" class="p-4 sm:p-5">
                                                                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                                                        <div class="rounded-lg border border-gray-200 bg-white px-3 py-3 dark:border-gray-800 dark:bg-gray-900">
                                                                            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ $tr ? 'Mevcut kayıt' : 'Current rows' }}</p>
                                                                            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format((int) $dataset['current_rows'], 0, ',', '.') }}</p>
                                                                        </div>
                                                                        <div class="rounded-lg border border-gray-200 bg-white px-3 py-3 dark:border-gray-800 dark:bg-gray-900">
                                                                            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ $tr ? 'Son çekimde işlendi' : 'Processed latest run' }}</p>
                                                                            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ number_format((int) $dataset['processed_rows'], 0, ',', '.') }}</p>
                                                                        </div>
                                                                        <div class="rounded-lg border border-gray-200 bg-white px-3 py-3 dark:border-gray-800 dark:bg-gray-900">
                                                                            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ $tr ? 'Başarılı yazma' : 'Committed batches' }}</p>
                                                                            <p class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $dataset['successful_batches'] }}</p>
                                                                        </div>
                                                                        <div class="rounded-lg border border-gray-200 bg-white px-3 py-3 dark:border-gray-800 dark:bg-gray-900">
                                                                            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ $tr ? 'Son güncelleme' : 'Last updated' }}</p>
                                                                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">{{ $dataset['last_collected_at']?->diffForHumans() ?: '—' }}</p>
                                                                        </div>
                                                                    </div>

                                                                    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(260px,0.55fr)]">
                                                                        <div>
                                                                            <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $tr ? 'Sonuç' : 'Result' }}</p>
                                                                            <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $dataset['result_detail'] }}</p>
                                                                        </div>
                                                                        <div>
                                                                            <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $tr ? 'Bu dataseti besleyen işlemler' : 'Contributing collectors' }}</p>
                                                                            <div class="mt-2 flex flex-wrap gap-1.5">
                                                                                @foreach ($dataset['collectors'] as $collector)
                                                                                    <span class="rounded-md bg-white px-2 py-1 text-[10px] font-medium text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $collector }}</span>
                                                                                @endforeach
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                <div x-show="tab === 'fields'" class="p-4 sm:p-5">
                                                                    @if (empty($dataset['fields']))
                                                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $tr ? 'Bu dataset için alan şeması bulunamadı.' : 'No field schema is available for this dataset.' }}</p>
                                                                    @else
                                                                        <div class="mb-3 flex items-center justify-between gap-3">
                                                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                                                {{ count($dataset['fields']) }} {{ $tr ? 'iş alanı' : 'business fields' }}
                                                                                @if ($dataset['system_field_count'] > 0)
                                                                                    · {{ $dataset['system_field_count'] }} {{ $tr ? 'sistem/provenance alanı gizli' : 'system/provenance fields hidden' }}
                                                                                @endif
                                                                            </p>
                                                                            <span class="font-mono text-[10px] text-gray-400">{{ $dataset['id'] }}</span>
                                                                        </div>
                                                                        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                                                            <div class="grid grid-cols-[minmax(150px,1fr)_minmax(160px,1fr)_100px_80px] gap-3 border-b border-gray-200 bg-gray-50 px-3 py-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-800 dark:bg-white/[0.025] dark:text-gray-400">
                                                                                <div>{{ $tr ? 'Alan' : 'Field' }}</div>
                                                                                <div>{{ $tr ? 'Teknik ad' : 'Technical name' }}</div>
                                                                                <div>{{ $tr ? 'Tip' : 'Type' }}</div>
                                                                                <div>{{ $tr ? 'Boş olabilir' : 'Nullable' }}</div>
                                                                            </div>
                                                                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                                                                @foreach ($dataset['fields'] as $field)
                                                                                    <div class="grid grid-cols-[minmax(150px,1fr)_minmax(160px,1fr)_100px_80px] gap-3 px-3 py-2.5 text-xs">
                                                                                        <div class="font-medium text-gray-800 dark:text-gray-200">{{ $field['label'] }}</div>
                                                                                        <div class="break-all font-mono text-[11px] text-gray-500 dark:text-gray-400">{{ $field['name'] }}</div>
                                                                                        <div class="text-gray-500 dark:text-gray-400">{{ $field['type'] }}</div>
                                                                                        <div class="text-gray-500 dark:text-gray-400">{{ $field['nullable'] ? ($tr ? 'Evet' : 'Yes') : ($tr ? 'Hayır' : 'No') }}</div>
                                                                                    </div>
                                                                                @endforeach
                                                                            </div>
                                                                        </div>
                                                                    @endif
                                                                </div>

                                                                <div x-show="tab === 'data'" class="p-4 sm:p-5">
                                                                    @if ($preview['state'] === 'available')
                                                                        <div class="mb-3 flex items-center justify-between gap-3">
                                                                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $tr ? 'Data Pool içindeki gerçek kayıtlardan ilk 5 örnek gösteriliyor.' : 'Showing up to 5 real records from the Data Pool.' }}</p>
                                                                            <span class="text-[10px] text-gray-400">{{ $tr ? 'Salt okunur önizleme' : 'Read-only preview' }}</span>
                                                                        </div>
                                                                        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                                                            <table class="min-w-full divide-y divide-gray-200 text-left text-xs dark:divide-gray-800">
                                                                                <thead class="bg-gray-50 dark:bg-white/[0.025]">
                                                                                    <tr>
                                                                                        @foreach ($preview['columns'] as $column)
                                                                                            <th class="whitespace-nowrap px-3 py-2 font-semibold text-gray-500 dark:text-gray-400">{{ $column['label'] }}</th>
                                                                                        @endforeach
                                                                                    </tr>
                                                                                </thead>
                                                                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                                                                    @foreach ($preview['rows'] as $record)
                                                                                        <tr>
                                                                                            @foreach ($preview['columns'] as $column)
                                                                                                <td class="max-w-[260px] px-3 py-2.5 align-top text-gray-700 dark:text-gray-300">
                                                                                                    <span class="line-clamp-3 break-words">{{ $record[$column['name']] ?? '—' }}</span>
                                                                                                </td>
                                                                                            @endforeach
                                                                                        </tr>
                                                                                    @endforeach
                                                                                </tbody>
                                                                            </table>
                                                                        </div>
                                                                    @elseif ($preview['state'] === 'empty')
                                                                        <div class="rounded-lg border border-gray-200 bg-white px-4 py-5 text-xs leading-5 text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                                                            {{ $dataset['state'] === 'completed'
                                                                                ? ($tr ? 'Bu dataset kontrol edildi ancak şu anda gösterilecek kayıt oluşmadı. Bu bazı datasetlerde normal olabilir; örneğin tarama sorunu bulunmaması 0 kayıt üretebilir.' : 'This dataset was checked but produced no previewable records. This can be valid, for example when no crawl issues are found.')
                                                                                : ($tr ? 'Bu dataset için henüz kayıt bulunmuyor.' : 'No records are available for this dataset yet.') }}
                                                                        </div>
                                                                    @else
                                                                        <div class="rounded-lg border border-gray-200 bg-white px-4 py-5 text-xs leading-5 text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                                                            {{ $tr ? 'Bu dataset için güvenli kayıt önizlemesi şu anda kullanılamıyor. Alan şeması ve çekim özeti yine de yukarıdaki sekmelerden görülebilir.' : 'A safe record preview is not currently available for this dataset. Its schema and collection summary remain available in the other tabs.' }}
                                                                        </div>
                                                                    @endif
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @elseif ($source['key'] === 'site_connector' && ! $selectedRow['wordpress_detected'])
                                            <div class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 text-xs leading-5 text-gray-500 sm:px-5 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-400">
                                                {{ $tr ? 'Bu web sitesi için desteklenen bir CMS bağlayıcısı henüz tanımlı değil.' : 'No supported CMS connector is defined for this website yet.' }}
                                            </div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 sm:px-5 dark:border-gray-800">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Son Veri Çekimleri' : 'Recent Collections' }}</h3>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $tr ? 'Çekim sonucu ile veri kapsamı birbirinden bağımsız gösterilir.' : 'Run result and data coverage are shown as separate concepts.' }}</p>
                                </div>
                            </div>

                            @if ($history->isEmpty())
                                <div class="px-4 py-6 text-sm text-gray-500 sm:px-5 dark:text-gray-400">{{ $tr ? 'Henüz veri çekim geçmişi yok.' : 'No collection history yet.' }}</div>
                            @else
                                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($history as $item)
                                        @php
                                            $historyState = match ($item['status']) {
                                                'completed' => 'completed',
                                                'partial' => 'partial',
                                                'failed', 'cancelled' => 'failed',
                                                'queued', 'running', 'retrying' => 'running',
                                                default => 'neutral',
                                            };
                                        @endphp
                                        <div class="grid gap-2 px-4 py-3 text-xs sm:grid-cols-[100px_150px_minmax(0,1fr)_140px] sm:items-center sm:px-5">
                                            <div class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Çekim' : 'Run' }} #{{ $item['id'] }}</div>
                                            <div><span class="inline-flex rounded-full px-2 py-1 text-[10px] font-semibold ring-1 ring-inset {{ $statusClasses($historyState) }}">{{ $item['status_label'] }}</span></div>
                                            <div class="text-gray-500 dark:text-gray-400">
                                                {{ $item['datasets_completed'] }}/{{ $item['datasets_total'] }} {{ $tr ? 'çekim işi tamamlandı' : 'collection jobs completed' }}
                                                @if ($item['datasets_failed'] > 0)
                                                    <span class="ml-1 text-rose-600 dark:text-rose-400">· {{ $item['datasets_failed'] }} {{ $tr ? 'başarısız' : 'failed' }}</span>
                                                @endif
                                            </div>
                                            <div class="text-gray-500 sm:text-right dark:text-gray-400">{{ $item['updated_at']?->diffForHumans() }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </section>
                    </div>
                </div>
            </div>
        @endif
    </section>
</div>
