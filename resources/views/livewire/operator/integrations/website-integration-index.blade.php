<div class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <a href="{{ route('operator.integrations') }}" wire:navigate class="font-medium text-brand-600 hover:underline">
                    {{ app()->getLocale() === 'tr' ? 'Entegrasyonlar' : 'Integrations' }}
                </a>
                <span class="text-gray-300">/</span>
                <span class="text-gray-500">Website</span>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                {{ app()->getLocale() === 'tr' ? 'Website Veri Kaynakları' : 'Website Data Sources' }}
            </h1>
            <p class="mt-2 max-w-4xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                {{ app()->getLocale() === 'tr'
                    ? 'Website Digital Asset’lerini buradan yönetebilir, public crawl, HTTP/HTML teknik analiz ve SSL/TLS verilerini gerçek Collection Engine üzerinden toplayabilirsiniz. PageSpeed, kendi bağlantısı hazır olduğunda aynı collection akışına katılır.'
                    : 'Manage Website Digital Assets here and collect public crawl, HTTP/HTML technical analysis and SSL/TLS data through the production Collection Engine. PageSpeed joins the same collection flow when its connection is configured.' }}
            </p>
        </div>

        <x-ta.button :href="route('operator.asset.create')" size="sm">
            {{ app()->getLocale() === 'tr' ? 'Website Ekle' : 'Add Website' }}
        </x-ta.button>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-xl border px-4 py-3 text-sm',
            'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-300' => $messageTone === 'error',
            'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-emerald-300' => $messageTone === 'success',
            'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/20 dark:text-blue-300' => ! in_array($messageTone, ['error', 'success'], true),
        ])>{{ $message }}</div>
    @endif

    <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @php
            $sourceCards = app()->getLocale() === 'tr'
                ? [
                    ['title' => 'Public Website Crawl', 'state' => 'Hazır', 'description' => 'Aynı site içindeki URL’leri kuyruk tabanlı olarak tarar. Harici hesap bağlantısı gerekmez.'],
                    ['title' => 'HTTP / HTML Intelligence', 'state' => 'Hazır', 'description' => 'Durum kodu, redirect, title, description, H1, canonical, noindex, içerik ve link graph verilerini toplar.'],
                    ['title' => 'SSL / TLS Infrastructure', 'state' => 'Hazır', 'description' => 'Website altyapı ve TLS snapshot verilerini Collection Engine’e yazar.'],
                    ['title' => 'PageSpeed Insights', 'state' => 'Bağlantı gerekli', 'description' => 'Lighthouse performans verileri için Website asset üzerinde etkin PageSpeed API bağlantısı gerekir.'],
                ]
                : [
                    ['title' => 'Public Website Crawl', 'state' => 'Ready', 'description' => 'Crawls same-site URLs with the queue-based crawler. No external account binding is required.'],
                    ['title' => 'HTTP / HTML Intelligence', 'state' => 'Ready', 'description' => 'Collects status, redirects, title, description, H1, canonical, noindex, content and link graph data.'],
                    ['title' => 'SSL / TLS Infrastructure', 'state' => 'Ready', 'description' => 'Writes Website infrastructure and TLS snapshots into the Collection Engine.'],
                    ['title' => 'PageSpeed Insights', 'state' => 'Connection required', 'description' => 'Lighthouse performance data requires an enabled PageSpeed API connection on the Website asset.'],
                ];
        @endphp

        @foreach ($sourceCards as $source)
            <article class="rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-start justify-between gap-3">
                    <h2 class="font-semibold text-gray-900 dark:text-white">{{ $source['title'] }}</h2>
                    <span class="whitespace-nowrap rounded-full bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300">
                        {{ $source['state'] }}
                    </span>
                </div>
                <p class="mt-3 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $source['description'] }}</p>
            </article>
        @endforeach
    </section>

    <section class="rounded-xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-900/50 dark:bg-amber-950/20">
        <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
            {{ app()->getLocale() === 'tr' ? 'WordPress bağlantı durumu' : 'WordPress connection status' }}
        </p>
        <p class="mt-1 text-sm leading-6 text-amber-800 dark:text-amber-300">
            {{ app()->getLocale() === 'tr'
                ? 'WordPress REST envanter motoru kod tabanında bulunuyor; ancak authenticated WordPress Site Connector eklentisi henüz production bağlantı olarak kullanıma açık değil. Demo Site Connectors alanı bu ekranda gerçek bağlantı gibi gösterilmez.'
                : 'The WordPress REST inventory engine exists in the codebase, but the authenticated WordPress Site Connector plugin is not yet available as a production connection. The demo Site Connectors catalog is not presented here as a real connection.' }}
        </p>
    </section>

    @if ($rows->isEmpty())
        <section class="rounded-xl bg-white p-8 text-center ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                {{ app()->getLocale() === 'tr' ? 'Henüz Website Digital Asset yok' : 'No Website Digital Asset yet' }}
            </h2>
            <p class="mx-auto mt-2 max-w-2xl text-sm text-gray-500 dark:text-gray-400">
                {{ app()->getLocale() === 'tr'
                    ? 'Önce müşteri/marka altında Website Digital Asset oluşturun. primary_url veya domain tanımlandığında public Website kaynakları veri toplamaya hazır olur.'
                    : 'Create a Website Digital Asset under a customer/brand first. Public Website sources become collectable when primary_url or domain is defined.' }}
            </p>
        </section>
    @else
        <div class="space-y-4">
            @foreach ($rows as $row)
                @php
                    $asset = $row['asset'];
                    $run = $row['run'];
                    $status = $run?->status?->value ?? null;
                    $statusColor = match ($status) {
                        'completed' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-300',
                        'partial' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-300',
                        'failed', 'cancelled' => 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-300',
                        'queued', 'running', 'retrying' => 'bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-300',
                        default => 'bg-gray-100 text-gray-600 dark:bg-white/[0.05] dark:text-gray-300',
                    };
                    $targetUrl = $asset->primary_url ?: $asset->domain;
                @endphp

                <article class="rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                    <div class="flex flex-col gap-4 border-b border-gray-100 p-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $asset->name }}</h2>
                                @if ($run)
                                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusColor }}">
                                        {{ strtoupper((string) $status) }}
                                    </span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-500 dark:bg-white/[0.05] dark:text-gray-400">
                                        {{ app()->getLocale() === 'tr' ? 'Henüz veri çekilmedi' : 'Never collected' }}
                                    </span>
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ $asset->brand?->customer?->name }} · {{ $asset->brand?->name }}
                            </p>
                            <p class="mt-2 break-all text-sm font-medium text-gray-700 dark:text-gray-300">{{ $targetUrl ?: '—' }}</p>
                            <p class="mt-1 text-xs text-gray-500">CMS: {{ $asset->cms ?: '—' }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button"
                                wire:click="collectNow({{ $asset->id }})"
                                wire:loading.attr="disabled"
                                wire:target="collectNow({{ $asset->id }})"
                                @disabled(! $row['collectable'])
                                class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
                                <span wire:loading.remove wire:target="collectNow({{ $asset->id }})">
                                    {{ app()->getLocale() === 'tr' ? 'Veri Çek' : 'Collect Data' }}
                                </span>
                                <span wire:loading wire:target="collectNow({{ $asset->id }})">
                                    {{ app()->getLocale() === 'tr' ? 'Kuyruğa alınıyor…' : 'Queueing…' }}
                                </span>
                            </button>
                            <x-ta.button :href="route('operator.asset.sources', ['assetId' => $asset->id])" size="sm" variant="outline">
                                {{ app()->getLocale() === 'tr' ? 'Kaynakları Yönet' : 'Manage Sources' }}
                            </x-ta.button>
                            <x-ta.button :href="route('operator.website', ['assetId' => $asset->id])" size="sm" variant="outline">
                                {{ app()->getLocale() === 'tr' ? 'Website’i Aç' : 'Open Website' }}
                            </x-ta.button>
                        </div>
                    </div>

                    <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Public Crawl + HTML</p>
                            <p class="mt-2 text-sm font-semibold {{ $row['collectable'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                                {{ $row['collectable']
                                    ? (app()->getLocale() === 'tr' ? 'Hazır' : 'Ready')
                                    : (app()->getLocale() === 'tr' ? 'URL gerekli' : 'URL required') }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">SSL / TLS</p>
                            <p class="mt-2 text-sm font-semibold {{ $row['collectable'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300' }}">
                                {{ $row['collectable']
                                    ? (app()->getLocale() === 'tr' ? 'Hazır' : 'Ready')
                                    : (app()->getLocale() === 'tr' ? 'Domain gerekli' : 'Domain required') }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">PageSpeed</p>
                            <p class="mt-2 text-sm font-semibold {{ $row['page_speed_ready'] ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400' }}">
                                {{ $row['page_speed_ready']
                                    ? (app()->getLocale() === 'tr' ? 'Hazır' : 'Ready')
                                    : (app()->getLocale() === 'tr' ? 'API bağlantısı gerekli' : 'API connection required') }}
                            </p>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">WordPress REST</p>
                            <p class="mt-2 text-sm font-semibold {{ $row['wordpress_detected'] ? 'text-amber-700 dark:text-amber-300' : 'text-gray-500 dark:text-gray-400' }}">
                                {{ $row['wordpress_detected']
                                    ? (app()->getLocale() === 'tr' ? 'CMS algılandı · family deferred' : 'CMS detected · family deferred')
                                    : (app()->getLocale() === 'tr' ? 'CMS’e göre değerlendirilir' : 'Evaluated by CMS') }}
                            </p>
                        </div>
                    </div>

                    <div class="border-t border-gray-100 px-5 py-4 text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        @if ($run)
                            <span>Collection #{{ $run->id }}</span>
                            <span class="mx-2">·</span>
                            <span>{{ $run->datasets_completed }}/{{ $run->datasets_total }} {{ app()->getLocale() === 'tr' ? 'dataset tamamlandı' : 'datasets completed' }}</span>
                            @if ((int) $run->datasets_failed > 0)
                                <span class="mx-2">·</span>
                                <span class="text-rose-600 dark:text-rose-400">{{ $run->datasets_failed }} {{ app()->getLocale() === 'tr' ? 'başarısız' : 'failed' }}</span>
                            @endif
                            <span class="mx-2">·</span>
                            <span>{{ $run->updated_at?->diffForHumans() }}</span>
                        @else
                            {{ app()->getLocale() === 'tr' ? 'İlk gerçek Website collection henüz çalıştırılmadı.' : 'The first production Website collection has not been run yet.' }}
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</div>
