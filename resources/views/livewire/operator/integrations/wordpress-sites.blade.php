@php
    $card = 'min-w-0';
@endphp
<div class="space-y-10">
    <div>
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">WordPress siteleri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500" title="Eklenti {{ $minimum }} ve üstünde, site yöneticisi açtıysa: tek tık panel girişi (tek kullanımlık, 60 saniye) ve onaylı güncelleme. Yalnız Admin, kayda geçer.">Güncellemeler geri alınamaz; önemli sitelerde önce yedek alın.</p>
    </div>
    @php
        $updatable = $rows->filter(fn ($r) => $r['connector_update']['available'])->count();
    @endphp
    @if ($isAdmin && $updatable > 0)
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm tone-info">
            <span>MoxDOP Connector {{ $latestConnector }} hazır: {{ $updatable }} siteye gönderilebilir. Otomatik dağıtılmaz; siz onaylayıp gönderirsiniz.</span>
            <x-ta.button type="button" wire:click="updateAllConnectors" wire:confirm="MoxDOP Connector {{ $latestConnector }} {{ $updatable }} web sitesine gönderilsin mi?" size="sm">Tüm sitelere gönder</x-ta.button>
        </div>
    @endif
    @if ($message !== '')<p class="rounded-lg px-3 py-2 text-sm tone-ok">{{ $message }}</p>@endif
    @if ($error !== '' || $flashError)<p class="rounded-lg px-3 py-2 text-sm tone-critical">{{ $error !== '' ? $error : $flashError }}</p>@endif

    @forelse ($rows as $row)
        @php
            $site = $row['site'];
            $h = $row['health'];
        @endphp
        <section class="border-b border-gray-200 pb-6 dark:border-gray-800" wire:key="wp-{{ $site->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="section-title">{{ $site->name }} <span class="text-sm font-normal text-gray-500">{{ $site->domain }}, {{ $site->brand?->name }}</span></h2>
                    <p class="text-xs text-gray-500">
                        Eklenti {{ $row['plugin_version'] ?: '—' }}@if ($row['connector_update']['available']) <span class="font-medium text-gray-900 dark:text-white">yeni: {{ $latestConnector }}</span>@elseif ($row['plugin_version'] !== '' && version_compare($row['plugin_version'], $latestConnector, '<')) <span class="text-warning-700" title="{{ $row['connector_update']['reason'] }}">(yeni: {{ $latestConnector }}, bir kez elle yükle)</span>@endif @unless ($row['managed']) <span class="text-warning-700">(yönetim için {{ $minimum }} gerekli)</span>@endunless
                        @if ($h), WordPress {{ $h['wordpress_version'] ?? '—' }}, PHP {{ $h['php_version'] ?? '—' }}@endif
                        @if ($row['checked_at']) · {{ \Illuminate\Support\Carbon::parse($row['checked_at'])->format('d.m.Y H:i') }}@endif
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                        <x-ta.pill :tone="$row['pending'] > 0 ? 'look' : ($h ? 'ok' : 'info')">{{ $h ? $row['pending'].' bekleyen güncelleme' : 'sağlık okunmadı' }}</x-ta.pill>
                        @if (($h['site_health'] ?? null) !== null)<x-ta.pill :tone="$row['critical'] > 0 ? 'critical' : 'info'">Site Sağlığı: {{ $row['critical'] }} kritik, {{ $h['site_health']['recommended'] }} öneri</x-ta.pill>@endif
                        @if ($row['error'])<span class="ml-1 text-error-700">{{ \Illuminate\Support\Str::limit($row['error'], 120) }}</span>@endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if ($isAdmin && $row['connector_update']['available'])
                        <x-ta.button type="button" wire:click="updateConnector({{ $site->id }})" wire:confirm="MoxDOP Connector {{ $row['plugin_version'] }} → {{ $latestConnector }} bu web sitesine gönderilsin mi?" size="sm">Web sitesine gönder</x-ta.button>
                    @endif
                    @if ($row['managed'])
                        <x-ta.button type="button" wire:click="refreshHealth({{ $site->id }})" size="sm" variant="outline">Sağlığı yenile</x-ta.button>
                        @if ($isAdmin && ($h['login_enabled'] ?? false))
                            <form method="post" action="{{ route('operator.integrations.wordpress-login', ['site' => $site->id]) }}" target="_blank">@csrf<x-ta.button type="submit" size="sm" variant="outline">WP paneline gir</x-ta.button></form>
                        @endif
                    @endif
                    <x-ta.button type="button" wire:click="$set('open', {{ $open === $site->id ? 'null' : $site->id }})" size="sm" variant="outline">{{ $open === $site->id ? 'Kapat' : 'Ayrıntı' }}</x-ta.button>
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
                <div class="mt-4">
                    @if ($pendingItems->isEmpty())
                        <p class="text-sm text-gray-500">Bekleyen güncelleme yok.</p>
                    @else
                        <ul class="divide-y divide-gray-200 text-sm dark:divide-gray-800">
                            @foreach ($pendingItems as $u)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                    <span>{{ $u['label'] }} <span class="text-xs text-gray-500">{{ $u['from'] }} → {{ $u['to'] }}</span></span>
                                    @if ($isAdmin && ($h['updates_enabled'] ?? false))
                                        <button type="button" wire:click="applyUpdate({{ $site->id }}, @js($u['type']), @js($u['item']), @js($u['label']))" wire:confirm="{{ $u['label'] }} {{ $u['to'] }} sürümüne güncellensin mi? Geri alınamaz." class="rounded-md px-2 py-0.5 text-xs ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:ring-gray-700">Güncelle</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @unless ($h['updates_enabled'] ?? false)<p class="mt-2 text-xs text-gray-500">Onaylı güncelleme bu sitede kapalı (site yöneticisi eklenti ayarlarından açar).</p>@endunless
                    @endif
                    @if ($actions->isNotEmpty())
                        <p class="mt-3 text-xs font-semibold text-gray-600">Son güncellemeler</p>
                        @foreach ($actions as $action)
                            <p class="text-xs text-gray-600">{{ $action->created_at?->format('d.m.Y H:i') }} · {{ $action->request_payload['label'] ?? $action->request_payload['item'] ?? '' }}, {{ $action->status }}@if (isset($action->result['to_version'])) → {{ $action->result['to_version'] }}@endif @if ($action->error)<span class="text-error-700">{{ \Illuminate\Support\Str::limit($action->error, 120) }}</span>@endif</p>
                        @endforeach
                    @endif
                </div>
            @endif
        </section>
    @empty
        <section class="text-sm text-gray-500">Bağlı WordPress sitesi yok (Entegrasyonlar › Site bağlayıcıları).</section>
    @endforelse
</div>
