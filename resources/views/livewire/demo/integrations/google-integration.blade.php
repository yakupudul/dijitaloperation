@php
    // Panel concept 2026-10-20: "Google bağlı mı, veri geliyor mu; gelmiyorsa sıradaki adım ne?" The four setup steps
    // answer it first, the page's next action sits on the right of the head.
    $L = fn (?string $label): string => \App\Support\Integrations\Presentation\OperatorLabel::turkish($label);
    $gAppReady = ($integration['app_configuration_label'] ?? '') === __('operator.states.configured');
    $gAuthReady = ($integration['auth_status'] ?? '') === 'connected';
    $gAccountsReady = (int) ($integration['bound'] ?? 0) > 0;
    $gDataReady = $gAccountsReady && ($integration['collection_state'] ?? '') === 'completed';
    $steps = [
        ['label' => 'Uygulama', 'detail' => $gAppReady ? 'Hazır' : 'Client ID / Secret gerekli', 'done' => $gAppReady, 'tab' => 'configuration'],
        ['label' => 'Yetki', 'detail' => $gAuthReady ? 'Google bağlı' : 'Google ile yetki verin', 'done' => $gAuthReady, 'tab' => 'configuration'],
        ['label' => 'Hesaplar', 'detail' => $gAccountsReady ? ($integration['bound'] ?? 0).' hesap bağlı' : 'Hesapları keşfedip markaya bağlayın', 'done' => $gAccountsReady, 'tab' => 'resources'],
        ['label' => 'Veri', 'detail' => $gDataReady ? 'Veri geliyor' : 'İlk veri çekimi bekleniyor', 'done' => $gDataReady, 'tab' => 'overview'],
    ];
    $stateColor = match ($integration['state'] ?? '') {
        'connected' => 'ok',
        'needs_attention', 'authorization_expired', 'configuration_incomplete' => 'look',
        default => 'info',
    };
    $input = 'h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 outline-none focus:border-gray-400 focus:ring-2 focus:ring-gray-900/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    $chevron = '<svg class="h-4 w-4 shrink-0 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>';
@endphp
<div class="space-y-10">
    @include('livewire.demo.partials.flash')

    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 items-center gap-3">
            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => 'google', 'size' => 'lg'])
            <div class="min-w-0">
                <h1 class="page-title">{{ $integration['name'] }}</h1>
                <p class="page-sub flex flex-wrap items-center gap-x-3 gap-y-1">
                    <x-ta.pill :tone="$stateColor">{{ $L($integration['state_label']) }}</x-ta.pill>
                    @if (($integration['last_check'] ?? '—') !== '—')<span>Son kontrol {{ $integration['last_check'] }}</span>@endif
                    @if (! empty($integration['next_action_label']))
                        <span>Sonraki adım: {{ $L($integration['next_action_label']) }}</span>
                    @endif
                </p>
            </div>
        </div>
        <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
            <a href="{{ route('operator.integrations') }}" wire:navigate class="btn-quiet">Tüm entegrasyonlar</a>
            @if ($integration['actions']['discover'] ?? false)
                <button type="button" wire:click="discoverResources" class="btn">
                    {{ ($integration['resources_discovered'] ?? 0) > 0 ? 'Hesapları yenile' : 'Hesapları keşfet' }}
                </button>
            @endif
            @if ($integration['actions']['collect'] ?? false)
                {{-- Starts the initial backfill for every bound Google account; per-account refresh is in "Otomatik hesap güncellemeleri". --}}
                <button type="button" wire:click="collectData" wire:loading.attr="disabled" class="btn">Tüm hesaplar için veri çek</button>
            @endif
            @if (! ($integration['actions']['authorize'] ?? false))
                <button type="button" wire:click="setTab('configuration')" class="btn-primary">{{ __('operator.integrations_ui.configure') }}</button>
            @endif
            @if (($integration['actions']['authorize'] ?? false) && ! empty($integration['authorize_url']) && ($integration['auth_status'] ?? '') !== 'connected')
                <a href="{{ $integration['authorize_url'] }}" class="btn-primary">Google ile bağlan</a>
            @elseif (($integration['actions']['authorize'] ?? false) && empty($integration['integration_id']))
                <button type="button" wire:click="bootstrapAndConnect" class="btn-primary">Ayarla ve bağlan</button>
            @elseif (($integration['actions']['reauthorize'] ?? false) && ! empty($integration['reauthorize_url']))
                <a href="{{ $integration['reauthorize_url'] }}" class="{{ $gAuthReady ? 'btn' : 'btn-primary' }}">Yeniden yetki ver</a>
            @endif
        </div>
    </header>

    @include('livewire.demo.integrations.partials.setup-steps', ['steps' => $steps])

    <div class="mb-6! flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-800" role="tablist" aria-label="Google bölümleri">
        @foreach ([
            'overview' => 'Genel Bakış',
            'resources' => 'Hesaplar',
            'configuration' => 'Ayarlar',
            'activity' => 'Geçmiş',
        ] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class(['tab', 'tab-active' => $tab === $key])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'overview')
        <section>
            <div class="metric-strip grid-cols-2 lg:grid-cols-4">
                @if ($integration['discovery_not_run'] ?? true)
                    <x-ta.metric-card label="Bulunan hesaplar" value="—" hint="Henüz keşfedilmedi" />
                @else
                    <x-ta.metric-card label="Bulunan hesaplar" :value="$integration['resources_discovered']" />
                @endif
                <x-ta.metric-card label="Bağlı" :value="$integration['bound']" />
                <x-ta.metric-card label="Bağlanmamış" :value="$integration['available']" :tone="(int) $integration['available'] > 0 ? 'look' : 'neutral'" />
                <x-ta.metric-card label="Bağlı dijital varlıklar" :value="$integration['dependent_assets']" />
            </div>
            <p class="mt-3 text-[13px] text-gray-500 dark:text-gray-400">
                Veri çekimi: <span class="text-gray-900 dark:text-white">{{ $L($integration['collection_state_label'] ?? 'Henüz çalışmadı') }}</span>.
                Veri durumu: <span class="text-gray-900 dark:text-white">{{ $L($integration['data_state_label'] ?? 'Veri yok') }}</span>.
            </p>
        </section>

        @if (! empty($preflight))
            <section>
                <div class="section-head">
                    <h2 class="section-title">Google ilk veri çekimi</h2>
                    <span class="text-sm text-gray-500" title="Veri çekimi arka planda sürer; sayfadan ayrılabilirsiniz.">{{ $preflight['summary']['eligible_resources'] ?? 0 }} bağlı kaynak veri çekimine hazır</span>
                </div>
                <ul class="card">
                    @foreach ($preflight['summary']['by_connector'] ?? [] as $provider => $counts)
                        <li class="card-row text-sm">
                            <span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $provider)) }}</span>
                            <span class="text-[13px] tabular-nums text-gray-500">{{ $counts['eligible'] ?? 0 }} uygun, {{ $counts['bound'] ?? 0 }} bağlı</span>
                        </li>
                    @endforeach
                    @foreach ($preflight['connectors'] ?? [] as $connector)
                        @if (($connector['status'] ?? '') !== 'ready')
                            <li class="card-row text-sm">
                                <x-ta.pill tone="look">{{ $connector['provider_or_source'] ?? $connector['capability'] }}</x-ta.pill>
                                <span class="min-w-0 flex-1 text-gray-700 dark:text-gray-300">{{ $connector['label'] }}</span>
                            </li>
                        @endif
                    @endforeach
                    @if (($preflight['can_start'] ?? false) || ! empty($preflight['message']))
                        <li class="card-row text-[13px] text-gray-500">
                            {{ ($preflight['can_start'] ?? false) ? 'Veri çekimi arka planda sürer; sayfadan ayrılabilirsiniz.' : $preflight['message'] }}
                        </li>
                    @endif
                </ul>
            </section>
        @endif

        <section>
            <div class="section-head">
                <h2 class="section-title">Google ürünleri <span class="section-count">{{ count($integration['resource_groups']) }}</span></h2>
            </div>
            <ul class="card">
                @foreach ($integration['resource_groups'] as $group)
                    @php
                        $unit = $group['capability'] === 'google_business_profile' ? 'konum' : ($group['capability'] === 'google_ads' ? 'hesap' : 'mülk');
                        $blocked = in_array($group['discovery_status'] ?? '', ['external_access_required', 'setup_required', 'scope_required'], true);
                    @endphp
                    <li>
                        <a href="{{ route('operator.integrations.connector', ['connector' => $group['connector']]) }}" wire:navigate class="card-row transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $group['type']])
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $group['label'] }}</span>
                                    <x-ta.pill :tone="$blocked ? 'look' : (($group['discovery_status'] ?? '') === 'ok' || ($group['discovery_status'] ?? '') === 'completed' ? 'ok' : 'info')">{{ $L($group['discovery_status_label'] ?? 'Keşif yapılmadı') }}</x-ta.pill>
                                </span>
                                <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">{{ $group['accounts'] }} {{ $unit }}, {{ $group['bound'] }} bağlı, {{ $group['available'] }} bağlanmamış</span>
                                @if ($blocked && ! empty($group['discovery_message']))
                                    <span class="mt-0.5 block text-[13px] text-warning-700 dark:text-warning-400">{{ $group['discovery_message'] }}</span>
                                @endif
                            </span>
                            {!! $chevron !!}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @elseif ($tab === 'connectors')
        <section>
            <div class="section-head">
                <h2 class="section-title" title="Tek Google yetkisi; ürün bağlayıcıları keşif, bağlama ve veri tazeliğini yönetir.">Google bağlayıcıları</h2>
            </div>
            <ul class="card">
                @foreach ($integration['connectors'] as $connector)
                    <li>
                        <a href="{{ route('operator.integrations.connector', ['connector' => $connector['ui_slug']]) }}" wire:navigate class="card-row transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $connector['ui_slug'] === 'google-ads' ? 'google_ads' : $connector['ui_slug']])
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $connector['label'] }}</span>
                                <span class="mt-0.5 block text-[13px] text-gray-500 dark:text-gray-400">{{ $L($connector['auth_status_label'] ?? 'Yetki yok') }}, {{ $L($connector['discovery_status_label'] ?? 'Keşif yapılmadı') }}. {{ $connector['discovered'] }} kaynak, {{ $connector['bound'] }} bağlı, {{ $connector['available'] }} bağlanmamış</span>
                                @if (($connector['discovery_status'] ?? '') === 'external_access_required')
                                    <span class="mt-0.5 block text-[13px] text-warning-700 dark:text-warning-400">API erişimi gerekli</span>
                                @endif
                            </span>
                            {!! $chevron !!}
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @elseif ($tab === 'configuration')
        <section>
            <div class="section-head">
                <h2 class="section-title">Bağlantı durumu</h2>
                <span class="text-[13px] text-gray-500">Gizli bilgiler kaydedildikten sonra bir daha gösterilmez.</span>
            </div>
            <dl class="card">
                @foreach (array_filter([
                    ['Uygulama bilgileri', $L($integration['app_configuration_label'] ?? 'Ayarlanmadı'), 'Uygulama bilgileri Google yetkisiyle aynı şey değildir.'],
                    ['Google Ads geliştirici anahtarı', $L($integration['ads_developer_token_label'] ?? 'Eksik'), null],
                    ['Yetki', $L($integration['auth_status_label'] ?? 'Yetki yok'), null],
                    ['Verilen izinler', $L($integration['granted_scopes_label'] ?? 'Verilmedi'), null],
                    ! empty($integration['account_email']) ? ['Yetki veren hesap', $integration['account_email'], null] : null,
                    ['Yazma işlemleri', $L($integration['write_actions'] ?? 'Disabled — MoxDOP is read / bind only'), null],
                ]) as [$termLabel, $termValue, $termHint])
                    <div class="card-row flex-wrap text-sm sm:flex-nowrap" @if ($termHint) title="{{ $termHint }}" @endif>
                        <dt class="w-full text-[13px] text-gray-500 sm:w-56 sm:shrink-0">{{ $termLabel }}</dt>
                        <dd class="min-w-0 flex-1 font-medium text-gray-900 dark:text-white">{{ $termValue }}</dd>
                    </div>
                @endforeach
                @if ($integration['actions']['disconnect'] ?? false)
                    <div class="card-row flex-wrap text-sm sm:flex-nowrap">
                        <dt class="w-full text-[13px] text-gray-500 sm:w-56 sm:shrink-0">Google erişimi</dt>
                        <dd class="min-w-0 flex-1 text-[13px] text-gray-500">MoxDOP için verilen yetkiyi kaldırır; hesaplar, bağlantılar ve veri korunur.</dd>
                        <x-ta.button wire:click="openDisconnect" size="sm" variant="danger">Google erişimini kaldır…</x-ta.button>
                    </div>
                @endif
            </dl>
        </section>

        @if ($canManageCredentials ?? false)
            <section>
                <div class="section-head"><h2 class="section-title">Uygulama bilgileri</h2></div>
                <form wire:submit.prevent="saveGoogleConfiguration" class="card p-5">
                    <div class="grid gap-4 md:grid-cols-2">
                        <x-ta.form.field label="Google OAuth Client ID" helper="Gizli değil, kaydettikten sonra görünür." :error="$errors->first('client_id')">
                            <input wire:model="googleClientId" type="text" autocomplete="off" class="{{ $input }}" />
                        </x-ta.form.field>
                        <x-ta.form.field label="Google OAuth Client Secret" :helper="$googleClientSecretConfigured ? 'Kayıtlı. Boş bırakırsan kayıtlı değer korunur.' : 'Yalnız yazılır, kaydettikten sonra gösterilmez.'" :error="$errors->first('client_secret')">
                            <input wire:model="googleClientSecret" type="password" autocomplete="new-password" placeholder="{{ $googleClientSecretConfigured ? 'Bilgiyi değiştir' : '' }}" class="{{ $input }}" />
                        </x-ta.form.field>
                        @if ($googleClientSecretConfigured ?? false)
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 md:col-span-2">
                                <input type="checkbox" wire:model="clearGoogleClientSecret" class="rounded border-gray-300" />
                                Kayıtlı Client Secret'ı sil
                            </label>
                        @endif
                        <x-ta.form.field label="Google Ads Developer Token" :helper="$googleDeveloperTokenConfigured ? 'Kayıtlı. Boş bırakırsan kayıtlı değer korunur.' : 'Yalnız yazılır. Google Ads hesaplarını keşfetmek için gerekli.'" :error="$errors->first('developer_token')" class="md:col-span-2">
                            <input wire:model="googleDeveloperToken" type="password" autocomplete="new-password" placeholder="{{ $googleDeveloperTokenConfigured ? 'Bilgiyi değiştir' : '' }}" class="{{ $input }}" />
                        </x-ta.form.field>
                        @if ($googleDeveloperTokenConfigured ?? false)
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300 md:col-span-2">
                                <input type="checkbox" wire:model="clearGoogleDeveloperToken" class="rounded border-gray-300" />
                                Kayıtlı Ads geliştirici anahtarını sil
                            </label>
                        @endif
                    </div>
                    <div class="mt-5 flex flex-wrap gap-2 border-t border-gray-200 pt-4 dark:border-gray-800">
                        <button type="submit" class="btn-primary">Kaydet</button>
                        <button type="button" wire:click="testGoogleConfiguration" class="btn">Ayarları test et</button>
                        @if (($integration['app_configuration_label'] ?? '') === 'Ayarlandı' || ($googleClientSecretConfigured ?? false))
                            <button type="button" wire:click="askRemoveGoogleCredentials" class="btn-quiet hover:text-error-600! dark:hover:text-error-400!">Bilgileri sil</button>
                        @endif
                    </div>
                </form>
                @if ($confirmRemoveGoogleCredentials ?? false)
                    <div class="card mt-3 flex flex-wrap items-center gap-3 px-4 py-3.5 sm:flex-nowrap">
                        <span class="row-icon border-transparent bg-warning-50 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5M12 16.5v.5"/><path d="M10.3 3.9 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">Google uygulama bilgileri silinsin mi?</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Kayıtlı Client ID, Client Secret ve geliştirici anahtarı silinir. Bulunan hesaplar, bağlantılar ve geçmiş korunur.</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="cancelRemoveGoogleCredentials" class="btn">Vazgeç</button>
                            <x-ta.button wire:click="removeGoogleConfiguration" size="sm" variant="danger">Evet, sil</x-ta.button>
                        </div>
                    </div>
                @endif
            </section>
        @else
            <p class="text-sm text-gray-500">Google uygulama bilgilerini yalnız yöneticiler görebilir ve değiştirebilir.</p>
        @endif
    @elseif ($tab === 'resources')
        <section x-data="{ q: '' }">
            <div class="section-head items-center">
                <h2 class="section-title" title="Bağlamak veri çekimini başlatmaz.">Bağlanmamış hesaplar <span class="section-count">{{ count($integration['unbound_resources'] ?? []) }}</span></h2>
                @if (! empty($integration['unbound_resources']))
                    <input type="search" x-model="q" placeholder="Hesap ara…" aria-label="Hesap ara" class="{{ $input }} ml-auto sm:w-64" />
                @endif
            </div>
            @if (empty($integration['unbound_resources']))
                <div class="card">
                    <x-ta.empty-state :icon="($integration['resources_discovered'] ?? 0) === 0 ? 'none' : 'check'" size="sm" :title="($integration['resources_discovered'] ?? 0) === 0 ? 'Henüz hesap keşfedilmedi.' : 'Bağlanmayı bekleyen hesap yok.'"
                        :message="($integration['resources_discovered'] ?? 0) === 0 ? 'Google bağlıysa yukarıdan hesapları keşfet.' : 'Bulunan hesapların hepsi bağlı ya da seçilemez durumda.'" />
                </div>
            @else
                <ul class="card">
                    @foreach ($integration['unbound_resources'] as $resource)
                        <li x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($resource['name'].' '.$resource['type_label'])) }}.includes(q.toLocaleLowerCase('tr'))" class="card-row flex-wrap sm:flex-nowrap">
                            @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $resource['type']])
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $resource['name'] }} <span class="font-normal text-gray-500">{{ $resource['type_label'] }}</span></p>
                                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Kimlik {{ $resource['external_id'] }}@if (filled($resource['manager'] ?? null)), MCC: {{ $resource['manager'] }}@endif</p>
                            </div>
                            <x-ta.pill tone="look" :title="$resource['status_label']">Bağlı değil</x-ta.pill>
                            @if ($integration['actions']['bind'] ?? false)
                                @if (auth()->user()?->hasRole(\App\Support\Roles::ADMIN))<button type="button" wire:click="bindResource('{{ $resource['id'] }}')" class="btn w-full sm:w-auto">Markaya bağla…</button>@endif
                            @else
                                <button type="button" class="btn w-full sm:w-auto" disabled>Önce Google ile bağlanın</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section>
            <div class="section-head">
                <h2 class="section-title">Bağlı hesaplar <span class="section-count">{{ count($integration['bindings'] ?? []) }}</span></h2>
            </div>
            <ul class="card">
                @forelse ($integration['bindings'] as $binding)
                    @php
                        [$resourceName, $resourceId] = array_pad(explode(' · ', (string) $binding['resource'], 2), 2, null);
                        $capabilityMark = match ((string) ($binding['capability'] ?? '')) { 'google_ads' => 'google_ads', 'google_business_profile' => 'gbp', 'search_console', 'google_search_console' => 'gsc', default => 'ga4' };
                    @endphp
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        @include('livewire.demo.integrations.partials.provider-mark', ['provider' => $capabilityMark])
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $resourceName }} <span class="font-normal text-gray-500">{{ \Illuminate\Support\Str::after($binding['binding'], ' · ') }}</span></p>
                            <p class="mt-0.5 truncate text-[13px] text-gray-500 dark:text-gray-400" title="{{ $binding['asset'] }}, {{ $binding['binding'] }}">{{ $binding['asset'] }}@if ($resourceId), kimlik {{ $resourceId }}@endif</p>
                        </div>
                        <a href="{{ $binding['asset_id'] && $binding['route'] !== 'operator.assets' ? route($binding['route'], ['assetId' => $binding['asset_id']]) : route($binding['route']) }}" class="btn w-full sm:w-auto">Varlığı aç</a>
                    </li>
                @empty
                    <li><x-ta.empty-state icon="none" size="sm" title="Henüz bağlanmış hesap yok." /></li>
                @endforelse
            </ul>
        </section>
    @else
        <section>
            <div class="section-head"><h2 class="section-title">Geçmiş</h2></div>
            <ul class="card">
                @foreach ($integration['activity'] as $event)
                    <li class="card-row flex-wrap sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $L($event['event']) }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $L($event['actor']) }}, {{ $event['when'] }}</p>
                        </div>
                        <x-ta.pill :tone="match ($event['status']) { 'success' => 'ok', 'failed', 'needs_attention' => 'look', default => 'info' }">{{ $L($event['status']) }}</x-ta.pill>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="mox-collection-monitor-embed" wire:key="google-collection-monitoring">
            @livewire(\App\Livewire\Collection\MonitoringPanel::class)
        </div>
    @endif

    <livewire:operator.integrations.resource-automations provider="google" />

    @if ($confirmDisconnect)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="disconnect-title">
            <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <h2 id="disconnect-title" class="text-base font-semibold text-gray-900 dark:text-white">Google erişimi kaldırılsın mı?</h2>
                <p class="mt-1 text-sm text-gray-500">MoxDOP için verilen Google yetkisinin tamamı kaldırılır. Geçmiş hesaplar, bağlantılar ve toplanan veri korunur.</p>
                <ul class="mt-3 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($integration['disconnect_impact'] as $label => $count)
                        <li><span class="font-medium tabular-nums">{{ $count }}</span> {{ $label }}</li>
                    @endforeach
                </ul>
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="cancelDisconnect" class="btn">Vazgeç</button>
                    <x-ta.button wire:click="confirmDisconnectAction" size="sm" variant="danger">Google erişimini kaldır</x-ta.button>
                </div>
            </div>
        </div>
    @endif

    @if ($showBindModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4" role="dialog" aria-modal="true" aria-labelledby="bind-title">
            <div class="w-full max-w-lg rounded-2xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <h2 id="bind-title" class="text-base font-semibold text-gray-900 dark:text-white">Google hesabını markaya bağla</h2>
                <p class="mt-1 text-sm text-gray-500">Yalnız bağlantı oluşturulur; veri çekimi ayrıca başlar.</p>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="text-[13px] text-gray-600 dark:text-gray-400" for="bind-brand">Müşteri / Marka</label>
                        <select id="bind-brand" wire:model.live="brandId" class="{{ $input }} mt-1">
                            <option value="">Marka seçin…</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand['id'] }}">{{ $brand['label'] }}</option>
                            @endforeach
                        </select>
                        @if ($suggestedBrandId && (int) $brandId !== (int) $suggestedBrandId)
                            <p class="mt-1.5 text-[13px] text-gray-500">Öneri (isim / alan adı benzerliği): <strong class="font-medium text-gray-900 dark:text-white">{{ $suggestedBrandName }}</strong> <button type="button" wire:click="useSuggestedBrand" class="link-primary">Bu markayı seç</button></p>
                        @endif
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="text-[13px] text-gray-600 dark:text-gray-400">Hedef</legend>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" wire:model.live="bindMode" value="create_asset" class="text-gray-900" />
                            Yeni dijital varlık oluştur
                            @if ($preferred_asset_type)
                                <span class="text-[13px] text-gray-500">({{ $preferred_asset_type }})</span>
                            @endif
                        </label>
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="radio" wire:model.live="bindMode" value="existing_asset" class="text-gray-900" />
                            Markadaki uygun bir dijital varlığa bağla
                        </label>
                    </fieldset>

                    @if ($bindMode === 'create_asset')
                        <div>
                            <label class="text-[13px] text-gray-600 dark:text-gray-400" for="bind-asset-name">Dijital varlık adı</label>
                            <input id="bind-asset-name" type="text" wire:model="assetName" class="{{ $input }} mt-1" />
                        </div>
                    @else
                        <div>
                            <label class="text-[13px] text-gray-600 dark:text-gray-400" for="bind-existing-asset">Mevcut dijital varlık</label>
                            <select id="bind-existing-asset" wire:model="digitalAssetId" class="{{ $input }} mt-1">
                                <option value="">Varlık seçin…</option>
                                @forelse ($compatibleAssets as $asset)
                                    <option value="{{ $asset['id'] }}">{{ $asset['name'] }}, {{ $asset['type'] }}</option>
                                @empty
                                    <option value="" disabled>Bu markada uygun, bağlanmamış varlık yok</option>
                                @endforelse
                            </select>
                        </div>
                    @endif
                </div>

                @if ($ownershipConflict)
                    <div class="mt-4">
                        <x-operator.ownership-transfer-panel :conflict="$ownershipConflict" action="transferBind" :can-transfer="auth()->user()?->hasRole(\App\Support\Roles::ADMIN) ?? false" />
                    </div>
                @else
                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="cancelBind" class="btn">Vazgeç</button>
                    <button type="button" wire:click="confirmBind" class="btn-primary">Bağla</button>
                </div>
                @endif
            </div>
        </div>
    @endif
</div>
