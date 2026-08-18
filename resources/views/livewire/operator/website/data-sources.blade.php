<div class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('operator.website', ['assetId' => $asset->id]) }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Website</a>
                <span class="text-gray-300">/</span>
                <span class="text-sm text-gray-500">Veri Kaynakları</span>
            </div>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">{{ $asset->name }} · Veri Kaynakları</h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">
                Website'i besleyen gerçek kaynakları burada yönet. GA4 ve Search Console doğrudan bu Website'e bağlanır; Google Ads, Meta Ads ve GBP aynı Brand altındaki ayrı Digital Asset'lerdir. DataForSEO ortak SEO intelligence motorudur.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="collectNow" class="rounded-lg bg-brand-500 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-600">Bağlı verileri çek</button>
            <button type="button" wire:click="refreshSeoIntelligence" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">SEO intelligence yenile</button>
            <a href="{{ route('operator.website.discovery', ['assetId' => $asset->id]) }}" wire:navigate class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700">Kamu Keşif</a>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1 ring-inset',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $messageTone === 'success',
            'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Website'e doğrudan bağlı kaynaklar</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Google hesabında keşfedilen GA4 ve Search Console kaynaklarını seçip doğrudan bu Website'e bağlayabilirsin.</p>
        </div>
        <div class="grid gap-4 lg:grid-cols-3">
            @foreach ($connections as $source)
                <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $source['label'] }}</h3>
                            <p class="mt-1 text-xs text-gray-500">{{ $source['subtitle'] ?? '—' }}</p>
                        </div>
                        <span @class([
                            'rounded-full px-2.5 py-1 text-xs font-medium',
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $source['connected'],
                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $source['connected'],
                        ])>{{ $source['connected'] ? 'Bağlı' : 'Bağlı değil' }}</span>
                    </div>

                    @if (! empty($source['display_name']))
                        <p class="mt-3 text-sm font-medium text-gray-800 dark:text-white/90">{{ $source['display_name'] }}</p>
                    @endif

                    <dl class="mt-4 space-y-2 text-xs">
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">Son veri</dt><dd class="text-right text-gray-600 dark:text-gray-300">{{ $source['last_sync_human'] ?? 'Henüz yok' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-gray-400">Son durum</dt><dd class="text-right text-gray-600 dark:text-gray-300">{{ $source['last_status'] ?? '—' }}</dd></div>
                    </dl>

                    @if ($source['key'] === 'ga4')
                        <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Keşfedilmiş GA4 property</label>
                            <select wire:model="ga4ResourceId" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-900">
                                <option value="">Kaynak seç</option>
                                @foreach ($ga4Resources as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('ga4ResourceId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="bindGa4" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600">{{ $source['connected'] ? 'Bağlantıyı değiştir' : 'Website’e bağla' }}</button>
                                @if ($source['connected'])
                                    <button type="button" wire:click="disableBinding('ga4')" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Devre dışı bırak</button>
                                @endif
                            </div>
                            @if ($ga4Resources === [])
                                <p class="text-xs text-gray-400">Başka bağlanabilir GA4 property yok. Önce Google kaynak keşfini çalıştır.</p>
                            @endif
                        </div>
                    @elseif ($source['key'] === 'search_console')
                        <div class="mt-4 space-y-2 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Keşfedilmiş Search Console property</label>
                            <select wire:model="searchConsoleResourceId" class="w-full rounded-lg border-gray-300 bg-white text-sm dark:border-gray-700 dark:bg-gray-900">
                                <option value="">Kaynak seç</option>
                                @foreach ($gscResources as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('searchConsoleResourceId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="bindSearchConsole" class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600">{{ $source['connected'] ? 'Bağlantıyı değiştir' : 'Website’e bağla' }}</button>
                                @if ($source['connected'])
                                    <button type="button" wire:click="disableBinding('search_console')" class="rounded-lg px-3 py-2 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">Devre dışı bırak</button>
                                @endif
                            </div>
                            @if ($gscResources === [])
                                <p class="text-xs text-gray-400">Başka bağlanabilir Search Console property yok. Önce Google kaynak keşfini çalıştır.</p>
                            @endif
                        </div>
                    @elseif ($source['key'] === 'wordpress')
                        <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                            <a href="{{ route('operator.integrations.site-connectors') }}" wire:navigate class="text-sm font-medium text-brand-600 hover:underline">Site Connector yönet →</a>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Provider motorları</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Hesap seviyesindeki entegrasyon durumu; Website'i besleyen motorlar burada açıkça görünür.</p>
        </div>
        <div class="grid gap-4 md:grid-cols-3">
            <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900 dark:text-white">Google</h3><span class="text-xs font-medium text-gray-500">{{ $googleStatusLabel }}</span></div>
                <p class="mt-2 text-sm text-gray-500">GA4, Search Console, Google Ads ve Google Business Profile kaynak keşfi.</p>
                <a href="{{ route('operator.integrations.google') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600 hover:underline">Google entegrasyonunu aç →</a>
            </article>
            <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900 dark:text-white">Meta</h3><span class="text-xs font-medium text-gray-500">{{ $metaStatusLabel }}</span></div>
                <p class="mt-2 text-sm text-gray-500">Meta Business ve reklam hesabı kaynak keşfi; reklam hesabı ayrı Meta Ads Digital Asset'e bağlanır.</p>
                <a href="{{ route('operator.integrations.meta') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600 hover:underline">Meta entegrasyonunu aç →</a>
            </article>
            <article class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <div class="flex items-center justify-between gap-3"><h3 class="font-semibold text-gray-900 dark:text-white">DataForSEO</h3><span class="text-xs font-medium {{ $dataForSeoConfigured ? 'text-emerald-600' : 'text-gray-500' }}">{{ $dataForSeoConfigured ? 'Configured' : 'Not configured' }}</span></div>
                <p class="mt-2 text-sm text-gray-500">SEO keyword intelligence ve bounded competitor discovery. Website arama pazarı: {{ $asset->seo_market_location_name ?: 'ayarlanmamış' }} · {{ $asset->seo_market_language_name ?: 'ayarlanmamış' }}.</p>
                @if ($dataForSeoConnectionStatus)
                    <p class="mt-2 text-xs text-gray-400">Connection: {{ $dataForSeoConnectionStatus }}</p>
                @endif
                <a href="{{ route('operator.integrations.dataforseo') }}" wire:navigate class="mt-4 inline-flex text-sm font-medium text-brand-600 hover:underline">DataForSEO ayarlarını aç →</a>
            </article>
        </div>
    </section>

    <section class="space-y-3">
        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Aynı markaya bağlı kanal varlıkları</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Google Ads, Meta Ads ve GBP Website binding'i değildir; aynı Brand altında ilişkilendirilir ve cross-channel analizde birlikte kullanılır.</p>
        </div>
        <div class="grid gap-3 lg:grid-cols-2">
            @forelse ($relatedAssets as $related)
                @php
                    $route = match ($related->type) {
                        'google_ads' => route('operator.google-ads.overview', ['assetId' => $related->id]),
                        'meta_ads' => route('operator.meta.overview', ['assetId' => $related->id]),
                        'google_business_profile', 'gbp' => route('operator.gbp', ['assetId' => $related->id]),
                        'ga4', 'google_analytics', 'analytics' => route('operator.analytics', ['assetId' => $related->id]),
                        'gsc', 'search_console', 'google_search_console' => route('operator.search-console', ['assetId' => $related->id]),
                        default => route('operator.assets'),
                    };
                @endphp
                <a href="{{ $route }}" wire:navigate class="flex items-center justify-between gap-4 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:bg-gray-800 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">{{ $related->name }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ str($related->type)->replace('_', ' ')->title() }}</p>
                    </div>
                    <span class="text-sm font-medium text-brand-600">Aç →</span>
                </a>
            @empty
                <div class="rounded-xl bg-gray-50 p-5 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">Bu marka altında henüz Google Ads, Meta Ads veya GBP gibi ilişkili kanal varlığı yok.</div>
            @endforelse
        </div>
    </section>
</div>
