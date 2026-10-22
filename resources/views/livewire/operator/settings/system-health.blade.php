@php
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

    $gb = fn (float $bytes): string => \App\Services\Operations\ServerStatusReader::gb($bytes);
    $bar = ['ok' => 'bg-success-500', 'look' => 'bg-warning-500', 'critical' => 'bg-error-500'];
    $server = $status['server'];
    $meters = array_values(array_filter([
        $server['memory'] !== null ? ['label' => 'Bellek (RAM)'] + $server['memory'] : null,
        $server['swap'] !== null ? ['label' => 'Takas alanı (swap)'] + $server['swap'] : null,
        ...$server['disks'],
    ]));
    $title = 'text-sm font-medium text-gray-900 dark:text-white';
    $line = 'mt-0.5 text-[13px] text-gray-500 dark:text-gray-400';
    $rowBtn = 'btn h-7! px-2.5! text-xs!';
    $okText = fn (bool $ok): string => $ok ? 'ok' : 'critical';
@endphp
<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title" title="Yalnız senin yapman gerekenler öne çıkar; sistemin kendisi düzelttikleri sessizce bekler, 2 günde düzelmezse sana gelir. Teknik ayrıntılar aşağıda.">Hata merkezi</h1>
            <p class="page-sub">Yayındaki sürüm: <span class="font-mono text-[13px]">{{ $release['sha'] !== null ? substr($release['sha'], 0, 12) : 'bilinmiyor' }}</span>@if ($release['deployed_at']), {{ $when($release['deployed_at']) }}@endif</p>
        </div>
        @if ($isAdmin)
            <button type="button" wire:click="retryStopped" class="btn-primary w-full sm:w-auto">Durmuş toplamaları tekrar dene</button>
        @endif
    </header>

    @if ($message !== '')
        <p class="tone-ok -mt-4 rounded-lg px-3 py-2 text-sm">{{ $message }}</p>
    @endif

    {{-- 1. What waits on the operator (or the developer); what the system fixes by itself comes last and closed. --}}
    <section class="-mt-4 space-y-8" data-error-center>
        @php
            $dots = ['you' => 'bg-error-500', 'code' => 'bg-warning-500', 'auto' => 'bg-gray-300 dark:bg-gray-600'];
        @endphp
        @if (collect($triage)->flatten(1)->isEmpty())
            <div class="card" data-error-center-clear>
                <div class="card-row">
                    <span class="row-icon border-transparent bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>
                    <p class="{{ $title }}">Açık sorun yok. Markalara bağlı tüm hesaplar ve sistem çalışıyor.</p>
                </div>
            </div>
        @endif
        @if (collect($triage)->flatten(1)->isNotEmpty())
            {{-- Canlı panel (2026-10-20): one tinted card per open bucket, the count first; the lists follow below. --}}
            @php
                $softTone = ['you' => 'soft-red', 'code' => 'soft-amber', 'auto' => ''];
                $softLine = ['you' => 'Bağlantı, izin ya da ayar senin elinde.', 'code' => 'Tekrar denemek işe yaramaz, düzeltme gerekir.', 'auto' => 'Sistem yeniden deniyor; bir şey yapmana gerek yok.'];
            @endphp
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" data-error-summary>
                @foreach (\App\Services\Observability\ErrorTriage::LABELS as $bucket => $label)
                    @continue(($triage[$bucket] ?? []) === [])
                    <div class="soft-card {{ $softTone[$bucket] }}">
                        <span class="soft-icon" aria-hidden="true">
                            @if ($bucket === 'you')<svg class="h-4 w-4 text-error-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>
                            @elseif ($bucket === 'code')<svg class="h-4 w-4 text-warning-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/></svg>
                            @else<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8M21 3v5h-5"/></svg>@endif
                        </span>
                        <p class="soft-number">{{ collect($triage[$bucket])->sum('count') }}</p>
                        <div>
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $label }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-400">{{ $softLine[$bucket] }}</p>
                        </div>
                        <a href="#hata-{{ $bucket }}" x-on:click="document.getElementById('hata-{{ $bucket }}').open = true" class="btn mt-auto self-start">Listeye git</a>
                    </div>
                @endforeach
            </div>
        @endif
        @foreach (\App\Services\Observability\ErrorTriage::LABELS as $bucket => $label)
            @continue(($triage[$bucket] ?? []) === [])
            <details id="hata-{{ $bucket }}" @if ($bucket !== 'auto') open @endif class="group scroll-mt-6" data-error-bucket="{{ $bucket }}">
                <summary class="section-head cursor-pointer list-none [&::-webkit-details-marker]:hidden">
                    <h2 class="section-title inline-flex items-center gap-2"><span class="h-2 w-2 rounded-full {{ $dots[$bucket] }}" aria-hidden="true"></span>{{ $label }} <span class="section-count">{{ collect($triage[$bucket])->sum('count') }}</span></h2>
                    @if ($bucket === 'auto')<span class="text-[13px] text-gray-500">Bir şey yapmana gerek yok; sistem yeniden deniyor.</span>@endif
                    @if ($bucket === 'code')<span class="text-[13px] text-gray-500">Tekrar denemek işe yaramaz, düzeltme gerekir.</span>@endif
                    <span class="ml-auto text-[13px] text-gray-500"><span class="group-open:hidden">Göster</span><span class="hidden group-open:inline">Gizle</span></span>
                </summary>
                <div class="space-y-3">
                    @foreach ($triage[$bucket] as $group)
                        <ul class="card" wire:key="err-{{ $bucket }}-{{ md5($group['key']) }}">
                            <li class="flex items-baseline gap-2 border-b border-gray-200 bg-gray-50 px-4 py-2.5 dark:border-gray-800 dark:bg-white/[0.02]">
                                <span class="text-[13px] font-semibold text-gray-900 dark:text-white">{{ $group['title'] }}</span>
                                @if ($group['count'] > 1)<span class="text-[13px] text-gray-500 tabular-nums">{{ $group['count'] }}</span>@endif
                            </li>
                            @foreach (array_slice($group['items'], 0, 8) as $item)
                                <li class="card-row flex-wrap items-start sm:flex-nowrap">
                                    <div class="min-w-0 flex-1">
                                        <p class="{{ $title }}">{{ $item['title'] }}@if ($item['escalated']) <span class="ml-1 text-[13px] font-medium text-error-700 dark:text-error-400">2 günde düzelmedi</span>@endif</p>
                                        <p class="{{ $line }}">{{ $item['what'] }}</p>
                                        @if ($bucket !== 'auto')<p class="mt-1 text-[13px] text-gray-700 dark:text-gray-300"><span class="font-medium">Ne yapmalı:</span> {{ $item['action'] }}</p>@endif
                                    </div>
                                    @if (($item['button']['run_now'] ?? null) !== null || $item['link_url'])
                                        <div class="flex w-full shrink-0 gap-2 sm:w-auto">
                                            @if (($item['button']['run_now'] ?? null) !== null)
                                                <button type="button" wire:click="runNowAutomation({{ (int) $item['button']['run_now'] }})" class="btn flex-1 sm:flex-none">{{ $item['button']['label'] ?? 'Şimdi güncelle' }}</button>
                                            @endif
                                            @if ($item['link_url'])<a href="{{ $item['link_url'] }}" wire:navigate class="btn flex-1 sm:flex-none">{{ $item['link_label'] ?? 'Aç' }}</a>@endif
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                            @if (count($group['items']) > 8)<li class="card-row text-[13px] text-gray-500">+{{ count($group['items']) - 8 }} benzer</li>@endif
                        </ul>
                    @endforeach
                </div>
            </details>
        @endforeach
    </section>

    {{-- 2. The machine and the software in two cards. --}}
    <div class="grid gap-10 lg:grid-cols-2 lg:gap-6">
        <section class="min-w-0" data-testid="server-status">
            <div class="section-head">
                <h2 class="section-title">Sunucu</h2>
                @if ($server['uptime_days'] !== null)<span class="ml-auto text-[13px] text-gray-500">{{ $server['uptime_days'] >= 1 ? (int) floor($server['uptime_days']).' gündür açık' : 'bugün yeniden başladı' }}</span>@endif
            </div>
            <div class="card">
                @forelse ($meters as $m)
                    <div class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" data-testid="server-meter" data-tone="{{ $m['tone'] }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                            <span class="{{ $title }}">{{ $m['label'] }}</span>
                            <span class="text-[13px] tabular-nums text-gray-500 dark:text-gray-400"><span class="font-medium text-gray-900 dark:text-white">{{ $gb($m['used']) }}</span> dolu, {{ $gb($m['free']) }} boş · toplam {{ $gb($m['total']) }}</span>
                        </div>
                        <div class="mt-2 flex items-center gap-3">
                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/[0.06]">
                                <div class="h-full rounded-full {{ $bar[$m['tone']] }}" style="width: {{ max(1, (int) round($m['share'] * 100)) }}%"></div>
                            </div>
                            <span class="shrink-0 whitespace-nowrap text-right text-xs tabular-nums text-gray-500">%{{ (int) round($m['share'] * 100) }} dolu</span>
                        </div>
                    </div>
                @empty
                    <p class="card-row text-sm text-gray-500">Bu sunucuda bellek ve disk bilgisi okunamadı.</p>
                @endforelse
                @if ($server['cpu'] !== null)
                    <div class="card-row flex-wrap justify-between gap-y-1" data-testid="server-cpu">
                        <span class="{{ $title }}" title="Yük çekirdek sayısını geçerse sunucu yetişemiyor demektir.">İşlemci yükü ({{ $server['cpu']['cores'] }} çekirdek)</span>
                        <x-ta.pill :tone="$server['cpu']['tone']" class="tabular-nums">son 1 / 5 / 15 dk: {{ implode(' / ', array_map(fn (float $l): string => str_replace('.', ',', (string) $l), $server['cpu']['load'])) }}</x-ta.pill>
                    </div>
                @endif
            </div>
        </section>
        <section class="min-w-0" data-testid="software-health">
            <div class="section-head"><h2 class="section-title">Yazılım sağlığı</h2></div>
            <ul class="card">
                @foreach ($status['software'] as $softwareLine)
                    <li class="card-row flex-wrap justify-between gap-y-1 py-3!" data-tone="{{ $softwareLine['tone'] }}">
                        <span class="text-sm text-gray-700 dark:text-gray-300">{{ $softwareLine['label'] }}</span>
                        <x-ta.pill :tone="$softwareLine['tone']">{{ $softwareLine['value'] }}</x-ta.pill>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    {{-- 3. Everything else is technical and stays closed. --}}
    <details class="group/tech" data-technical>
        <summary class="card flex cursor-pointer list-none items-center gap-3.5 px-4 py-3.5 hover:bg-gray-50 dark:hover:bg-white/[0.02] [&::-webkit-details-marker]:hidden">
            <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></span>
            <span class="min-w-0 flex-1">
                <span class="block {{ $title }}">Teknik ayrıntılar</span>
                <span class="block {{ $line }}">Zamanlayıcı, işçiler, bağlantılar, hesaplar, eklentiler</span>
            </span>
            <span class="btn-quiet"><span class="group-open/tech:hidden">Aç</span><span class="hidden group-open/tech:inline">Kapat</span></span>
        </summary>
        <div class="mt-10 space-y-10">
            <section>
                <div class="section-head"><h2 class="section-title">Altyapı</h2></div>
                <div class="card">
                    @if ($health['backup'] !== null)
                        <div class="card-row flex-wrap items-start sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <h3 class="{{ $title }}">Sistem yedeği</h3>
                                <p class="{{ $line }}">Son başarılı: {{ $when($health['backup']['last_success_at']) }}@if ($health['backup']['bytes']), {{ number_format($health['backup']['bytes'] / 1048576, 1, ',', '.') }} MB @endif, {{ $health['backup']['remote'] ? 'uzak kopya var' : 'yalnız sunucuda (MOXDOP_BACKUP_REMOTE_DISK ile uzak kopya)' }}.@if ($health['backup']['last_success_at']) Her yedek alındıktan sonra baştan sona okunup doğrulanır.@endif</p>
                                <p class="mt-1 text-xs {{ ($health['backup']['drill']['state'] ?? null) === 'failed' ? 'text-error-700 dark:text-error-400' : 'text-neutral-500' }}">Geri yükleme provası (ayda bir): {{ ($health['backup']['drill'] ?? null) === null ? 'henüz yapılmadı' : $when($health['backup']['drill']['checked_at']).' · '.$health['backup']['drill']['message'] }}</p>
                                @if ($health['backup']['last_error'])<p class="mt-1 text-xs text-error-700 dark:text-error-400">Son hata: {{ \Illuminate\Support\Str::limit($health['backup']['last_error'], 200) }}</p>@endif
                            </div>
                            <x-ta.pill :tone="$okText($health['backup']['ok'])">{{ $health['backup']['last_success_at'] === null ? 'Hiç yedek alınmamış' : ($health['backup']['ok'] ? 'Güncel' : 'Son yedek eski') }}</x-ta.pill>
                        </div>
                    @endif
                    @if (isset($health['two_factor']))
                        <div class="card-row flex-wrap items-start sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <h3 class="{{ $title }}">İki adımlı doğrulama</h3>
                                <p class="{{ $line }}">@if ($health['two_factor']['admins_without'] !== []){{ implode(', ', $health['two_factor']['admins_without']) }}. @endif{{ $health['two_factor']['enforced'] ? 'Zorunlu: 2FA\'sız yönetici yalnız Profil sayfasını açabilir.' : 'Zorunlu değil (MOXDOP_REQUIRE_ADMIN_2FA=true ile zorunlu olur).' }}</p>
                            </div>
                            <x-ta.pill :tone="$okText($health['two_factor']['admins_without'] === [])">{{ $health['two_factor']['admins_without'] === [] ? 'Tüm yöneticilerde açık' : count($health['two_factor']['admins_without']).' yöneticide kapalı' }}</x-ta.pill>
                        </div>
                    @endif
                    <div class="card-row flex-wrap items-start sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <h3 class="{{ $title }}">Zamanlayıcı</h3>
                            <p class="{{ $line }}">Son: {{ $when($health['scheduler']['last_seen_at']) }}</p>
                            @if (isset($health['watchdog']))
                                <p @class(['mt-0.5 text-[13px]', 'text-gray-500' => $health['watchdog']['installed'], 'text-warning-700 dark:text-warning-400' => ! $health['watchdog']['installed']])>
                                    {{ $health['watchdog']['installed'] ? 'Dış izleme çalışıyor (son: '.$when($health['watchdog']['last_run_at']).')' : 'Dış izleme kurulu değil: zamanlayıcı durursa haber gelmez. deploy/staging/cron.example içindeki moxdop:ops:watchdog satırını ekleyin.' }}
                                </p>
                            @endif
                        </div>
                        <x-ta.pill :tone="$okText($health['scheduler']['ok'])">{{ $health['scheduler']['minutes'] === null ? 'Hiç çalışmamış' : ($health['scheduler']['ok'] ? 'Çalışıyor' : 'Durmuş olabilir') }}</x-ta.pill>
                    </div>
                    <div class="card-row flex-wrap items-start sm:flex-nowrap">
                        <div class="min-w-0 flex-1">
                            <h3 class="{{ $title }}">İşçiler</h3>
                            @if ($health['workers'] === [])
                                <p class="{{ $line }}">Henüz işçi sinyali yok (kuyruk yoklaması 5 dakikada bir çalışır).</p>
                            @endif
                        </div>
                        @if ($health['workers'] !== [])
                            <div class="flex w-full flex-col gap-1 sm:w-auto sm:items-end">
                                @foreach ($health['workers'] as $worker)
                                    <x-ta.pill :tone="$okText($worker['ok'])">{{ $worker['name'] }} <span class="text-gray-500">{{ $worker['minutes'] }} dk önce</span></x-ta.pill>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            <section>
                <div class="section-head"><h2 class="section-title">Açık sistem uyarıları <span class="section-count">{{ count($health['alerts']) }}</span></h2></div>
                <ul class="card">
                    @forelse ($health['alerts'] as $alert)
                        <li class="card-row flex-wrap items-start sm:flex-nowrap">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <span class="{{ $title }}">{{ $alert['title'] }}</span>
                                    <x-ta.pill :tone="$alert['severity'] === 'critical' ? 'critical' : 'look'">{{ $alert['severity'] === 'critical' ? 'Kritik' : 'Uyarı' }}</x-ta.pill>
                                </p>
                                <p class="{{ $line }}">{{ $when($alert['since']) }}, {{ $alert['count'] }} kez gözlendi @if (($alert['repeat_label'] ?? '') !== ''), <span class="font-medium text-warning-700 dark:text-warning-400">{{ $alert['repeat_label'] }}</span>@endif</p>
                                @if ($alert['summary'])<p class="mt-1 text-[13px] text-gray-700 dark:text-gray-300"><span class="font-medium">Ne oldu:</span> {{ $alert['summary'] }}</p>@endif
                                @if ($alert['why'] ?? null)<p class="text-[13px] text-gray-700 dark:text-gray-300"><span class="font-medium">Neden önemli:</span> {{ $alert['why'] }}</p>@endif
                                @if ($alert['action'] ?? null)<p class="text-[13px] text-gray-700 dark:text-gray-300"><span class="font-medium">Ne yapmalısın:</span> {{ $alert['action'] }}</p>@endif
                            </div>
                            @if (! empty($alert['button']['url']) || ! empty($alert['button']['run_now']) || (! empty($alert['link_url']) && ! str_contains((string) $alert['link_url'], '/settings/system-health')))
                                <div class="flex w-full shrink-0 flex-wrap gap-2 sm:w-auto sm:justify-end">
                                    @if (! empty($alert['button']['url']))<a href="{{ $alert['button']['url'] }}" class="btn flex-1 sm:flex-none">{{ $alert['button']['label'] }}</a>@endif
                                    @if (! empty($alert['button']['run_now']))<button type="button" wire:click="runNowAutomation({{ (int) $alert['button']['run_now'] }})" wire:loading.attr="disabled" class="btn flex-1 sm:flex-none">{{ $alert['button']['label'] }}</button>@endif
                                    @if (! empty($alert['link_url']) && ! str_contains((string) $alert['link_url'], '/settings/system-health'))<a href="{{ $alert['link_url'] }}" wire:navigate class="btn-quiet flex-1 sm:flex-none">{{ $alert['link_label'] }}</a>@endif
                                </div>
                            @endif
                        </li>
                    @empty
                        <li><x-ta.empty-state size="sm" title="Açık uyarı yok." /></li>
                    @endforelse
                </ul>
            </section>

            @php
                $live = $health['live_checks'] ?? [];
                $liveFailed = count(array_filter($live, fn (array $c): bool => $c['status'] === 'fail'));
                $liveStatus = ['ok' => ['Çalışıyor', 'ok'], 'fail' => ['Başarısız', 'critical'], 'skipped' => ['Denenmedi', 'info']];
            @endphp
            <section id="canli-dogrulama" class="scroll-mt-20">
                <div class="section-head">
                    <h2 class="section-title" title="Her sabah her bağlantı ve bağlı hesap için en ucuz salt okunur çağrı yapılır (Google anahtar yenileme, GA4 1 günlük rapor, Search Console site okuma, Google Ads müşteri sorgusu, İşletme Profili konum okuma, Meta hesap durumu, DataForSEO ücretsiz hesap bilgisi, WordPress imzalı durum). Hiçbir şey yazılmaz.">Canlı doğrulama</h2>
                    <span class="text-[13px] text-gray-500">{{ count($live) }} kontrol, <span @class(['font-medium text-error-600 dark:text-error-400' => $liveFailed > 0])>{{ $liveFailed }} başarısız</span>, {{ $health['suspicious_data'] ?? 0 }} veri şüphesi</span>
                    @if ($isAdmin)
                        <button type="button" wire:click="verifyNow" wire:loading.attr="disabled" class="btn ml-auto">Şimdi doğrula</button>
                    @endif
                </div>
                @if ($live === [])
                    <div class="card"><x-ta.empty-state size="sm" icon="none" title="Henüz doğrulama yapılmadı" message="moxdop:verify:live her sabah çalışır." /></div>
                @else
                    <div class="card overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Bağlantı / hesap</th><th>Sonuç</th><th class="text-right!">Süre</th><th class="hidden md:table-cell">Zaman</th><th class="hidden lg:table-cell">Ayrıntı</th></tr></thead>
                            <tbody>
                                @foreach ($live as $check)
                                    <tr wire:key="live-{{ md5($check['check_key']) }}">
                                        <td class="text-gray-900 dark:text-white">{{ $check['label'] }}<p class="text-xs text-gray-500 lg:hidden">{{ $check['message'] ?? '' }}</p></td>
                                        <td><x-ta.pill :tone="$liveStatus[$check['status']][1] ?? 'info'">{{ $liveStatus[$check['status']][0] ?? $check['status'] }}</x-ta.pill></td>
                                        <td class="text-right text-xs tabular-nums text-gray-500">{{ $check['latency_ms'] !== null ? number_format($check['latency_ms'], 0, ',', '.').' ms' : '—' }}</td>
                                        <td class="hidden whitespace-nowrap text-xs text-gray-500 md:table-cell">{{ $when($check['checked_at']) }}</td>
                                        <td class="hidden text-xs text-gray-500 lg:table-cell">{{ $check['message'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <div class="grid gap-10 lg:grid-cols-3 lg:gap-6">
                <section class="min-w-0">
                    <div class="section-head"><h2 class="section-title">Kuyruk bekleme süreleri</h2></div>
                    <ul class="card">
                        @if (($health['queue_waits'] ?? null) === null)
                            <li class="card-row text-[13px] text-gray-500">Ölçülmüyor: kuyruk sürücüsü redis değil ya da Horizon okunamadı.</li>
                        @else
                            @forelse ($health['queue_waits'] as $wait)
                                <li class="card-row gap-2.5 text-sm text-gray-900 dark:text-white">
                                    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $wait['over'] ? 'bg-error-500' : 'bg-success-500' }}" aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">{{ $wait['queue'] }} — {{ $duration($wait['wait_seconds']) }}</span>
                                    @if ($wait['threshold_seconds'] !== null)<span class="text-xs text-gray-500">(eşik {{ $duration($wait['threshold_seconds']) }})</span>@endif
                                </li>
                            @empty
                                <li class="card-row text-[13px] text-gray-500">Horizon çalışan kuyruk bildirmiyor.</li>
                            @endforelse
                        @endif
                    </ul>
                </section>
                <section class="min-w-0 lg:col-span-2">
                    <div class="section-head"><h2 class="section-title">Uygulama hataları <span class="section-count">son 7 gün, en sık</span></h2></div>
                    <ul class="card">
                        @forelse ($health['error_groups'] ?? [] as $group)
                            <li class="card-row items-start">
                                <span class="w-14 shrink-0 pt-0.5 text-right text-[13px] font-medium tabular-nums text-error-700 dark:text-error-400">{{ number_format($group['occurrences'], 0, ',', '.') }} kez</span>
                                <div class="min-w-0 flex-1">
                                    <p class="{{ $title }}">{{ class_basename($group['class']) }} <span class="font-mono text-xs font-normal text-gray-500">{{ $group['location'] }}</span></p>
                                    <p class="{{ $line }}">{{ $group['message'] }}</p>
                                    <p class="text-xs text-gray-500">İlk {{ $when($group['first_seen_at']) }}, son {{ $when($group['last_seen_at']) }}@if ($group['last_release']), sürüm <span class="font-mono">{{ $group['last_release'] }}</span>@endif</p>
                                </div>
                            </li>
                        @empty
                            <li><x-ta.empty-state size="sm" title="Son 7 günde kaydedilmiş uygulama hatası yok." /></li>
                        @endforelse
                    </ul>
                </section>
            </div>

            <section>
                <div class="section-head"><h2 class="section-title">Bağlantı yetkileri</h2></div>
                <div class="card overflow-x-auto">
                    <table class="data-table">
                        <thead><tr><th>Sağlayıcı</th><th>Durum</th><th>Yetki bitişi</th><th class="hidden md:table-cell">Son hata</th></tr></thead>
                        <tbody>
                            @foreach ($health['integrations'] as $integration)
                                <tr>
                                    <td class="font-medium text-gray-900 dark:text-white">{{ $integration['name'] }} <span class="text-xs font-normal text-gray-500">{{ $integration['provider'] }}</span></td>
                                    <td class="text-[13px] text-gray-700 dark:text-gray-300">{{ $authLabels[$integration['status']] ?? $integration['status'] }}{{ $integration['auth_status'] !== '' ? ', '.($authLabels[strtoupper($integration['auth_status'])] ?? $integration['auth_status']) : '' }}</td>
                                    <td @class(['whitespace-nowrap text-[13px] tabular-nums', 'font-medium text-error-600 dark:text-error-400' => $integration['expires_in_days'] !== null && $integration['expires_in_days'] <= 7, 'text-gray-700 dark:text-gray-300' => ! ($integration['expires_in_days'] !== null && $integration['expires_in_days'] <= 7)])>
                                        {{ $integration['expires_at'] ?? '—' }}@if ($integration['expires_in_days'] !== null) ({{ $integration['expires_in_days'] }} gün)@endif
                                    </td>
                                    <td class="hidden text-xs text-gray-500 md:table-cell">{{ $integration['last_error'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <div class="section-head">
                    <h2 class="section-title">Hesaplar ve veri tazeliği <span class="section-count">{{ $health['account_counts']['total'] }}</span></h2>
                    <span class="text-[13px] text-gray-500">{{ $health['account_counts']['total'] }} hesap, {{ $health['account_counts']['attention'] }} durmuş, {{ $health['account_counts']['stale'] }} verisi eski</span>
                    <label class="ml-auto flex items-center gap-2 text-[13px] text-gray-600 dark:text-gray-400"><input type="checkbox" wire:model.live="onlyProblems" class="rounded border-gray-300" /> Sadece sorunlular</label>
                </div>
                @if ($health['accounts'] === [])
                    <div class="card"><x-ta.empty-state size="sm" title="Sorunlu hesap yok." /></div>
                @else
                    <div class="card overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Hesap</th><th>Durum</th><th class="hidden md:table-cell">Veri tarihi</th><th class="hidden lg:table-cell">Son başarılı</th><th class="hidden lg:table-cell">Sonraki</th><th class="text-right!"><span class="sr-only">İşlem</span></th></tr></thead>
                            <tbody>
                                @foreach ($health['accounts'] as $account)
                                    <tr wire:key="acc-{{ $account['id'] }}" id="hesap-{{ $account['id'] }}">
                                        <td class="text-gray-900 dark:text-white">{{ $account['name'] }} <span class="block text-xs text-gray-500">{{ $typeLabels[$account['type']] ?? $account['type'] }}</span></td>
                                        <td class="text-xs">
                                            @if (! $account['enabled'])
                                                <x-ta.pill tone="info">Kapalı</x-ta.pill>
                                            @elseif ($account['state'] === 'attention')
                                                <x-ta.pill tone="critical" :title="$account['error'] ? __('resource-auto.'.$account['error']) : ''">{{ $errorLabels[$account['error']] ?? ($account['error'] ?: 'Dikkat') }}</x-ta.pill>
                                                @if ($account['error'] && \Illuminate\Support\Facades\Lang::has('resource-auto.'.$account['error']))<p class="mt-0.5 max-w-xs text-gray-500">{{ __('resource-auto.'.$account['error']) }}</p>@endif
                                            @else
                                                <x-ta.pill tone="ok">{{ $stateLabels[$account['state']] ?? $account['state'] }}</x-ta.pill>
                                            @endif
                                            @if ($account['stale']) <span class="text-warning-700 dark:text-warning-400">, veri eski</span> @endif
                                        </td>
                                        <td class="hidden whitespace-nowrap text-xs tabular-nums md:table-cell">{{ $account['data_through'] ?? '—' }}</td>
                                        <td class="hidden whitespace-nowrap text-xs tabular-nums lg:table-cell">{{ $when($account['last_success']) }}</td>
                                        <td class="hidden whitespace-nowrap text-xs tabular-nums lg:table-cell">{{ $when($account['next']) }}</td>
                                        <td class="whitespace-nowrap text-right">
                                            {{-- Faz 13: one action per row. --}}
                                            @if ($account['error'] === 'reconnect')
                                                {{-- One click: straight to the provider's consent screen, back here after. --}}
                                                <a href="{{ route($account['provider'] === 'meta' ? 'integrations.meta.authorize' : 'integrations.google.authorize', ['integration' => $account['integration_id']]) }}" class="{{ $rowBtn }}">Yeniden bağlan</a>
                                            @elseif (in_array($account['error'], ['binding', 'unbound'], true))
                                                <a href="{{ route($account['provider'] === 'meta' ? 'operator.integrations.meta' : 'operator.integrations.google', ['tab' => 'resources']) }}" wire:navigate class="{{ $rowBtn }}">Varlığa bağla</a>
                                            @elseif ($isAdmin && $account['enabled'] && ($account['state'] === 'attention' || $account['stale']))
                                                <button type="button" wire:click="runNow({{ $account['id'] }})" wire:loading.attr="disabled" class="{{ $rowBtn }}">Şimdi çek</button>
                                            @endif
                                            <button type="button" wire:click="toggleDatasets({{ $account['id'] }})" class="btn-quiet h-7! px-2! text-xs!">Veri türleri</button>
                                        </td>
                                    </tr>
                                    @if ($datasetsFor === $account['id'])
                                        <tr wire:key="acc-ds-{{ $account['id'] }}">
                                            <td colspan="6" class="bg-gray-50 dark:bg-white/[0.03]">
                                                @forelse ($datasets as $ds)
                                                    <p class="text-xs text-gray-600 dark:text-gray-300"><span class="font-medium" title="{{ $ds['dataset'] }}">{{ \App\Support\Operator\DatasetLabels::dataset($ds['dataset']) }}</span>: veri {{ $ds['through'] ?? '—' }} tarihine kadar @if ($ds['from'])({{ $ds['from'] }}'den beri)@endif, {{ $runStatus[strtolower((string) $ds['status'])] ?? strtolower((string) $ds['status']) }}, son çekim {{ $when($ds['collected_at']) }}</p>
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

            <section>
                <div class="section-head"><h2 class="section-title">WordPress eklentisi <span class="section-count">güncel sürüm {{ $health['plugin_current'] }}</span></h2></div>
                <ul class="card">
                    @forelse ($health['plugins'] as $plugin)
                        <li class="card-row flex-wrap justify-between gap-y-1">
                            <span class="text-sm text-gray-900 dark:text-white">{{ $plugin['site'] }} — {{ $plugin['version'] ?? 'sürüm bilinmiyor' }}</span>
                            <span class="flex flex-wrap gap-3">
                                @if ($plugin['outdated'])<x-ta.pill tone="look">güncelleme gerekli</x-ta.pill>@endif
                                @if ($plugin['silent'])<x-ta.pill tone="critical">1 günden uzun süredir sinyal yok</x-ta.pill>@endif
                                @if (! $plugin['outdated'] && ! $plugin['silent'])<x-ta.pill tone="ok">Güncel</x-ta.pill>@endif
                            </span>
                        </li>
                    @empty
                        <li><x-ta.empty-state size="sm" icon="none" title="Eşleşmiş WordPress sitesi yok." /></li>
                    @endforelse
                </ul>
            </section>

            <p class="text-[13px]"><a href="{{ route('operator.settings', ['section' => 'operations']) }}" wire:navigate class="link-neutral">Ayarlar, Operasyon</a></p>
        </div>
    </details>
</div>
