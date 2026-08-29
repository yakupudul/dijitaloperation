<div class="space-y-5">
    @php
        $tr = app()->getLocale() === 'tr';
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
                {{ $tr ? 'Web Sitesi Veri Toplama' : 'Website Data Collection' }}
            </h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                {{ $tr
                    ? 'Web sitelerinden hangi verilerin toplandığını, hangi kaynakların eksik kaldığını ve son veri çekiminin sonucunu tek ekranda izleyin.'
                    : 'See what was collected from each website, what is still missing, and the result of the latest collection in one place.' }}
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
                ['label' => $tr ? 'Son çekim başarılı' : 'Latest run successful', 'value' => $stats['completed'], 'detail' => $tr ? 'Tamamlanan siteler' : 'Completed websites'],
                ['label' => $tr ? 'Dikkat gereken' : 'Needs attention', 'value' => $stats['attention'], 'detail' => $tr ? 'Kısmi, başarısız veya eksik kurulum' : 'Partial, failed, or incomplete setup'],
                ['label' => $tr ? 'Henüz veri çekilmedi' : 'Never collected', 'value' => $stats['never_collected'], 'detail' => $tr ? 'İlk çekim bekleniyor' : 'Waiting for first run'],
            ] as $metric)
                <div class="px-4 py-4 sm:px-5">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</p>
                    <div class="mt-1 flex items-end gap-2">
                        <p class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">{{ $metric['value'] }}</p>
                        <p class="pb-0.5 text-[11px] text-gray-400">{{ $metric['detail'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
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
            <div class="grid min-h-[620px] xl:grid-cols-[360px_minmax(0,1fr)]">
                <aside class="border-b border-gray-200 xl:border-b-0 xl:border-r dark:border-gray-800">
                    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                        <div>
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Web Siteleri' : 'Websites' }}</h2>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $rows->count() }} {{ $tr ? 'kayıt gösteriliyor' : 'items shown' }}</p>
                        </div>
                    </div>

                    <div class="max-h-[760px] overflow-y-auto">
                        @foreach ($rows as $row)
                            @php
                                $asset = $row['asset'];
                                $selected = (int) ($selectedRow['asset']->id ?? 0) === (int) $asset->id;
                                $state = $row['overall_state'];
                                $stateTone = match ($state) {
                                    'completed' => 'text-emerald-700 bg-emerald-50 dark:text-emerald-300 dark:bg-emerald-950/30',
                                    'partial' => 'text-amber-700 bg-amber-50 dark:text-amber-300 dark:bg-amber-950/30',
                                    'attention', 'needs_setup' => 'text-rose-700 bg-rose-50 dark:text-rose-300 dark:bg-rose-950/30',
                                    'running' => 'text-blue-700 bg-blue-50 dark:text-blue-300 dark:bg-blue-950/30',
                                    default => 'text-gray-600 bg-gray-100 dark:text-gray-300 dark:bg-white/[0.05]',
                                };
                                $targetUrl = $asset->primary_url ?: $asset->domain;
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
                                    <span class="shrink-0 rounded-full px-2 py-1 text-[10px] font-semibold {{ $stateTone }}">
                                        {{ $row['status_label'] }}
                                    </span>
                                </div>

                                <p class="mt-2 truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $asset->brand?->customer?->name ?: '—' }}
                                    <span class="mx-1 text-gray-300 dark:text-gray-700">·</span>
                                    {{ $asset->brand?->name ?: '—' }}
                                </p>

                                <div class="mt-3 flex items-center justify-between gap-3 text-[11px] text-gray-500 dark:text-gray-400">
                                    <span>{{ $row['completed_sources'] }}/{{ $row['source_total'] }} {{ $tr ? 'kaynak tamamlandı' : 'sources completed' }}</span>
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
                        $pendingSources = collect($selectedRow['sources'])->filter(fn ($source) => $source['state'] !== 'completed');
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
                            @foreach ([
                                ['label' => $tr ? 'Son veri çekimi' : 'Latest collection', 'value' => $run?->updated_at?->diffForHumans() ?: '—'],
                                ['label' => $tr ? 'Tamamlanan kaynak' : 'Completed sources', 'value' => $selectedRow['completed_sources'].' / '.$selectedRow['source_total']],
                                ['label' => $tr ? 'Yazılan kayıt' : 'Rows written', 'value' => number_format((int) $selectedRow['rows_written'], 0, ',', '.')],
                                ['label' => $tr ? 'İşlenen sayfa / işlem' : 'Pages / steps processed', 'value' => number_format((int) $selectedRow['pages_completed'], 0, ',', '.')],
                            ] as $item)
                                <div class="border-b border-gray-200 px-4 py-3 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0 dark:border-gray-800">
                                    <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $item['label'] }}</p>
                                    <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ $item['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-5 p-5 sm:p-6">
                        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex flex-col gap-2 border-b border-gray-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Veri Çekim Özeti' : 'Collection Summary' }}</h3>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $run
                                            ? ($tr ? "Son çekimde her veri kaynağının gerçek sonucu." : 'Actual result of each data source in the latest run.')
                                            : ($tr ? 'Bu web sitesi için henüz veri çekimi yapılmadı.' : 'No collection has run for this website yet.') }}
                                    </p>
                                </div>
                                @if ($run)
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Çekim' : 'Run' }} #{{ $run->id }}</span>
                                        <span class="mx-1">·</span>
                                        <span>{{ $selectedRow['status_label'] }}</span>
                                    </div>
                                @endif
                            </div>

                            <div class="hidden grid-cols-[minmax(190px,1.1fr)_130px_165px_minmax(220px,1.4fr)] gap-4 border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 lg:grid dark:border-gray-800 dark:bg-white/[0.025] dark:text-gray-400">
                                <div>{{ $tr ? 'Veri kaynağı' : 'Data source' }}</div>
                                <div>{{ $tr ? 'Durum' : 'Status' }}</div>
                                <div>{{ $tr ? 'Toplanan' : 'Collected' }}</div>
                                <div>{{ $tr ? 'Sonuç / kalan iş' : 'Result / remaining work' }}</div>
                            </div>

                            <div class="divide-y divide-gray-200 dark:divide-gray-800">
                                @foreach ($selectedRow['sources'] as $source)
                                    @php
                                        $tone = match ($source['tone']) {
                                            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900/50',
                                            'warning' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900/50',
                                            'error' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/30 dark:text-rose-300 dark:ring-rose-900/50',
                                            'info' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/30 dark:text-blue-300 dark:ring-blue-900/50',
                                            default => 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-white/[0.05] dark:text-gray-300 dark:ring-gray-700',
                                        };
                                        $collectedParts = [];
                                        if ((int) $source['rows_written'] > 0) {
                                            $collectedParts[] = number_format((int) $source['rows_written'], 0, ',', '.').' '.($tr ? 'kayıt' : 'rows');
                                        }
                                        if ((int) $source['pages_completed'] > 0) {
                                            $collectedParts[] = number_format((int) $source['pages_completed'], 0, ',', '.').' '.($tr ? 'sayfa/işlem' : 'pages/steps');
                                        }
                                    @endphp

                                    <div class="grid gap-3 px-4 py-4 lg:grid-cols-[minmax(190px,1.1fr)_130px_165px_minmax(220px,1.4fr)] lg:items-center lg:gap-4">
                                        <div>
                                            <div class="flex items-start gap-3">
                                                <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/[0.05] dark:text-gray-400">
                                                    @switch($source['key'])
                                                        @case('crawl')
                                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a15 15 0 0 1 0 18"></path></svg>
                                                            @break
                                                        @case('html')
                                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m8 9-3 3 3 3M16 9l3 3-3 3M14 5l-4 14"></path></svg>
                                                            @break
                                                        @case('tls')
                                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
                                                            @break
                                                        @default
                                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 14a8 8 0 1 1 16 0"></path><path d="m12 14 4-4"></path><path d="M6 18h12"></path></svg>
                                                    @endswitch
                                                </div>
                                                <div>
                                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $source['label'] }}</p>
                                                    <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $source['description'] }}</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold ring-1 ring-inset {{ $tone }}">
                                                {{ $source['status_label'] }}
                                            </span>
                                        </div>

                                        <div class="text-xs text-gray-600 dark:text-gray-300">
                                            {{ $collectedParts !== [] ? implode(' · ', $collectedParts) : '—' }}
                                        </div>

                                        <div>
                                            <p class="text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $source['result_detail'] }}</p>
                                            @if ($source['error_code'] && in_array($source['state'], ['failed', 'partial'], true))
                                                <p class="mt-1 text-[10px] font-mono text-gray-400">{{ $tr ? 'Hata kodu' : 'Error code' }}: {{ $source['error_code'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        <div class="grid gap-5 lg:grid-cols-2">
                            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Eksik veya Bekleyenler' : 'Missing or Pending' }}</h3>
                                    <span class="rounded-full bg-gray-100 px-2 py-1 text-[10px] font-semibold text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">
                                        {{ $pendingSources->count() + ($selectedRow['wordpress_detected'] ? 1 : 0) }}
                                    </span>
                                </div>

                                @if ($pendingSources->isEmpty() && ! $selectedRow['wordpress_detected'])
                                    <div class="mt-3 rounded-lg bg-emerald-50 px-3 py-3 text-xs leading-5 text-emerald-700 dark:bg-emerald-950/25 dark:text-emerald-300">
                                        {{ $tr ? 'Aktif veri kaynaklarında eksik veya bekleyen işlem yok.' : 'No missing or pending work in active data sources.' }}
                                    </div>
                                @else
                                    <div class="mt-3 divide-y divide-gray-100 dark:divide-gray-800">
                                        @foreach ($pendingSources as $source)
                                            <div class="py-3 first:pt-0 last:pb-0">
                                                <div class="flex items-start gap-2.5">
                                                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ in_array($source['state'], ['failed', 'partial'], true) ? 'bg-rose-500' : 'bg-amber-400' }}"></span>
                                                    <div>
                                                        <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $source['label'] }}</p>
                                                        <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $source['result_detail'] }}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach

                                        @if ($selectedRow['wordpress_detected'])
                                            <div class="py-3 first:pt-0 last:pb-0">
                                                <div class="flex items-start gap-2.5">
                                                    <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                                    <div>
                                                        <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $tr ? 'WordPress Envanteri' : 'WordPress Inventory' }}</p>
                                                        <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                                            {{ $tr
                                                                ? 'WordPress algılandı; kimlik doğrulamalı WordPress bağlayıcısı henüz canlı kullanıma açılmadığı için bu veri kaynağı bekliyor.'
                                                                : 'WordPress detected; this source is pending until the authenticated WordPress connector is production-ready.' }}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </section>

                            <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Toplanan Teknik Veri Kapsamı' : 'Technical Data Coverage' }}</h3>
                                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                    {{ $tr ? 'Başarılı site taraması ve teknik HTML çekimleri aşağıdaki veri gruplarını besler.' : 'Successful crawl and technical HTML collection feed the following data groups.' }}
                                </p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach (($tr
                                        ? ['URL ve sayfalar', 'HTTP durumları', 'Başlık ve meta verileri', 'Başlık hiyerarşisi', 'Yapısal veri', 'İçerik istatistikleri', 'İç / dış bağlantılar', 'Tarama sorunları', 'SSL/TLS', 'PageSpeed performansı']
                                        : ['URLs and pages', 'HTTP statuses', 'Titles and metadata', 'Heading hierarchy', 'Structured data', 'Content statistics', 'Internal / external links', 'Crawl issues', 'SSL/TLS', 'PageSpeed performance']) as $coverage)
                                        <span class="rounded-md bg-gray-100 px-2.5 py-1.5 text-[11px] font-medium text-gray-600 dark:bg-white/[0.05] dark:text-gray-300">{{ $coverage }}</span>
                                    @endforeach
                                </div>
                            </section>
                        </div>

                        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Son Veri Çekimleri' : 'Recent Collections' }}</h3>
                            </div>

                            @if ($history->isEmpty())
                                <div class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400">{{ $tr ? 'Henüz veri çekim geçmişi yok.' : 'No collection history yet.' }}</div>
                            @else
                                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($history as $item)
                                        @php
                                            $historyTone = match ($item['status']) {
                                                'completed' => 'text-emerald-700 bg-emerald-50 dark:text-emerald-300 dark:bg-emerald-950/30',
                                                'partial' => 'text-amber-700 bg-amber-50 dark:text-amber-300 dark:bg-amber-950/30',
                                                'failed', 'cancelled' => 'text-rose-700 bg-rose-50 dark:text-rose-300 dark:bg-rose-950/30',
                                                'queued', 'running', 'retrying' => 'text-blue-700 bg-blue-50 dark:text-blue-300 dark:bg-blue-950/30',
                                                default => 'text-gray-600 bg-gray-100 dark:text-gray-300 dark:bg-white/[0.05]',
                                            };
                                        @endphp
                                        <div class="grid gap-2 px-4 py-3 text-xs sm:grid-cols-[100px_130px_minmax(0,1fr)_140px] sm:items-center">
                                            <div class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Çekim' : 'Run' }} #{{ $item['id'] }}</div>
                                            <div><span class="rounded-full px-2 py-1 text-[10px] font-semibold {{ $historyTone }}">{{ $item['status_label'] }}</span></div>
                                            <div class="text-gray-500 dark:text-gray-400">
                                                {{ $item['datasets_completed'] }}/{{ $item['datasets_total'] }} {{ $tr ? 'işlem tamamlandı' : 'tasks completed' }}
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
