<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-400">{{ __('operator.site_connectors.title') }}</p>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">WordPress Connector</h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-500 dark:text-gray-400">WordPress’in içeride bildiği CMS verisini imzalı, salt-okunur ve sayfalanmış snapshot’lar halinde toplar. Public Discovery dışarıdan doğrulama kaynağı olarak ayrıca çalışır.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.integrations.site-connector.download', ['connector' => 'wordpress']) }}" class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ $packageFilename }}</a>
            <a href="{{ route('operator.integrations.site-connectors') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.site_connectors.catalog') }}</a>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-lg border px-4 py-3 text-sm',
            'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200' => $messageTone === 'success',
            'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200' => $messageTone === 'error',
            'border-gray-200 bg-gray-50 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200' => ! in_array($messageTone, ['success', 'error']),
        ])>{{ $message }}</div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Website seçimi</h2>
            <div class="mt-3 space-y-2">
                @forelse ($assets as $asset)
                    @php($assetConnection = $asset->connections->first())
                    <button type="button" wire:click="selectAsset({{ $asset->id }})" @class([
                        'w-full rounded-lg border px-3 py-3 text-left',
                        'border-brand-300 bg-brand-50 dark:border-brand-700 dark:bg-brand-500/10' => $selectedAssetId === $asset->id,
                        'border-gray-200 dark:border-gray-700' => $selectedAssetId !== $asset->id,
                    ])>
                        <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $asset->name }}</span>
                        <span class="block text-xs text-gray-500">{{ $asset->domain ?: $asset->primary_url }}</span>
                        <span class="mt-1 block text-xs {{ $assetConnection?->enabled && $assetConnection?->credential ? 'text-emerald-600' : 'text-amber-600' }}">
                            {{ $assetConnection?->enabled && $assetConnection?->credential ? 'Eşleştirilmiş' : 'Eşleştirilmemiş' }}
                        </span>
                    </button>
                @empty
                    <p class="text-sm text-gray-500">Henüz Website dijital varlığı yok.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            @if ($selected)
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $selected->name }}</h2>
                        <p class="text-sm text-gray-500">{{ $selected->primary_url ?: $selected->domain }}</p>
                    </div>
                    <x-ta.badge :color="$connection?->enabled && $connection?->credential ? 'success' : 'warning'" size="sm">
                        {{ $connection?->enabled && $connection?->credential ? 'Bağlı' : 'Bağlı değil' }}
                    </x-ta.badge>
                </div>

                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-gray-400">Son başarılı istek</dt><dd class="font-medium text-gray-800 dark:text-white">{{ $connection?->last_success_at?->diffForHumans() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Eklenti sürümü</dt><dd class="font-medium text-gray-800 dark:text-white">{{ data_get($connection?->config, 'plugin_version', '—') }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Durum endpoint’i</dt><dd class="truncate font-mono text-xs text-gray-600 dark:text-gray-300">{{ data_get($connection?->config, 'status_url', '—') }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Son bağlantı hatası</dt><dd class="text-xs text-gray-600 dark:text-gray-300">{{ $connection?->last_error ?: '—' }}</dd></div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="button" wire:click="issuePairingCode" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ $connection?->credential ? 'Eşleştirmeyi döndür' : 'Eşleştirme kodu üret' }}</button>
                    @if ($connection?->credential)
                        <button type="button" wire:click="testConnection" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Bağlantıyı doğrula</button>
                        <button type="button" wire:click="disconnect" wire:confirm="Bu Website için MoxDOP Connector erişimi iptal edilsin mi?" class="rounded-lg px-3 py-2 text-sm font-medium text-red-700 ring-1 ring-inset ring-red-300 dark:text-red-300 dark:ring-red-700">Bağlantıyı kes</button>
                    @endif
                </div>

                @if ($pairingCode)
                    <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Tek kullanımlık kod</p>
                        <code class="mt-2 block break-all text-base font-semibold text-gray-900 dark:text-white">{{ $pairingCode }}</code>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">WordPress → Ayarlar → MoxDOP Connector ekranına girin. Son kullanma: {{ $pairingExpiresAt }}. Kod veritabanında açık metin tutulmaz.</p>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500">Bir Website seçin.</p>
            @endif
        </section>
    </div>

    <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Kurulum</h2>
        <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>Production ZIP paketini indirin ve WordPress’e eklenti olarak kurup etkinleştirin.</li>
            <li>Yukarıda ilgili Website için tek kullanımlık kod üretin.</li>
            <li>WordPress → Ayarlar → MoxDOP Connector altında <code>https://app.moximu.com</code> ve kodu girin.</li>
            <li>Bağlantıyı doğrulayın; ardından Website entegrasyonundan veri çekimini başlatın.</li>
        </ol>
    </section>
</div>
