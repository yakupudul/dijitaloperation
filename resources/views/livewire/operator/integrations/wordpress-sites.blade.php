@php
    // Panel concept 2026-10-20: "Hangi WordPress sitesi güncelleme bekliyor ya da sorunlu?" The connector update that
    // waits on the operator comes first, then one row per site with its status and buttons; details open in place.
    $updatable = $rows->filter(fn ($r) => $r['connector_update']['available'])->count();
    $sorted = $rows->sortBy(fn ($r) => ($r['error'] || $r['critical'] > 0 ? '0' : ($r['pending'] > 0 ? '1' : '2')).mb_strtolower((string) $r['site']->name));
@endphp
<div class="space-y-10">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">WordPress siteleri</h1>
            <p class="page-sub" title="Eklenti {{ $minimum }} ve üstünde, site yöneticisi açtıysa: tek tık panel girişi (tek kullanımlık, 60 saniye) ve onaylı güncelleme. Yalnız Admin, kayda geçer.">{{ $rows->count() }} site. Güncellemeler geri alınamaz; önemli sitelerde önce yedek alın.</p>
        </div>
    </header>

    @if ($isAdmin && $updatable > 0)
        <div class="card -mt-4">
            <div class="card-row flex-wrap sm:flex-nowrap">
                <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">MoxDOP Connector {{ $latestConnector }} hazır: {{ $updatable }} siteye gönderilebilir.</p>
                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Otomatik dağıtılmaz; siz onaylayıp gönderirsiniz.</p>
                </div>
                <button type="button" wire:click="updateAllConnectors" wire:confirm="MoxDOP Connector {{ $latestConnector }} {{ $updatable }} web sitesine gönderilsin mi?" class="btn-primary w-full sm:w-auto">Tüm sitelere gönder</button>
            </div>
        </div>
    @endif
    @if ($message !== '')<p class="card px-4 py-3 text-sm text-success-700 dark:text-success-400" role="status">{{ $message }}</p>@endif
    @if ($error !== '' || $flashError)<p class="card px-4 py-3 text-sm text-error-700 dark:text-error-400" role="alert">{{ $error !== '' ? $error : $flashError }}</p>@endif

    <section>
        <div class="section-head">
            <h2 class="section-title">Siteler <span class="section-count">{{ $rows->count() }}</span></h2>
        </div>
        <ul class="card">
            @forelse ($sorted as $row)
                @php
                    $site = $row['site'];
                    $h = $row['health'];
                @endphp
                <li class="border-t border-gray-200 first:border-t-0 dark:border-gray-800" wire:key="wp-{{ $site->id }}">
                    <div class="card-row flex-wrap items-start border-t-0 lg:flex-nowrap lg:items-center">
                        <x-ta.brand-avatar :name="$site->brand?->name ?? $site->name" :logo="$site->brand?->logo_url" />
                        <div class="min-w-0 flex-1">
                            @php
                                $siteName = str_replace(' · ', ', ', (string) $site->name);
                                $where = implode(', ', array_filter([(string) $site->domain, (string) $site->brand?->name]));
                                $oldPlugin = $row['plugin_version'] !== '' && version_compare($row['plugin_version'], $latestConnector, '<');
                                $env = $h ? 'WordPress '.($h['wordpress_version'] ?? '—').', PHP '.($h['php_version'] ?? '—') : '';
                                $checked = $row['checked_at'] ? \Illuminate\Support\Carbon::parse($row['checked_at'])->format('d.m.Y H:i') : '';
                            @endphp
                            <p class="truncate text-sm font-medium text-gray-900 dark:text-white" title="{{ $siteName }}">{{ $siteName }}</p>
                            @if ($where !== '')<p class="truncate text-[13px] text-gray-500 dark:text-gray-400">{{ $where }}</p>@endif
                            <p class="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                                <span>
                                    Eklenti {{ $row['plugin_version'] ?: '—' }}
                                    @if ($row['connector_update']['available'])
                                        <span class="font-medium text-gray-900 dark:text-white">yeni: {{ $latestConnector }}</span>
                                    @elseif ($oldPlugin)
                                        <span class="text-warning-700 dark:text-warning-400" title="{{ $row['connector_update']['reason'] }}">(yeni: {{ $latestConnector }}, bir kez elle yükle)</span>
                                    @endif
                                    @unless ($row['managed'])
                                        <span class="text-warning-700 dark:text-warning-400">(yönetim için {{ $minimum }} gerekli)</span>
                                    @endunless
                                </span>
                                @if ($env !== '')<span class="whitespace-nowrap">{{ $env }}</span>@endif
                                @if ($checked !== '')<span class="whitespace-nowrap" title="Son okuma">{{ $checked }}</span>@endif
                            </p>
                            <p class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                                <x-ta.pill :tone="$row['pending'] > 0 ? 'look' : ($h ? 'ok' : 'info')">{{ $h ? $row['pending'].' bekleyen güncelleme' : 'sağlık okunmadı' }}</x-ta.pill>
                                @if (($h['site_health'] ?? null) !== null)<x-ta.pill :tone="$row['critical'] > 0 ? 'critical' : 'info'">Site Sağlığı: {{ $row['critical'] }} kritik, {{ $h['site_health']['recommended'] }} öneri</x-ta.pill>@endif
                                @if ($row['error'])<span class="text-xs text-error-700 dark:text-error-400">{{ \Illuminate\Support\Str::limit($row['error'], 120) }}</span>@endif
                            </p>
                        </div>
                        <div class="flex w-full flex-wrap items-center gap-1.5 lg:w-auto lg:flex-nowrap">
                            @if ($row['managed'])
                                <button type="button" wire:click="refreshHealth({{ $site->id }})" class="btn-quiet">Sağlığı yenile</button>
                                @if ($isAdmin && ($h['login_enabled'] ?? false))
                                    <form method="post" action="{{ route('operator.integrations.wordpress-login', ['site' => $site->id]) }}" target="_blank">@csrf<button type="submit" class="btn-quiet">WP paneline gir</button></form>
                                @endif
                            @endif
                            @if ($isAdmin && $row['connector_update']['available'])
                                <button type="button" wire:click="updateConnector({{ $site->id }})" wire:confirm="MoxDOP Connector {{ $row['plugin_version'] }} → {{ $latestConnector }} bu web sitesine gönderilsin mi?" class="btn">Web sitesine gönder</button>
                            @endif
                            <button type="button" wire:click="$set('open', {{ $open === $site->id ? 'null' : $site->id }})" class="btn">{{ $open === $site->id ? 'Kapat' : 'Ayrıntı' }}</button>
                        </div>
                    </div>

                    @if ($open === $site->id && $h)
                        @php
                            $pendingItems = collect([]);
                            if (($h['core_update'] ?? null) !== null) {
                                $pendingItems->push(['type' => 'core', 'item' => '', 'label' => 'WordPress', 'from' => $h['wordpress_version'] ?? '', 'to' => $h['core_update']]);
                            }
                            foreach ((array) ($h['plugins'] ?? []) as $p) {
                                if (($p['update'] ?? null) !== null) {
                                    $pendingItems->push(['type' => 'plugin', 'item' => $p['file'], 'label' => $p['name'], 'from' => $p['version'], 'to' => $p['update']]);
                                }
                            }
                            foreach ((array) ($h['themes'] ?? []) as $t) {
                                if (($t['update'] ?? null) !== null) {
                                    $pendingItems->push(['type' => 'theme', 'item' => $t['stylesheet'], 'label' => $t['name'].' (tema)', 'from' => $t['version'], 'to' => $t['update']]);
                                }
                            }
                        @endphp
                        <div class="border-t border-gray-200 bg-gray-50/60 dark:border-gray-800 dark:bg-white/[0.02]">
                            @if ($pendingItems->isEmpty())
                                <p class="px-4 py-3 text-[13px] text-gray-500 sm:pl-14">Bekleyen güncelleme yok.</p>
                            @else
                                <ul>
                                    @foreach ($pendingItems as $u)
                                        <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-gray-200 px-4 py-2.5 text-sm first:border-t-0 sm:flex-nowrap sm:pl-14 dark:border-gray-800">
                                            <span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $u['label'] }} <span class="text-[13px] text-gray-500">{{ $u['from'] }} sürümünden {{ $u['to'] }} sürümüne</span></span>
                                            @if ($isAdmin && ($h['updates_enabled'] ?? false))
                                                <button type="button" wire:click="applyUpdate({{ $site->id }}, @js($u['type']), @js($u['item']), @js($u['label']))" wire:confirm="{{ $u['label'] }} {{ $u['to'] }} sürümüne güncellensin mi? Geri alınamaz." class="btn h-7! px-2.5! text-xs!">Güncelle</button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                                @unless ($h['updates_enabled'] ?? false)<p class="border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 sm:pl-14 dark:border-gray-800">Onaylı güncelleme bu sitede kapalı (site yöneticisi eklenti ayarlarından açar).</p>@endunless
                            @endif
                            @if ($actions->isNotEmpty())
                                <div class="border-t border-gray-200 px-4 py-3 sm:pl-14 dark:border-gray-800">
                                    <p class="text-[13px] font-medium text-gray-700 dark:text-gray-300">Son güncellemeler</p>
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach ($actions as $action)
                                            <li class="text-xs text-gray-600 dark:text-gray-400"><span class="tabular-nums text-gray-400">{{ $action->created_at?->format('d.m.Y H:i') }}</span> {{ $action->request_payload['label'] ?? $action->request_payload['item'] ?? '' }}, {{ mb_strtolower($action->statusLabel()) }}@if (isset($action->result['to_version'])), {{ $action->result['to_version'] }}@endif @if ($action->error)<span class="text-error-700 dark:text-error-400">{{ \Illuminate\Support\Str::limit($action->error, 120) }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li><x-ta.empty-state icon="none" size="sm" title="Bağlı WordPress sitesi yok." message="Entegrasyonlar sayfasındaki site bağlayıcılarından bir site eşleştir." /></li>
            @endforelse
        </ul>
    </section>
</div>
