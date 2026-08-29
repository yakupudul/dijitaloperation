<div class="space-y-6">
    @php
        $tr = app()->getLocale() === 'tr';
        $sourceCards = [
            [
                'key' => 'crawl',
                'title' => 'Public Website Crawl',
                'state' => $tr ? 'Hazır' : 'Ready',
                'tone' => 'success',
                'description' => $tr
                    ? 'Aynı domain içindeki URL’leri kuyruk tabanlı tarar; harici hesap bağlantısı istemez.'
                    : 'Queue-based crawl of same-domain URLs without requiring an external account.',
            ],
            [
                'key' => 'html',
                'title' => 'HTTP / HTML Intelligence',
                'state' => $tr ? 'Hazır' : 'Ready',
                'tone' => 'success',
                'description' => $tr
                    ? 'Status, redirect, title, meta, heading, canonical, noindex, içerik ve link graph verisi.'
                    : 'Status, redirect, title, meta, headings, canonical, noindex, content and link graph evidence.',
            ],
            [
                'key' => 'tls',
                'title' => 'SSL / TLS Infrastructure',
                'state' => $tr ? 'Hazır' : 'Ready',
                'tone' => 'success',
                'description' => $tr
                    ? 'Website altyapı ve sertifika snapshot’larını production Collection Engine’e yazar.'
                    : 'Writes Website infrastructure and certificate snapshots to the production Collection Engine.',
            ],
            [
                'key' => 'speed',
                'title' => 'PageSpeed Insights',
                'state' => $tr ? 'Bağlantı gerekli' : 'Connection required',
                'tone' => 'neutral',
                'description' => $tr
                    ? 'Lighthouse performans ölçümleri için asset üzerinde PageSpeed API bağlantısı gerekir.'
                    : 'Requires a PageSpeed API connection on the asset for Lighthouse performance measurements.',
            ],
        ];
    @endphp

    <section class="overflow-hidden rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <div class="relative overflow-hidden px-5 py-6 sm:px-6 lg:px-8 lg:py-8">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-brand-500/10 blur-3xl"></div>
            <div class="absolute bottom-0 right-1/3 h-28 w-28 rounded-full bg-blue-500/10 blur-3xl"></div>

            <div class="relative flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between">
                <div class="max-w-4xl">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <a href="{{ route('operator.integrations') }}" wire:navigate class="font-medium text-brand-600 hover:underline">
                            {{ $tr ? 'Entegrasyonlar' : 'Integrations' }}
                        </a>
                        <span class="text-gray-300">/</span>
                        <span class="text-gray-500 dark:text-gray-400">Website</span>
                    </div>

                    <div class="mt-4 flex items-start gap-4">
                        <div class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600 sm:flex dark:bg-brand-500/15 dark:text-brand-400">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="9"></circle>
                                <path d="M3 12h18"></path>
                                <path d="M12 3a15 15 0 0 1 0 18"></path>
                                <path d="M12 3a15 15 0 0 0 0 18"></path>
                            </svg>
                        </div>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-400">
                                Website Data Operations
                            </p>
                            <h1 class="mt-1 text-2xl font-bold tracking-tight text-gray-900 sm:text-3xl dark:text-white">
                                {{ $tr ? 'Web sitelerinin veri toplama merkezi' : 'Website data collection center' }}
                            </h1>
                            <p class="mt-3 max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                                {{ $tr
                                    ? 'Public crawl, teknik HTML sinyalleri, SSL/TLS ve yapılandırılmışsa PageSpeed verilerini tek yerden yönetin. Her kart size neyin hazır olduğunu, son collection sonucunu ve sonraki en iyi adımı gösterir.'
                                    : 'Manage public crawl, technical HTML signals, SSL/TLS and configured PageSpeed data from one place. Each card shows readiness, the latest collection result and the next best action.' }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 flex-wrap gap-2">
                    <x-ta.button :href="route('operator.assets')" size="sm" variant="outline">
                        {{ $tr ? 'Website Asset’leri' : 'Website Assets' }}
                    </x-ta.button>
                    <x-ta.button :href="route('operator.asset.create')" size="sm">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                        {{ $tr ? 'Website Ekle' : 'Add Website' }}
                    </x-ta.button>
                </div>
            </div>

            <div class="relative mt-7 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    ['label' => $tr ? 'Toplam Website' : 'Total Websites', 'value' => $stats['total'], 'detail' => $tr ? 'portföyde' : 'in portfolio', 'icon' => 'globe'],
                    ['label' => 'Collect Ready', 'value' => $stats['collect_ready'], 'detail' => $tr ? 'URL/domain hazır' : 'URL/domain ready', 'icon' => 'check'],
                    ['label' => $tr ? 'PageSpeed Bağlı' : 'PageSpeed Connected', 'value' => $stats['pagespeed_connected'], 'detail' => $tr ? 'Lighthouse hazır' : 'Lighthouse ready', 'icon' => 'speed'],
                    ['label' => 'WordPress', 'value' => $stats['wordpress_detected'], 'detail' => $tr ? 'CMS algılandı' : 'CMS detected', 'icon' => 'wp'],
                    ['label' => $tr ? 'Son 7 Gün' : 'Last 7 Days', 'value' => $stats['recently_collected'], 'detail' => $tr ? 'collection çalıştı' : 'collected', 'icon' => 'clock'],
                ] as $metric)
                    <div class="rounded-xl bg-gray-50/80 p-4 ring-1 ring-inset ring-gray-200/80 dark:bg-white/[0.025] dark:ring-gray-800">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</p>
                                <p class="mt-1 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $metric['value'] }}</p>
                            </div>
                            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-gray-500 shadow-sm ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-400 dark:ring-gray-700">
                                @switch($metric['icon'])
                                    @case('check')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"></path></svg>
                                        @break
                                    @case('speed')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 14a8 8 0 1 1 16 0"></path><path d="m12 14 4-4"></path><path d="M6 18h12"></path></svg>
                                        @break
                                    @case('wp')
                                        <span class="text-xs font-bold">WP</span>
                                        @break
                                    @case('clock')
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>
                                        @break
                                    @default
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"></path></svg>
                                @endswitch
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-gray-400">{{ $metric['detail'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    @if ($message !== '')
        <div @class([
            'rounded-xl border px-4 py-3 text-sm shadow-sm',
            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-300' => $messageTone === 'error',
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-300' => $messageTone === 'success',
            'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-300' => ! in_array($messageTone, ['error', 'success'], true),
        ])>{{ $message }}</div>
    @endif

    <section class="rounded-2xl bg-white p-5 ring-1 ring-inset ring-gray-200 sm:p-6 dark:bg-gray-900 dark:ring-gray-800">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-gray-400">{{ $tr ? 'Veri kapsamı' : 'Data coverage' }}</p>
                <h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Website veri aileleri' : 'Website data families' }}</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $tr ? 'Production collection akışına dahil olan temel teknik veri kaynakları.' : 'Core technical sources included in the production collection flow.' }}
                </p>
            </div>
            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                {{ $tr ? 'Production Engine aktif' : 'Production Engine active' }}
            </span>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($sourceCards as $source)
                <article class="group rounded-xl border border-gray-200 bg-gray-50/60 p-4 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-sm dark:border-gray-800 dark:bg-white/[0.02] dark:hover:bg-white/[0.04]">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-gray-500 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-400 dark:ring-gray-700">
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
                        <span @class([
                            'whitespace-nowrap rounded-full px-2.5 py-1 text-[11px] font-semibold',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300' => $source['tone'] === 'success',
                            'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300' => $source['tone'] !== 'success',
                        ])>{{ $source['state'] }}</span>
                    </div>
                    <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-white">{{ $source['title'] }}</h3>
                    <p class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $source['description'] }}</p>
                </article>
            @endforeach
        </div>
    </section>

    <section class="flex flex-col gap-4 rounded-2xl border border-amber-200 bg-amber-50/60 p-5 sm:flex-row sm:items-start sm:justify-between dark:border-amber-900/50 dark:bg-amber-950/20">
        <div class="flex gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900/60">
                <span class="text-xs font-bold">WP</span>
            </div>
            <div class="max-w-4xl">
                <h2 class="text-sm font-semibold text-amber-950 dark:text-amber-100">
                    {{ $tr ? 'WordPress Connector durumu' : 'WordPress Connector status' }}
                </h2>
                <p class="mt-1 text-sm leading-6 text-amber-800 dark:text-amber-300">
                    {{ $tr
                        ? 'WordPress REST envanter motoru kod tabanında mevcut. Ancak authenticated Site Connector eklentisi henüz production bağlantı değil; demo connector bu ekranda gerçek bağlantı gibi gösterilmez.'
                        : 'The WordPress REST inventory engine exists in the codebase. The authenticated Site Connector plugin is not production-ready yet, so the demo connector is never presented here as a real connection.' }}
                </p>
            </div>
        </div>
        <span class="inline-flex w-fit shrink-0 items-center rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
            {{ $tr ? 'Henüz aktif değil' : 'Not active yet' }}
        </span>
    </section>

    <section class="space-y-4">
        <div class="rounded-2xl bg-white p-4 ring-1 ring-inset ring-gray-200 sm:p-5 dark:bg-gray-900 dark:ring-gray-800">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <div class="min-w-0 flex-1">
                    <label for="website-search" class="sr-only">{{ $tr ? 'Web sitelerinde ara' : 'Search websites' }}</label>
                    <div class="relative max-w-xl">
                        <svg class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
                        </svg>
                        <input id="website-search" type="search" wire:model.live.debounce.250ms="search"
                            placeholder="{{ $tr ? 'Website adı, domain, marka veya müşteri ara…' : 'Search website, domain, brand or customer…' }}"
                            class="h-11 w-full rounded-xl border border-gray-200 bg-gray-50 pl-10 pr-4 text-sm text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-brand-300 focus:bg-white focus:ring-4 focus:ring-brand-500/5 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200 dark:focus:border-brand-700 dark:focus:bg-white/[0.04]">
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ($filters as $option)
                        <button type="button" wire:click="$set('filter', '{{ $option['key'] }}')"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-xs font-semibold transition ring-1 ring-inset',
                                'bg-brand-500 text-white ring-brand-500 shadow-sm' => $filter === $option['key'],
                                'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.04]' => $filter !== $option['key'],
                            ])>
                            {{ $option['label'] }}
                            <span @class([
                                'rounded-md px-1.5 py-0.5 text-[10px]',
                                'bg-white/20 text-white' => $filter === $option['key'],
                                'bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400' => $filter !== $option['key'],
                            ])>{{ $option['count'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($stats['total'] === 0)
            <div class="rounded-2xl bg-white px-6 py-14 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600 dark:text-brand-400">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18"></path></svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Henüz Website Digital Asset yok' : 'No Website Digital Asset yet' }}</h2>
                <p class="mx-auto mt-2 max-w-2xl text-sm leading-6 text-gray-500 dark:text-gray-400">
                    {{ $tr ? 'Müşteri ve marka altında ilk Website asset’ini oluşturun. URL veya domain tanımlandığında public collection otomatik olarak hazır hale gelir.' : 'Create the first Website asset under a customer and brand. Public collection becomes available when a URL or domain is defined.' }}
                </p>
                <div class="mt-5">
                    <x-ta.button :href="route('operator.asset.create')" size="sm">{{ $tr ? 'Website Ekle' : 'Add Website' }}</x-ta.button>
                </div>
            </div>
        @elseif ($rows->isEmpty())
            <div class="rounded-2xl bg-white px-6 py-10 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $tr ? 'Bu filtreyle eşleşen Website yok' : 'No website matches this filter' }}</h2>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $tr ? 'Arama metnini veya seçili filtreyi değiştirin.' : 'Change the search term or selected filter.' }}</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($rows as $row)
                    @php
                        $asset = $row['asset'];
                        $run = $row['run'];
                        $status = $run?->status?->value;
                        $targetUrl = $asset->primary_url ?: $asset->domain;
                        $stateMeta = match ($row['overall_state']) {
                            'ready' => ['label' => $tr ? 'Hazır' : 'Ready', 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:ring-emerald-900/50'],
                            'partial' => ['label' => 'Partial', 'class' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:ring-amber-900/50'],
                            'running' => ['label' => $tr ? 'Çalışıyor' : 'Running', 'class' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950/30 dark:text-blue-300 dark:ring-blue-900/50'],
                            'attention' => ['label' => $tr ? 'İnceleme gerekli' : 'Needs attention', 'class' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/30 dark:text-rose-300 dark:ring-rose-900/50'],
                            default => ['label' => $tr ? 'Kurulum gerekli' : 'Setup required', 'class' => 'bg-gray-100 text-gray-600 ring-gray-200 dark:bg-white/[0.06] dark:text-gray-300 dark:ring-gray-700'],
                        };
                    @endphp

                    <article wire:key="website-integration-{{ $asset->id }}" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-inset ring-gray-200 transition hover:shadow-md dark:bg-gray-900 dark:ring-gray-800">
                        <div class="p-5 sm:p-6">
                            <div class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-500/10 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M3 12h18M12 3a15 15 0 0 1 0 18"></path></svg>
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h2 class="truncate text-lg font-semibold text-gray-900 dark:text-white">{{ $asset->name }}</h2>
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $stateMeta['class'] }}">
                                                    {{ $stateMeta['label'] }}
                                                </span>
                                                @if ($asset->cms)
                                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-medium text-gray-600 dark:bg-white/[0.06] dark:text-gray-300">
                                                        {{ strtoupper((string) $asset->cms) }}
                                                    </span>
                                                @endif
                                            </div>
                                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                                {{ $asset->brand?->customer?->name ?: '—' }}
                                                <span class="mx-1 text-gray-300">·</span>
                                                {{ $asset->brand?->name ?: '—' }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                                        <a @if($targetUrl) href="{{ $targetUrl }}" target="_blank" rel="noopener noreferrer" @endif
                                            class="inline-flex min-w-0 items-center gap-2 font-medium text-gray-700 hover:text-brand-600 dark:text-gray-300 dark:hover:text-brand-400">
                                            <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.1 1.1"></path><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.1-1.1"></path></svg>
                                            <span class="break-all">{{ $targetUrl ?: ($tr ? 'URL / domain tanımlı değil' : 'URL / domain not set') }}</span>
                                        </a>
                                    </div>
                                </div>

                                <div class="flex shrink-0 flex-wrap gap-2">
                                    <button type="button"
                                        wire:click="collectNow({{ $asset->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="collectNow({{ $asset->id }})"
                                        @disabled(! $row['collectable'])
                                        title="{{ ! $row['collectable'] ? ($tr ? 'Önce URL veya domain ekleyin' : 'Add a URL or domain first') : '' }}"
                                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-45">
                                        <svg wire:loading.remove wire:target="collectNow({{ $asset->id }})" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 12a8 8 0 1 1-2.34-5.66"></path><path d="M20 4v6h-6"></path></svg>
                                        <span wire:loading.remove wire:target="collectNow({{ $asset->id }})">{{ $tr ? 'Veri Çek' : 'Collect Data' }}</span>
                                        <span wire:loading wire:target="collectNow({{ $asset->id }})">{{ $tr ? 'Kuyruğa alınıyor…' : 'Queueing…' }}</span>
                                    </button>
                                    <x-ta.button :href="route('operator.asset.sources', ['assetId' => $asset->id])" size="sm" variant="outline">
                                        {{ $tr ? 'Kaynakları Yönet' : 'Manage Sources' }}
                                    </x-ta.button>
                                    <x-ta.button :href="route('operator.website', ['assetId' => $asset->id])" size="sm" variant="outline">
                                        {{ $tr ? 'Website’i Aç' : 'Open Website' }}
                                    </x-ta.button>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                <div class="rounded-xl bg-gray-50 p-3.5 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.025] dark:ring-gray-800">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Public Crawl + HTML</p>
                                        <span class="h-2 w-2 rounded-full {{ $row['collectable'] ? 'bg-emerald-500' : 'bg-amber-400' }}"></span>
                                    </div>
                                    <p class="mt-2 text-sm font-semibold {{ $row['collectable'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                                        {{ $row['collectable'] ? ($tr ? 'Hazır' : 'Ready') : ($tr ? 'URL gerekli' : 'URL required') }}
                                    </p>
                                </div>

                                <div class="rounded-xl bg-gray-50 p-3.5 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.025] dark:ring-gray-800">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">SSL / TLS</p>
                                        <span class="h-2 w-2 rounded-full {{ $row['collectable'] ? 'bg-emerald-500' : 'bg-amber-400' }}"></span>
                                    </div>
                                    <p class="mt-2 text-sm font-semibold {{ $row['collectable'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                                        {{ $row['collectable'] ? ($tr ? 'Hazır' : 'Ready') : ($tr ? 'Domain gerekli' : 'Domain required') }}
                                    </p>
                                </div>

                                <div class="rounded-xl bg-gray-50 p-3.5 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.025] dark:ring-gray-800">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">PageSpeed</p>
                                        <span class="h-2 w-2 rounded-full {{ $row['page_speed_ready'] ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                                    </div>
                                    <p class="mt-2 text-sm font-semibold {{ $row['page_speed_ready'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400' }}">
                                        {{ $row['page_speed_ready'] ? ($tr ? 'Bağlı' : 'Connected') : ($tr ? 'API bağlantısı gerekli' : 'API connection required') }}
                                    </p>
                                </div>

                                <div class="rounded-xl bg-gray-50 p-3.5 ring-1 ring-inset ring-gray-100 dark:bg-white/[0.025] dark:ring-gray-800">
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-400">WordPress REST</p>
                                        <span class="h-2 w-2 rounded-full {{ $row['wordpress_detected'] ? 'bg-amber-400' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                                    </div>
                                    <p class="mt-2 text-sm font-semibold {{ $row['wordpress_detected'] ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">
                                        {{ $row['wordpress_detected'] ? ($tr ? 'CMS algılandı · deferred' : 'CMS detected · deferred') : ($tr ? 'CMS’e bağlı' : 'Depends on CMS') }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-100 bg-gray-50/60 px-5 py-4 sm:px-6 dark:border-gray-800 dark:bg-white/[0.015]">
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.8fr)] lg:items-center">
                                <div>
                                    @if ($run)
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <div class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                <span class="font-semibold text-gray-700 dark:text-gray-300">Collection #{{ $run->id }}</span>
                                                <span>·</span>
                                                <span>{{ strtoupper((string) $status) }}</span>
                                                <span>·</span>
                                                <span>{{ $run->datasets_completed }}/{{ $run->datasets_total }} {{ $tr ? 'dataset' : 'datasets' }}</span>
                                                @if ((int) $run->datasets_failed > 0)
                                                    <span>·</span>
                                                    <span class="font-medium text-rose-600 dark:text-rose-400">{{ $run->datasets_failed }} {{ $tr ? 'başarısız' : 'failed' }}</span>
                                                @endif
                                                <span>·</span>
                                                <span>{{ $run->updated_at?->diffForHumans() }}</span>
                                            </div>
                                            <span class="text-xs font-semibold text-gray-600 dark:text-gray-300">%{{ $row['progress'] }}</span>
                                        </div>
                                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
                                            <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ $row['progress'] }}%"></div>
                                        </div>
                                    @else
                                        <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                            <span class="h-2 w-2 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                            <span>{{ $tr ? 'Henüz production Website collection çalıştırılmadı.' : 'No production Website collection has been run yet.' }}</span>
                                        </div>
                                    @endif
                                </div>

                                <div class="rounded-xl bg-white px-4 py-3 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                                    <div class="flex items-start gap-3">
                                        <div class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-500/10 text-brand-600 dark:text-brand-400">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12c.5.5 1 1.5 1 2h6c0-.5.5-1.5 1-2a7 7 0 0 0-4-12z"></path></svg>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-gray-400">{{ $tr ? 'Sonraki en iyi adım' : 'Next best action' }}</p>
                                            <p class="mt-1 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $row['next_action'] }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</div>
