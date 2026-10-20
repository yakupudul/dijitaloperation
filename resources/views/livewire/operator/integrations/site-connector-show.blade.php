<div class="space-y-10">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs text-gray-500">{{ __('operator.site_connectors.title') }}</p>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="WordPress’in içeride bildiği CMS verisini imzalı, salt-okunur ve sayfalanmış snapshot’lar halinde toplar.">WordPress Connector</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('operator.integrations.site-connector.download', ['connector' => 'wordpress']) }}" class="inline-flex rounded-lg bg-brand-500 px-3 py-2 text-sm font-medium text-white hover:bg-brand-600">{{ $packageFilename }}</a>
            <a href="{{ route('operator.integrations') }}" wire:navigate class="inline-flex rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">{{ __('operator.nav.integrations') }}</a>
        </div>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-lg px-4 py-3 text-sm',
            'tone-ok' => $messageTone === 'success',
            'tone-critical' => $messageTone === 'error',
            'tone-info' => ! in_array($messageTone, ['success', 'error']),
        ])>{{ $message }}</div>
    @endif

    <div class="grid gap-10 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
        <section>
            <h2 class="section-title">Website seçimi</h2>
            <div class="mt-3 divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($assets as $asset)
                    @php($assetConnection = $asset->connections->first())
                    <button type="button" wire:click="selectAsset({{ $asset->id }})" @class([
                        'w-full rounded-md px-2 py-2.5 text-left hover:bg-gray-50 dark:hover:bg-white/[0.03]',
                        'bg-gray-100 dark:bg-white/[0.06]' => $selectedAssetId === $asset->id,
                    ])>
                        <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $asset->name }}</span>
                        <span class="block text-xs text-gray-500">{{ $asset->domain ?: $asset->primary_url }}</span>
                        <x-ta.pill class="mt-1" :tone="$assetConnection?->enabled && $assetConnection?->credential ? 'ok' : 'look'">{{ $assetConnection?->enabled && $assetConnection?->credential ? 'Eşleştirilmiş' : 'Eşleştirilmemiş' }}</x-ta.pill>
                    </button>
                @empty
                    <p class="text-sm text-gray-500">Henüz Website dijital varlığı yok.</p>
                @endforelse
            </div>
        </section>

        <section>
            @if ($selected)
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="section-title">{{ $selected->name }}</h2>
                        <p class="text-sm text-gray-500">{{ $selected->primary_url ?: $selected->domain }}</p>
                    </div>
                    <x-ta.pill :tone="$connection?->enabled && $connection?->credential ? 'ok' : 'look'">{{ $connection?->enabled && $connection?->credential ? 'Bağlı' : 'Bağlı değil' }}</x-ta.pill>
                </div>

                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-gray-400">Son başarılı istek</dt><dd class="font-medium text-gray-800 dark:text-white">{{ $connection?->last_success_at?->diffForHumans() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Eklenti sürümü</dt><dd class="font-medium text-gray-800 dark:text-white">{{ data_get($connection?->config, 'plugin_version', '—') }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Durum endpoint’i</dt><dd class="truncate font-mono text-xs text-gray-600 dark:text-gray-300">{{ data_get($connection?->config, 'status_url', '—') }}</dd></div>
                    <div><dt class="text-xs text-gray-400">Son bağlantı hatası</dt><dd class="text-xs text-gray-600 dark:text-gray-300">{{ $connection?->last_error ?: '—' }}</dd></div>
                    @if ($pullSummary)
                        <div class="sm:col-span-2"><dt class="text-xs text-gray-400">Site işleri kendisi alıyor</dt><dd class="text-xs text-gray-600 dark:text-gray-300">{{ $pullSummary }}</dd></div>
                    @endif
                </dl>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button type="button" wire:click="issuePairingCode" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700">{{ $connection?->credential ? 'Eşleştirmeyi döndür' : 'Eşleştirme kodu üret' }}</button>
                    @if ($connection?->credential)
                        <button type="button" wire:click="testConnection" class="rounded-lg px-3 py-2 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Bağlantıyı doğrula</button>
                        <button type="button" wire:click="disconnect" wire:confirm="Bu Website için MoxDOP Connector erişimi iptal edilsin mi?" class="rounded-lg px-3 py-2 text-sm font-medium text-error-700 ring-1 ring-inset ring-error-300 dark:text-error-300 dark:ring-error-700">Bağlantıyı kes</button>
                    @endif
                </div>

                @if ($connection?->credential)
                    @php($pluginBuild = in_array('build', (array) data_get($connection?->config, 'capabilities', []), true))
                    <div class="mt-8 border-t border-gray-200 pt-6 dark:border-gray-800">
                        <h3 class="section-title">Claude site kurulumu</h3>
                        <p class="mt-1 max-w-2xl text-xs text-gray-500 dark:text-gray-400">Claude MCP üzerinden bu sitede ACF alan grubu ve CPT, sayfa, Elementor şablonu, görsel, menü ve temel ayarları kurar. <strong>Doğrudan</strong>: hemen uygulanır. <strong>Onaylı</strong>: aşağıdaki kayıtta senin onayını bekler. Her kurulum kayıttan geri alınabilir. Sitede eklentinin “Site building” ayarı da açık olmalı (eklenti {{ config('moxdop-wordpress.build_min_plugin_version') }}+). Tema ve eklenti dosyalarına dokunulmaz.</p>
                        <p class="mt-1 text-xs {{ $pluginBuild ? 'text-gray-500' : 'text-warning-700 dark:text-warning-400' }}">Eklentide: {{ $pluginBuild ? 'açık' : (version_compare((string) data_get($connection?->config, 'plugin_version', '0.0.0'), (string) config('moxdop-wordpress.build_min_plugin_version'), '>=') ? 'kapalı (WordPress › Ayarlar › MoxDOP Connector › “Site building” açılmalı)' : 'eski sürüm, eklentiyi güncelle') }}</p>
                        <div class="mt-3 inline-flex rounded-lg ring-1 ring-inset ring-gray-300 dark:ring-gray-700" role="group">
                            @foreach (['off' => 'Kapalı', 'direct' => 'Doğrudan', 'approval' => 'Onaylı'] as $value => $label)
                                @php($active = ($buildMode ?? 'off') === $value)
                                <button type="button" @if ($isAdmin && ! $active) wire:click="setBuildMode('{{ $value }}')" @if ($value === 'direct') wire:confirm="Claude bu sitede onay beklemeden değişiklik yapabilsin mi?" @endif @endif @disabled(! $isAdmin) @class([
                                    'px-3 py-1.5 text-sm font-medium first:rounded-l-lg last:rounded-r-lg',
                                    'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $active,
                                    'text-gray-700 dark:text-gray-300' => ! $active,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($pairingCode)
                    <div class="mt-5 rounded-lg p-4 tone-look">
                        <p class="text-xs font-semibold">Tek kullanımlık kod</p>
                        <code class="mt-2 block break-all text-base font-semibold text-gray-900 dark:text-white">{{ $pairingCode }}</code>
                        <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">WordPress › Ayarlar › MoxDOP Connector ekranına girin. Son kullanma: {{ $pairingExpiresAt }}. Kod veritabanında açık metin tutulmaz.</p>
                    </div>
                @endif
            @else
                <p class="text-sm text-gray-500">Bir Website seçin.</p>
            @endif
        </section>
    </div>

    @if ($connection?->credential)
        <section>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="section-title">Site kurulum kaydı <span class="text-sm font-normal text-gray-500">{{ $selected->name }}, son 20</span></h2>
            </div>
            <div class="mt-3 divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($buildLog as $entry)
                    <div wire:key="build-{{ $entry['id'] }}" class="py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-medium text-gray-900 dark:text-white">#{{ $entry['id'] }}</span>
                                <x-ta.pill :tone="match ($entry['status']) { 'succeeded' => 'ok', 'failed', 'undo_failed' => 'critical', 'awaiting_approval', 'partial' => 'look', default => 'info' }">{{ $entry['status_label'] }}</x-ta.pill>
                                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($entry['created_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} <span class="ml-1">{{ $entry['mode'] === 'approval' ? 'onaylı' : 'doğrudan' }}</span></span>
                            </div>
                            @if ($isAdmin)
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($entry['status'] === 'awaiting_approval')
                                        <button type="button" wire:click="approveBuild({{ $entry['id'] }})" class="rounded-lg bg-brand-500 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-600">Onayla</button>
                                        <input type="text" wire:model="rejectReasons.{{ $entry['id'] }}" placeholder="Red nedeni (isteğe bağlı)" class="rounded-lg border-gray-300 px-2 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800">
                                        <button type="button" wire:click="rejectBuild({{ $entry['id'] }})" class="rounded-lg px-3 py-1.5 text-sm font-medium text-error-700 ring-1 ring-inset ring-error-300 dark:text-error-300 dark:ring-error-700">Reddet</button>
                                    @endif
                                    @if ($entry['undoable'])
                                        <button type="button" wire:click="undoBuild({{ $entry['id'] }})" wire:confirm="Bu kurulumla yapılanlar geri alınsın mı? Oluşturulanlar çöpe taşınır, değiştirilenler eski haline döner." class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Geri al</button>
                                        @if ($entry['status'] === 'undo_failed')
                                            <button type="button" wire:click="forceUndoBuild({{ $entry['id'] }})" wire:confirm="Kurulumdan sonra sitede yapılan değişikliklerin üzerine yazılacak. Emin misin?" class="rounded-lg px-3 py-1.5 text-sm font-medium text-error-700 ring-1 ring-inset ring-error-300 dark:text-error-300 dark:ring-error-700">Yine de geri al</button>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </div>
                        @if ($entry['error'])
                            <p class="mt-2 text-xs text-error-700 dark:text-error-300">{{ $entry['error'] }}</p>
                        @endif
                        @if ($entry['reject_reason'])
                            <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">Red nedeni: {{ $entry['reject_reason'] }}</p>
                        @endif
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($entry['operations'] as $op)
                                <li class="flex flex-wrap items-baseline gap-x-2">
                                    <span @class(['text-xs', 'text-success-600' => $op['ok'] === true, 'text-error-600' => $op['ok'] === false, 'text-gray-400' => $op['ok'] === null])>{{ $op['ok'] === true ? '✓' : ($op['ok'] === false ? '✕' : '•') }}</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-100">{{ $op['label'] }}</span>
                                    <span class="text-gray-600 dark:text-gray-300">{{ $op['target'] }}</span>
                                    @if ($op['detail'])<span class="text-xs text-gray-500">{{ $op['detail'] }}</span>@endif
                                    @if ($op['edit_url'])<a href="{{ $op['edit_url'] }}" target="_blank" rel="noopener" class="text-xs link-neutral">düzenle</a>@endif
                                    @if ($op['url'])<a href="{{ $op['url'] }}" target="_blank" rel="noopener" class="text-xs link-neutral">aç</a>@endif
                                    @if ($op['error'])<span class="w-full text-xs text-warning-700 dark:text-warning-300">{{ $op['error'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($entry['undone_at'])
                            <p class="mt-2 text-xs text-gray-500">Geri alındı: {{ \Illuminate\Support\Carbon::parse($entry['undone_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500">Bu sitede henüz Claude kurulumu yok.</p>
                @endforelse
            </div>
        </section>
    @endif

    <section>
        <h2 class="section-title">Kurulum</h2>
        <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm text-gray-600 dark:text-gray-300">
            <li>Production ZIP paketini indirin ve WordPress’e eklenti olarak kurup etkinleştirin.</li>
            <li>Yukarıda ilgili Website için tek kullanımlık kod üretin.</li>
            <li>WordPress › Ayarlar › MoxDOP Connector altında <code>https://app.moximu.com</code> ve kodu girin.</li>
            <li>Bağlantıyı doğrulayın; ardından Website entegrasyonundan veri çekimini başlatın.</li>
        </ol>
    </section>
</div>
