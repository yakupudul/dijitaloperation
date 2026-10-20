@php
    // Panel concept 2026-10-20: "Meta reklam hesapları bağlı mı, veri geliyor mu; değilse sıradaki adım ne?"
    // The setup steps answer it first; the page's one main action sits on the right of the head.
    $isTr = app()->getLocale() === 'tr';
    $L = fn (?string $label): string => \App\Support\Integrations\Presentation\OperatorLabel::turkish($label);
    $appReady = (bool) ($integration['app_configured'] ?? false);
    $authReady = ($integration['auth_status'] ?? '') === 'connected';
    $businessReady = (int) ($integration['businesses_selected'] ?? 0) > 0;
    $bindingReady = (int) ($integration['bound'] ?? 0) > 0;
    $collectionLabel = $L((string) ($integration['collection_state_label'] ?? ($isTr ? 'Henüz çalıştırılmadı' : 'Not run yet')));
    $dataLabel = $L((string) ($integration['data_state_label'] ?? ($isTr ? 'Veri yok' : 'No data')));
    // Faz 12: the step is done only when the last run actually completed (not when a label merely exists).
    $collectionReady = $bindingReady && ($integration['collection_state'] ?? '') === 'completed';

    $tabs = [
        'overview' => $isTr ? 'Genel Bakış' : 'Overview',
        'configuration' => $isTr ? 'Bağlantı' : 'Connection',
        'resources' => $isTr ? 'Reklam Hesapları' : 'Ad Accounts',
        'connectors' => $isTr ? 'Veri Toplama' : 'Data Collection',
        'activity' => $isTr ? 'Geçmiş' : 'History',
    ];

    $steps = [
        [
            'label' => $isTr ? 'Uygulama' : 'Application',
            'detail' => $appReady ? ($isTr ? 'Hazır' : 'Ready') : ($isTr ? 'App ID / Secret gerekli' : 'App ID / Secret required'),
            'done' => $appReady,
            'tab' => 'configuration',
        ],
        [
            'label' => $isTr ? 'Yetkilendirme' : 'Authorization',
            'detail' => $authReady ? ($isTr ? 'Meta bağlı' : 'Meta connected') : ($isTr ? 'Meta ile bağlanılmalı' : 'OAuth required'),
            'done' => $authReady,
            'tab' => 'configuration',
        ],
        [
            'label' => $isTr ? 'Reklam Hesabı' : 'Ad Account',
            'detail' => $bindingReady
                ? (($integration['bound'] ?? 0).' '.($isTr ? 'hesap bağlı' : 'account(s) bound'))
                : ($isTr ? 'Hesap bağlanmalı' : 'Account must be bound'),
            'done' => $bindingReady,
            'tab' => 'resources',
        ],
        [
            'label' => $isTr ? 'Veri' : 'Data',
            'detail' => $collectionLabel,
            'done' => $collectionReady,
            'tab' => 'connectors',
        ],
    ];

    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none focus:border-gray-400 focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $label = 'text-[13px] text-gray-600 dark:text-gray-400';
    $spin = '<svg wire:loading.class="animate-spin" wire:target="collectData" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $warnIcon = '<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>';
    $lookIcon = 'row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400';
    $accountLine = fn (array $row): string => collect([
        'ID '.($row['external_id_masked'] ?? $row['external_id'] ?? '—'),
        $row['business'] ?? null,
        $row['currency'] ?? null,
        $row['timezone'] ?? null,
    ])->filter()->implode(', ');
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'meta', 'size' => 'lg'])
            <div class="min-w-0">
                <h1 class="page-title">{{ $isTr ? 'Meta Ads Entegrasyonu' : 'Meta Ads Integration' }}</h1>
                <p class="page-sub flex flex-wrap items-center gap-x-3 gap-y-1">
                    <x-ta.pill :tone="$authReady ? 'ok' : 'look'">{{ $authReady ? ($isTr ? 'Bağlı' : 'Connected') : ($isTr ? 'Bağlantı gerekli' : 'Connection required') }}</x-ta.pill>
                    <span>Meta Marketing API</span>
                </p>
            </div>
        </div>

        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">{{ $isTr ? 'Entegrasyonlar' : 'Integrations' }}</a>
            @if (! $appReady)
                <button type="button" wire:click="setTab('configuration')" class="btn-primary">{{ $isTr ? 'Bağlantıyı Kur' : 'Set up connection' }}</button>
            @elseif (! $authReady)
                @if (! empty($integration['authorize_url']))
                    <a href="{{ $integration['authorize_url'] }}" class="btn-primary">{{ $isTr ? 'Meta ile Bağlan' : 'Connect Meta' }}</a>
                @else
                    <button type="button" wire:click="bootstrapAndConnect" class="btn-primary">{{ $isTr ? 'Meta ile Bağlan' : 'Connect Meta' }}</button>
                @endif
            @elseif (! $bindingReady)
                <button type="button" wire:click="setTab('resources')" class="btn-primary">{{ $isTr ? 'Reklam Hesabı Bağla' : 'Bind Ad Account' }}</button>
            @elseif ($integration['actions']['collect'] ?? false)
                <button type="button" wire:click="collectData" wire:loading.attr="disabled" class="btn-primary">
                    {!! $spin !!}
                    {{ $collectionReady ? ($isTr ? 'Verileri Güncelle' : 'Refresh Data') : ($isTr ? 'Verileri Topla' : 'Collect Data') }}
                </button>
            @endif
        </div>
    </header>

    @include('livewire.demo.integrations.partials.setup-steps', ['steps' => $steps])

    @if ($confirmDisconnect ?? false)
        <div class="card -mt-4 flex flex-wrap items-center gap-3.5 px-4 py-3.5 sm:flex-nowrap">
            <span class="{{ $lookIcon }}">{!! $warnIcon !!}</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $isTr ? 'Meta yetkilendirmesini kaldır?' : 'Disconnect Meta authorization?' }}</p>
                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $isTr ? 'Yetki kaldırılır; keşfedilen işletmeler, reklam hesapları, bağlantılar ve geçmiş veri silinmez.' : 'OAuth authorization is removed; discovered businesses, ad accounts, bindings and historical data are preserved.' }}</p>
            </div>
            <div class="flex gap-2">
                <button type="button" wire:click="cancelDisconnect" class="btn">{{ $isTr ? 'Vazgeç' : 'Cancel' }}</button>
                <x-ta.button wire:click="confirmDisconnectAction" size="sm" variant="danger">{{ $isTr ? 'Bağlantıyı Kes' : 'Disconnect' }}</x-ta.button>
            </div>
        </div>
    @endif

    {{-- Tabs --}}
    <div class="mb-6! flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Meta Ads integration sections">
        @foreach ($tabs as $key => $tabLabel)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class(['tab', 'tab-active' => $tab === $key])>{{ $tabLabel }}</button>
        @endforeach
    </div>

    {{-- OVERVIEW --}}
    @if ($tab === 'overview')
        <div class="metric-strip grid-cols-2 lg:grid-cols-4">
            <x-ta.metric-card :label="$isTr ? 'Keşfedilen işletme' : 'Businesses discovered'" :value="number_format((int) ($integration['businesses_discovered'] ?? 0))"
                :hint="number_format((int) ($integration['businesses_selected'] ?? 0)).' '.($isTr ? 'tanesi keşif için seçili' : 'selected for discovery')" title="Business Portfolio" />
            <x-ta.metric-card :label="$isTr ? 'Keşfedilen reklam hesabı' : 'Ad accounts discovered'" :value="number_format((int) ($integration['ad_accounts_discovered'] ?? 0))"
                :hint="number_format((int) ($integration['available'] ?? 0)).' '.($isTr ? 'bağlanabilir hesap' : 'available to bind')" />
            <x-ta.metric-card :label="$isTr ? 'MoxDOP’a bağlı hesap' : 'Accounts bound to MoxDOP'" :value="number_format((int) ($integration['bound'] ?? 0))"
                :hint="$bindingReady ? ($isTr ? 'Veri çekimine hazır' : 'Ready for analytical collection') : ($isTr ? 'Henüz hesap bağlanmadı' : 'No account bound yet')" />
            <x-ta.metric-card :label="$isTr ? 'Veri toplama durumu' : 'Collection status'" :value="$collectionReady ? ($isTr ? 'Geliyor' : 'Flowing') : '—'" :hint="$collectionLabel.'. '.$dataLabel.'.'" :muted="! $collectionReady" />
        </div>

        <section>
            <div class="section-head">
                <h2 class="section-title" title="{{ $isTr ? 'Business Portfolio keşif konteyneridir. Veri toplama ve raporlama reklam hesabı seviyesinde yapılır.' : 'Business Portfolio is a discovery container. Collection and reporting operate at Ad Account level.' }}">{{ $isTr ? 'Bağlantı Sağlığı' : 'Connection Health' }}</h2>
                <span class="text-sm text-gray-500">{{ $authReady ? ($isTr ? 'Meta yetkisi aktif' : 'Meta authorization active') : ($isTr ? 'Kurulum tamamlanmadı' : 'Setup incomplete') }}</span>
            </div>
            <dl class="card">
                @foreach ([
                    [$isTr ? 'Uygulama' : 'App', $L($integration['app_configuration_label'] ?? '—')],
                    [$isTr ? 'Yetki' : 'OAuth', $L($integration['auth_status_label'] ?? '—')],
                    ['Graph API', $integration['graph_api_version'] ?? '—'],
                    [$isTr ? 'Bağlantı testi' : 'Connection test', $L($integration['connection_test_label'] ?? '—')],
                ] as [$termLabel, $termValue])
                    <div class="card-row flex-wrap text-sm sm:flex-nowrap">
                        <dt class="w-full text-[13px] text-gray-500 sm:w-56 sm:shrink-0">{{ $termLabel }}</dt>
                        <dd class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">{{ $termValue }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @if (! empty($integration['bindings']))
            <section>
                <div class="section-head">
                    <h2 class="section-title" title="{{ $isTr ? 'Meta Ads dijital varlıklarına bağlanan hesaplar.' : 'Accounts bound to Meta Ads Digital Assets.' }}">{{ $isTr ? 'Bağlı Reklam Hesapları' : 'Connected Ad Accounts' }} <span class="section-count">{{ count($integration['bindings']) }}</span></h2>
                    <button type="button" wire:click="setTab('resources')" class="ml-auto text-[13px] text-gray-500 hover:text-gray-900 dark:hover:text-white">{{ $isTr ? 'Yönet' : 'Manage' }}</button>
                </div>
                <ul class="card">
                    @foreach ($integration['bindings'] as $binding)
                        <li class="card-row flex-wrap sm:flex-nowrap">
                            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'meta'])
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $binding['resource'] }}</p>
                                <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400" title="{{ str_replace(' · ', ', ', (string) $binding['asset']) }}">{{ $accountLine($binding) }}. {{ str_replace(' · ', ', ', (string) $binding['asset']) }}@if (! empty($binding['brand'])), {{ $binding['brand'] }}@endif</p>
                            </div>
                            @if (! empty($binding['route']) && ! empty($binding['asset_id']))
                                <a href="{{ route($binding['route'], ['assetId' => $binding['asset_id']]) }}" wire:navigate class="btn w-full sm:w-auto">{{ $isTr ? 'Çalışma Alanını Aç' : 'Open Workspace' }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

    {{-- CONNECTION --}}
    @elseif ($tab === 'configuration')
        <div class="grid gap-10 lg:grid-cols-[1fr_.8fr]">
            <section>
                <div class="section-head">
                    <h2 class="section-title" title="{{ $isTr ? 'Meta App ID ve App Secret yalnızca Meta bağlantısını kurmak için kullanılır.' : 'Meta App ID and App Secret are used to establish the OAuth connection.' }}">{{ $isTr ? 'Uygulama Bilgileri' : 'Application Credentials' }}</h2>
                    <x-ta.pill :tone="$appReady ? 'ok' : 'look'" class="ml-auto">{{ $appReady ? ($isTr ? 'Yapılandırıldı' : 'Configured') : ($isTr ? 'Eksik' : 'Missing') }}</x-ta.pill>
                </div>

                @if ($canManageCredentials ?? false)
                    <form wire:submit.prevent="saveMetaConfiguration" class="card space-y-4 p-5">
                        <div>
                            <label for="meta-app-id" class="{{ $label }}">Meta App ID</label>
                            <input id="meta-app-id" wire:model="metaAppId" type="text" autocomplete="off" class="{{ $input }} mt-1" />
                            @error('metaAppId') <p class="mt-1 text-xs text-error-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="meta-app-secret" class="{{ $label }}">Meta App Secret</label>
                            <input id="meta-app-secret" wire:model="metaAppSecret" type="password" autocomplete="new-password" placeholder="{{ ($metaAppSecretConfigured ?? false) ? ($isTr ? 'Değiştirmek için yeni değer girin' : 'Enter a new value to replace') : '' }}" class="{{ $input }} mt-1" />
                            <p class="mt-1 text-xs text-gray-500">{{ ($metaAppSecretConfigured ?? false) ? ($isTr ? 'Kayıtlı. Boş bırakırsan kayıtlı değer korunur.' : 'Stored. Leave blank to keep the existing secret.') : ($isTr ? 'Kaydedildikten sonra tekrar gösterilmez.' : 'Never shown again after save.') }}</p>
                            @error('metaAppSecret') <p class="mt-1 text-xs text-error-600">{{ $message }}</p> @enderror
                        </div>

                        @if ($metaAppSecretConfigured ?? false)
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" wire:model="clearMetaAppSecret" class="rounded border-gray-300 text-gray-900" />
                                {{ $isTr ? 'Kayıtlı App Secret’ı temizle' : 'Clear stored App Secret' }}
                            </label>
                        @endif

                        <div class="flex flex-wrap gap-2 border-t border-gray-200 pt-4 dark:border-gray-800">
                            <button type="submit" class="btn-primary">{{ $isTr ? 'Bilgileri Kaydet' : 'Save Credentials' }}</button>
                            <button type="button" wire:click="testMetaConfiguration" class="btn">{{ $isTr ? 'Bağlantıyı Test Et' : 'Test Connection' }}</button>
                            @if ($appReady || ($metaAppSecretConfigured ?? false))
                                <button type="button" wire:click="askRemoveMetaCredentials" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">{{ $isTr ? 'Bilgileri Kaldır' : 'Remove Credentials' }}</button>
                            @endif
                        </div>
                    </form>
                @else
                    <div class="card"><x-ta.empty-state icon="none" size="sm" :title="$isTr ? 'Meta uygulama bilgilerini yalnızca yöneticiler değiştirebilir.' : 'Only administrators can change Meta application credentials.'" /></div>
                @endif

                @if ($confirmRemoveMetaCredentials ?? false)
                    <div class="card mt-3 flex flex-wrap items-center gap-3.5 px-4 py-3.5 sm:flex-nowrap">
                        <span class="row-icon border-transparent bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400">{!! $warnIcon !!}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $isTr ? 'Meta uygulama bilgilerini kaldır?' : 'Remove Meta application credentials?' }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $isTr ? 'App ID ve App Secret silinir. Keşfedilen kaynaklar, bağlantılar ve geçmiş veri korunur.' : 'App ID and App Secret are deleted. Discovered resources, bindings and history are preserved.' }}</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="cancelRemoveMetaCredentials" class="btn">{{ $isTr ? 'Vazgeç' : 'Cancel' }}</button>
                            <x-ta.button wire:click="removeMetaConfiguration" size="sm" variant="danger">{{ $isTr ? 'Kaldır' : 'Remove' }}</x-ta.button>
                        </div>
                    </div>
                @endif
            </section>

            <div class="space-y-10">
                <section>
                    <div class="section-head">
                        <h2 class="section-title" title="{{ $isTr ? 'MoxDOP reklam hesaplarını okuyabilmek için Meta kullanıcı yetkisini kullanır.' : 'MoxDOP uses Meta user authorization to read accessible ad accounts.' }}">{{ $isTr ? 'Meta Yetkilendirmesi' : 'Meta Authorization' }}</h2>
                    </div>
                    <div class="card">
                        <div class="card-row text-sm"><span class="flex-1 text-[13px] text-gray-500">{{ $isTr ? 'Durum' : 'Status' }}</span><x-ta.pill :tone="$authReady ? 'ok' : 'look'">{{ $L($integration['auth_status_label'] ?? '—') }}</x-ta.pill></div>
                        <div class="card-row text-sm"><span class="flex-1 text-[13px] text-gray-500">{{ $isTr ? 'Kimlik bilgisi' : 'Credential' }}</span><span class="font-medium text-gray-900 dark:text-white">{{ $L($integration['authorization_credential_label'] ?? '—') }}</span></div>
                        <div class="card-row text-sm"><span class="flex-1 text-[13px] text-gray-500">{{ $isTr ? 'Test' : 'Test' }}</span><span class="font-medium text-gray-900 dark:text-white">{{ $L($integration['connection_test_label'] ?? '—') }}</span></div>
                        <div class="card-row flex-wrap gap-2">
                            @if (! $authReady && $appReady)
                                @if (! empty($integration['authorize_url']))
                                    <a href="{{ $integration['authorize_url'] }}" class="btn-primary w-full sm:w-auto">{{ $isTr ? 'Meta ile Bağlan' : 'Connect with Meta' }}</a>
                                @else
                                    <button type="button" wire:click="bootstrapAndConnect" class="btn-primary w-full sm:w-auto">{{ $isTr ? 'Meta ile Bağlan' : 'Connect with Meta' }}</button>
                                @endif
                            @elseif ($authReady)
                                @if (($integration['actions']['reauthorize'] ?? false) && ! empty($integration['reauthorize_url']))
                                    <a href="{{ $integration['reauthorize_url'] }}" class="btn">{{ $isTr ? 'Yeniden Yetkilendir' : 'Reauthorize Meta' }}</a>
                                @endif
                                @if ($integration['actions']['disconnect'] ?? false)
                                    <button type="button" wire:click="askDisconnect" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">{{ $isTr ? 'Meta Bağlantısını Kes' : 'Disconnect Meta' }}</button>
                                @endif
                            @else
                                <button type="button" wire:click="setTab('configuration')" class="btn w-full sm:w-auto" disabled>{{ $isTr ? 'Önce uygulama bilgilerini kaydedin' : 'Save application credentials first' }}</button>
                            @endif
                        </div>
                    </div>
                </section>

                <section>
                    <div class="section-head">
                        <h3 class="section-title">{{ $isTr ? 'Dönüş adresi' : 'OAuth Callback' }}</h3>
                    </div>
                    <div class="card px-4 py-3">
                        <code class="block overflow-x-auto text-xs text-gray-900 dark:text-gray-200">{{ url('/integrations/meta/callback') }}</code>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">{{ $isTr ? 'Meta Developer panelindeki Valid OAuth Redirect URI ile birebir aynı olmalı.' : 'Must exactly match the Valid OAuth Redirect URI in Meta Developer.' }}</p>
                </section>
            </div>
        </div>

    {{-- ACCOUNTS --}}
    @elseif ($tab === 'resources')
        <section>
            <div class="section-head items-center">
                <h2 class="section-title" title="{{ $isTr ? 'Business Portfolio analitik varlık değildir. Yalnızca hangi reklam hesaplarının keşfedileceğini belirleyen erişim konteyneridir.' : 'Business Portfolio is not an analytical asset. It only controls which ad accounts can be discovered.' }}">{{ $isTr ? 'Keşif Kapsamını Seçin' : 'Choose Discovery Scope' }} <span class="section-count">{{ number_format((int) ($integration['businesses_selected'] ?? 0)) }}/{{ number_format((int) ($integration['businesses_discovered'] ?? 0)) }}</span></h2>
                <div class="ml-auto flex flex-wrap gap-2">
                    @if ($integration['actions']['discover_businesses'] ?? false)
                        <button type="button" wire:click="discoverBusinesses" class="btn">{{ $isTr ? 'Business’ları Yenile' : 'Discover Businesses' }}</button>
                    @endif
                    @if ($integration['actions']['discover_ad_accounts'] ?? false)
                        <button type="button" wire:click="discoverAdAccounts" class="btn-primary">{{ $isTr ? 'Reklam Hesaplarını Keşfet' : 'Discover Ad Accounts' }}</button>
                    @endif
                </div>
            </div>

            <div class="metric-strip mb-3 grid-cols-3">
                <x-ta.metric-card :label="$isTr ? 'Keşfedilen Business' : 'Businesses discovered'" :value="number_format((int) ($integration['businesses_discovered'] ?? 0))" />
                <x-ta.metric-card :label="$isTr ? 'Seçili Business' : 'Selected businesses'" :value="number_format((int) ($integration['businesses_selected'] ?? 0))" />
                <x-ta.metric-card :label="$isTr ? 'Keşfedilen Reklam Hesabı' : 'Ad accounts discovered'" :value="number_format((int) ($integration['ad_accounts_discovered'] ?? 0))" />
            </div>

            <ul class="card">
                @forelse ($integration['businesses'] ?? [] as $business)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $business['name'] }}</p>
                            <p class="mt-0.5 truncate text-[13px] text-gray-500">ID {{ $business['external_id'] }}</p>
                        </div>
                        @if ($business['selected'] ?? false)
                            <x-ta.pill tone="ok">{{ $isTr ? 'Seçili' : 'Selected' }}</x-ta.pill>
                        @endif
                        @if ($integration['actions']['select_business'] ?? false)
                            <button type="button" wire:click="toggleBusinessSelection('{{ $business['id'] }}')" class="{{ ($business['selected'] ?? false) ? 'btn-quiet' : 'btn' }} w-full sm:w-auto">
                                {{ ($business['selected'] ?? false) ? ($isTr ? 'Keşif kapsamından çıkar' : 'Remove from discovery') : ($isTr ? 'Keşif için kullan' : 'Use for discovery') }}
                            </button>
                        @endif
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" :title="$isTr ? 'Henüz Business Portfolio keşfedilmedi' : 'No Business Portfolios discovered yet'" :message="$isTr ? 'Meta bağlantısı aktifse Business’ları keşfederek başlayın.' : 'If Meta is authorized, start by discovering businesses.'" /></li>
                @endforelse
            </ul>
        </section>

        <section x-data="{ q: '' }">
            <div class="section-head items-center">
                <h2 class="section-title" title="{{ $isTr ? 'Bir reklam hesabını ilgili marka ve Meta Ads dijital varlığı ile eşleştirin.' : 'Bind an ad account to the correct brand and Meta Ads Digital Asset.' }}">{{ $isTr ? 'Bağlanabilir Reklam Hesapları' : 'Available Ad Accounts' }} <span class="section-count">{{ count($integration['unbound_resources'] ?? []) }}</span></h2>
                <div class="ml-auto flex w-full flex-wrap items-center gap-2 sm:w-auto">
                    @if (! empty($integration['unbound_resources']))
                        <input type="search" x-model="q" placeholder="{{ $isTr ? 'Hesap ara…' : 'Search accounts…' }}" aria-label="{{ $isTr ? 'Hesap ara' : 'Search accounts' }}" class="{{ $input }} sm:w-56" />
                    @endif
                    @if (($integration['actions']['discover_businesses'] ?? false) || ($integration['actions']['discover_ad_accounts'] ?? false))
                        <button type="button" wire:click="refreshResources" class="btn">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ $isTr ? 'Kaynakları Yenile' : 'Refresh Resources' }}
                        </button>
                    @endif
                </div>
            </div>

            <ul class="card">
                @forelse ($integration['unbound_resources'] ?? [] as $resource)
                    <li x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower((string) $resource['name'])) }}.includes(q.toLocaleLowerCase('tr'))" class="card-row flex-wrap sm:flex-nowrap">
                        @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'meta'])
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5"><span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $resource['name'] }}</span>@if (! empty($resource['access_label']))<x-ta.pill tone="info">{{ $resource['access_label'] }}</x-ta.pill>@endif</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $accountLine($resource) }}</p>
                        </div>
                        @if ($integration['actions']['bind'] ?? false)
                            @if (auth()->user()?->hasRole(\App\Support\Roles::ADMIN))<button type="button" wire:click="bindResource('{{ $resource['id'] }}')" class="btn w-full sm:w-auto">{{ $isTr ? 'Markaya Bağla' : 'Bind to Brand' }}</button>@endif
                        @endif
                    </li>
                @empty
                    <li><x-ta.empty-state size="sm" :title="$isTr ? 'Bağlanmayı bekleyen reklam hesabı yok' : 'No unbound ad accounts'" :message="$isTr ? 'Yeni hesaplar için keşfi tekrar çalıştırabilirsiniz.' : 'Run discovery again to find new accounts.'" /></li>
                @endforelse
            </ul>
        </section>

        <section>
            <div class="section-head">
                <h2 class="section-title" title="{{ $isTr ? 'Bu hesaplar Meta Ads dijital varlığı üzerinden veri toplamaya uygundur.' : 'These accounts are eligible for collection through Meta Ads Digital Assets.' }}">{{ $isTr ? 'Bağlı Reklam Hesapları' : 'Connected Ad Accounts' }} <span class="section-count">{{ count($integration['bindings'] ?? []) }}</span></h2>
            </div>
            <ul class="card">
                @forelse ($integration['bindings'] ?? [] as $binding)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'meta'])
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5"><span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $binding['resource'] }}</span><x-ta.pill tone="ok">{{ $isTr ? 'Bağlı' : 'Bound' }}</x-ta.pill></p>
                            <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400" title="{{ str_replace(' · ', ', ', (string) $binding['asset']) }}">{{ $accountLine($binding) }}. {{ str_replace(' · ', ', ', (string) $binding['asset']) }}@if (! empty($binding['brand'])), {{ $binding['brand'] }}@endif</p>
                        </div>
                        <div class="flex w-full flex-wrap gap-2 sm:w-auto sm:flex-nowrap">
                            @if ($integration['actions']['unbind'] ?? false)
                                <button type="button" wire:click="unbindBinding('{{ $binding['id'] }}')" wire:confirm="{{ $isTr ? 'Bu reklam hesabının Meta Ads varlığıyla bağlantısı kaldırılsın mı?' : 'Disconnect this Ad Account from its Meta Ads asset?' }}" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">{{ $isTr ? 'Bağlantıyı Kaldır' : 'Unbind' }}</button>
                            @endif
                            @if (! empty($binding['route']) && ! empty($binding['asset_id']))
                                <a href="{{ route($binding['route'], ['assetId' => $binding['asset_id']]) }}" wire:navigate class="btn">{{ $isTr ? 'Çalışma Alanını Aç' : 'Open Workspace' }}</a>
                            @endif
                        </div>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" :title="$isTr ? 'Henüz bağlı reklam hesabı yok.' : 'No connected ad accounts yet.'" /></li>
                @endforelse
            </ul>
        </section>

    {{-- DATA COLLECTION --}}
    @elseif ($tab === 'connectors')
        <section>
            <div class="section-head items-center">
                <h2 class="section-title" title="{{ $isTr ? 'Bağlı reklam hesaplarından performans, dönüşüm, kreatif, hedefleme ve ölçüm verilerini veri havuzuna aktarır.' : 'Ingests performance, conversion, creative, targeting and measurement data from bound ad accounts into the Data Pool.' }}">{{ $isTr ? 'Meta Ads Veri Toplama' : 'Meta Ads Data Collection' }}</h2>
                @if ($integration['actions']['collect'] ?? false)
                    <button type="button" wire:click="collectData" wire:loading.attr="disabled" class="btn-primary ml-auto">
                        {!! $spin !!}
                        {{ $collectionReady ? ($isTr ? 'Şimdi Güncelle' : 'Refresh Now') : ($isTr ? 'İlk Toplamayı Başlat' : 'Start Initial Collection') }}
                    </button>
                @endif
            </div>

            @if (! $bindingReady)
                <div class="card mb-3 flex flex-wrap items-center gap-3.5 px-4 py-3.5 sm:flex-nowrap">
                    <span class="{{ $lookIcon }}">{!! $warnIcon !!}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $isTr ? 'Önce bir reklam hesabı bağlayın' : 'Bind an ad account first' }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $isTr ? 'Veri toplama yalnız senin onayladığın reklam hesabı bağlantıları üzerinden çalışır.' : 'Collection runs only against human-confirmed Meta Ad Account bindings.' }}</p>
                    </div>
                    <button type="button" wire:click="setTab('resources')" class="btn w-full sm:w-auto">{{ $isTr ? 'Reklam hesaplarına git' : 'Go to ad accounts' }}</button>
                </div>
            @endif

            <div class="metric-strip grid-cols-1 sm:grid-cols-3">
                <x-ta.metric-card :label="$isTr ? 'Bağlı hesap' : 'Bound accounts'" :value="number_format((int) ($integration['bound'] ?? 0))" />
                <x-ta.metric-card :label="$isTr ? 'Toplama durumu' : 'Collection status'" :value="$collectionLabel" muted />
                <x-ta.metric-card :label="$isTr ? 'Veri havuzu' : 'Data Pool'" :value="$dataLabel" muted />
            </div>
        </section>

        @if (! empty($preflight))
            <section>
                <div class="section-head">
                    <h2 class="section-title">{{ $isTr ? 'Toplama Hazırlığı' : 'Collection Readiness' }}</h2>
                    <x-ta.pill :tone="($preflight['can_start'] ?? false) ? 'ok' : 'look'">{{ ($preflight['can_start'] ?? false) ? ($isTr ? 'Hazır' : 'Ready') : ($isTr ? 'Dikkat gerekli' : 'Needs attention') }}</x-ta.pill>
                </div>
                <div class="metric-strip grid-cols-3">
                    <x-ta.metric-card :label="$isTr ? 'Uygun hesap' : 'Eligible resources'" :value="number_format((int) ($preflight['summary']['eligible_resources'] ?? 0))" />
                    <x-ta.metric-card :label="$isTr ? 'Planlanan veri seti' : 'Planned datasets'" :value="number_format((int) ($preflight['summary']['planned_datasets'] ?? 0))" />
                    <x-ta.metric-card :label="$isTr ? 'Zaten güncel' : 'Already satisfied'" :value="number_format((int) ($preflight['summary']['already_satisfied_datasets'] ?? 0))" />
                </div>
                @if (! empty($preflight['action_required']) || ! empty($preflight['message']))
                    <ul class="card mt-3">
                        @foreach ($preflight['action_required'] ?? [] as $issue)
                            <li class="card-row text-sm">
                                <span class="{{ $lookIcon }}">{!! $warnIcon !!}</span>
                                <span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $issue['label'] ?? ($isTr ? 'İşlem gerekli' : 'Action required') }}</span>
                                <span class="text-xs text-gray-500">{{ $issue['provider_or_source'] ?? 'META_ADS' }}</span>
                            </li>
                        @endforeach
                        @if (! empty($preflight['message']))
                            <li class="card-row text-[13px] text-gray-500">{{ $preflight['message'] }}</li>
                        @endif
                    </ul>
                @endif
            </section>
        @endif

        <section>
            <div class="section-head">
                <h2 class="section-title" title="{{ $isTr ? 'Meta Ads uzman analizi için gereken veri katmanları.' : 'Data layers required for specialist Meta Ads analysis.' }}">{{ $isTr ? 'Topladığımız Veri Grupları' : 'Collected Data Groups' }}</h2>
            </div>
            <div class="metric-strip sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    [$isTr ? 'Performans' : 'Performance', $isTr ? 'Hesap, kampanya, reklam seti ve reklam günlük metrikleri' : 'Daily account, campaign, ad set and ad metrics'],
                    [$isTr ? 'Dönüşümler' : 'Conversions', $isTr ? 'Aksiyonlar, aksiyon değerleri ve dönüşüm türleri' : 'Actions, action values and conversion event types'],
                    [$isTr ? 'Kreatif ve video' : 'Creative & Video', $isTr ? 'Kreatif ilişkileri ve video izleme hunisi' : 'Creative relationships and video viewing funnel'],
                    [$isTr ? 'Kırılımlar' : 'Breakdowns', $isTr ? 'Ülke, yaş/cinsiyet, yerleşim ve cihaz' : 'Country, age/gender, placement and device'],
                    [$isTr ? 'Hedefleme' : 'Targeting', $isTr ? 'Reklam seti hedeflemesi, optimizasyon hedefi ve atıf ayarı' : 'Ad set targeting, optimization goal and attribution config'],
                    [$isTr ? 'Ölçüm Sağlığı' : 'Measurement Health', $isTr ? 'Pixel / veri seti ve özel dönüşüm bilgileri' : 'Pixel / Dataset and Custom Conversion metadata'],
                ] as [$groupTitle, $groupDescription])
                    <div class="bg-white px-4 py-3.5 dark:bg-gray-900">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $groupTitle }}</p>
                        <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $groupDescription }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section>
            <div class="section-head">
                <h2 class="section-title">{{ $isTr ? 'Geçmiş veri ve yenileme' : 'Historical Data Policy' }}</h2>
            </div>
            <dl class="card">
                @foreach ([
                    [$isTr ? 'Hesap ve kampanya, günlük' : 'CORE: Account + Campaign daily', '≈ 37 '.($isTr ? 'ay' : 'months')],
                    [$isTr ? 'Reklam seti, reklam, aksiyonlar, kreatif, kırılımlar' : 'WARM: Ad Set, Ad, actions, creative, breakdowns', '≈ 13 '.($isTr ? 'ay' : 'months')],
                    [$isTr ? 'Saatlik veri' : 'HOT: Hourly data', '90 '.($isTr ? 'gün' : 'days')],
                    [$isTr ? 'Günlük tekrar okuma' : 'Daily replay', '7 '.($isTr ? 'gün' : 'days')],
                    [$isTr ? 'Haftalık mutabakat' : 'Weekly reconciliation', '35 '.($isTr ? 'gün' : 'days')],
                    [$isTr ? 'Kaynak birimi' : 'Reporting grain', $isTr ? 'Reklam hesabı' : 'Ad Account'],
                ] as [$termLabel, $termValue])
                    <div class="card-row text-sm">
                        <dt class="min-w-0 flex-1 text-gray-600 dark:text-gray-300">{{ $termLabel }}</dt>
                        <dd class="shrink-0 font-medium tabular-nums text-gray-900 dark:text-white">{{ $termValue }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

    {{-- HISTORY --}}
    @else
        <div class="grid gap-10 lg:grid-cols-[1fr_.65fr]">
            <section>
                <div class="section-head">
                    <h2 class="section-title" title="{{ $isTr ? 'Yalnızca Meta bağlantısı, keşif, bağlama ve veri toplama olayları.' : 'Only Meta authorization, discovery, binding and collection events.' }}">{{ $isTr ? 'Meta Aktivite Geçmişi' : 'Meta Activity History' }}</h2>
                </div>
                <ul class="card">
                    @forelse ($integration['activity'] ?? [] as $event)
                        @php
                            $eventStatus = (string) ($event['status'] ?? 'info');
                            $eventTone = in_array($eventStatus, ['success', 'completed'], true)
                                ? 'ok'
                                : (in_array($eventStatus, ['failed', 'needs_attention', 'error', 'warning'], true) ? 'look' : 'info');
                        @endphp
                        <li class="card-row flex-wrap sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $L($event['event']) }}</p>
                                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $L($event['actor']) }}, {{ $event['when'] }}</p>
                            </div>
                            <x-ta.pill :tone="$eventTone">{{ $L($eventStatus) }}</x-ta.pill>
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" size="sm" :title="$isTr ? 'Henüz Meta aktivitesi yok.' : 'No Meta activity yet.'" /></li>
                    @endforelse
                </ul>
            </section>

            <section>
                <div class="section-head"><h3 class="section-title">{{ $isTr ? 'Mevcut Durum' : 'Current State' }}</h3></div>
                <dl class="card">
                    @foreach ([
                        [$isTr ? 'Yetki' : 'OAuth', $L($integration['auth_status_label'] ?? '—')],
                        [$isTr ? 'İşletme' : 'Business', number_format((int) ($integration['businesses_discovered'] ?? 0))],
                        [$isTr ? 'Reklam hesabı' : 'Ad Accounts', number_format((int) ($integration['ad_accounts_discovered'] ?? 0))],
                        [$isTr ? 'Bağlı hesap' : 'Bindings', number_format((int) ($integration['bound'] ?? 0))],
                        [$isTr ? 'Veri çekimi' : 'Collection', $collectionLabel],
                    ] as [$termLabel, $termValue])
                        <div class="card-row text-sm">
                            <dt class="flex-1 text-[13px] text-gray-500">{{ $termLabel }}</dt>
                            <dd class="max-w-[60%] truncate text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $termValue }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>
    @endif

    {{-- Bind modal --}}
    @if ($showBindModal ?? false)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="meta-bind-title">
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-4 dark:border-gray-800">
                    <div>
                        <h2 id="meta-bind-title" class="text-base font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam Hesabını Markaya Bağla' : 'Bind Ad Account to Brand' }}</h2>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ $isTr ? 'Yalnız seçili reklam hesabı Meta Ads dijital varlığına bağlanır; Business Portfolio’nun tamamı bağlanmaz.' : 'This binds only the selected ad account to a Meta Ads Digital Asset; it does not bind the whole Business Portfolio.' }}</p>
                    </div>
                    <button type="button" wire:click="cancelBind" class="btn-quiet h-8 w-8 px-0!" aria-label="Close">×</button>
                </div>

                <div class="space-y-5 p-6">
                    @if ($bindingPreview)
                        <div class="flex items-center gap-3">
                            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'meta', 'size' => 'lg'])
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $bindingPreview['name'] }}</p>
                                <p class="mt-0.5 text-[13px] text-gray-500">{{ collect(['ID '.$bindingPreview['external_id'], $bindingPreview['business'] ?? null, $bindingPreview['currency'] ?? null, $bindingPreview['timezone'] ?? null])->filter()->implode(', ') }}</p>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label for="meta-bind-brand" class="{{ $label }}">{{ $isTr ? 'Müşteri / Marka' : 'Customer / Brand' }}</label>
                        <select id="meta-bind-brand" wire:model.live="brandId" class="{{ $input }} mt-1">
                            <option value="">{{ $isTr ? 'Marka seçin…' : 'Select Brand…' }}</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand['id'] }}">{{ $brand['label'] }}</option>
                            @endforeach
                        </select>
                        @if ($suggestedBrandId && (int) $brandId !== (int) $suggestedBrandId)
                            <p class="mt-1.5 text-[13px] text-gray-500">Öneri (isim / alan adı benzerliği): <strong class="font-medium text-gray-900 dark:text-white">{{ $suggestedBrandName }}</strong> <button type="button" wire:click="useSuggestedBrand" class="link-primary">Bu markayı seç</button></p>
                        @endif
                    </div>

                    <fieldset>
                        <legend class="{{ $label }}">{{ $isTr ? 'Meta Ads dijital varlığı' : 'Meta Ads Digital Asset' }}</legend>
                        <div class="mt-1 grid gap-2">
                            <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border p-3.5', 'border-gray-900 dark:border-white' => $bindMode === 'create_asset', 'border-gray-200 dark:border-gray-800' => $bindMode !== 'create_asset'])>
                                <input type="radio" wire:model.live="bindMode" value="create_asset" class="mt-0.5 text-gray-900" />
                                <span><span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $isTr ? 'Yeni / uygun Meta Ads varlığını kullan' : 'Use a new / compatible Meta Ads asset' }}</span><span class="mt-0.5 block text-[13px] text-gray-500">{{ $isTr ? 'Markada uygun boş Meta Ads varlığı varsa o kullanılır; yoksa oluşturulur.' : 'An existing compatible unbound Meta Ads asset is reused; otherwise one is created.' }}</span></span>
                            </label>
                            <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border p-3.5', 'border-gray-900 dark:border-white' => $bindMode === 'existing_asset', 'border-gray-200 dark:border-gray-800' => $bindMode !== 'existing_asset'])>
                                <input type="radio" wire:model.live="bindMode" value="existing_asset" class="mt-0.5 text-gray-900" />
                                <span><span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $isTr ? 'Mevcut Meta Ads varlığına bağla' : 'Bind an existing Meta Ads asset' }}</span><span class="mt-0.5 block text-[13px] text-gray-500">{{ $isTr ? 'Markadaki belirli bir Meta Ads varlığını seçin.' : 'Choose a specific Meta Ads Digital Asset in this brand.' }}</span></span>
                            </label>
                        </div>
                    </fieldset>

                    @if ($bindMode === 'create_asset')
                        <div>
                            <label for="meta-bind-asset-name" class="{{ $label }}">{{ $isTr ? 'Dijital varlık adı' : 'Digital Asset name' }}</label>
                            <input id="meta-bind-asset-name" type="text" wire:model="assetName" class="{{ $input }} mt-1" />
                        </div>
                    @else
                        <div>
                            <label for="meta-bind-existing-asset" class="{{ $label }}">{{ $isTr ? 'Mevcut Meta Ads varlığı' : 'Existing Meta Ads Digital Asset' }}</label>
                            <select id="meta-bind-existing-asset" wire:model="digitalAssetId" class="{{ $input }} mt-1">
                                <option value="">{{ $isTr ? 'Varlık seçin…' : 'Select asset…' }}</option>
                                @forelse ($compatibleAssets as $asset)
                                    <option value="{{ $asset['id'] }}">{{ $asset['name'] }}@if (! empty($asset['has_active_binding'])), {{ $isTr ? 'zaten bağlı' : 'already connected' }}@endif</option>
                                @empty
                                    <option value="" disabled>{{ $isTr ? 'Bu markada Meta Ads varlığı yok' : 'No Meta Ads assets in this brand' }}</option>
                                @endforelse
                            </select>
                        </div>
                    @endif

                    <label class="flex items-start gap-2.5 rounded-lg border border-gray-200 p-3 text-[13px] text-gray-700 dark:border-gray-800 dark:text-gray-300">
                        <input type="checkbox" wire:model="allowReplace" class="mt-0.5 rounded border-gray-300 text-gray-900" />
                        <span>{{ $isTr ? 'Seçilen Meta Ads varlığında başka bir reklam hesabı bağlıysa onu değiştir. Önceki hesabın geçmiş verileri kendi hesabında korunur.' : 'If another ad account is already bound to the selected Meta Ads asset, replace it. Historical data remains attached to the previous account.' }}</span>
                    </label>
                </div>

                @if ($ownershipConflict)
                    <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-800">
                        <x-operator.ownership-transfer-panel :conflict="$ownershipConflict" action="transferBind" :can-transfer="auth()->user()?->hasRole(\App\Support\Roles::ADMIN) ?? false" />
                    </div>
                @endif
                <div @class(['flex justify-end gap-2 border-t border-gray-200 px-6 py-4 dark:border-gray-800', 'hidden' => $ownershipConflict !== null])>
                    <button type="button" wire:click="cancelBind" class="btn">{{ $isTr ? 'Vazgeç' : 'Cancel' }}</button>
                    <button type="button" wire:click="confirmBind" class="btn-primary">{{ $isTr ? 'Bağlantıyı Onayla' : 'Confirm Connection' }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
