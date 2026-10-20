@php
    // Panel concept 2026-10-20: "Hangi site Connector ile eşleşmiş, seçtiğim siteyi nasıl bağlarım?" The picked site's
    // state and its one next step come first; the site list sits beside it, the build log and setup steps below.
    $paired = fn ($c): bool => (bool) ($c?->enabled && $c?->credential);
    $pairedCount = $assets->filter(fn ($a): bool => $paired($a->connections->first()))->count();
@endphp
<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-start gap-3">
            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'wordpress', 'size' => 'lg'])
            <div class="min-w-0">
                <h1 class="page-title" title="WordPress’in içeride bildiği CMS verisini imzalı, salt-okunur ve sayfalanmış snapshot’lar halinde toplar.">WordPress Connector</h1>
                <p class="page-sub">{{ __('operator.site_connectors.title') }}: {{ $assets->count() }} site, {{ $pairedCount }} eşleştirilmiş.</p>
            </div>
        </div>
        <div class="flex w-full flex-wrap gap-2 sm:w-auto">
            <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">{{ __('operator.nav.integrations') }}</a>
            <a href="{{ route('operator.integrations.site-connector.download', ['connector' => 'wordpress']) }}" class="btn" title="Eklenti paketini indir">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
                {{ $packageFilename }}
            </a>
        </div>
    </header>

    @if ($message !== '')
        <p @class([
            'card -mt-4 px-4 py-3 text-sm',
            'text-success-700 dark:text-success-400' => $messageTone === 'success',
            'text-error-700 dark:text-error-400' => $messageTone === 'error',
            'text-gray-700 dark:text-gray-300' => ! in_array($messageTone, ['success', 'error']),
        ]) role="status">{{ $message }}</p>
    @endif

    <div class="grid gap-10 lg:grid-cols-[minmax(0,0.75fr)_minmax(0,1.25fr)]">
        <section class="order-last min-w-0 lg:order-none">
            <div class="section-head"><h2 class="section-title">Website seçimi <span class="section-count">{{ $assets->count() }}</span></h2></div>
            <ul class="card">
                @forelse ($assets as $asset)
                    @php($assetPaired = $paired($asset->connections->first()))
                    <li class="border-t border-gray-200 first:border-t-0 dark:border-gray-800">
                        <button type="button" wire:click="selectAsset({{ $asset->id }})" @class([
                            'card-row w-full border-t-0 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.03]',
                            'bg-gray-50 shadow-[inset_2px_0_0_var(--color-gray-900)] dark:bg-white/[0.05] dark:shadow-[inset_2px_0_0_white]' => $selectedAssetId === $asset->id,
                        ]) @if ($selectedAssetId === $asset->id) aria-current="true" @endif>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ str_replace(' · ', ', ', (string) $asset->name) }}</span>
                                <span class="block truncate text-[13px] text-gray-500">{{ $asset->domain ?: $asset->primary_url }}</span>
                            </span>
                            <x-ta.pill :tone="$assetPaired ? 'ok' : 'look'">{{ $assetPaired ? 'Eşleştirilmiş' : 'Eşleştirilmemiş' }}</x-ta.pill>
                        </button>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" title="Henüz Website dijital varlığı yok." /></li>
                @endforelse
            </ul>
        </section>

        <section class="min-w-0">
            @if ($selected)
                @php($isPaired = $paired($connection))
                <div class="section-head items-center">
                    <h2 class="section-title min-w-0 truncate">{{ str_replace(' · ', ', ', (string) $selected->name) }}</h2>
                    <x-ta.pill :tone="$isPaired ? 'ok' : 'look'">{{ $isPaired ? 'Bağlı' : 'Bağlı değil' }}</x-ta.pill>
                </div>
                <div class="card">
                    <div class="card-row flex-wrap sm:flex-nowrap">
                        <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $selected->primary_url ?: $selected->domain }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $isPaired ? 'Eklenti eşleşmiş; bağlantıyı doğrulayabilirsiniz.' : 'Eşleştirme kodu üretip WordPress’teki MoxDOP Connector ekranına girin.' }}</p>
                        </div>
                        <div class="flex w-full flex-wrap gap-1.5 sm:w-auto sm:flex-nowrap">
                            @if ($connection?->credential)
                                <button type="button" wire:click="testConnection" class="btn-primary">Bağlantıyı doğrula</button>
                            @endif
                            <button type="button" wire:click="issuePairingCode" class="{{ $connection?->credential ? 'btn' : 'btn-primary' }}">{{ $connection?->credential ? 'Eşleştirmeyi döndür' : 'Eşleştirme kodu üret' }}</button>
                        </div>
                    </div>
                    @if ($pairingCode)
                        <div class="card-row flex-wrap bg-gray-50/60 dark:bg-white/[0.02]">
                            <div class="min-w-0 flex-1">
                                <p class="text-[13px] text-gray-500">Tek kullanımlık kod</p>
                                <code class="mt-1 block break-all font-mono text-base font-semibold text-gray-900 dark:text-white">{{ $pairingCode }}</code>
                                <p class="mt-1 text-xs text-gray-500" title="Kod veritabanında açık metin tutulmaz.">WordPress, Ayarlar, MoxDOP Connector ekranına girin. Son kullanma: {{ $pairingExpiresAt }}.</p>
                            </div>
                        </div>
                    @endif
                    <dl>
                        <div class="card-row flex-wrap"><dt class="w-40 shrink-0 text-[13px] text-gray-500">Son başarılı istek</dt><dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $connection?->last_success_at?->diffForHumans() ?? '—' }}</dd></div>
                        <div class="card-row flex-wrap"><dt class="w-40 shrink-0 text-[13px] text-gray-500">Eklenti sürümü</dt><dd class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ data_get($connection?->config, 'plugin_version', '—') }}</dd></div>
                        <div class="card-row flex-wrap"><dt class="w-40 shrink-0 text-[13px] text-gray-500">Durum adresi</dt><dd class="min-w-0 flex-1 truncate font-mono text-xs text-gray-600 dark:text-gray-300" title="{{ data_get($connection?->config, 'status_url', '—') }}">{{ data_get($connection?->config, 'status_url', '—') }}</dd></div>
                        <div class="card-row flex-wrap"><dt class="w-40 shrink-0 text-[13px] text-gray-500">Son bağlantı hatası</dt><dd @class(['min-w-0 flex-1 text-[13px]', 'text-error-700 dark:text-error-400' => $connection?->last_error, 'text-gray-600 dark:text-gray-300' => ! $connection?->last_error])>{{ $connection?->last_error ?: '—' }}</dd></div>
                        @if ($pullSummary)
                            <div class="card-row flex-wrap"><dt class="w-40 shrink-0 text-[13px] text-gray-500">Site işleri kendisi alıyor</dt><dd class="min-w-0 flex-1 text-[13px] text-gray-600 dark:text-gray-300">{{ $pullSummary }}</dd></div>
                        @endif
                    </dl>
                    @if ($connection?->credential)
                        <div class="card-row justify-end">
                            <button type="button" wire:click="disconnect" wire:confirm="Bu Website için MoxDOP Connector erişimi iptal edilsin mi?" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">Bağlantıyı kes</button>
                        </div>
                    @endif
                </div>

                @if ($connection?->credential)
                    @php($pluginBuild = in_array('build', (array) data_get($connection?->config, 'capabilities', []), true))
                    <div class="mt-8">
                        <div class="section-head">
                            <h3 class="section-title" title="Claude MCP üzerinden bu sitede ACF alan grubu ve CPT, sayfa, Elementor şablonu, görsel, menü ve temel ayarları kurar. Doğrudan: hemen uygulanır. Onaylı: aşağıdaki kayıtta senin onayını bekler. Her kurulum kayıttan geri alınabilir. Sitede eklentinin “Site building” ayarı da açık olmalı (eklenti {{ config('moxdop-wordpress.build_min_plugin_version') }}+). Tema ve eklenti dosyalarına dokunulmaz.">Claude site kurulumu</h3>
                        </div>
                        <div class="card">
                            <div class="card-row flex-wrap sm:flex-nowrap">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">Claude bu sitede sayfa, şablon ve ayar kurabilir</p>
                                    <p class="mt-0.5 text-[13px] {{ $pluginBuild ? 'text-gray-500 dark:text-gray-400' : 'text-warning-700 dark:text-warning-400' }}">Eklentide: {{ $pluginBuild ? 'açık' : (version_compare((string) data_get($connection?->config, 'plugin_version', '0.0.0'), (string) config('moxdop-wordpress.build_min_plugin_version'), '>=') ? 'kapalı (WordPress, Ayarlar, MoxDOP Connector, “Site building” açılmalı)' : 'eski sürüm, eklentiyi güncelle') }}</p>
                                </div>
                                <div class="inline-flex shrink-0 rounded-lg border border-gray-200 p-0.5 dark:border-gray-700" role="group">
                                    @foreach (['off' => 'Kapalı', 'direct' => 'Doğrudan', 'approval' => 'Onaylı'] as $value => $label)
                                        @php($active = ($buildMode ?? 'off') === $value)
                                        <button type="button" @if ($isAdmin && ! $active) wire:click="setBuildMode('{{ $value }}')" @if ($value === 'direct') wire:confirm="Claude bu sitede onay beklemeden değişiklik yapabilsin mi?" @endif @endif @disabled(! $isAdmin) @class([
                                            'h-7 rounded-md px-3 text-[13px] font-medium',
                                            'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $active,
                                            'text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white' => ! $active,
                                        ])>{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            @else
                <div class="card"><x-ta.empty-state icon="none" size="sm" title="Bir Website seçin." /></div>
            @endif
        </section>
    </div>

    @if ($connection?->credential)
        <section>
            <div class="section-head">
                <h2 class="section-title">Site kurulum kaydı <span class="section-count">{{ str_replace(' · ', ', ', (string) $selected->name) }}, son 20</span></h2>
            </div>
            <ul class="card">
                @forelse ($buildLog as $entry)
                    <li wire:key="build-{{ $entry['id'] }}" class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-2.5 gap-y-1">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">Kurulum #{{ $entry['id'] }}</span>
                                <x-ta.pill :tone="match ($entry['status']) { 'succeeded' => 'ok', 'failed', 'undo_failed' => 'critical', 'awaiting_approval', 'partial' => 'look', default => 'info' }">{{ $entry['status_label'] }}</x-ta.pill>
                                <span class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($entry['created_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}, {{ $entry['mode'] === 'approval' ? 'onaylı' : 'doğrudan' }}</span>
                            </div>
                            @if ($isAdmin)
                                <div class="flex w-full flex-wrap items-center gap-1.5 sm:w-auto">
                                    @if ($entry['status'] === 'awaiting_approval')
                                        <input type="text" wire:model="rejectReasons.{{ $entry['id'] }}" placeholder="Red nedeni (isteğe bağlı)" aria-label="Red nedeni" class="h-8 w-full rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-900 sm:w-48 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                        <button type="button" wire:click="rejectBuild({{ $entry['id'] }})" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">Reddet</button>
                                        <button type="button" wire:click="approveBuild({{ $entry['id'] }})" class="btn-dark">Onayla</button>
                                    @endif
                                    @if ($entry['undoable'])
                                        <button type="button" wire:click="undoBuild({{ $entry['id'] }})" wire:confirm="Bu kurulumla yapılanlar geri alınsın mı? Oluşturulanlar çöpe taşınır, değiştirilenler eski haline döner." class="btn">Geri al</button>
                                        @if ($entry['status'] === 'undo_failed')
                                            <button type="button" wire:click="forceUndoBuild({{ $entry['id'] }})" wire:confirm="Kurulumdan sonra sitede yapılan değişikliklerin üzerine yazılacak. Emin misin?" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">Yine de geri al</button>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </div>
                        @if ($entry['error'])
                            <p class="mt-2 text-[13px] text-error-700 dark:text-error-400">{{ $entry['error'] }}</p>
                        @endif
                        @if ($entry['reject_reason'])
                            <p class="mt-2 text-[13px] text-gray-600 dark:text-gray-300">Red nedeni: {{ $entry['reject_reason'] }}</p>
                        @endif
                        <ul class="mt-2 space-y-1 text-[13px]">
                            @foreach ($entry['operations'] as $op)
                                <li class="flex flex-wrap items-baseline gap-x-2">
                                    <span @class(['h-1.5 w-1.5 shrink-0 translate-y-[-1px] rounded-full', 'bg-success-500' => $op['ok'] === true, 'bg-error-500' => $op['ok'] === false, 'bg-gray-300 dark:bg-gray-600' => $op['ok'] === null]) aria-label="{{ $op['ok'] === true ? 'tamam' : ($op['ok'] === false ? 'hata' : 'bekliyor') }}"></span>
                                    <span class="font-medium text-gray-900 dark:text-gray-100">{{ $op['label'] }}</span>
                                    <span class="text-gray-600 dark:text-gray-300">{{ $op['target'] }}</span>
                                    @if ($op['detail'])<span class="text-xs text-gray-500">{{ $op['detail'] }}</span>@endif
                                    @if ($op['edit_url'])<a href="{{ $op['edit_url'] }}" target="_blank" rel="noopener" class="text-xs link-neutral">düzenle</a>@endif
                                    @if ($op['url'])<a href="{{ $op['url'] }}" target="_blank" rel="noopener" class="text-xs link-neutral">aç</a>@endif
                                    @if ($op['error'])<span class="w-full pl-3.5 text-xs text-warning-700 dark:text-warning-400">{{ $op['error'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($entry['undone_at'])
                            <p class="mt-2 text-xs text-gray-500">Geri alındı: {{ \Illuminate\Support\Carbon::parse($entry['undone_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                        @endif
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" title="Bu sitede henüz Claude kurulumu yok." /></li>
                @endforelse
            </ul>
        </section>
    @endif

    <section>
        <div class="section-head"><h2 class="section-title">Kurulum</h2></div>
        <ol class="card">
            @foreach ([
                'Production ZIP paketini indirin ve WordPress’e eklenti olarak kurup etkinleştirin.',
                'Yukarıda ilgili Website için tek kullanımlık kod üretin.',
                'WordPress, Ayarlar, MoxDOP Connector altında https://app.moximu.com adresini ve kodu girin.',
                'Bağlantıyı doğrulayın; ardından Website entegrasyonundan veri çekimini başlatın.',
            ] as $step)
                <li class="card-row items-start">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-gray-300 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">{{ $loop->iteration }}</span>
                    <span class="min-w-0 flex-1 pt-0.5 text-sm text-gray-700 dark:text-gray-300">{{ $step }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>
