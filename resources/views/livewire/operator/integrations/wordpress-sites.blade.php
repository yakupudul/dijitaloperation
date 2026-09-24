@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">WordPress siteleri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">MoxDOP Connector bağlı siteler: eklenti ve WordPress sürümü, bekleyen güncellemeler, WordPress Site Sağlığı sonucu. Eklenti {{ $minimum }} ve üstünde, site yöneticisi eklenti ayarlarından açtıysa: tek tık panel girişi (tek kullanımlık, 60 saniye) ve onaylı güncelleme. Her ikisi yalnız Admin içindir ve kayda geçer. Güncellemeler geri alınamaz; önemli sitelerde önce yedek alın.</p>
    </div>
    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '' || $flashError)<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error !== '' ? $error : $flashError }}</p>@endif

    @forelse ($rows as $row)
        @php
            $site = $row['site'];
            $h = $row['health'];
        @endphp
        <section class="{{ $card }}" wire:key="wp-{{ $site->id }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $site->name }} <span class="font-normal text-gray-500">· {{ $site->domain }} · {{ $site->brand?->name }}</span></p>
                    <p class="text-xs text-gray-500">
                        Eklenti {{ $row['plugin_version'] ?: '—' }}@unless ($row['managed']) <span class="text-amber-700">(yönetim için {{ $minimum }} gerekli)</span>@endunless
                        @if ($h) · WordPress {{ $h['wordpress_version'] ?? '—' }} · PHP {{ $h['php_version'] ?? '—' }}@endif
                        @if ($row['checked_at']) · {{ \Illuminate\Support\Carbon::parse($row['checked_at'])->format('d.m.Y H:i') }}@endif
                    </p>
                    <p class="mt-1 text-xs">
                        <span @class(['rounded-full px-2 py-0.5', 'bg-amber-50 text-amber-800' => $row['pending'] > 0, 'bg-emerald-50 text-emerald-700' => $row['pending'] === 0 && $h])>{{ $h ? $row['pending'].' bekleyen güncelleme' : 'sağlık okunmadı' }}</span>
                        @if (($h['site_health'] ?? null) !== null)<span @class(['ml-1 rounded-full px-2 py-0.5', 'bg-rose-50 text-rose-700' => $row['critical'] > 0, 'bg-gray-100 text-gray-600' => $row['critical'] === 0])>Site Sağlığı: {{ $row['critical'] }} kritik, {{ $h['site_health']['recommended'] }} öneri</span>@endif
                        @if ($row['error'])<span class="ml-1 text-rose-700">{{ \Illuminate\Support\Str::limit($row['error'], 120) }}</span>@endif
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
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
                <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-800">
                    @if ($pendingItems->isEmpty())
                        <p class="text-sm text-gray-500">Bekleyen güncelleme yok.</p>
                    @else
                        <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                            @foreach ($pendingItems as $u)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-1.5">
                                    <span>{{ $u['label'] }} <span class="text-xs text-gray-500">{{ $u['from'] }} → {{ $u['to'] }}</span></span>
                                    @if ($isAdmin && ($h['updates_enabled'] ?? false))
                                        <button type="button" wire:click="applyUpdate({{ $site->id }}, @js($u['type']), @js($u['item']), @js($u['label']))" wire:confirm="{{ $u['label'] }} {{ $u['to'] }} sürümüne güncellensin mi? Geri alınamaz." class="rounded px-2 py-0.5 text-xs text-brand-700 ring-1 ring-inset ring-brand-300">Güncelle</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @unless ($h['updates_enabled'] ?? false)<p class="mt-2 text-xs text-gray-500">Onaylı güncelleme bu sitede kapalı (site yöneticisi eklenti ayarlarından açar).</p>@endunless
                    @endif
                    @if ($actions->isNotEmpty())
                        <p class="mt-3 text-xs font-semibold text-gray-600">Son güncellemeler</p>
                        @foreach ($actions as $action)
                            <p class="text-xs text-gray-600">{{ $action->created_at?->format('d.m.Y H:i') }} · {{ $action->request_payload['label'] ?? $action->request_payload['item'] ?? '' }} · {{ $action->status }}@if (isset($action->result['to_version'])) → {{ $action->result['to_version'] }}@endif @if ($action->error)<span class="text-rose-700">{{ \Illuminate\Support\Str::limit($action->error, 120) }}</span>@endif</p>
                        @endforeach
                    @endif
                </div>
            @endif
        </section>
    @empty
        <section class="{{ $card }} text-sm text-gray-500">Bağlı WordPress sitesi yok (Entegrasyonlar › Site bağlayıcıları).</section>
    @endforelse
</div>
