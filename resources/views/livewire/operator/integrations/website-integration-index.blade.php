@php
    $tr = app()->getLocale() === 'tr';
    $pollWebsiteActivity = ($selectedRow['overall_state'] ?? null) === 'running';
    $operatorRead = null;
    $siteHealth = [
        'available' => false,
        'run_scoped' => false,
        'total' => 0,
        'critical' => 0,
        'high' => 0,
        'medium' => 0,
        'low_info' => 0,
        'redirect' => 0,
        'seo' => 0,
        'availability' => 0,
        'issues' => [],
    ];

    if ($selectedRow !== null) {
        $operatorRead = app(\App\Services\Collection\Website\WebsiteOperatorReadModel::class)
            ->forAsset((int) $selectedRow['asset']->id, $selectedRow['run']?->id);
        $siteHealth = $operatorRead['site_health'];
    }

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

    $collectorLabel = fn (string $key) => match ($key) {
        'crawl' => $tr ? 'Site taraması' : 'Site crawl',
        'html' => $tr ? 'Teknik HTML kontrolü' : 'Technical HTML check',
        'tls' => $tr ? 'SSL/TLS altyapısı' : 'SSL/TLS infrastructure',
        'pagespeed' => 'PageSpeed',
        default => $key,
    };

    $collectorStateLabel = fn (string $state) => match ($state) {
        'completed' => $tr ? 'Tamamlandı' : 'Completed',
        'running' => $tr ? 'Devam ediyor' : 'In progress',
        'partial' => $tr ? 'Kısmi' : 'Partial',
        'failed' => $tr ? 'Başarısız' : 'Failed',
        'connection_required' => $tr ? 'Bağlantı gerekli' : 'Connection required',
        'needs_setup' => $tr ? 'Kurulum gerekli' : 'Setup required',
        'not_eligible' => $tr ? 'Uygun değil' : 'Not eligible',
        'skipped' => $tr ? 'Atlandı' : 'Skipped',
        default => $tr ? 'Henüz çalışmadı' : 'Not run yet',
    };

    $effectiveDatasetState = function (array $dataset): string {
        $state = (string) ($dataset['state'] ?? 'not_run');
        if ($state === 'completed'
            && (int) ($dataset['processed_rows'] ?? 0) === 0
            && (string) ($dataset['id'] ?? '') !== 'website_crawl_issue_snapshot') {
            return 'attention';
        }

        return $state;
    };

    $datasetOutcome = function (array $dataset) use ($tr): string {
        $id = (string) ($dataset['id'] ?? '');
        $state = (string) ($dataset['state'] ?? 'not_run');
        $processed = max(0, (int) ($dataset['processed_rows'] ?? 0));

        if ($state === 'connection_required') {
            return $tr ? 'PageSpeed bağlantısı gerekli. Bu kaynak bağlanmadan performans verisi alınamaz.' : 'PageSpeed connection is required before performance data can be collected.';
        }
        if ($state === 'needs_setup') {
            return $tr ? 'Web sitesi URL/domain bilgisi tamamlanmadan bu veri grubu çalışamaz.' : 'The website URL/domain must be configured before this data group can run.';
        }
        if ($state === 'running') {
            return $tr ? 'Veri çekimi devam ediyor; sonuçlar canlı olarak güncelleniyor.' : 'Collection is still running; results are updating live.';
        }
        if ($state === 'failed') {
            return $tr ? 'Son veri çekiminde bu veri grubu üretilemedi.' : 'This data group could not be produced in the latest collection.';
        }
        if ($state === 'partial') {
            return $tr ? 'Son veri çekimi kısmi sonuç üretti; tamamlanmayan işlem var.' : 'The latest collection produced partial results.';
        }
        if ($state === 'completed' && $id === 'website_crawl_issue_snapshot' && $processed === 0) {
            return $tr ? 'Son çekimde tarama sorunu bulunmadı.' : 'No crawl issues were found in the latest collection.';
        }
        if ($state === 'completed' && $processed === 0) {
            return $tr
                ? 'Çekim tamamlandı ancak bu veri grubunda veri üretilmedi. Veri hattı veya sayfa kapsamı kontrol edilmeli.'
                : 'The run completed but this data group produced no data. Check the pipeline or page coverage.';
        }
        if ($state === 'completed' && $id === 'website_url') {
            return $tr ? number_format($processed, 0, ',', '.').' URL son çekimde kaydedildi.' : number_format($processed).' URLs were recorded in the latest collection.';
        }
        if ($state === 'completed' && $id === 'website_crawl_issue_snapshot') {
            return $tr ? number_format($processed, 0, ',', '.').' sorun kaydı son çekimde üretildi.' : number_format($processed).' issue records were produced in the latest collection.';
        }
        if ($state === 'completed') {
            return $tr ? number_format($processed, 0, ',', '.').' kayıt son çekimde üretildi.' : number_format($processed).' records were produced in the latest collection.';
        }

        return $tr ? 'Bu veri grubu için henüz sonuç yok.' : 'No result is available for this data group yet.';
    };
@endphp

<div class="space-y-5" @if ($pollWebsiteActivity) wire:poll.2s @endif>
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
                    ? 'Her web sitesinde ne toplandığını, neyin eksik kaldığını, hangi sorunların bulunduğunu ve canlı veri çekiminin ilerlemesini tek ekranda izleyin.'
                    : 'See what was collected, what is missing, which problems were found, and the live progress of each website collection in one place.' }}
            </p>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2">
            <x-ta.button :href="route('operator.assets')" size="sm" variant="outline">
                {{ $tr ? 'Web Sitesi Varlıkları' : 'Website Assets' }}
            </x-ta.button>
            <x-ta.button :href="route('operator.asset.create')" size="sm">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
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
                ['label' => $tr ? 'Son çekim başarılı' : 'Latest run successful', 'value' => $stats['completed'], 'detail' => $tr ? 'Çekim işlemi tamamlandı' : 'Collection run completed'],
                ['label' => $tr ? 'Dikkat gereken' : 'Needs attention', 'value' => $stats['attention'], 'detail' => $tr ? 'Kısmi, başarısız veya eksik' : 'Partial, failed, or incomplete'],
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
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
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
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Eşleşen web sitesi bulunamadı' : 'No matching website found' }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $tr ? 'Arama veya filtreyi değiştirin.' : 'Change the search or filter.' }}</p>
            </div>
        @else
            <div class="grid min-h-[720px] xl:grid-cols-[330px_minmax(0,1fr)]">
                <aside class="border-b border-gray-200 xl:border-b-0 xl:border-r dark:border-gray-800">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Web Siteleri' : 'Websites' }}</h2>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $rows->count() }} {{ $tr ? 'kayıt gösteriliyor' : 'items shown' }}</p>
                    </div>

                    <div class="max-h-[900px] overflow-y-auto">
                        @foreach ($rows as $row)
                            @php
                                $asset = $row['asset'];
                                $selected = (int) ($selectedRow['asset']->id ?? 0) === (int) $asset->id;
                                $targetUrl = $asset->primary_url ?: $asset->domain;
                                $runState = $row['run']?->status?->value ?? 'never';
                                $runDot = match ($runState) {
                                    'completed' => 'bg-emerald-500',
                                    'queued', 'running', 'retrying', 'cancellation_requested' => 'bg-blue-500',
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
                                    <span>{{ $tr ? 'Çekim' : 'Run' }}: {{ $row['run_status_label'] }}</span>
                                </div>
                                <div class="mt-1.5 flex items-center justify-between gap-3 text-[11px] text-gray-500 dark:text-gray-400">
                                    <span>{{ $tr ? 'Kapsam' : 'Coverage' }}: {{ $row['completed_collectors'] }}/{{ $row['collector_total'] }} {{ $tr ? 'kaynak' : 'sources' }}</span>
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
                        $latestPages = $operatorRead['latest_pages'] ?? ['count' => 0, 'available' => false, 'run_scoped' => false];
                        $processedPages = collect($selectedRow['collectors'])
                            ->map(fn (array $collector) => (int) ($collector['dataset_run']?->pages_completed ?? 0))
                            ->max() ?? 0;
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
                                    <a href="{{ $targetUrl }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-flex items-center gap-1.5 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                        {{ $targetUrl }}
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 5h5v5M10 14 19 5M19 13v6H5V5h6"></path></svg>
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
                                <x-ta.button :href="route('operator.asset.sources', ['assetId' => $asset->id])" size="sm" variant="outline">{{ $tr ? 'Kaynakları Yönet' : 'Manage Sources' }}</x-ta.button>
                            </div>
                        </div>

                        <div class="mt-5 grid overflow-hidden rounded-lg border border-gray-200 sm:grid-cols-2 xl:grid-cols-4 dark:border-gray-800">
                            <div class="border-b border-gray-200 px-4 py-3 sm:border-b-0 sm:border-r dark:border-gray-800">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Son durum' : 'Latest status' }}</p>
                                <div class="mt-1 flex items-center gap-2">
                                    <span class="h-2 w-2 rounded-full {{ $dotClasses($selectedRow['overall_state']) }}"></span>
                                    <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $selectedRow['run_status_label'] }}</p>
                                </div>
                            </div>
                            <div class="border-b border-gray-200 px-4 py-3 sm:border-b-0 xl:border-r dark:border-gray-800">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Son çekimde geçerli URL' : 'Valid URLs in latest run' }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ $latestPages['available'] ? number_format((int) $latestPages['count'], 0, ',', '.') : '—' }}</p>
                            </div>
                            <div class="border-b border-gray-200 px-4 py-3 sm:border-b-0 sm:border-r dark:border-gray-800">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Site sorunları' : 'Site issues' }}</p>
                                <p class="mt-1 text-base font-semibold text-gray-900 dark:text-white">{{ $siteHealth['available'] ? number_format((int) $siteHealth['total'], 0, ',', '.') : '—' }}</p>
                            </div>
                            <div class="px-4 py-3">
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400">{{ $tr ? 'Kritik sorun' : 'Critical issues' }}</p>
                                <p @class([
                                    'mt-1 text-base font-semibold',
                                    'text-rose-600 dark:text-rose-400' => (int) $siteHealth['critical'] > 0,
                                    'text-gray-900 dark:text-white' => (int) $siteHealth['critical'] === 0,
                                ])>{{ $siteHealth['available'] ? number_format((int) $siteHealth['critical'], 0, ',', '.') : '—' }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-5 p-5 sm:p-6">
                        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                            <div class="flex flex-col gap-2 border-b border-gray-200 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5 dark:border-gray-800">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Canlı Veri Çekimi' : 'Live Collection' }}</h3>
                                        @if ($pollWebsiteActivity)
                                            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2 py-1 text-[10px] font-semibold text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-950/30 dark:text-blue-300 dark:ring-blue-900/50">
                                                <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-blue-500"></span>
                                                {{ $tr ? '2 sn’de bir güncelleniyor' : 'Updating every 2s' }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $run ? (($tr ? 'Çekim #' : 'Run #').$run->id) : ($tr ? 'Henüz çekim yok' : 'No run yet') }}
                                        @if ($processedPages > 0)
                                            · {{ number_format($processedPages, 0, ',', '.') }} {{ $tr ? 'sayfa/işlem tamamlandı' : 'pages/steps completed' }}
                                        @endif
                                    </p>
                                </div>
                                @if ($run?->updated_at)
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $tr ? 'Son hareket' : 'Last activity' }}: {{ $run->updated_at->diffForHumans() }}</span>
                                @endif
                            </div>

                            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($selectedRow['collectors'] as $collector)
                                    @php
                                        $datasetRun = $collector['dataset_run'];
                                        $state = (string) $collector['state'];
                                        $progressCurrent = (int) ($datasetRun?->progress_current ?? 0);
                                        $progressTotal = (int) ($datasetRun?->progress_total ?? 0);
                                        $rowsWritten = (int) ($datasetRun?->rows_written ?? 0);
                                        $pagesCompleted = (int) ($datasetRun?->pages_completed ?? 0);
                                    @endphp
                                    <div class="grid gap-2 px-4 py-3 text-xs sm:grid-cols-[180px_130px_minmax(0,1fr)_150px] sm:items-center sm:px-5">
                                        <div class="flex items-center gap-2 font-medium text-gray-800 dark:text-gray-200">
                                            <span class="h-2 w-2 rounded-full {{ $dotClasses($state) }}"></span>
                                            {{ $collectorLabel($collector['key']) }}
                                        </div>
                                        <div>
                                            <span class="inline-flex rounded-full px-2 py-1 text-[10px] font-semibold ring-1 ring-inset {{ $statusClasses($state) }}">{{ $collectorStateLabel($state) }}</span>
                                        </div>
                                        <div class="text-gray-500 dark:text-gray-400">
                                            @if ($state === 'connection_required')
                                                {{ $tr ? 'API bağlantısı yapılmalı' : 'API connection required' }}
                                            @elseif ($datasetRun)
                                                @if ($pagesCompleted > 0)
                                                    <span>{{ number_format($pagesCompleted, 0, ',', '.') }} {{ $tr ? 'sayfa/işlem' : 'pages/steps' }}</span>
                                                @elseif ($progressTotal > 0)
                                                    <span>{{ $progressCurrent }}/{{ $progressTotal }} {{ $tr ? 'tamamlandı' : 'completed' }}</span>
                                                @else
                                                    <span>{{ $collectorStateLabel($state) }}</span>
                                                @endif
                                                @if ($rowsWritten > 0)
                                                    <span class="ml-1">· {{ number_format($rowsWritten, 0, ',', '.') }} {{ $tr ? 'kayıt işlendi' : 'records processed' }}</span>
                                                @endif
                                            @else
                                                {{ $tr ? 'Henüz çalıştırılmadı' : 'Not run yet' }}
                                            @endif
                                        </div>
                                        <div class="text-gray-400 sm:text-right">
                                            {{ $datasetRun?->last_activity_at?->diffForHumans() ?: '—' }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                            <div class="border-b border-gray-200 px-4 py-3 sm:px-5 dark:border-gray-800">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Site Sağlığı' : 'Site Health' }}</h3>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            {{ $tr ? 'HTTP başarılı olsa bile kritik WordPress, uygulama hata ekranları ve soft-404 sonuçları burada sorun olarak yükseltilir.' : 'WordPress/application error pages and soft 404s are elevated here even when HTTP itself succeeds.' }}
                                        </p>
                                    </div>
                                    @if ($siteHealth['available'] && $siteHealth['run_scoped'])
                                        <span class="text-[10px] font-medium text-gray-400">{{ $tr ? 'Yalnızca son çekim' : 'Latest run only' }}</span>
                                    @endif
                                </div>
                            </div>

                            @if (! $siteHealth['available'])
                                <div class="px-4 py-6 text-sm text-gray-500 sm:px-5 dark:text-gray-400">{{ $tr ? 'Site sağlığı verisi henüz okunamıyor.' : 'Site health data is not available yet.' }}</div>
                            @elseif ((int) $siteHealth['total'] === 0)
                                <div class="flex items-start gap-3 px-4 py-5 sm:px-5">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m5 12 4 4L19 6"></path></svg>
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Son çekimde sorun bulunmadı' : 'No issues found in the latest collection' }}</p>
                                        <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $tr ? 'Tarama sorunları için 0 kayıt iyi sonuç olarak yorumlanır.' : 'Zero crawl-issue records is treated as a healthy result.' }}</p>
                                    </div>
                                </div>
                            @else
                                <div class="grid divide-y divide-gray-200 border-b border-gray-200 sm:grid-cols-4 sm:divide-x sm:divide-y-0 dark:divide-gray-800 dark:border-gray-800">
                                    @foreach ([
                                        ['label' => $tr ? 'Kritik' : 'Critical', 'value' => $siteHealth['critical'], 'class' => 'text-rose-600 dark:text-rose-400'],
                                        ['label' => $tr ? 'Yüksek' : 'High', 'value' => $siteHealth['high'], 'class' => 'text-orange-600 dark:text-orange-400'],
                                        ['label' => $tr ? 'Yönlendirme' : 'Redirect', 'value' => $siteHealth['redirect'], 'class' => 'text-amber-600 dark:text-amber-400'],
                                        ['label' => $tr ? 'SEO eksikliği' : 'SEO issues', 'value' => $siteHealth['seo'], 'class' => 'text-gray-900 dark:text-white'],
                                    ] as $healthMetric)
                                        <div class="px-4 py-3 text-center">
                                            <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ $healthMetric['label'] }}</p>
                                            <p class="mt-1 text-xl font-semibold {{ $healthMetric['class'] }}">{{ number_format((int) $healthMetric['value'], 0, ',', '.') }}</p>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="divide-y divide-gray-100 dark:divide-gray-800">
                                    @foreach ($siteHealth['issues'] as $issue)
                                        @php
                                            $issueTone = match ($issue['severity']) {
                                                'critical' => 'bg-rose-500',
                                                'high' => 'bg-orange-500',
                                                'medium' => 'bg-amber-400',
                                                default => 'bg-gray-300 dark:bg-gray-600',
                                            };
                                        @endphp
                                        <div class="px-4 py-4 sm:px-5">
                                            <div class="flex items-start gap-3">
                                                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $issueTone }}"></span>
                                                <div class="min-w-0 flex-1">
                                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                                                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $issue['title'] }}</p>
                                                        <span class="text-[10px] font-medium uppercase text-gray-400">{{ $issue['severity'] }}</span>
                                                    </div>
                                                    <p class="mt-1 break-all text-xs font-medium text-gray-600 dark:text-gray-300">{{ $issue['url'] }}</p>
                                                    <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $issue['message'] }}</p>
                                                    <div class="mt-2 flex flex-wrap items-center gap-3">
                                                        @if ($issue['url'] !== '')
                                                            <a href="{{ $issue['url'] }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $tr ? 'Sayfayı aç' : 'Open page' }}</a>
                                                        @endif
                                                        <details class="text-xs text-gray-500 dark:text-gray-400">
                                                            <summary class="cursor-pointer font-medium hover:text-gray-700 dark:hover:text-gray-200">{{ $tr ? 'Teknik ayrıntı' : 'Technical detail' }}</summary>
                                                            <div class="mt-2 rounded-md bg-gray-50 px-3 py-2 font-mono text-[10px] dark:bg-white/[0.03]">{{ $issue['code'] }} · {{ $issue['severity'] }}</div>
                                                        </details>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </section>

                        <section>
                            <div class="mb-3">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Toplanan Veriler' : 'Collected Data' }}</h3>
                                <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">
                                    {{ $tr ? 'Ham tablo yapısı yerine veri kaynaklarını ve sonuçlarını operatör gözüyle gösterir. Teknik şema gerektiğinde kapalı ayrıntı bölümünden açılabilir.' : 'Shows sources and outcomes for operators instead of raw storage structure. Technical schema remains available only in a collapsed detail section.' }}
                                </p>
                            </div>

                            <div class="space-y-3">
                                @foreach ($selectedRow['data_sources'] as $source)
                                    @php
                                        $sourceGroups = collect($source['datasets']);
                                        $expandable = $source['key'] !== 'google' && $sourceGroups->isNotEmpty();
                                        $sourceStatusClass = $statusClasses($source['state']);
                                        $sourceDescription = $source['key'] === 'public_web'
                                            ? ($tr
                                                ? 'Siteye eklenti kurmadan URL/domain üzerinden toplanan web verileri. Site taraması, HTML ve SSL doğrudan çalışır; PageSpeed için harici API bağlantısı gerekir.'
                                                : 'Website data collected without installing a plugin. Crawl, HTML and SSL work directly; PageSpeed requires an external API connection.')
                                            : $source['description'];
                                    @endphp
                                    <article wire:key="source-{{ $asset->id }}-{{ $source['key'] }}" x-data="{ open: {{ $source['key'] === 'public_web' ? 'true' : 'false' }} }" class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                        <button type="button" @if ($expandable) @click="open = !open" @endif class="flex w-full items-start gap-3 px-4 py-4 text-left sm:px-5">
                                            <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/[0.05] dark:text-gray-400">
                                                @if ($source['key'] === 'google')
                                                    <span class="text-xs font-bold">G</span>
                                                @elseif ($source['key'] === 'public_web')
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"></path></svg>
                                                @else
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="4"></rect><path d="M8 12h8M12 8v8"></path></svg>
                                                @endif
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                                    <div>
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</h4>
                                                            <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-semibold ring-1 ring-inset {{ $sourceStatusClass }}">{{ $source['status_label'] }}</span>
                                                        </div>
                                                        <p class="mt-1 max-w-3xl text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $sourceDescription }}</p>
                                                    </div>
                                                    <div class="flex shrink-0 items-center gap-3">
                                                        @if ($source['key'] === 'public_web')
                                                            <div class="text-right">
                                                                <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $source['completed'] }}/{{ $source['total'] }} {{ $tr ? 'veri grubu' : 'data groups' }}</p>
                                                                <p class="mt-0.5 text-[10px] text-gray-400">%{{ $source['coverage_percent'] }} {{ $tr ? 'kapsam' : 'coverage' }}</p>
                                                            </div>
                                                        @endif
                                                        @if ($expandable)
                                                            <svg class="h-4 w-4 text-gray-400 transition" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"></path></svg>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                                                    {{ $tr ? 'Bağlantı:' : 'Connection:' }} <strong class="font-medium text-gray-700 dark:text-gray-300">{{ $source['connection_label'] }}</strong>
                                                </div>
                                            </div>
                                        </button>

                                        @if ($source['key'] === 'google')
                                            <div class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 sm:px-5 dark:border-gray-800 dark:bg-white/[0.02]">
                                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                                    <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $tr ? 'GA4 ve Search Console bu Website-native veri çekiminin dışında, mevcut Google entegrasyon alanında yönetilir.' : 'GA4 and Search Console are managed in the existing Google integration area, outside this Website-native collection.' }}</p>
                                                    <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-600 hover:underline dark:text-brand-400">{{ $tr ? 'Google kaynaklarını yönet' : 'Manage Google sources' }}</a>
                                                </div>
                                            </div>
                                        @endif

                                        @if ($expandable)
                                            <div x-show="open" x-collapse class="border-t border-gray-200 dark:border-gray-800">
                                                <div class="bg-gray-50 px-4 py-2.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:px-5 dark:bg-white/[0.025] dark:text-gray-400">{{ $tr ? 'Veri grupları' : 'Data groups' }}</div>
                                                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                                                    @foreach ($sourceGroups as $dataset)
                                                        @php
                                                            $effectiveState = $effectiveDatasetState($dataset);
                                                            $preview = $dataset['preview'];
                                                            $processedRows = (int) $dataset['processed_rows'];
                                                        @endphp
                                                        <div wire:key="data-group-{{ $asset->id }}-{{ $source['key'] }}-{{ $dataset['id'] }}" x-data="{ open: false }" class="bg-white dark:bg-gray-900">
                                                            <button type="button" @click="open = !open" class="flex w-full items-start gap-3 px-4 py-4 text-left sm:px-5">
                                                                <div class="mt-1 flex h-5 w-5 shrink-0 items-center justify-center">
                                                                    @if ($effectiveState === 'completed')
                                                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"><svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m5 12 4 4L19 6"></path></svg></span>
                                                                    @elseif ($effectiveState === 'running')
                                                                        <span class="h-4 w-4 animate-spin rounded-full border-2 border-blue-200 border-t-blue-600 dark:border-blue-900 dark:border-t-blue-300"></span>
                                                                    @elseif (in_array($effectiveState, ['partial', 'attention', 'connection_required', 'needs_setup'], true))
                                                                        <span class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">!</span>
                                                                    @elseif ($effectiveState === 'failed')
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
                                                                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset {{ $statusClasses($effectiveState) }}">
                                                                                    @if ($effectiveState === 'attention' && $dataset['state'] === 'completed')
                                                                                        {{ $tr ? 'Kontrol gerekli' : 'Check required' }}
                                                                                    @else
                                                                                        {{ $dataset['status_label'] }}
                                                                                    @endif
                                                                                </span>
                                                                            </div>
                                                                            <p class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $datasetOutcome($dataset) }}</p>
                                                                        </div>
                                                                        <div class="flex shrink-0 items-center gap-4">
                                                                            <div class="text-right">
                                                                                @if ($dataset['id'] === 'website_crawl_issue_snapshot' && $dataset['state'] === 'completed' && $processedRows === 0)
                                                                                    <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ $tr ? 'Sorun bulunmadı' : 'No issues found' }}</p>
                                                                                @elseif ($dataset['state'] === 'connection_required')
                                                                                    <p class="text-xs font-semibold text-amber-700 dark:text-amber-300">{{ $tr ? 'Bağlantı gerekli' : 'Connection required' }}</p>
                                                                                @else
                                                                                    <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ number_format($processedRows, 0, ',', '.') }} {{ $tr ? 'son çekim' : 'latest run' }}</p>
                                                                                @endif
                                                                            </div>
                                                                            <svg class="h-4 w-4 text-gray-400 transition" :class="open ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"></path></svg>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </button>

                                                            <div x-show="open" x-collapse class="border-t border-gray-100 bg-gray-50/60 p-4 sm:p-5 dark:border-gray-800 dark:bg-white/[0.018]">
                                                                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_220px]">
                                                                    <div>
                                                                        <p class="text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $tr ? 'Sonuç' : 'Result' }}</p>
                                                                        <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $datasetOutcome($dataset) }}</p>
                                                                    </div>
                                                                    <div class="rounded-lg border border-gray-200 bg-white px-3 py-3 dark:border-gray-800 dark:bg-gray-900">
                                                                        <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">{{ $tr ? 'Son güncelleme' : 'Last updated' }}</p>
                                                                        <p class="mt-1 text-xs font-semibold text-gray-900 dark:text-white">{{ $dataset['last_collected_at']?->diffForHumans() ?: '—' }}</p>
                                                                    </div>
                                                                </div>

                                                                <div class="mt-4">
                                                                    <p class="mb-2 text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $tr ? 'Gerçek veri örneği' : 'Real data sample' }}</p>
                                                                    @if ($preview['state'] === 'available')
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
                                                                                                <td class="max-w-[260px] px-3 py-2.5 align-top text-gray-700 dark:text-gray-300"><span class="line-clamp-3 break-words">{{ $record[$column['name']] ?? '—' }}</span></td>
                                                                                            @endforeach
                                                                                        </tr>
                                                                                    @endforeach
                                                                                </tbody>
                                                                            </table>
                                                                        </div>
                                                                    @elseif ($dataset['id'] === 'website_crawl_issue_snapshot' && $dataset['state'] === 'completed' && $processedRows === 0)
                                                                        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-4 text-xs text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-300">{{ $tr ? 'Sorun bulunmadığı için gösterilecek sorun kaydı yok.' : 'There are no issue records to show because no crawl issues were found.' }}</div>
                                                                    @else
                                                                        <div class="rounded-lg border border-gray-200 bg-white px-4 py-4 text-xs leading-5 text-gray-500 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400">{{ $tr ? 'Bu veri grubu için gösterilebilir gerçek kayıt henüz yok.' : 'No previewable real records are available for this data group yet.' }}</div>
                                                                    @endif
                                                                </div>

                                                                <details class="mt-4 rounded-lg border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                                                                    <summary class="cursor-pointer px-4 py-3 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.025]">{{ $tr ? 'Teknik Ayrıntılar' : 'Technical Details' }}</summary>
                                                                    <div class="border-t border-gray-200 px-4 py-4 dark:border-gray-800">
                                                                        <div class="grid gap-2 text-[11px] text-gray-500 sm:grid-cols-2 dark:text-gray-400">
                                                                            <div><span class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Veri grubu kimliği' : 'Data group ID' }}:</span> <span class="font-mono">{{ $dataset['id'] }}</span></div>
                                                                            <div><span class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Fiziksel tablo' : 'Physical table' }}:</span> <span class="font-mono">{{ $dataset['table'] ?: '—' }}</span></div>
                                                                            <div><span class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Veri havuzundaki toplam' : 'Total in data pool' }}:</span> {{ number_format((int) $dataset['current_rows'], 0, ',', '.') }}</div>
                                                                            <div><span class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Sistem alanı' : 'System fields' }}:</span> {{ $dataset['system_field_count'] }}</div>
                                                                        </div>
                                                                        @if (! empty($dataset['fields']))
                                                                            <div class="mt-3 flex flex-wrap gap-1.5">
                                                                                @foreach ($dataset['fields'] as $field)
                                                                                    <span class="rounded-md bg-gray-50 px-2 py-1 font-mono text-[10px] text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-white/[0.03] dark:text-gray-400 dark:ring-gray-700">{{ $field['name'] }} · {{ $field['type'] }}</span>
                                                                                @endforeach
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                </details>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @elseif ($source['key'] === 'site_connector' && ! $selectedRow['wordpress_detected'])
                                            <div class="border-t border-gray-100 bg-gray-50/60 px-4 py-3 text-xs leading-5 text-gray-500 sm:px-5 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-400">{{ $tr ? 'Bu web sitesi için desteklenen bir CMS bağlayıcısı henüz tanımlı değil.' : 'No supported CMS connector is defined for this website yet.' }}</div>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                            <div class="border-b border-gray-200 px-4 py-3 sm:px-5 dark:border-gray-800">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Son Veri Çekimleri' : 'Recent Collections' }}</h3>
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $tr ? 'Çekim sonucu ile veri kapsamı ayrı kavramlar olarak tutulur.' : 'Run result and data coverage remain separate concepts.' }}</p>
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
                                                'queued', 'running', 'retrying', 'cancellation_requested' => 'running',
                                                default => 'neutral',
                                            };
                                        @endphp
                                        <div class="grid gap-2 px-4 py-3 text-xs sm:grid-cols-[100px_150px_minmax(0,1fr)_140px] sm:items-center sm:px-5">
                                            <div class="font-medium text-gray-700 dark:text-gray-300">{{ $tr ? 'Çekim' : 'Run' }} #{{ $item['id'] }}</div>
                                            <div><span class="inline-flex rounded-full px-2 py-1 text-[10px] font-semibold ring-1 ring-inset {{ $statusClasses($historyState) }}">{{ $item['status_label'] }}</span></div>
                                            <div class="text-gray-500 dark:text-gray-400">
                                                {{ $item['datasets_completed'] }}/{{ $item['datasets_total'] }} {{ $tr ? 'toplama işi tamamlandı' : 'collection jobs completed' }}
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
