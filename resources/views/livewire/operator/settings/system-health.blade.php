@php
    $card = 'min-w-0';
    $when = fn (?string $value): string => $value ? \Illuminate\Support\Carbon::parse($value)->timezone('Europe/Istanbul')->format('d.m.Y H:i') : '—';
    $errorLabels = [
        'reconnect' => 'Bağlantı yenilenmeli', 'collection_failed' => 'Tekrarlayan hata', 'request_requires_fix' => 'İstek düzeltilmeli (yazılım)',
        'cancelled' => 'İptal edildi', 'manager' => 'Yönetici hesabı', 'not_enabled' => 'Hesap kapalı', 'binding' => 'Bağlama yok', 'unbound' => 'Varlığa bağlı değil',
        'customer_passive' => 'Müşteri pasif',
    ];
    $stateLabels = ['waiting' => 'Sırada', 'planning' => 'Planlanıyor', 'collecting' => 'Çekiliyor', 'current' => 'Güncel', 'attention' => 'Durdu'];
    $typeLabels = ['google_ads' => 'Google Ads', 'ga4' => 'GA4', 'search_console' => 'Search Console', 'google_business_profile' => 'İşletme Profili', 'meta_ads' => 'Meta Ads'];
    $runStatus = ['completed' => 'tamamlandı', 'failed' => 'başarısız', 'partial' => 'kısmen tamamlandı', 'running' => 'çekiliyor', 'queued' => 'sırada', 'retrying' => 'yeniden denenecek', 'cancelled' => 'durduruldu', 'skipped' => 'atlandı', 'cancellation_requested' => 'durduruluyor'];
    $authLabels = ['active' => 'Açık', 'disabled' => 'Kapalı', 'REFRESH_REQUIRED' => 'izin yenilenmeli', 'REAUTH_REQUIRED' => 'yeniden bağlanmalı', 'REVOKED' => 'izin geri alındı', 'EXPIRED' => 'izin süresi doldu', 'PERMISSION_REQUIRED' => 'ek izin gerekli', 'CONNECTED' => 'bağlı', 'OK' => 'bağlı', 'ERROR' => 'hata'];
    $duration = fn (int $seconds): string => $seconds < 60 ? $seconds.' sn' : (int) ceil($seconds / 60).' dk';
    $release = $health['release'] ?? ['sha' => null, 'deployed_at' => null];
@endphp
<div class="space-y-10">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-900 dark:hover:text-white">Ayarlar</a>
            <h1 class="mt-1 text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Yalnız senin yapman gerekenler öne çıkar; sistemin kendisi düzelttikleri sessizce bekler, 2 günde düzelmezse sana gelir. Teknik ayrıntılar aşağıda.">Hata merkezi</h1>
            <p class="mt-1 text-sm text-gray-500">Yayındaki sürüm: <span class="font-mono">{{ $release['sha'] !== null ? substr($release['sha'], 0, 12) : 'bilinmiyor' }}</span>@if ($release['deployed_at']), {{ $when($release['deployed_at']) }}@endif</p>
        </div>
        <div class="flex gap-2">
            @if ($isAdmin)
                <button type="button" wire:click="retryStopped" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600">Durmuş toplamaları tekrar dene</button>
            @endif
        </div>
    </div>

    @if ($message !== '')
        <p class="tone-ok rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif

    @php
        $gb = fn (float $bytes): string => \App\Services\Operations\ServerStatusReader::gb($bytes);
        $bar = ['ok' => 'bg-success-500', 'look' => 'bg-warning-500', 'critical' => 'bg-error-500'];
        $server = $status['server'];
        $meters = array_values(array_filter([
            $server['memory'] !== null ? ['label' => 'Bellek (RAM)'] + $server['memory'] : null,
            $server['swap'] !== null ? ['label' => 'Takas alanı (swap)'] + $server['swap'] : null,
            ...$server['disks'],
        ]));
    @endphp
    <div class="grid gap-10 lg:grid-cols-2">
        <section class="{{ $card }} space-y-4" data-testid="server-status">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="section-title">Sunucu</h2>
                @if ($server['uptime_days'] !== null)<span class="text-xs text-gray-500">{{ $server['uptime_days'] >= 1 ? (int) floor($server['uptime_days']).' gündür açık' : 'bugün yeniden başladı' }}</span>@endif
            </div>
            @forelse ($meters as $m)
                <div data-testid="server-meter" data-tone="{{ $m['tone'] }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                        <span class="font-medium text-gray-800 dark:text-gray-200">{{ $m['label'] }}</span>
                        <span class="tabular-nums text-gray-600 dark:text-gray-400"><span class="font-semibold text-gray-900 dark:text-white">{{ $gb($m['used']) }}</span> dolu, {{ $gb($m['free']) }} boş · toplam {{ $gb($m['total']) }}</span>
                    </div>
                    <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.06]">
                        <div class="h-full rounded-full {{ $bar[$m['tone']] }}" style="width: {{ max(1, (int) round($m['share'] * 100)) }}%"></div>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">%{{ (int) round($m['share'] * 100) }} dolu</p>
                </div>
            @empty
                <p class="text-sm text-gray-500">Bu sunucuda bellek ve disk bilgisi okunamadı.</p>
            @endforelse
            @if ($server['cpu'] !== null)
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm" data-testid="server-cpu">
                    <span class="font-medium text-gray-800 dark:text-gray-200" title="Yük çekirdek sayısını geçerse sunucu yetişemiyor demektir.">İşlemci yükü ({{ $server['cpu']['cores'] }} çekirdek)</span>
                    <span class="inline-flex items-center gap-1.5 text-xs tabular-nums text-gray-700 dark:text-gray-300"><span class="h-1.5 w-1.5 rounded-full {{ $bar[$server['cpu']['tone']] ?? 'bg-gray-300' }}"></span>son 1 / 5 / 15 dk: {{ implode(' / ', array_map(fn (float $l): string => str_replace('.', ',', (string) $l), $server['cpu']['load'])) }}</span>
                </div>
            @endif
        </section>
        <section class="{{ $card }} space-y-3" data-testid="software-health">
            <h2 class="section-title">Yazılım sağlığı</h2>
            <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                @foreach ($status['software'] as $line)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm" data-tone="{{ $line['tone'] }}">
                        <span class="text-gray-700 dark:text-gray-300">{{ $line['label'] }}</span>
                        <span class="inline-flex items-center gap-1.5 text-xs text-gray-700 dark:text-gray-300"><span class="h-1.5 w-1.5 rounded-full {{ $bar[$line['tone']] ?? 'bg-gray-300' }}"></span>{{ $line['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
    <section class="space-y-3" data-error-center>
        @php
            $tones = [
                'you' => ['bg-error-500', 'text-gray-900 dark:text-white'],
                'code' => ['bg-warning-500', 'text-gray-900 dark:text-white'],
                'auto' => ['bg-gray-300 dark:bg-gray-600', 'text-gray-700 dark:text-gray-300'],
            ];
        @endphp
        @if (collect($triage)->flatten(1)->isEmpty())
            <p class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300" data-error-center-clear><span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>Açık sorun yok. Markalara bağlı tüm hesaplar ve sistem çalışıyor.</p>
        @endif
        @foreach (\App\Services\Observability\ErrorTriage::LABELS as $bucket => $label)
            @continue(($triage[$bucket] ?? []) === [])
            <details @if ($bucket !== 'auto') open @endif class="border-t border-gray-200 pt-4 dark:border-gray-800" data-error-bucket="{{ $bucket }}">
                <summary class="cursor-pointer text-base font-semibold {{ $tones[$bucket][1] }}"><span class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full align-middle {{ $tones[$bucket][0] }}"></span>{{ $label }} <span class="text-sm font-normal text-gray-500">{{ collect($triage[$bucket])->sum('count') }}</span>
                    @if ($bucket === 'auto')<span class="text-xs font-normal text-gray-500">, bir şey yapmana gerek yok; sistem yeniden deniyor</span>@endif
                    @if ($bucket === 'code')<span class="text-xs font-normal text-gray-500">, tekrar denemek işe yaramaz, düzeltme gerekir</span>@endif
                </summary>
                <div class="mt-2 divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($triage[$bucket] as $group)
                        <div class="py-3" wire:key="err-{{ $bucket }}-{{ md5($group['key']) }}">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $group['title'] }}@if ($group['count'] > 1)<span class="ml-1 text-sm font-normal text-gray-500">{{ $group['count'] }}</span>@endif</p>
                            <ul class="mt-2 space-y-2">
                                @foreach (array_slice($group['items'], 0, 8) as $item)
                                    <li class="text-xs text-gray-700 dark:text-gray-300">
                                        <p><span class="font-medium">{{ $item['title'] }}</span>@if ($item['escalated']) <span class="font-medium text-error-700 dark:text-error-400">2 günde düzelmedi</span>@endif</p>
                                        <p class="text-gray-500">{{ $item['what'] }}</p>
                                        @if ($bucket !== 'auto')<p class="mt-0.5"><span class="font-medium">Ne yapmalı:</span> {{ $item['action'] }}</p>@endif
                                        <div class="mt-1 flex flex-wrap gap-2">
                                            @if (($item['button']['run_now'] ?? null) !== null)
                                                <button type="button" wire:click="runNowAutomation({{ (int) $item['button']['run_now'] }})" class="rounded-lg px-2.5 py-1 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-800 dark:text-white">{{ $item['button']['label'] ?? 'Şimdi güncelle' }}</button>
                                            @endif
                                            @if ($item['link_url'])<a href="{{ $item['link_url'] }}" wire:navigate class="rounded-lg px-2.5 py-1 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-800 dark:text-white">{{ $item['link_label'] ?? 'Aç' }}</a>@endif
                                        </div>
                                    </li>
                                @endforeach
                                @if (count($group['items']) > 8)<li class="text-xs text-gray-500">+{{ count($group['items']) - 8 }} benzer</li>@endif
                            </ul>
                        </div>
                    @endforeach
                </div>
            </details>
        @endforeach
    </section>

    <details data-technical>
        <summary class="cursor-pointer border-t border-gray-200 pt-4 text-sm font-semibold text-gray-900 dark:border-gray-800 dark:text-white">Teknik ayrıntılar (zamanlayıcı, işçiler, bağlantılar, hesaplar, eklentiler)</summary>
        <div class="mt-6 space-y-10">


    <div class="grid gap-10 lg:grid-cols-3">
    @if ($health['backup'] !== null)
        <section class="{{ $card }}">
            <h2 class="section-title">Sistem yedeği</h2>
            <p class="mt-2 text-sm {{ $health['backup']['ok'] ? 'text-success-600' : 'text-error-600' }}">
                {{ $health['backup']['last_success_at'] === null ? 'Hiç yedek alınmamış' : ($health['backup']['ok'] ? 'Güncel' : 'Son yedek eski') }}
            </p>
            <p class="text-xs text-gray-500">Son başarılı: {{ $when($health['backup']['last_success_at']) }}@if ($health['backup']['bytes']), {{ number_format($health['backup']['bytes'] / 1048576, 1, ',', '.') }} MB @endif · {{ $health['backup']['remote'] ? 'uzak kopya var' : 'yalnız sunucuda (MOXDOP_BACKUP_REMOTE_DISK ile uzak kopya)' }}</p>
            @if ($health['backup']['last_success_at'])<p class="text-xs text-gray-500">Her yedek alındıktan sonra baştan sona okunup doğrulanır.</p>@endif
            @if ($health['backup']['last_error'])<p class="mt-1 text-xs text-error-700">Son hata: {{ \Illuminate\Support\Str::limit($health['backup']['last_error'], 200) }}</p>@endif
        </section>
    @endif
    @if (isset($health['two_factor']))
        <section class="{{ $card }}">
            <h2 class="section-title">İki adımlı doğrulama</h2>
            <p class="mt-2 text-sm {{ $health['two_factor']['admins_without'] === [] ? 'text-success-600' : 'text-error-600' }}">
                {{ $health['two_factor']['admins_without'] === [] ? 'Tüm yöneticilerde açık' : count($health['two_factor']['admins_without']).' yöneticide kapalı' }}
            </p>
            @if ($health['two_factor']['admins_without'] !== [])<p class="text-xs text-gray-500">{{ implode(', ', $health['two_factor']['admins_without']) }}</p>@endif
            <p class="text-xs text-gray-500">{{ $health['two_factor']['enforced'] ? 'Zorunlu: 2FA\'sız yönetici yalnız Profil sayfasını açabilir.' : 'Zorunlu değil (MOXDOP_REQUIRE_ADMIN_2FA=true ile zorunlu olur).' }}</p>
        </section>
    @endif
        <section class="{{ $card }}">
            <h2 class="section-title">Zamanlayıcı</h2>
            <p class="mt-2 text-sm {{ $health['scheduler']['ok'] ? 'text-success-600' : 'text-error-600' }}">
                {{ $health['scheduler']['minutes'] === null ? 'Hiç çalışmamış' : ($health['scheduler']['ok'] ? 'Çalışıyor' : 'Durmuş olabilir') }}
            </p>
            <p class="text-xs text-gray-500">Son: {{ $when($health['scheduler']['last_seen_at']) }}</p>
            @if (isset($health['watchdog']))
                <p class="mt-2 text-xs {{ $health['watchdog']['installed'] ? 'text-gray-500' : 'text-warning-700' }}">
                    {{ $health['watchdog']['installed'] ? 'Dış izleme çalışıyor (son: '.$when($health['watchdog']['last_run_at']).')' : 'Dış izleme kurulu değil: zamanlayıcı durursa haber gelmez. deploy/staging/cron.example içindeki moxdop:ops:watchdog satırını ekleyin.' }}
                </p>
            @endif
        </section>
        <section class="{{ $card }} lg:col-span-2">
            <h2 class="section-title">İşçiler</h2>
            @forelse ($health['workers'] as $worker)
                <p class="mt-1 flex items-center gap-2 text-sm"><span class="h-1.5 w-1.5 rounded-full {{ $worker['ok'] ? 'bg-success-500' : 'bg-error-500' }}"></span>{{ $worker['name'] }} <span class="text-xs text-gray-500">{{ $worker['minutes'] }} dk önce</span></p>
            @empty
                <p class="mt-2 text-sm text-gray-500">Henüz işçi sinyali yok (kuyruk yoklaması 5 dakikada bir çalışır).</p>
            @endforelse
        </section>
    </div>

    <section class="{{ $card }}">
        <h2 class="section-title">Açık sistem uyarıları ({{ count($health['alerts']) }})</h2>
        @forelse ($health['alerts'] as $alert)
            <div class="border-b border-gray-200 py-3 text-sm dark:border-gray-800">
                <x-ta.pill :tone="$alert['severity'] === 'critical' ? 'critical' : 'look'">{{ $alert['severity'] === 'critical' ? 'Kritik' : 'Uyarı' }}</x-ta.pill>
                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $alert['title'] }}</span>
                <span class="text-xs text-gray-500">{{ $when($alert['since']) }}, {{ $alert['count'] }} kez gözlendi</span>
                @if (($alert['repeat_label'] ?? '') !== '')<span class="text-xs font-medium text-warning-700">· {{ $alert['repeat_label'] }}</span>@endif
                @if ($alert['summary'])<p class="mt-1 text-xs text-gray-600 dark:text-gray-400"><span class="font-semibold">Ne oldu:</span> {{ $alert['summary'] }}</p>@endif
                @if ($alert['why'] ?? null)<p class="text-xs text-gray-600 dark:text-gray-400"><span class="font-semibold">Neden önemli:</span> {{ $alert['why'] }}</p>@endif
                @if ($alert['action'] ?? null)<p class="text-xs text-gray-600 dark:text-gray-400"><span class="font-semibold">Ne yapmalısın:</span> {{ $alert['action'] }}</p>@endif
                <p class="mt-1 flex flex-wrap gap-3 text-xs">
                    @if (! empty($alert['button']['url']))<a href="{{ $alert['button']['url'] }}" class="link-primary font-semibold">{{ $alert['button']['label'] }}</a>@endif
                    @if (! empty($alert['button']['run_now']))<button type="button" wire:click="runNowAutomation({{ (int) $alert['button']['run_now'] }})" wire:loading.attr="disabled" class="link-primary font-semibold">{{ $alert['button']['label'] }}</button>@endif
                    @if (! empty($alert['link_url']) && ! str_contains((string) $alert['link_url'], '/settings/system-health'))<a href="{{ $alert['link_url'] }}" wire:navigate class="link-neutral">{{ $alert['link_label'] }}</a>@endif
                </p>
            </div>
        @empty
            <p class="mt-2 text-sm text-success-600">Açık uyarı yok.</p>
        @endforelse
    </section>

    @php
        $live = $health['live_checks'] ?? [];
        $liveFailed = count(array_filter($live, fn (array $c): bool => $c['status'] === 'fail'));
        $liveStatus = ['ok' => ['Çalışıyor', 'text-success-600'], 'fail' => ['Başarısız', 'text-error-600'], 'skipped' => ['Denenmedi', 'text-gray-500']];
    @endphp
    <section class="{{ $card }}" id="canli-dogrulama">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="section-title" title="Her sabah her bağlantı ve bağlı hesap için en ucuz salt okunur çağrı yapılır (Google anahtar yenileme, GA4 1 günlük rapor, Search Console site okuma, Google Ads müşteri sorgusu, İşletme Profili konum okuma, Meta hesap durumu, DataForSEO ücretsiz hesap bilgisi, WordPress imzalı durum). Hiçbir şey yazılmaz.">Canlı doğrulama</h2>
                <p class="mt-1 text-xs text-gray-500">
                    {{ count($live) }} kontrol, <span @class(['font-semibold text-error-600' => $liveFailed > 0])>{{ $liveFailed }} başarısız</span>
                    · {{ $health['suspicious_data'] ?? 0 }} veri şüphesi
                </p>
            </div>
            @if ($isAdmin)
                <button type="button" wire:click="verifyNow" wire:loading.attr="disabled" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Şimdi doğrula</button>
            @endif
        </div>
        @if ($live === [])
            <p class="mt-2 text-sm text-gray-500">Henüz doğrulama yapılmadı (moxdop:verify:live her sabah çalışır).</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-2 pr-3">Bağlantı / hesap</th><th class="font-normal py-2 pr-3">Sonuç</th><th class="font-normal py-2 pr-3">Süre</th><th class="font-normal py-2 pr-3">Zaman</th><th class="font-normal py-2">Ayrıntı</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($live as $check)
                            <tr wire:key="live-{{ md5($check['check_key']) }}">
                                <td class="py-2 pr-3 text-gray-800 dark:text-gray-200">{{ $check['label'] }}</td>
                                <td class="py-2.5 pr-3 text-xs {{ $liveStatus[$check['status']][1] ?? '' }}">{{ $liveStatus[$check['status']][0] ?? $check['status'] }}</td>
                                <td class="py-2 pr-3 text-xs text-gray-500">{{ $check['latency_ms'] !== null ? number_format($check['latency_ms'], 0, ',', '.').' ms' : '—' }}</td>
                                <td class="py-2 pr-3 text-xs text-gray-500">{{ $when($check['checked_at']) }}</td>
                                <td class="py-2 text-xs text-gray-500">{{ $check['message'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <div class="grid gap-10 lg:grid-cols-3">
        <section class="{{ $card }}">
            <h2 class="section-title">Kuyruk bekleme süreleri</h2>
            @if (($health['queue_waits'] ?? null) === null)
                <p class="mt-2 text-sm text-gray-500">Ölçülmüyor: kuyruk sürücüsü redis değil ya da Horizon okunamadı.</p>
            @else
                @forelse ($health['queue_waits'] as $wait)
                    <p class="mt-1 text-sm">
                        <span class="mr-1 inline-block h-1.5 w-1.5 rounded-full align-middle {{ $wait['over'] ? 'bg-error-500' : 'bg-success-500' }}"></span>
                        {{ $wait['queue'] }} — {{ $duration($wait['wait_seconds']) }}
                        @if ($wait['threshold_seconds'] !== null)<span class="text-xs text-gray-500">(eşik {{ $duration($wait['threshold_seconds']) }})</span>@endif
                    </p>
                @empty
                    <p class="mt-2 text-sm text-gray-500">Horizon çalışan kuyruk bildirmiyor.</p>
                @endforelse
            @endif
        </section>
        <section class="{{ $card }} lg:col-span-2">
            <h2 class="section-title">Uygulama hataları (son 7 gün, en sık)</h2>
            @forelse ($health['error_groups'] ?? [] as $group)
                <div class="border-b border-gray-200 py-3 text-sm dark:border-gray-800">
                    <span class="text-xs font-semibold text-error-700 dark:text-error-400">{{ number_format($group['occurrences'], 0, ',', '.') }} kez</span>
                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ class_basename($group['class']) }}</span>
                    <span class="font-mono text-xs text-gray-500">{{ $group['location'] }}</span>
                    <p class="text-xs text-gray-500">{{ $group['message'] }}</p>
                    <p class="text-xs text-gray-500">İlk {{ $when($group['first_seen_at']) }}, son {{ $when($group['last_seen_at']) }}@if ($group['last_release']) · sürüm <span class="font-mono">{{ $group['last_release'] }}</span>@endif</p>
                </div>
            @empty
                <p class="mt-2 text-sm text-success-600">Son 7 günde kaydedilmiş uygulama hatası yok.</p>
            @endforelse
        </section>
    </div>

    <section class="{{ $card }}">
        <h2 class="section-title">Bağlantı yetkileri</h2>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-2 pr-3">Sağlayıcı</th><th class="font-normal py-2 pr-3">Durum</th><th class="font-normal py-2 pr-3">Yetki bitişi</th><th class="font-normal py-2">Son hata</th></tr></thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($health['integrations'] as $integration)
                        <tr>
                            <td class="py-2 pr-3 font-medium text-gray-800 dark:text-gray-200">{{ $integration['name'] }} <span class="text-xs text-gray-500">{{ $integration['provider'] }}</span></td>
                            <td class="py-2 pr-3">{{ $authLabels[$integration['status']] ?? $integration['status'] }}{{ $integration['auth_status'] !== '' ? ' · '.($authLabels[strtoupper($integration['auth_status'])] ?? $integration['auth_status']) : '' }}</td>
                            <td @class(['py-2 pr-3', 'font-semibold text-error-600' => $integration['expires_in_days'] !== null && $integration['expires_in_days'] <= 7])>
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
            <h2 class="section-title">Hesaplar ve veri tazeliği</h2>
            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400"><input type="checkbox" wire:model.live="onlyProblems" class="rounded border-gray-300" /> Sadece sorunlular</label>
        </div>
        <p class="mt-1 text-xs text-gray-500">{{ $health['account_counts']['total'] }} hesap, {{ $health['account_counts']['attention'] }} durmuş, {{ $health['account_counts']['stale'] }} verisi eski</p>
        @if ($health['accounts'] === [])
            <p class="mt-2 text-sm text-success-600">Sorunlu hesap yok.</p>
        @else
            <div class="mt-2 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800"><tr><th class="font-normal py-2 pr-3">Hesap</th><th class="font-normal py-2 pr-3">Durum</th><th class="font-normal py-2 pr-3">Veri tarihi</th><th class="font-normal py-2 pr-3">Son başarılı</th><th class="font-normal py-2 pr-3">Sonraki</th><th class="font-normal py-2">İşlem</th></tr></thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($health['accounts'] as $account)
                            <tr wire:key="acc-{{ $account['id'] }}" id="hesap-{{ $account['id'] }}">
                                <td class="py-2 pr-3 text-gray-800 dark:text-gray-200">{{ $account['name'] }} <span class="text-xs text-gray-500">{{ $typeLabels[$account['type']] ?? $account['type'] }}</span></td>
                                <td class="py-2 pr-3 text-xs">
                                    @if (! $account['enabled'])
                                        <span class="text-gray-500">Kapalı</span>
                                    @elseif ($account['state'] === 'attention')
                                        <span class="text-error-600" title="{{ $account['error'] ? __('resource-auto.'.$account['error']) : '' }}">{{ $errorLabels[$account['error']] ?? ($account['error'] ?: 'Dikkat') }}</span>
                                        @if ($account['error'] && \Illuminate\Support\Facades\Lang::has('resource-auto.'.$account['error']))<p class="text-gray-500">{{ __('resource-auto.'.$account['error']) }}</p>@endif
                                    @else
                                        {{ $stateLabels[$account['state']] ?? $account['state'] }}
                                    @endif
                                    @if ($account['stale']) <span class="text-warning-600">· veri eski</span> @endif
                                </td>
                                <td class="py-2 pr-3 text-xs">{{ $account['data_through'] ?? '—' }}</td>
                                <td class="py-2 pr-3 text-xs">{{ $when($account['last_success']) }}</td>
                                <td class="py-2 pr-3 text-xs">{{ $when($account['next']) }}</td>
                                <td class="py-2 text-xs">
                                    {{-- Faz 13: one action per row. --}}
                                    @if ($account['error'] === 'reconnect')
                                        {{-- One click: straight to the provider's consent screen, back here after. --}}
                                        <a href="{{ route($account['provider'] === 'meta' ? 'integrations.meta.authorize' : 'integrations.google.authorize', ['integration' => $account['integration_id']]) }}" class="link-primary font-medium">Yeniden bağlan</a>
                                    @elseif (in_array($account['error'], ['binding', 'unbound'], true))
                                        <a href="{{ route($account['provider'] === 'meta' ? 'operator.integrations.meta' : 'operator.integrations.google', ['tab' => 'resources']) }}" wire:navigate class="link-primary font-medium">Varlığa bağla</a>
                                    @elseif ($isAdmin && $account['enabled'] && ($account['state'] === 'attention' || $account['stale']))
                                        <button type="button" wire:click="runNow({{ $account['id'] }})" wire:loading.attr="disabled" class="link-primary font-medium">Şimdi çek</button>
                                    @endif
                                    <button type="button" wire:click="toggleDatasets({{ $account['id'] }})" class="ml-2 text-gray-500 hover:underline">Veri türleri</button>
                                </td>
                            </tr>
                            @if ($datasetsFor === $account['id'])
                                <tr wire:key="acc-ds-{{ $account['id'] }}">
                                    <td colspan="6" class="bg-gray-50 px-3 py-2 dark:bg-white/[0.03]">
                                        @forelse ($datasets as $ds)
                                            <p class="text-xs text-gray-600 dark:text-gray-300"><span title="{{ $ds['dataset'] }}">{{ \App\Support\Operator\DatasetLabels::dataset($ds['dataset']) }}</span> · veri {{ $ds['through'] ?? '—' }} tarihine kadar @if ($ds['from'])({{ $ds['from'] }}'den beri) @endif · {{ $runStatus[strtolower((string) $ds['status'])] ?? strtolower((string) $ds['status']) }} · son çekim {{ $when($ds['collected_at']) }}</p>
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
        <h2 class="section-title">WordPress eklentisi (güncel sürüm {{ $health['plugin_current'] }})</h2>
        @forelse ($health['plugins'] as $plugin)
            <p class="mt-1 text-sm">
                {{ $plugin['site'] }} — {{ $plugin['version'] ?? 'sürüm bilinmiyor' }}
                @if ($plugin['outdated']) <span class="text-warning-600">· güncelleme gerekli</span> @endif
                @if ($plugin['silent']) <span class="text-error-600">· 1 günden uzun süredir sinyal yok</span> @endif
            </p>
        @empty
            <p class="mt-2 text-sm text-gray-500">Eşleşmiş WordPress sitesi yok.</p>
        @endforelse
    </section>
        </div>
    </details>
</div>
