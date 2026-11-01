{{-- Ayarlar › WordPress: versions, Site Health, pending updates, one-click panel login (from the old WordPress siteleri screen). --}}
<div>
@if ($connected)
    @php
        $h = $health;
        $env = $h ? 'WordPress '.($h['wordpress_version'] ?? '—').', PHP '.($h['php_version'] ?? '—') : '';
        $checked = $checkedAt ? \Illuminate\Support\Carbon::parse($checkedAt)->format('d.m.Y H:i') : '';
        $items = collect();
        if ($h) {
            if (($h['core_update'] ?? null) !== null) {
                $items->push(['type' => 'core', 'item' => '', 'label' => 'WordPress', 'from' => $h['wordpress_version'] ?? '', 'to' => $h['core_update']]);
            }
            foreach ((array) ($h['plugins'] ?? []) as $p) {
                if (($p['update'] ?? null) !== null) {
                    $items->push(['type' => 'plugin', 'item' => $p['file'], 'label' => $p['name'], 'from' => $p['version'], 'to' => $p['update']]);
                }
            }
            foreach ((array) ($h['themes'] ?? []) as $t) {
                if (($t['update'] ?? null) !== null) {
                    $items->push(['type' => 'theme', 'item' => $t['stylesheet'], 'label' => $t['name'].' (tema)', 'from' => $t['version'], 'to' => $t['update']]);
                }
            }
        }
    @endphp
    <section class="min-w-0 text-sm" data-wordpress-card>
        <div class="section-head items-center">
            <h2 class="section-title" title="Eklenti {{ $minimum }} ve üstünde, site yöneticisi açtıysa: tek tık panel girişi (tek kullanımlık, 60 saniye) ve onaylı güncelleme. Yalnız Admin, kayda geçer.">WordPress</h2>
            @if ($managed)
                <span class="ml-auto flex flex-wrap items-center gap-1.5">
                    <button type="button" wire:click="refreshHealth" class="btn-quiet">Sağlığı yenile</button>
                    @if ($isAdmin && ($h['login_enabled'] ?? false))
                        <form method="post" action="{{ \App\Http\Controllers\Operator\WordPressLoginController::url($websiteId) }}" target="_blank">@csrf<button type="submit" class="btn">WP paneline gir</button></form>
                    @endif
                </span>
            @endif
        </div>
        @if ($message !== '')<p class="mb-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-success-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-success-400" role="status">{{ $message }}</p>@endif
        @if ($error !== '' || $flashError)<p class="mb-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-error-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-error-400" role="alert">{{ $error !== '' ? $error : $flashError }}</p>@endif
        <div class="card">
            <div class="card-row flex-wrap">
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap gap-x-3 gap-y-0.5 text-[13px] text-gray-500 dark:text-gray-400">
                        @if ($env !== '')<span class="whitespace-nowrap">{{ $env }}</span>@endif
                        @if ($checked !== '')<span class="whitespace-nowrap" title="Son okuma">son okuma {{ $checked }}</span>@endif
                        @unless ($managed)<span class="text-warning-700 dark:text-warning-400">Yönetim için eklenti {{ $minimum }} gerekli.</span>@endunless
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1">
                        <x-ta.pill :tone="$pending > 0 ? 'look' : ($h ? 'ok' : 'info')">{{ $h ? $pending.' bekleyen güncelleme' : 'sağlık okunmadı' }}</x-ta.pill>
                        @if (($h['site_health'] ?? null) !== null)<x-ta.pill :tone="$critical > 0 ? 'critical' : 'info'">Site Sağlığı: {{ $critical }} kritik, {{ $h['site_health']['recommended'] }} öneri</x-ta.pill>@endif
                        @if ($healthError)<span class="text-xs text-error-700 dark:text-error-400">{{ \Illuminate\Support\Str::limit($healthError, 120) }}</span>@endif
                    </p>
                </div>
            </div>
            @if ($h)
                @if ($items->isEmpty())
                    <p class="border-t border-gray-200 px-4 py-3 text-[13px] text-gray-500 dark:border-gray-800">Bekleyen güncelleme yok.</p>
                @else
                    <ul data-wordpress-updates>
                        @foreach ($items as $u)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-gray-200 px-4 py-2.5 sm:flex-nowrap dark:border-gray-800">
                                <span class="min-w-0 flex-1 text-gray-900 dark:text-white">{{ $u['label'] }} <span class="text-[13px] text-gray-500">{{ $u['from'] }} sürümünden {{ $u['to'] }} sürümüne</span></span>
                                @if ($isAdmin && ($h['updates_enabled'] ?? false))
                                    <button type="button" wire:click="applyUpdate(@js($u['type']), @js($u['item']), @js($u['label']))" wire:confirm="{{ $u['label'] }} {{ $u['to'] }} sürümüne güncellensin mi? Geri alınamaz." class="btn-dark h-7! px-2.5! text-xs!">Güncelle</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @unless ($h['updates_enabled'] ?? false)<p class="border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800">Onaylı güncelleme bu sitede kapalı (site yöneticisi eklenti ayarlarından açar).</p>@endunless
                @endif
            @endif
            @if ($actions->isNotEmpty())
                <div class="border-t border-gray-200 px-4 py-3 dark:border-gray-800">
                    <p class="text-[13px] font-medium text-gray-700 dark:text-gray-300">Son güncellemeler</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($actions as $action)
                            <li class="text-xs text-gray-600 dark:text-gray-400"><span class="tabular-nums text-gray-400">{{ $action->created_at?->format('d.m.Y H:i') }}</span> {{ $action->request_payload['label'] ?? $action->request_payload['item'] ?? '' }}, {{ mb_strtolower($action->statusLabel()) }}@if (isset($action->result['to_version'])), {{ $action->result['to_version'] }}@endif @if ($action->error)<span class="text-error-700 dark:text-error-400">{{ \Illuminate\Support\Str::limit($action->error, 120) }}</span>@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </section>
@endif
</div>
