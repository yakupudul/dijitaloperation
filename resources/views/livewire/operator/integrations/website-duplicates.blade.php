@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $ruleLabels = [
        'keeper' => 'tutulanınki kalır',
        'newer' => 'yeni olan kalır',
        'connector' => 'kalan bağlayıcınınki kalır',
    ];
    $tableLabels = [
        'website_url' => 'Sayfalar',
        'seo_tasks' => 'SEO görevleri',
        'seo_plans' => 'SEO planları',
        'site_fix_items' => 'Site düzeltmeleri',
        'findings' => 'Bulgular',
        'evidence' => 'Kanıtlar',
        'runs' => 'Çalıştırmalar',
        'tasks' => 'İşler',
        'wordpress_site_health' => 'WordPress sağlığı',
        'asset_alerts' => 'Uyarılar',
        'uptime_checks' => 'Erişilebilirlik ölçümleri',
    ];
    $bindingActions = [
        'move' => 'taşınır',
        'replace' => 'tutulandaki pasif bağlantının yerine geçer',
        'disable' => 'tutulanın bağlantısı kalır; bu pasifleşir',
    ];
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Kopya web siteleri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Aynı alan adına (www., http/https ve sondaki / fark etmez) sahip birden fazla web sitesi kaydı. Birleştirince kopyanın bağlı hesapları, toplanmış verisi, SEO görevleri, site düzeltmeleri ve WordPress bağlayıcısı tutulan kayda taşınır; aynı şey iki kayıtta da varsa tutulanınki kalır. Kopya kayıt arşivlenir, silinmez. Birleştirmeyi yalnız Admin yapar; farklı müşterilere ait kayıtlar yetki devri onayı ister.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif

    @forelse ($groups as $group)
        @php $keeperId = $keeperFor($group); @endphp
        <section class="{{ $card }}" wire:key="dup-group-{{ $group['key'] }}" data-duplicate-group="{{ $group['host'] }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $group['host'] }}
                        <span class="font-normal text-gray-500">· {{ count($group['assets']) }} kayıt</span>
                        @if ($group['cross_customer'])<span class="ml-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs text-amber-800">farklı müşteriler</span>@endif
                    </p>
                    <p class="text-xs text-gray-500">Tutulacak kaydı seçin; varsayılan: aktif müşterinin markasındaki, bağlı hesabı olan, en çok verisi olan, en eski kayıt.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ta.button type="button" wire:click="preview({{ $group['key'] }})" size="sm" variant="outline">{{ $previewGroup === $group['key'] ? 'Önizlemeyi kapat' : 'Önizle' }}</x-ta.button>
                    @if ($isAdmin)
                        @if ($group['cross_customer'])
                            <x-ta.button type="button" wire:click="mergeGroup({{ $group['key'] }})" size="sm">Birleştir</x-ta.button>
                        @else
                            <x-ta.button type="button" wire:click="mergeGroup({{ $group['key'] }})" wire:confirm="Kopya kayıtlar #{{ $keeperId }} ile birleştirilsin mi? Kopyalar arşivlenir." size="sm">Birleştir</x-ta.button>
                        @endif
                    @endif
                </div>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="py-2 pr-3">Tut</th>
                            <th class="py-2 pr-3">Kayıt</th>
                            <th class="py-2 pr-3">Müşteri › Marka</th>
                            <th class="py-2 pr-3">Oluşturma</th>
                            <th class="py-2 pr-3 text-right">Hesap</th>
                            <th class="py-2 pr-3 text-right">Sayfa</th>
                            <th class="py-2 pr-3 text-right">Veri</th>
                            <th class="py-2 pr-3 text-right">SEO görevi</th>
                            <th class="py-2 pr-3 text-right">Site düzeltmesi</th>
                            <th class="py-2 pr-3">WordPress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($group['assets'] as $row)
                            <tr wire:key="dup-asset-{{ $row['id'] }}" @class(['bg-brand-50/50 dark:bg-brand-500/5' => $row['id'] === $keeperId])>
                                <td class="py-2 pr-3">
                                    <input type="radio" name="keeper-{{ $group['key'] }}" value="{{ $row['id'] }}" wire:model.live="keepers.{{ $group['key'] }}" @checked($row['id'] === $keeperId) @disabled(! $isAdmin) aria-label="{{ $row['name'] }} kaydını tut">
                                </td>
                                <td class="py-2 pr-3">
                                    <span class="font-medium text-gray-800 dark:text-gray-100">#{{ $row['id'] }} {{ $row['name'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $row['url'] }} · {{ $row['status'] }}</span>
                                </td>
                                <td class="py-2 pr-3 text-gray-700 dark:text-gray-300">
                                    {{ $row['customer'] ?? 'markasız' }}@if ($row['brand']) › {{ $row['brand'] }}@endif
                                    @if ($row['customer'] && ! $row['customer_active'])<span class="text-xs text-amber-700">(pasif)</span>@endif
                                </td>
                                <td class="py-2 pr-3 text-gray-500">{{ $row['created_at'] ? \Illuminate\Support\Carbon::parse($row['created_at'])->format('d.m.Y') : '—' }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['bindings'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['pages'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['facts'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['seo_tasks'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['site_fixes'] }}</td>
                                <td class="py-2 pr-3">{{ match ($row['connector']) { 'paired' => 'eşleşmiş', 'pending' => 'bekliyor', default => '—' } }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($ownershipConflict !== null && ($pendingTransfer['group'] ?? null) === $group['key'])
                <div class="mt-4">
                    <x-operator.ownership-transfer-panel :conflict="$ownershipConflict" action="confirmMerge" :can-transfer="$isAdmin" button="Birleştir" />
                </div>
            @endif

            @if ($previewGroup === $group['key'])
                <div class="mt-4 space-y-3" data-merge-preview>
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Önizleme (deneme — hiçbir şey değişmez)</p>
                    @foreach ($plans as $duplicateId => $plan)
                        @if (isset($plan['error']))
                            <p class="rounded-lg bg-rose-50 p-3 text-sm text-rose-800" wire:key="plan-{{ $duplicateId }}">#{{ $duplicateId }}: {{ $plan['error'] }}</p>
                            @continue
                        @endif
                        <div class="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/[0.03]" wire:key="plan-{{ $duplicateId }}">
                            <p class="font-medium text-gray-800 dark:text-gray-100">#{{ $duplicateId }} → #{{ $keeperId }}: {{ $plan['move_total'] }} satır taşınır, {{ $plan['collision_total'] }} çakışma
                                @if ($plan['cross_customer'])<span class="text-amber-700">· yetki devri</span>@endif
                            </p>
                            @if ($plan['bindings'] !== [])
                                <ul class="mt-2 space-y-0.5 text-xs text-gray-600 dark:text-gray-300">
                                    @foreach ($plan['bindings'] as $binding)
                                        <li>Hesap bağlantısı {{ $binding['capability'] }} ({{ $binding['status'] }}): {{ $bindingActions[$binding['action']] ?? $binding['action'] }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @foreach ($plan['connectors'] as $connector)
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Bağlayıcı ({{ $connector['type'] }}): {{ $connector['winner'] === 'keeper' ? 'tutulan kaydınki kalır' : 'kopyadaki taşınır, tutulandaki pasifleşir' }}</p>
                            @endforeach
                            @if ($plan['tables'] !== [])
                                <table class="mt-2 min-w-full text-xs">
                                    <thead class="text-gray-500"><tr><th class="py-1 pr-3 text-left">Veri</th><th class="py-1 pr-3 text-right">Satır</th><th class="py-1 pr-3 text-right">Çakışan</th><th class="py-1 pr-3 text-left">Çakışınca</th></tr></thead>
                                    <tbody>
                                        @foreach ($plan['tables'] as $table)
                                            <tr>
                                                <td class="py-0.5 pr-3">{{ $tableLabels[$table['table']] ?? $table['table'] }}</td>
                                                <td class="py-0.5 pr-3 text-right">{{ $table['rows'] }}</td>
                                                <td @class(['py-0.5 pr-3 text-right', 'text-amber-700' => $table['collisions'] > 0])>{{ $table['collisions'] }}</td>
                                                <td class="py-0.5 pr-3 text-gray-500">{{ $table['collisions'] > 0 ? ($ruleLabels[$table['rule']] ?? $table['rule']) : '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @elseif ($plan['bindings'] === [] && $plan['connectors'] === [])
                                <p class="mt-1 text-xs text-gray-500">Kopyada taşınacak veri yok; yalnız arşivlenir.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @empty
        <x-ta.empty-state title="Kopya web sitesi yok" message="Her alan adı tek bir web sitesi kaydında." />
    @endforelse

    @if ($recent->isNotEmpty())
        <section class="{{ $card }}">
            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Son birleştirmeler</p>
            <ul class="mt-2 space-y-1 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($recent as $merge)
                    <li>{{ $merge['at'] }} · {{ $merge['host'] }}: #{{ $merge['duplicate_id'] }} {{ $merge['duplicate'] }} → #{{ $merge['keeper_id'] }} {{ $merge['keeper'] }} · {{ $merge['moved'] }} satır taşındı, {{ $merge['dropped'] }} bırakıldı @if ($merge['cross_customer'])· yetki devri @endif · {{ $merge['by'] ?? '—' }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
