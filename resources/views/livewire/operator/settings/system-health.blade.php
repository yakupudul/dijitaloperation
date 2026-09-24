@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $when = fn (?string $value): string => $value ? \Illuminate\Support\Carbon::parse($value)->timezone('Europe/Istanbul')->format('d.m.Y H:i') : '—';
    $errorLabels = [
        'reconnect' => 'Bağlantı yenilenmeli', 'collection_failed' => 'Tekrarlayan hata', 'request_requires_fix' => 'İstek düzeltilmeli (yazılım)',
        'cancelled' => 'İptal edildi', 'manager' => 'Yönetici hesabı', 'binding' => 'Bağlama yok', 'unbound' => 'Varlığa bağlı değil',
        'customer_passive' => 'Müşteri pasif',
    ];
    $stateLabels = ['waiting' => 'Sırada', 'planning' => 'Planlanıyor', 'collecting' => 'Çekiliyor', 'current' => 'Güncel', 'attention' => 'Durdu'];
    $typeLabels = ['google_ads' => 'Google Ads', 'ga4' => 'GA4', 'search_console' => 'Search Console', 'google_business_profile' => 'İşletme Profili', 'meta_ads' => 'Meta Ads'];
@endphp
<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-brand-600">← Ayarlar</a>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">Sistem Sağlığı</h1>
            <p class="mt-1 text-sm text-gray-500">Zamanlayıcı, işçiler, sistem uyarıları, bağlantı yetkileri, hesap bazında veri tazeliği ve WordPress eklenti sürümleri.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('operator.settings.background-operations') }}" wire:navigate class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Arka plan işleri</a>
            @if ($isAdmin)
                <button type="button" wire:click="retryStopped" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Durmuş toplamaları tekrar dene</button>
            @endif
        </div>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
    @if ($health['backup'] !== null)
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Sistem yedeği</h2>
            <p class="mt-2 text-sm {{ $health['backup']['ok'] ? 'text-emerald-600' : 'text-rose-600' }}">
                {{ $health['backup']['last_success_at'] === null ? 'Hiç yedek alınmamış' : ($health['backup']['ok'] ? 'Güncel' : 'Son yedek eski') }}
            </p>
            <p class="text-xs text-gray-500">Son başarılı: {{ $when($health['backup']['last_success_at']) }}@if ($health['backup']['bytes']) · {{ number_format($health['backup']['bytes'] / 1048576, 1, ',', '.') }} MB @endif · {{ $health['backup']['remote'] ? 'uzak kopya var' : 'yalnız sunucuda (MOXDOP_BACKUP_REMOTE_DISK ile uzak kopya)' }}</p>
            @if ($health['backup']['last_success_at'])<p class="text-xs text-gray-500">Her yedek alındıktan sonra baştan sona okunup doğrulanır.</p>@endif
            @if ($health['backup']['last_error'])<p class="mt-1 text-xs text-rose-700">Son hata: {{ \Illuminate\Support\Str::limit($health['backup']['last_error'], 200) }}</p>@endif
        </section>
    @endif
    @if (isset($health['two_factor']))
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">İki adımlı doğrulama</h2>
            <p class="mt-2 text-sm {{ $health['two_factor']['admins_without'] === [] ? 'text-emerald-600' : 'text-rose-600' }}">
                {{ $health['two_factor']['admins_without'] === [] ? 'Tüm yöneticilerde açık' : count($health['two_factor']['admins_without']).' yöneticide kapalı' }}
            </p>
            @if ($health['two_factor']['admins_without'] !== [])<p class="text-xs text-gray-500">{{ implode(', ', $health['two_factor']['admins_without']) }}</p>@endif
            <p class="text-xs text-gray-500">{{ $health['two_factor']['enforced'] ? 'Zorunlu: 2FA\'sız yönetici yalnız Profil sayfasını açabilir.' : 'Zorunlu değil (MOXDOP_REQUIRE_ADMIN_2FA=true ile zorunlu olur).' }}</p>
        </section>
    @endif
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Zamanlayıcı</h2>
            <p class="mt-2 text-sm {{ $health['scheduler']['ok'] ? 'text-emerald-600' : 'text-rose-600' }}">
                {{ $health['scheduler']['minutes'] === null ? 'Hiç çalışmamış' : ($health['scheduler']['ok'] ? 'Çalışıyor' : 'Durmuş olabilir') }}
            </p>
            <p class="text-xs text-gray-500">Son: {{ $when($health['scheduler']['last_seen_at']) }}</p>
            @if (isset($health['watchdog']))
                <p class="mt-2 text-xs {{ $health['watchdog']['installed'] ? 'text-gray-500' : 'text-amber-700' }}">
                    {{ $health['watchdog']['installed'] ? 'Dış izleme çalışıyor (son: '.$when($health['watchdog']['last_run_at']).')' : 'Dış izleme kurulu değil: zamanlayıcı durursa haber gelmez. deploy/staging/cron.example içindeki moxdop:ops:watchdog satırını ekleyin.' }}
                </p>
            @endif
        </section>
        <section class="{{ $card }} lg:col-span-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">İşçiler</h2>
            @forelse ($health['workers'] as $worker)
                <p class="mt-1 text-sm"><span class="{{ $worker['ok'] ? 'text-emerald-600' : 'text-rose-600' }}">●</span> {{ $worker['name'] }} <span class="text-xs text-gray-500">— {{ $worker['minutes'] }} dk önce</span></p>
            @empty
                <p class="mt-2 text-sm text-gray-500">Henüz işçi sinyali yok (kuyruk yoklaması 5 dakikada bir çalışır).</p>
            @endforelse
        </section>
    </div>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Açık sistem uyarıları ({{ count($health['alerts']) }})</h2>
        @forelse ($health['alerts'] as $alert)
            <div class="mt-2 border-t border-gray-100 pt-2 text-sm dark:border-gray-800">
                <span @class(['rounded px-1.5 py-0.5 text-xs font-semibold', 'bg-rose-50 text-rose-700' => $alert['severity'] === 'critical', 'bg-amber-50 text-amber-700' => $alert['severity'] !== 'critical'])>{{ $alert['severity'] === 'critical' ? 'Kritik' : 'Uyarı' }}</span>
                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $alert['title'] }}</span>
                <span class="text-xs text-gray-500">· {{ $when($alert['since']) }} · {{ $alert['count'] }} kez</span>
                @if ($alert['summary'])<p class="text-xs text-gray-500">{{ $alert['summary'] }}</p>@endif
            </div>
        @empty
            <p class="mt-2 text-sm text-emerald-600">Açık uyarı yok.</p>
        @endforelse
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Bağlantı yetkileri</h2>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-400"><tr><th class="py-2 pr-3">Sağlayıcı</th><th class="py-2 pr-3">Durum</th><th class="py-2 pr-3">Yetki bitişi</th><th class="py-2">Son hata</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($health['integrations'] as $integration)
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-800 dark:text-gray-200">{{ $integration['name'] }} <span class="text-xs text-gray-400">{{ $integration['provider'] }}</span></td>
                            <td class="py-2 pr-3">{{ $integration['status'] }}{{ $integration['auth_status'] !== '' ? ' · '.$integration['auth_status'] : '' }}</td>
                            <td @class(['py-2 pr-3', 'font-semibold text-rose-600' => $integration['expires_in_days'] !== null && $integration['expires_in_days'] <= 7])>
                                {{ $integration['expires_at'] ?? '—' }}@if ($integration['expires_in_days'] !== null) ({{ $integration['expires_in_days'] }} gün)@endif
                            </td>
                            <td class="py-2 text-xs text-gray-500">{{ $integration['last_error'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Hesaplar ve veri tazeliği</h2>
            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400"><input type="checkbox" wire:model.live="onlyProblems" class="rounded border-gray-300" /> Sadece sorunlular</label>
        </div>
        <p class="mt-1 text-xs text-gray-500">{{ $health['account_counts']['total'] }} hesap · {{ $health['account_counts']['attention'] }} durmuş · {{ $health['account_counts']['stale'] }} verisi eski</p>
        @if ($health['accounts'] === [])
            <p class="mt-2 text-sm text-emerald-600">Sorunlu hesap yok.</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-400"><tr><th class="py-2 pr-3">Hesap</th><th class="py-2 pr-3">Durum</th><th class="py-2 pr-3">Veri tarihi</th><th class="py-2 pr-3">Son başarılı</th><th class="py-2 pr-3">Sonraki</th><th class="py-2">İşlem</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($health['accounts'] as $account)
                            <tr wire:key="acc-{{ $account['id'] }}">
                                <td class="py-2 pr-3 text-gray-800 dark:text-gray-200">{{ $account['name'] }} <span class="text-xs text-gray-400">{{ $typeLabels[$account['type']] ?? $account['type'] }}</span></td>
                                <td class="py-2 pr-3 text-xs">
                                    @if (! $account['enabled'])
                                        <span class="text-gray-400">Kapalı</span>
                                    @elseif ($account['state'] === 'attention')
                                        <span class="text-rose-600" title="{{ $account['error'] ? __('resource-auto.'.$account['error']) : '' }}">{{ $errorLabels[$account['error']] ?? ($account['error'] ?: 'Dikkat') }}</span>
                                        @if ($account['error'] && \Illuminate\Support\Facades\Lang::has('resource-auto.'.$account['error']))<p class="text-gray-500">{{ __('resource-auto.'.$account['error']) }}</p>@endif
                                    @else
                                        {{ $stateLabels[$account['state']] ?? $account['state'] }}
                                    @endif
                                    @if ($account['stale']) <span class="text-amber-600">· veri eski</span> @endif
                                </td>
                                <td class="py-2 pr-3 text-xs">{{ $account['data_through'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-xs">{{ $when($account['last_success']) }}</td>
                                <td class="py-2 pr-3 text-xs">{{ $when($account['next']) }}</td>
                                <td class="py-2 text-xs">
                                    {{-- Faz 13: one action per row. --}}
                                    @if ($account['error'] === 'reconnect')
                                        <a href="{{ route($account['provider'] === 'meta' ? 'operator.integrations.meta' : 'operator.integrations.google') }}" wire:navigate class="font-medium text-brand-600 hover:underline">Yeniden bağlan</a>
                                    @elseif (in_array($account['error'], ['binding', 'unbound'], true))
                                        <a href="{{ route($account['provider'] === 'meta' ? 'operator.integrations.meta' : 'operator.integrations.google', ['tab' => 'resources']) }}" wire:navigate class="font-medium text-brand-600 hover:underline">Varlığa bağla</a>
                                    @elseif ($isAdmin && $account['enabled'] && ($account['state'] === 'attention' || $account['stale']))
                                        <button type="button" wire:click="runNow({{ $account['id'] }})" wire:loading.attr="disabled" class="font-medium text-brand-600 hover:underline">Şimdi çek</button>
                                    @endif
                                    <button type="button" wire:click="toggleDatasets({{ $account['id'] }})" class="ml-2 text-gray-500 hover:underline">Veri setleri</button>
                                </td>
                            </tr>
                            @if ($datasetsFor === $account['id'])
                                <tr wire:key="acc-ds-{{ $account['id'] }}">
                                    <td colspan="6" class="bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                                        @forelse ($datasets as $ds)
                                            <p class="text-xs text-gray-600 dark:text-gray-300"><span class="font-mono">{{ $ds['dataset'] }}</span> · veri {{ $ds['through'] ?? '—' }} tarihine kadar @if ($ds['from'])({{ $ds['from'] }}'den beri) @endif · {{ strtolower($ds['status']) }} · son çekim {{ $when($ds['collected_at']) }}</p>
                                        @empty
                                            <p class="text-xs text-gray-500">Bu hesap için veri seti kaydı yok (İşletme Profili ayrı toplayıcıyla çekilir).</p>
                                        @endforelse
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">WordPress eklentisi (güncel sürüm {{ $health['plugin_current'] }})</h2>
        @forelse ($health['plugins'] as $plugin)
            <p class="mt-1 text-sm">
                {{ $plugin['site'] }} — {{ $plugin['version'] ?? 'sürüm bilinmiyor' }}
                @if ($plugin['outdated']) <span class="text-amber-600">· güncelleme gerekli</span> @endif
                @if ($plugin['silent']) <span class="text-rose-600">· 1 günden uzun süredir sinyal yok</span> @endif
            </p>
        @empty
            <p class="mt-2 text-sm text-gray-500">Eşleşmiş WordPress sitesi yok.</p>
        @endforelse
    </section>
</div>
