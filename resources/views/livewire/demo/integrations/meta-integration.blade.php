@php
    $isTr = app()->getLocale() === 'tr';
    $appReady = (bool) ($integration['app_configured'] ?? false);
    $authReady = ($integration['auth_status'] ?? '') === 'connected';
    $businessReady = (int) ($integration['businesses_selected'] ?? 0) > 0;
    $bindingReady = (int) ($integration['bound'] ?? 0) > 0;
    $collectionLabel = (string) ($integration['collection_state_label'] ?? ($isTr ? 'Henüz çalıştırılmadı' : 'Not run yet'));
    $dataLabel = (string) ($integration['data_state_label'] ?? ($isTr ? 'Veri yok' : 'No data'));
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
            'detail' => $authReady ? ($isTr ? 'Meta bağlı' : 'Meta connected') : ($isTr ? 'OAuth gerekli' : 'OAuth required'),
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

    $nextStep = collect($steps)->first(fn (array $step) => ! $step['done']);
@endphp

<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <header class="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <img src="{{ asset('images/digital-assets/meta.svg') }}" alt="" class="mt-1 h-8 w-8 shrink-0 object-contain" aria-hidden="true" />
            <div class="min-w-0">
                <p class="text-xs text-gray-500">Meta Marketing API</p>
                <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $isTr ? 'Meta Ads Entegrasyonu' : 'Meta Ads Integration' }}</h1>
                <x-ta.pill :tone="$authReady ? 'ok' : 'look'" class="mt-1 text-sm">{{ $authReady ? ($isTr ? 'Bağlı' : 'Connected') : ($isTr ? 'Bağlantı gerekli' : 'Connection required') }}</x-ta.pill>
            </div>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2">
            @if (! $appReady)
                <button type="button" wire:click="setTab('configuration')" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600">
                    {{ $isTr ? 'Bağlantıyı Kur' : 'Set up connection' }}
                </button>
            @elseif (! $authReady)
                @if (! empty($integration['authorize_url']))
                    <a href="{{ $integration['authorize_url'] }}" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600">
                        {{ $isTr ? 'Meta ile Bağlan' : 'Connect Meta' }}
                    </a>
                @else
                    <button type="button" wire:click="bootstrapAndConnect" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600">
                        {{ $isTr ? 'Meta ile Bağlan' : 'Connect Meta' }}
                    </button>
                @endif
            @elseif (! $bindingReady)
                <button type="button" wire:click="setTab('resources')" class="inline-flex items-center justify-center rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600">
                    {{ $isTr ? 'Reklam Hesabı Bağla' : 'Bind Ad Account' }}
                </button>
            @elseif ($integration['actions']['collect'] ?? false)
                <button type="button" wire:click="collectData" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs transition hover:bg-brand-600 disabled:opacity-60">
                    <svg wire:loading.class="animate-spin" wire:target="collectData" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    {{ $collectionReady ? ($isTr ? 'Verileri Güncelle' : 'Refresh Data') : ($isTr ? 'Verileri Topla' : 'Collect Data') }}
                </button>
            @endif

            <a href="{{ route('operator.integrations') }}" wire:navigate class="inline-flex items-center justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 transition hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.03]">
                {{ $isTr ? 'Entegrasyonlar' : 'Integrations' }}
            </a>
        </div>
    </header>

    {{-- Setup progress --}}
    @include('livewire.demo.integrations.partials.setup-steps', ['steps' => $steps])

    @if ($confirmDisconnect ?? false)
        <section class="rounded-lg p-4 tone-look">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 inline-flex shrink-0 text-warning-700 dark:text-warning-300">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4m0 4h.01M10.3 4.5L2.8 17.5A2 2 0 004.5 20h15a2 2 0 001.7-3L13.7 4.5a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Meta yetkilendirmesini kaldır?' : 'Disconnect Meta authorization?' }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $isTr ? 'OAuth yetkisi kaldırılır; keşfedilmiş Business ve reklam hesapları, binding kayıtları ve geçmiş veriler silinmez.' : 'OAuth authorization is removed; discovered businesses, ad accounts, bindings and historical data are preserved.' }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" wire:click="confirmDisconnectAction" class="rounded-lg bg-warning-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-warning-700">{{ $isTr ? 'Bağlantıyı Kes' : 'Disconnect' }}</button>
                        <button type="button" wire:click="cancelDisconnect" class="rounded-lg bg-white px-3.5 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Vazgeç' : 'Cancel' }}</button>
                    </div>
                </div>
            </div>
        </section>
    @endif

    {{-- Tabs --}}
    <div class="overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Meta Ads integration sections">
        <div class="flex min-w-max gap-5">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" @class([
                    '-mb-px border-b-2 px-0.5 pb-3 pt-1 text-sm font-medium transition',
                    'border-gray-900 text-gray-900 dark:border-white dark:text-white' => $tab === $key,
                    'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' => $tab !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- OVERVIEW --}}
    @if ($tab === 'overview')
        <div class="grid grid-cols-2 gap-x-8 gap-y-6 xl:grid-cols-4">
            <article>
                <p class="text-xs text-gray-500">Business Portfolio</p>
                <p class="mt-1 text-[26px] leading-8 font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format((int) ($integration['businesses_discovered'] ?? 0)) }}</p>
                <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isTr ? 'Keşfedilen işletme' : 'Businesses discovered' }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ number_format((int) ($integration['businesses_selected'] ?? 0)) }} {{ $isTr ? 'tanesi discovery için seçili' : 'selected for discovery' }}</p>
            </article>

            <article>
                <p class="text-xs text-gray-500">Ad Accounts</p>
                <p class="mt-1 text-[26px] leading-8 font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format((int) ($integration['ad_accounts_discovered'] ?? 0)) }}</p>
                <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isTr ? 'Keşfedilen reklam hesabı' : 'Ad accounts discovered' }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ number_format((int) ($integration['available'] ?? 0)) }} {{ $isTr ? 'bağlanabilir hesap' : 'available to bind' }}</p>
            </article>

            <article>
                <p class="text-xs text-gray-500">Bindings</p>
                <p class="mt-1 text-[26px] leading-8 font-semibold tabular-nums text-gray-900 dark:text-white">{{ number_format((int) ($integration['bound'] ?? 0)) }}</p>
                <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isTr ? 'MoxDOP’a bağlı hesap' : 'Accounts bound to MoxDOP' }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $bindingReady ? ($isTr ? 'Analitik toplama için hazır' : 'Ready for analytical collection') : ($isTr ? 'Henüz hesap bağlanmadı' : 'No account bound yet') }}</p>
            </article>

            <article>
                <p class="text-xs text-gray-500">Data Pool</p>
                <p class="mt-1 truncate text-lg font-bold text-gray-900 dark:text-white">{{ $collectionLabel }}</p>
                <p class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isTr ? 'Veri toplama durumu' : 'Collection status' }}</p>
                <p class="mt-1 truncate text-xs text-gray-500">{{ $dataLabel }}</p>
            </article>
        </div>

        <div class="grid gap-10 xl:grid-cols-[1.35fr_.65fr]">
            <section>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="section-title">{{ $isTr ? 'Meta Ads bağlantısı ne durumda?' : 'Where does the Meta Ads connection stand?' }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'MoxDOP’un veri çekebilmesi için gereken dört aşama.' : 'The four stages required before MoxDOP can collect Meta Ads data.' }}</p>
                    </div>
                    @if ($nextStep)
                        <button type="button" wire:click="setTab('{{ $nextStep['tab'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg px-3.5 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700">
                            {{ $isTr ? 'Sonraki adım' : 'Next step' }}
                        </button>
                    @endif
                </div>

                <div class="mt-4 divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($steps as $index => $step)
                        <button type="button" wire:click="setTab('{{ $step['tab'] }}')" class="flex w-full items-center gap-4 py-3 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <span @class([
                                'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                                'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $step['done'],
                                'text-gray-500 ring-1 ring-inset ring-gray-300 dark:text-gray-400 dark:ring-gray-700' => ! $step['done'],
                            ])>
                                @if ($step['done'])
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.5l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                @else
                                    <span class="text-xs font-bold">{{ $index + 1 }}</span>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ $step['label'] }}</span>
                                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $step['detail'] }}</span>
                            </span>
                            <svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    @endforeach
                </div>
            </section>

            <section>
                <p class="text-xs text-gray-500">{{ $isTr ? 'Bağlantı Sağlığı' : 'Connection Health' }}</p>
                <h2 class="section-title mt-1">{{ $authReady ? ($isTr ? 'Meta yetkisi aktif' : 'Meta authorization active') : ($isTr ? 'Kurulum tamamlanmadı' : 'Setup incomplete') }}</h2>
                <div class="mt-5 space-y-4">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 pb-3 dark:border-gray-800">
                        <span class="text-sm text-gray-500">App</span>
                        <span class="text-sm font-semibold">{{ $integration['app_configuration_label'] ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 pb-3 dark:border-gray-800">
                        <span class="text-sm text-gray-500">OAuth</span>
                        <span class="text-sm font-semibold">{{ $integration['auth_status_label'] ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 pb-3 dark:border-gray-800">
                        <span class="text-sm text-gray-500">Graph API</span>
                        <span class="text-sm font-semibold">{{ $integration['graph_api_version'] ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-gray-500">{{ $isTr ? 'Bağlantı testi' : 'Connection test' }}</span>
                        <span class="text-sm font-semibold">{{ $integration['connection_test_label'] ?? '—' }}</span>
                    </div>
                </div>

                <div class="mt-6">
                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">{{ $isTr ? 'Analitik model' : 'Analytics model' }}</p>
                    <p class="mt-1 text-xs leading-5 text-gray-500">{{ $isTr ? 'Business Portfolio keşif konteyneridir. Veri toplama ve raporlama reklam hesabı seviyesinde yapılır.' : 'Business Portfolio is a discovery container. Collection and reporting operate at Ad Account level.' }}</p>
                </div>
            </section>
        </div>

        @if (! empty($integration['bindings']))
            <section>
                <div class="flex items-center justify-between gap-3 pb-3">
                    <div>
                        <h2 class="section-title">{{ $isTr ? 'Bağlı Reklam Hesapları' : 'Connected Ad Accounts' }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ $isTr ? 'Meta Ads Digital Asset’larına bağlanan hesaplar.' : 'Accounts bound to Meta Ads Digital Assets.' }}</p>
                    </div>
                    <button type="button" wire:click="setTab('resources')" class="text-sm link-neutral">{{ $isTr ? 'Yönet' : 'Manage' }}</button>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($integration['bindings'] as $binding)
                        <div class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['resource'] }}</p>
                                <p class="mt-1 text-xs text-gray-500">
                                    ID {{ $binding['external_id_masked'] ?? $binding['external_id'] ?? '—' }}
                                    @if (! empty($binding['business'])), {{ $binding['business'] }}@endif
                                    @if (! empty($binding['currency'])), {{ $binding['currency'] }}@endif
                                </p>
                                <p class="mt-1 text-xs text-gray-500">{{ $binding['asset'] }} @if (! empty($binding['brand'])) · {{ $binding['brand'] }} @endif</p>
                            </div>
                            @if (! empty($binding['route']) && ! empty($binding['asset_id']))
                                <a href="{{ route($binding['route'], ['assetId' => $binding['asset_id']]) }}" wire:navigate class="inline-flex shrink-0 items-center justify-center rounded-lg bg-gray-50 px-3.5 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-100 dark:bg-white/[0.03] dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Çalışma Alanını Aç' : 'Open Workspace' }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

    {{-- CONNECTION --}}
    @elseif ($tab === 'configuration')
        <div class="grid gap-10 xl:grid-cols-[1fr_.72fr]">
            <div class="space-y-10">
                <section>
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="section-title">{{ $isTr ? 'Uygulama Bilgileri' : 'Application Credentials' }}</h2>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Meta App ID ve App Secret yalnızca OAuth bağlantısını kurmak için kullanılır.' : 'Meta App ID and App Secret are used to establish the OAuth connection.' }}</p>
                        </div>
                        <x-ta.pill :tone="$appReady ? 'ok' : 'look'">{{ $appReady ? ($isTr ? 'Yapılandırıldı' : 'Configured') : ($isTr ? 'Eksik' : 'Missing') }}</x-ta.pill>
                    </div>

                    @if ($canManageCredentials ?? false)
                        <form wire:submit.prevent="saveMetaConfiguration" class="mt-6 space-y-5">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label for="meta-app-id" class="text-sm font-medium text-gray-700 dark:text-gray-300">Meta App ID</label>
                                    <input id="meta-app-id" wire:model="metaAppId" type="text" autocomplete="off" class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-theme-xs outline-none transition focus:border-brand-300 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                                    @error('metaAppId') <p class="mt-1.5 text-xs text-error-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="meta-app-secret" class="text-sm font-medium text-gray-700 dark:text-gray-300">Meta App Secret</label>
                                    <input id="meta-app-secret" wire:model="metaAppSecret" type="password" autocomplete="new-password" placeholder="{{ ($metaAppSecretConfigured ?? false) ? ($isTr ? 'Değiştirmek için yeni değer girin' : 'Enter a new value to replace') : '' }}" class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-900 shadow-theme-xs outline-none transition focus:border-brand-300 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                                    <p class="mt-1.5 text-xs text-gray-500">{{ ($metaAppSecretConfigured ?? false) ? ($isTr ? 'Kayıtlı. Boş bırakırsanız mevcut secret korunur.' : 'Stored. Leave blank to keep the existing secret.') : ($isTr ? 'Kaydedildikten sonra tekrar gösterilmez.' : 'Never shown again after save.') }}</p>
                                    @error('metaAppSecret') <p class="mt-1.5 text-xs text-error-600">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            @if ($metaAppSecretConfigured ?? false)
                                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                    <input type="checkbox" wire:model="clearMetaAppSecret" class="rounded border-gray-300 text-brand-500 focus:ring-brand-500" />
                                    {{ $isTr ? 'Kayıtlı App Secret’ı temizle' : 'Clear stored App Secret' }}
                                </label>
                            @endif

                            <div class="flex flex-wrap gap-2 border-t border-gray-200 pt-5 dark:border-gray-800">
                                <button type="submit" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs hover:bg-brand-600">{{ $isTr ? 'Bilgileri Kaydet' : 'Save Credentials' }}</button>
                                <button type="button" wire:click="testMetaConfiguration" class="rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Bağlantıyı Test Et' : 'Test Connection' }}</button>
                                @if ($appReady || ($metaAppSecretConfigured ?? false))
                                    <button type="button" wire:click="askRemoveMetaCredentials" class="rounded-lg px-4 py-2.5 text-sm font-medium text-error-600 hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">{{ $isTr ? 'Bilgileri Kaldır' : 'Remove Credentials' }}</button>
                                @endif
                            </div>
                        </form>
                    @else
                        <div class="mt-5 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Meta uygulama bilgilerini yalnızca yöneticiler değiştirebilir.' : 'Only administrators can change Meta application credentials.' }}</div>
                    @endif
                </section>

                @if ($confirmRemoveMetaCredentials ?? false)
                    <section class="rounded-lg p-4 tone-critical">
                        <p class="text-sm font-semibold text-error-800 dark:text-error-300">{{ $isTr ? 'Meta uygulama bilgilerini kaldır?' : 'Remove Meta application credentials?' }}</p>
                        <p class="mt-1 text-sm text-error-700/80 dark:text-error-300/80">{{ $isTr ? 'App ID ve App Secret silinir. Keşfedilen kaynaklar, binding’ler ve geçmiş veriler korunur.' : 'App ID and App Secret are deleted. Discovered resources, bindings and history are preserved.' }}</p>
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="removeMetaConfiguration" class="rounded-lg bg-error-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-error-700">{{ $isTr ? 'Kaldır' : 'Remove' }}</button>
                            <button type="button" wire:click="cancelRemoveMetaCredentials" class="rounded-lg bg-white px-3.5 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Vazgeç' : 'Cancel' }}</button>
                        </div>
                    </section>
                @endif
            </div>

            <div class="space-y-10">
                <section>
                    <h2 class="section-title">{{ $isTr ? 'Meta Yetkilendirmesi' : 'Meta Authorization' }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'MoxDOP reklam hesaplarını okuyabilmek için Meta kullanıcı yetkisini kullanır.' : 'MoxDOP uses Meta user authorization to read accessible ad accounts.' }}</p>

                    <div class="mt-5">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm text-gray-500">{{ $isTr ? 'Durum' : 'Status' }}</span>
                            <x-ta.pill :tone="$authReady ? 'ok' : 'look'">{{ $integration['auth_status_label'] ?? '—' }}</x-ta.pill>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <span class="text-sm text-gray-500">{{ $isTr ? 'Kimlik bilgisi' : 'Credential' }}</span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $integration['authorization_credential_label'] ?? '—' }}</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <span class="text-sm text-gray-500">{{ $isTr ? 'Test' : 'Test' }}</span>
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $integration['connection_test_label'] ?? '—' }}</span>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-2">
                        @if (! $authReady && $appReady)
                            @if (! empty($integration['authorize_url']))
                                <a href="{{ $integration['authorize_url'] }}" class="inline-flex items-center justify-center rounded-lg bg-[#1877F2] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#166fe5]">{{ $isTr ? 'Meta ile Bağlan' : 'Connect with Meta' }}</a>
                            @else
                                <button type="button" wire:click="bootstrapAndConnect" class="rounded-lg bg-[#1877F2] px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-[#166fe5]">{{ $isTr ? 'Meta ile Bağlan' : 'Connect with Meta' }}</button>
                            @endif
                        @elseif ($authReady)
                            @if (($integration['actions']['reauthorize'] ?? false) && ! empty($integration['reauthorize_url']))
                                <a href="{{ $integration['reauthorize_url'] }}" class="inline-flex items-center justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Yeniden Yetkilendir' : 'Reauthorize Meta' }}</a>
                            @endif
                            @if ($integration['actions']['disconnect'] ?? false)
                                <button type="button" wire:click="askDisconnect" class="rounded-lg px-4 py-2.5 text-sm font-medium text-error-600 hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">{{ $isTr ? 'Meta Bağlantısını Kes' : 'Disconnect Meta' }}</button>
                            @endif
                        @else
                            <button type="button" wire:click="setTab('configuration')" class="rounded-lg bg-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $isTr ? 'Önce uygulama bilgilerini kaydedin' : 'Save application credentials first' }}</button>
                        @endif
                    </div>
                </section>

                <section>
                    <h3 class="section-title">{{ $isTr ? 'OAuth Callback' : 'OAuth Callback' }}</h3>
                    <p class="mt-1 text-xs leading-5 text-gray-800/75 dark:text-gray-300/75">{{ $isTr ? 'Meta Developer panelindeki Valid OAuth Redirect URI alanı MoxDOP callback adresiyle birebir eşleşmelidir.' : 'The Valid OAuth Redirect URI in Meta Developer must exactly match the MoxDOP callback URL.' }}</p>
                    <code class="mt-3 block overflow-x-auto rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-900 dark:bg-white/[0.03] dark:text-gray-200">{{ url('/integrations/meta/callback') }}</code>
                </section>
            </div>
        </div>

    {{-- ACCOUNTS --}}
    @elseif ($tab === 'resources')
        <div class="space-y-10">
            <section>
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="section-title">{{ $isTr ? 'Keşif Kapsamını Seçin' : 'Choose Discovery Scope' }}</h2>
                        <p class="mt-1 max-w-3xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Business Portfolio analitik varlık değildir. Yalnızca hangi reklam hesaplarının keşfedileceğini belirleyen erişim konteyneridir.' : 'Business Portfolio is not an analytical asset. It only controls which ad accounts can be discovered.' }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($integration['actions']['discover_businesses'] ?? false)
                            <button type="button" wire:click="discoverBusinesses" class="rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Business’ları Yenile' : 'Discover Businesses' }}</button>
                        @endif
                        @if ($integration['actions']['discover_ad_accounts'] ?? false)
                            <button type="button" wire:click="discoverAdAccounts" class="rounded-lg bg-brand-500 px-3.5 py-2 text-sm font-semibold text-white shadow-theme-xs hover:bg-brand-600">{{ $isTr ? 'Reklam Hesaplarını Keşfet' : 'Discover Ad Accounts' }}</button>
                        @endif
                    </div>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3">
                    <div>
                        <p class="text-xs text-gray-500">{{ $isTr ? 'Keşfedilen Business' : 'Businesses discovered' }}</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((int) ($integration['businesses_discovered'] ?? 0)) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">{{ $isTr ? 'Seçili Business' : 'Selected businesses' }}</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((int) ($integration['businesses_selected'] ?? 0)) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">{{ $isTr ? 'Keşfedilen Reklam Hesabı' : 'Ad accounts discovered' }}</p>
                        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((int) ($integration['ad_accounts_discovered'] ?? 0)) }}</p>
                    </div>
                </div>

                @if (empty($integration['businesses']))
                    <div class="mt-5 py-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Henüz Business Portfolio keşfedilmedi' : 'No Business Portfolios discovered yet' }}</p>
                        <p class="mt-1 text-sm text-gray-500">{{ $isTr ? 'Meta bağlantısı aktifse Business’ları keşfederek başlayın.' : 'If Meta is authorized, start by discovering businesses.' }}</p>
                    </div>
                @else
                    <div class="mt-5 grid gap-x-8 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($integration['businesses'] as $business)
                            <article @class([
                                'border-b border-gray-200 py-3 dark:border-gray-800',
                            ])>
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $business['name'] }}</p>
                                        <p class="mt-1 truncate text-xs text-gray-500">ID {{ $business['external_id'] }}</p>
                                    </div>
                                    @if ($business['selected'] ?? false)
                                        <x-ta.pill tone="ok">{{ $isTr ? 'Seçili' : 'Selected' }}</x-ta.pill>
                                    @endif
                                </div>
                                @if ($integration['actions']['select_business'] ?? false)
                                    <button type="button" wire:click="toggleBusinessSelection('{{ $business['id'] }}')" class="mt-2 rounded-lg px-3 py-1.5 text-xs font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-white/[0.03] dark:text-gray-300 dark:ring-gray-700">
                                        {{ ($business['selected'] ?? false) ? ($isTr ? 'Keşif kapsamından çıkar' : 'Remove from discovery') : ($isTr ? 'Keşif için kullan' : 'Use for discovery') }}
                                    </button>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>

            <section>
                <div class="flex flex-col gap-4 pb-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="section-title">{{ $isTr ? 'Bağlanabilir Reklam Hesapları' : 'Available Ad Accounts' }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bir reklam hesabını ilgili marka ve Meta Ads Digital Asset ile eşleştirin.' : 'Bind an ad account to the correct brand and Meta Ads Digital Asset.' }}</p>
                    </div>
                    @if (($integration['actions']['discover_businesses'] ?? false) || ($integration['actions']['discover_ad_accounts'] ?? false))
                        <button type="button" wire:click="refreshResources" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            {{ $isTr ? 'Kaynakları Yenile' : 'Refresh Resources' }}
                        </button>
                    @endif
                </div>

                @if (empty($integration['unbound_resources']))
                    <div class="py-6">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Bağlanmayı bekleyen reklam hesabı yok' : 'No unbound ad accounts' }}</p>
                        <p class="mt-1 text-sm text-gray-500">{{ $isTr ? 'Yeni hesaplar için keşfi tekrar çalıştırabilirsiniz.' : 'Run discovery again to find new accounts.' }}</p>
                    </div>
                @else
                    <div x-data="{ q: '' }">
                    <div class="pb-3"><input type="search" x-model="q" placeholder="{{ $isTr ? 'Hesap ara…' : 'Search accounts…' }}" aria-label="{{ $isTr ? 'Hesap ara' : 'Search accounts' }}" class="w-full max-w-sm rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" /></div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($integration['unbound_resources'] as $resource)
                            <div x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower((string) $resource['name'])) }}.includes(q.toLocaleLowerCase('tr'))" class="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $resource['name'] }}</p>
                                        @if (! empty($resource['access_label']))
                                            <x-ta.pill tone="info">{{ $resource['access_label'] }}</x-ta.pill>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">
                                        ID {{ $resource['external_id_masked'] ?? $resource['external_id'] }}
                                        @if (! empty($resource['business'])), {{ $resource['business'] }}@endif
                                        @if (! empty($resource['currency'])), {{ $resource['currency'] }}@endif
                                        @if (! empty($resource['timezone'])), {{ $resource['timezone'] }}@endif
                                    </p>
                                </div>
                                @if ($integration['actions']['bind'] ?? false)
                                    @if (auth()->user()?->hasRole(\App\Support\Roles::ADMIN))<button type="button" wire:click="bindResource('{{ $resource['id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg px-3 py-2 text-sm font-medium text-gray-900 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-white dark:ring-gray-700 dark:hover:bg-gray-800">{{ $isTr ? 'Markaya Bağla' : 'Bind to Brand' }}</button>@endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    </div>
                @endif
            </section>

            <section>
                <div class="pb-3">
                    <h2 class="section-title">{{ $isTr ? 'Bağlı Reklam Hesapları' : 'Connected Ad Accounts' }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bu hesaplar Meta Ads Digital Asset üzerinden veri toplamaya uygundur.' : 'These accounts are eligible for collection through Meta Ads Digital Assets.' }}</p>
                </div>

                @if (empty($integration['bindings']))
                    <div class="py-6 text-sm text-gray-500">{{ $isTr ? 'Henüz bağlı reklam hesabı yok.' : 'No connected ad accounts yet.' }}</div>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($integration['bindings'] as $binding)
                            <div class="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $binding['resource'] }}</p>
                                        <x-ta.pill tone="ok">{{ $isTr ? 'Bağlı' : 'Bound' }}</x-ta.pill>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">
                                        ID {{ $binding['external_id_masked'] ?? $binding['external_id'] ?? '—' }}
                                        @if (! empty($binding['business'])) · {{ $binding['business'] }} @endif
                                        @if (! empty($binding['currency'])) · {{ $binding['currency'] }} @endif
                                    </p>
                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $binding['asset'] }}</span>
                                        @if (! empty($binding['brand']))
                                            <span class="text-gray-500">{{ $binding['brand'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    @if (! empty($binding['route']) && ! empty($binding['asset_id']))
                                        <a href="{{ route($binding['route'], ['assetId' => $binding['asset_id']]) }}" wire:navigate class="rounded-lg bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Çalışma Alanını Aç' : 'Open Workspace' }}</a>
                                    @endif
                                    @if ($integration['actions']['unbind'] ?? false)
                                        <button type="button" wire:click="unbindBinding('{{ $binding['id'] }}')" wire:confirm="{{ $isTr ? 'Bu reklam hesabının Meta Ads varlığıyla bağlantısı kaldırılsın mı?' : 'Disconnect this Ad Account from its Meta Ads asset?' }}" class="rounded-lg px-3.5 py-2 text-sm font-medium text-error-600 hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10">{{ $isTr ? 'Bağlantıyı Kaldır' : 'Unbind' }}</button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>

    {{-- DATA COLLECTION --}}
    @elseif ($tab === 'connectors')
        <div class="grid gap-10 xl:grid-cols-[1.25fr_.75fr]">
            <div class="space-y-10">
                <section>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <h2 class="section-title">{{ $isTr ? 'Meta Ads Veri Toplama' : 'Meta Ads Data Collection' }}</h2>
                            <p class="mt-1 max-w-2xl text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Bağlı reklam hesaplarından performans, dönüşüm, creative, targeting ve measurement verilerini Data Pool’a aktarır.' : 'Ingests performance, conversion, creative, targeting and measurement data from bound ad accounts into the Data Pool.' }}</p>
                        </div>
                        @if ($integration['actions']['collect'] ?? false)
                            <button type="button" wire:click="collectData" wire:loading.attr="disabled" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs hover:bg-brand-600 disabled:opacity-60">
                                <svg wire:loading.class="animate-spin" wire:target="collectData" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 12a8 8 0 10-2.34 5.66M20 12V6m0 6h-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ $collectionReady ? ($isTr ? 'Şimdi Güncelle' : 'Refresh Now') : ($isTr ? 'İlk Toplamayı Başlat' : 'Start Initial Collection') }}
                            </button>
                        @endif
                    </div>

                    <div class="mt-6 grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-gray-500">{{ $isTr ? 'Bağlı hesap' : 'Bound accounts' }}</p>
                            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((int) ($integration['bound'] ?? 0)) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">{{ $isTr ? 'Toplama durumu' : 'Collection status' }}</p>
                            <p class="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $collectionLabel }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">{{ $isTr ? 'Data Pool' : 'Data Pool' }}</p>
                            <p class="mt-1 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $dataLabel }}</p>
                        </div>
                    </div>

                    @if (! $bindingReady)
                        <div class="mt-5 flex items-start gap-3 rounded-lg p-4 text-sm tone-look">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-warning-700 dark:text-warning-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4m0 4h.01M10.3 4.5L2.8 17.5A2 2 0 004.5 20h15a2 2 0 001.7-3L13.7 4.5a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <div>
                                <p class="text-sm font-semibold text-warning-900 dark:text-warning-200">{{ $isTr ? 'Önce bir reklam hesabı bağlayın' : 'Bind an ad account first' }}</p>
                                <p class="mt-1 text-sm text-warning-800/80 dark:text-warning-300/80">{{ $isTr ? 'Veri toplama yalnızca insan tarafından onaylanmış Meta Ad Account binding’leri üzerinden çalışır.' : 'Collection runs only against human-confirmed Meta Ad Account bindings.' }}</p>
                                <button type="button" wire:click="setTab('resources')" class="mt-3 text-sm font-semibold text-warning-800 underline underline-offset-4 dark:text-warning-200">{{ $isTr ? 'Reklam hesaplarına git' : 'Go to ad accounts' }}</button>
                            </div>
                        </div>
                    @endif
                </section>

                @if (! empty($preflight))
                    <section>
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2 class="section-title">{{ $isTr ? 'Toplama Hazırlığı' : 'Collection Readiness' }}</h2>
                            </div>
                            <x-ta.pill :tone="($preflight['can_start'] ?? false) ? 'ok' : 'look'">{{ ($preflight['can_start'] ?? false) ? ($isTr ? 'Hazır' : 'Ready') : ($isTr ? 'Dikkat gerekli' : 'Needs attention') }}</x-ta.pill>
                        </div>

                        <div class="mt-5 grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3">
                            <div>
                                <p class="text-xs text-gray-500">{{ $isTr ? 'Uygun hesap' : 'Eligible resources' }}</p>
                                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((int) ($preflight['summary']['eligible_resources'] ?? 0)) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">{{ $isTr ? 'Planlanan dataset' : 'Planned datasets' }}</p>
                                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((int) ($preflight['summary']['planned_datasets'] ?? 0)) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">{{ $isTr ? 'Zaten güncel' : 'Already satisfied' }}</p>
                                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ number_format((int) ($preflight['summary']['already_satisfied_datasets'] ?? 0)) }}</p>
                            </div>
                        </div>

                        @foreach ($preflight['action_required'] ?? [] as $issue)
                            <div class="mt-4 rounded-lg p-4 text-sm tone-look">
                                <span class="font-semibold">{{ $issue['provider_or_source'] ?? 'META_ADS' }}</span>: {{ $issue['label'] ?? ($isTr ? 'İşlem gerekli' : 'Action required') }}
                            </div>
                        @endforeach

                        @if (! empty($preflight['message']))
                            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ $preflight['message'] }}</p>
                        @endif
                    </section>
                @endif

                <section>
                    <h2 class="section-title">{{ $isTr ? 'Topladığımız Veri Grupları' : 'Collected Data Groups' }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Meta Ads uzman analizi için gereken veri katmanları.' : 'Data layers required for specialist Meta Ads analysis.' }}</p>
                    <div class="mt-5 grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ([
                            [$isTr ? 'Performans' : 'Performance', $isTr ? 'Hesap, kampanya, ad set ve reklam günlük metrikleri' : 'Daily account, campaign, ad set and ad metrics'],
                            [$isTr ? 'Dönüşümler' : 'Conversions', $isTr ? 'Actions, action values ve conversion event türleri' : 'Actions, action values and conversion event types'],
                            ['Creative & Video', $isTr ? 'Creative ilişkileri ve video izleme hunisi' : 'Creative relationships and video viewing funnel'],
                            [$isTr ? 'Kırılımlar' : 'Breakdowns', $isTr ? 'Ülke, yaş/cinsiyet, placement ve cihaz' : 'Country, age/gender, placement and device'],
                            [$isTr ? 'Hedefleme' : 'Targeting', $isTr ? 'Ad set targeting, optimization goal ve attribution config' : 'Ad set targeting, optimization goal and attribution config'],
                            [$isTr ? 'Ölçüm Sağlığı' : 'Measurement Health', $isTr ? 'Pixel / Dataset ve Custom Conversion metadata' : 'Pixel / Dataset and Custom Conversion metadata'],
                        ] as [$title, $description])
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</p>
                                </div>
                                <p class="mt-2 text-xs leading-5 text-gray-500 dark:text-gray-400">{{ $description }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="space-y-10">
                <section>
                    <h2 class="section-title">{{ $isTr ? 'Geçmiş Veri Politikası' : 'Historical Data Policy' }}</h2>
                    <div class="mt-5 space-y-4">
                        <div>
                            <p class="text-xs text-gray-500">CORE</p>
                            <p class="mt-1 text-sm font-semibold">≈ 37 {{ $isTr ? 'ay' : 'months' }}</p>
                            <p class="mt-1 text-xs text-gray-500">Account + Campaign daily</p>
                        </div>
                        <div class="border-t border-gray-200 pt-4 dark:border-gray-800">
                            <p class="text-xs text-gray-500">WARM</p>
                            <p class="mt-1 text-sm font-semibold">≈ 13 {{ $isTr ? 'ay' : 'months' }}</p>
                            <p class="mt-1 text-xs text-gray-500">Ad Set, Ad, actions, creative, breakdowns</p>
                        </div>
                        <div class="border-t border-gray-200 pt-4 dark:border-gray-800">
                            <p class="text-xs text-gray-500">HOT</p>
                            <p class="mt-1 text-sm font-semibold">90 {{ $isTr ? 'gün' : 'days' }}</p>
                            <p class="mt-1 text-xs text-gray-500">Hourly data</p>
                        </div>
                    </div>
                </section>

                <section>
                    <h3 class="section-title">{{ $isTr ? 'Otomatik Güncelleme' : 'Automatic Refresh' }}</h3>
                    <div class="mt-4 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">{{ $isTr ? 'Günlük tekrar okuma' : 'Daily replay' }}</span><span class="font-semibold text-gray-900 dark:text-white">7 {{ $isTr ? 'gün' : 'days' }}</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">{{ $isTr ? 'Haftalık reconciliation' : 'Weekly reconciliation' }}</span><span class="font-semibold text-gray-900 dark:text-white">35 {{ $isTr ? 'gün' : 'days' }}</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">{{ $isTr ? 'Kaynak birimi' : 'Reporting grain' }}</span><span class="font-semibold text-gray-900 dark:text-white">Ad Account</span></div>
                    </div>
                </section>
            </aside>
        </div>

    {{-- HISTORY --}}
    @else
        <div class="grid gap-10 xl:grid-cols-[1fr_.65fr]">
            <section>
                <div class="pb-3">
                    <h2 class="section-title">{{ $isTr ? 'Meta Aktivite Geçmişi' : 'Meta Activity History' }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $isTr ? 'Yalnızca Meta bağlantısı, discovery, binding ve veri toplama olayları.' : 'Only Meta authorization, discovery, binding and collection events.' }}</p>
                </div>

                @if (empty($integration['activity']))
                    <div class="py-6 text-sm text-gray-500">{{ $isTr ? 'Henüz Meta aktivitesi yok.' : 'No Meta activity yet.' }}</div>
                @else
                    <div class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($integration['activity'] as $event)
                            @php
                                $eventStatus = (string) ($event['status'] ?? 'info');
                                $eventTone = in_array($eventStatus, ['success', 'completed'], true)
                                    ? 'success'
                                    : (in_array($eventStatus, ['failed', 'needs_attention', 'error', 'warning'], true) ? 'warning' : 'neutral');
                            @endphp
                            <div class="flex gap-3 py-2.5">
                                <span @class([
                                    'mt-2 h-1.5 w-1.5 shrink-0 rounded-full',
                                    'bg-success-500' => $eventTone === 'success',
                                    'bg-warning-500' => $eventTone === 'warning',
                                    'bg-gray-300 dark:bg-gray-600' => $eventTone === 'neutral',
                                ])></span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-start justify-between gap-2">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $event['event'] }}</p>
                                        <span class="text-xs text-gray-500">{{ $event['when'] }}</span>
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">{{ $event['actor'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <aside class="space-y-10">
                <section>
                    <h3 class="section-title">{{ $isTr ? 'Mevcut Durum' : 'Current State' }}</h3>
                    <div class="mt-4 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">OAuth</span><span class="font-semibold text-gray-900 dark:text-white">{{ $integration['auth_status_label'] ?? '—' }}</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Business</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format((int) ($integration['businesses_discovered'] ?? 0)) }}</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Ad Accounts</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format((int) ($integration['ad_accounts_discovered'] ?? 0)) }}</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Bindings</span><span class="font-semibold text-gray-900 dark:text-white">{{ number_format((int) ($integration['bound'] ?? 0)) }}</span></div>
                        <div class="flex items-center justify-between gap-3"><span class="text-gray-500">Collection</span><span class="max-w-[55%] truncate text-right font-semibold text-gray-900 dark:text-white">{{ $collectionLabel }}</span></div>
                    </div>
                </section>

            </aside>
        </div>
    @endif

    {{-- Bind modal --}}
    @if ($showBindModal ?? false)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="meta-bind-title">
            <div class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-gray-900">
                <div class="border-b border-gray-200 px-6 py-5 dark:border-gray-800">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 id="meta-bind-title" class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Reklam Hesabını Markaya Bağla' : 'Bind Ad Account to Brand' }}</h2>
                            <p class="mt-1 text-sm text-gray-500">{{ $isTr ? 'Bu işlem yalnızca seçili reklam hesabını Meta Ads Digital Asset ile eşleştirir; tüm Business Portfolio bağlanmaz.' : 'This binds only the selected ad account to a Meta Ads Digital Asset; it does not bind the whole Business Portfolio.' }}</p>
                        </div>
                        <button type="button" wire:click="cancelBind" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800 dark:hover:text-gray-200" aria-label="Close">×</button>
                    </div>
                </div>

                <div class="space-y-5 p-6">
                    @if ($bindingPreview)
                        <div>
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white ring-1 ring-inset ring-gray-200 dark:bg-white/95"><img src="{{ asset('images/digital-assets/meta.svg') }}" alt="" class="h-6 w-6" /></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $bindingPreview['name'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500">ID {{ $bindingPreview['external_id'] }} @if (! empty($bindingPreview['business'])) · {{ $bindingPreview['business'] }} @endif @if (! empty($bindingPreview['currency'])) · {{ $bindingPreview['currency'] }} @endif @if (! empty($bindingPreview['timezone'])) · {{ $bindingPreview['timezone'] }} @endif</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div>
                        <label for="meta-bind-brand" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isTr ? 'Müşteri / Marka' : 'Customer / Brand' }}</label>
                        <select id="meta-bind-brand" wire:model.live="brandId" class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-300 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                            <option value="">{{ $isTr ? 'Marka seçin…' : 'Select Brand…' }}</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand['id'] }}">{{ $brand['label'] }}</option>
                            @endforeach
                        </select>
                        @if ($suggestedBrandId && (int) $brandId !== (int) $suggestedBrandId)
                            <p class="mt-1 text-xs text-gray-500">Öneri (isim / alan adı benzerliği): <strong>{{ $suggestedBrandName }}</strong> <button type="button" wire:click="useSuggestedBrand" class="font-medium text-brand-600 hover:underline">Bu markayı seç</button></p>
                        @endif
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700 dark:text-gray-300">Meta Ads Digital Asset</legend>
                        <div class="mt-2 grid gap-2">
                            <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border p-3.5', 'border-gray-900 dark:border-white' => $bindMode === 'create_asset', 'border-gray-200 dark:border-gray-800' => $bindMode !== 'create_asset'])>
                                <input type="radio" wire:model.live="bindMode" value="create_asset" class="mt-0.5 text-brand-500 focus:ring-brand-500" />
                                <span><span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Yeni / uygun Meta Ads varlığını kullan' : 'Use a new / compatible Meta Ads asset' }}</span><span class="mt-0.5 block text-xs text-gray-500">{{ $isTr ? 'Markada uygun boş Meta Ads varlığı varsa tekrar kullanılır; yoksa oluşturulur.' : 'An existing compatible unbound Meta Ads asset is reused; otherwise one is created.' }}</span></span>
                            </label>
                            <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border p-3.5', 'border-gray-900 dark:border-white' => $bindMode === 'existing_asset', 'border-gray-200 dark:border-gray-800' => $bindMode !== 'existing_asset'])>
                                <input type="radio" wire:model.live="bindMode" value="existing_asset" class="mt-0.5 text-brand-500 focus:ring-brand-500" />
                                <span><span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ $isTr ? 'Mevcut Meta Ads varlığına bağla' : 'Bind an existing Meta Ads asset' }}</span><span class="mt-0.5 block text-xs text-gray-500">{{ $isTr ? 'Markadaki belirli bir Meta Ads Digital Asset’ı seçin.' : 'Choose a specific Meta Ads Digital Asset in this brand.' }}</span></span>
                            </label>
                        </div>
                    </fieldset>

                    @if ($bindMode === 'create_asset')
                        <div>
                            <label for="meta-bind-asset-name" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isTr ? 'Digital Asset adı' : 'Digital Asset name' }}</label>
                            <input id="meta-bind-asset-name" type="text" wire:model="assetName" class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-300 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                        </div>
                    @else
                        <div>
                            <label for="meta-bind-existing-asset" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $isTr ? 'Mevcut Meta Ads varlığı' : 'Existing Meta Ads Digital Asset' }}</label>
                            <select id="meta-bind-existing-asset" wire:model="digitalAssetId" class="mt-1.5 w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm text-gray-900 outline-none focus:border-brand-300 focus:ring-4 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                <option value="">{{ $isTr ? 'Varlık seçin…' : 'Select asset…' }}</option>
                                @forelse ($compatibleAssets as $asset)
                                    <option value="{{ $asset['id'] }}">{{ $asset['name'] }} @if (! empty($asset['has_active_binding'])) · {{ $isTr ? 'zaten bağlı' : 'already connected' }} @endif</option>
                                @empty
                                    <option value="" disabled>{{ $isTr ? 'Bu markada Meta Ads varlığı yok' : 'No Meta Ads assets in this brand' }}</option>
                                @endforelse
                            </select>
                        </div>
                    @endif

                    <label class="tone-look flex items-start gap-2.5 rounded-lg p-3 text-sm">
                        <input type="checkbox" wire:model="allowReplace" class="mt-0.5 rounded border-warning-300 text-warning-600 focus:ring-warning-500" />
                        <span>{{ $isTr ? 'Seçilen Meta Ads varlığında başka bir reklam hesabı bağlıysa onu değiştir. Önceki hesabın geçmiş verileri kendi hesabında korunur.' : 'If another ad account is already bound to the selected Meta Ads asset, replace it. Historical data remains attached to the previous account.' }}</span>
                    </label>
                </div>

                @if ($ownershipConflict)
                    <div class="border-t border-gray-200 px-6 py-4 dark:border-gray-800">
                        <x-operator.ownership-transfer-panel :conflict="$ownershipConflict" action="transferBind" :can-transfer="auth()->user()?->hasRole(\App\Support\Roles::ADMIN) ?? false" />
                    </div>
                @endif
                <div @class(['flex justify-end gap-2 border-t border-gray-200 px-6 py-4 dark:border-gray-800', 'hidden' => $ownershipConflict !== null])>
                    <button type="button" wire:click="cancelBind" class="rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">{{ $isTr ? 'Vazgeç' : 'Cancel' }}</button>
                    <button type="button" wire:click="confirmBind" class="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-theme-xs hover:bg-brand-600">{{ $isTr ? 'Bağlantıyı Onayla' : 'Confirm Connection' }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
