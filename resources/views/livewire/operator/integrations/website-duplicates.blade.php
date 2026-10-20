@php
    $card = 'min-w-0';
    $ruleLabels = [
        'keeper' => 'tutulanınki kalır',
        'newer' => 'yeni olan kalır',
        'connector' => 'kalan bağlayıcınınki kalır',
    ];
    $tableLabels = [
        'website_url' => 'Sayfalar',
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
<div class="space-y-10">
    <div>
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Kopya web siteleri</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Kopya kayıt arşivlenir, silinmez; birleştirmeyi yalnız Admin yapar.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg px-3 py-2 text-sm tone-ok">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg px-3 py-2 text-sm tone-critical">{{ $error }}</p>@endif

    @forelse ($groups as $group)
        @php $keeperId = $keeperFor($group); @endphp
        <section class="{{ $card }}" wire:key="dup-group-{{ $group['key'] }}" data-duplicate-group="{{ $group['host'] }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="section-title">{{ $group['host'] }}
                        <span class="text-sm font-normal text-gray-500">{{ count($group['assets']) }} kayıt</span>
                        @if ($group['cross_customer'])<x-ta.pill tone="look" class="ml-2 font-normal">farklı müşteriler</x-ta.pill>@endif
                    </h2>
                    <p class="text-xs text-gray-500" title="Varsayılan: aktif müşterinin markasındaki, bağlı hesabı olan, en çok verisi olan, en eski kayıt.">Tutulacak kaydı seçin.</p>
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
                    <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800 [&_th]:font-normal">
                        <tr>
                            <th class="py-2 pr-3">Tut</th>
                            <th class="py-2 pr-3">Kayıt</th>
                            <th class="py-2 pr-3">Müşteri › Marka</th>
                            <th class="py-2 pr-3">Oluşturma</th>
                            <th class="py-2 pr-3 text-right">Hesap</th>
                            <th class="py-2 pr-3 text-right">Sayfa</th>
                            <th class="py-2 pr-3 text-right">Veri</th>
                            <th class="py-2 pr-3">WordPress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($group['assets'] as $row)
                            <tr wire:key="dup-asset-{{ $row['id'] }}" @class(['bg-gray-50 dark:bg-white/[0.03]' => $row['id'] === $keeperId])>
                                <td class="py-2 pr-3">
                                    <input type="radio" name="keeper-{{ $group['key'] }}" value="{{ $row['id'] }}" wire:model.live="keepers.{{ $group['key'] }}" @checked($row['id'] === $keeperId) @disabled(! $isAdmin) aria-label="{{ $row['name'] }} kaydını tut">
                                </td>
                                <td class="py-2 pr-3">
                                    <span class="font-medium text-gray-800 dark:text-gray-100">#{{ $row['id'] }} {{ $row['name'] }}</span>
                                    <span class="block text-xs text-gray-500">{{ $row['url'] }}, {{ $row['status'] }}</span>
                                </td>
                                <td class="py-2 pr-3 text-gray-700 dark:text-gray-300">
                                    {{ $row['customer'] ?? 'markasız' }}@if ($row['brand']) › {{ $row['brand'] }}@endif
                                    @if ($row['customer'] && ! $row['customer_active'])<span class="text-xs text-warning-700">(pasif)</span>@endif
                                </td>
                                <td class="py-2 pr-3 text-gray-500">{{ $row['created_at'] ? \Illuminate\Support\Carbon::parse($row['created_at'])->format('d.m.Y') : '—' }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['bindings'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['pages'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['counts']['facts'] }}</td>
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
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">Önizleme <span class="font-normal text-gray-500">deneme, hiçbir şey değişmez</span></p>
                    @foreach ($plans as $duplicateId => $plan)
                        @if (isset($plan['error']))
                            <p class="rounded-lg p-3 text-sm tone-critical" wire:key="plan-{{ $duplicateId }}">#{{ $duplicateId }}: {{ $plan['error'] }}</p>
                            @continue
                        @endif
                        <div class="border-b border-gray-200 py-3 text-sm dark:border-gray-800" wire:key="plan-{{ $duplicateId }}">
                            <p class="font-medium text-gray-800 dark:text-gray-100">#{{ $duplicateId }} → #{{ $keeperId }}: {{ $plan['move_total'] }} satır taşınır, {{ $plan['collision_total'] }} çakışma
                                @if ($plan['cross_customer'])<span class="text-warning-700">, yetki devri</span>@endif
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
                                                <td @class(['py-0.5 pr-3 text-right', 'text-warning-700' => $table['collisions'] > 0])>{{ $table['collisions'] }}</td>
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
        <section>
            <h2 class="section-title">Son birleştirmeler</h2>
            <ul class="mt-2 divide-y divide-gray-200 text-sm text-gray-600 dark:divide-gray-800 dark:text-gray-300">
                @foreach ($recent as $merge)
                    <li class="py-2.5"><span class="text-gray-900 dark:text-white">{{ $merge['host'] }}</span>: #{{ $merge['duplicate_id'] }} {{ $merge['duplicate'] }} → #{{ $merge['keeper_id'] }} {{ $merge['keeper'] }}, {{ $merge['moved'] }} satır taşındı, {{ $merge['dropped'] }} bırakıldı @if ($merge['cross_customer']), yetki devri @endif <span class="block text-xs text-gray-500">{{ $merge['at'] }}, {{ $merge['by'] ?? '—' }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
