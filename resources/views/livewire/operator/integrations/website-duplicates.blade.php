@php
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
    {{-- Panel concept 2026-10-20: "Hangi alan adı birden çok kayıtta, hangisini tutacağım?" One section per host. --}}
    <header>
        <h1 class="page-title">Kopya web siteleri</h1>
        <p class="page-sub">{{ count($groups) > 0 ? count($groups).' alan adı birden çok kayıtta. ' : '' }}Kopya kayıt arşivlenir, silinmez; birleştirmeyi yalnız Admin yapar.</p>
    </header>

    @if ($message !== '')<p class="card -mt-4 px-4 py-3 text-sm text-success-700 dark:text-success-400" role="status">{{ $message }}</p>@endif
    @if ($error !== '')<p class="card -mt-4 px-4 py-3 text-sm text-error-700 dark:text-error-400" role="alert">{{ $error }}</p>@endif

    @forelse ($groups as $group)
        @php $keeperId = $keeperFor($group); @endphp
        <section wire:key="dup-group-{{ $group['key'] }}" data-duplicate-group="{{ $group['host'] }}">
            <div class="section-head items-center">
                <h2 class="section-title">{{ $group['host'] }} <span class="section-count">{{ count($group['assets']) }} kayıt</span></h2>
                @if ($group['cross_customer'])<x-ta.pill tone="look">farklı müşteriler</x-ta.pill>@endif
                <span class="text-[13px] text-gray-500" title="Varsayılan: aktif müşterinin markasındaki, bağlı hesabı olan, en çok verisi olan, en eski kayıt.">Tutulacak kaydı seçin.</span>
                <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                    <button type="button" wire:click="preview({{ $group['key'] }})" class="btn">{{ $previewGroup === $group['key'] ? 'Önizlemeyi kapat' : 'Önizle' }}</button>
                    @if ($isAdmin)
                        @if ($group['cross_customer'])
                            <button type="button" wire:click="mergeGroup({{ $group['key'] }})" class="btn-primary">Birleştir</button>
                        @else
                            <button type="button" wire:click="mergeGroup({{ $group['key'] }})" wire:confirm="Kopya kayıtlar #{{ $keeperId }} ile birleştirilsin mi? Kopyalar arşivlenir." class="btn-primary">Birleştir</button>
                        @endif
                    @endif
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="w-12">Tut</th>
                            <th>Kayıt</th>
                            <th>Müşteri, marka</th>
                            <th class="hidden md:table-cell">Oluşturma</th>
                            <th class="text-right!">Hesap</th>
                            <th class="text-right!">Sayfa</th>
                            <th class="text-right!">Veri</th>
                            <th class="hidden sm:table-cell">WordPress</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group['assets'] as $row)
                            <tr wire:key="dup-asset-{{ $row['id'] }}" @class(['[&>td]:bg-gray-50 dark:[&>td]:bg-white/[0.03]' => $row['id'] === $keeperId])>
                                <td>
                                    <input type="radio" name="keeper-{{ $group['key'] }}" value="{{ $row['id'] }}" wire:model.live="keepers.{{ $group['key'] }}" @checked($row['id'] === $keeperId) @disabled(! $isAdmin) aria-label="{{ $row['name'] }} kaydını tut">
                                </td>
                                <td class="max-w-0 w-[34%]">
                                    <span class="block truncate font-medium text-gray-900 dark:text-white" title="#{{ $row['id'] }} {{ $row['name'] }}">{{ $row['name'] }} <span class="font-normal text-gray-400">#{{ $row['id'] }}</span></span>
                                    <span class="block truncate text-xs text-gray-500" title="{{ $row['url'] }}">{{ $row['url'] }}, {{ $row['status'] }}</span>
                                </td>
                                <td class="text-[13px] text-gray-700 dark:text-gray-300">
                                    {{ $row['customer'] ?? 'markasız' }}@if ($row['brand']), {{ $row['brand'] }}@endif
                                    @if ($row['customer'] && ! $row['customer_active'])<span class="text-xs text-warning-700 dark:text-warning-400">(pasif)</span>@endif
                                </td>
                                <td class="hidden text-[13px] text-gray-500 md:table-cell">{{ $row['created_at'] ? \Illuminate\Support\Carbon::parse($row['created_at'])->format('d.m.Y') : '—' }}</td>
                                <td class="text-right tabular-nums">{{ $row['counts']['bindings'] }}</td>
                                <td class="text-right tabular-nums">{{ $row['counts']['pages'] }}</td>
                                <td class="text-right tabular-nums">{{ $row['counts']['facts'] }}</td>
                                <td class="hidden text-[13px] sm:table-cell">{{ match ($row['connector']) { 'paired' => 'eşleşmiş', 'pending' => 'bekliyor', default => '—' } }}</td>
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
                <div class="card mt-3" data-merge-preview>
                    <p class="border-b border-gray-200 bg-gray-50 px-4 py-2.5 text-[13px] font-medium text-gray-700 dark:border-gray-800 dark:bg-white/[0.02] dark:text-gray-300">Önizleme <span class="font-normal text-gray-500">deneme, hiçbir şey değişmez</span></p>
                    @foreach ($plans as $duplicateId => $plan)
                        @if (isset($plan['error']))
                            <p class="card-row text-sm text-error-700 dark:text-error-400" wire:key="plan-{{ $duplicateId }}">#{{ $duplicateId }}: {{ $plan['error'] }}</p>
                            @continue
                        @endif
                        <div class="border-t border-gray-200 px-4 py-3.5 text-sm first-of-type:border-t-0 dark:border-gray-800" wire:key="plan-{{ $duplicateId }}">
                            <p class="font-medium text-gray-900 dark:text-white">#{{ $duplicateId }} kaydından #{{ $keeperId }} kaydına: {{ $plan['move_total'] }} satır taşınır, {{ $plan['collision_total'] }} çakışma @if ($plan['cross_customer'])<span class="text-warning-700 dark:text-warning-400">(yetki devri)</span>@endif</p>
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
                                <table class="mt-2 min-w-full text-xs tabular-nums">
                                    <thead class="text-gray-500"><tr><th class="py-1 pr-3 text-left">Veri</th><th class="py-1 pr-3 text-right">Satır</th><th class="py-1 pr-3 text-right">Çakışan</th><th class="py-1 pr-3 text-left">Çakışınca</th></tr></thead>
                                    <tbody>
                                        @foreach ($plan['tables'] as $table)
                                            <tr>
                                                <td class="py-0.5 pr-3">{{ $tableLabels[$table['table']] ?? $table['table'] }}</td>
                                                <td class="py-0.5 pr-3 text-right">{{ $table['rows'] }}</td>
                                                <td @class(['py-0.5 pr-3 text-right', 'text-warning-700 dark:text-warning-400' => $table['collisions'] > 0])>{{ $table['collisions'] }}</td>
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
        <div class="card"><x-ta.empty-state title="Kopya web sitesi yok" message="Her alan adı tek bir web sitesi kaydında." /></div>
    @endforelse

    @if ($recent->isNotEmpty())
        <section>
            <div class="section-head"><h2 class="section-title">Son birleştirmeler <span class="section-count">{{ $recent->count() }}</span></h2></div>
            <ul class="card">
                @foreach ($recent as $merge)
                    <li class="card-row">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $merge['host'] }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">#{{ $merge['duplicate_id'] }} {{ $merge['duplicate'] }} kaydı #{{ $merge['keeper_id'] }} {{ $merge['keeper'] }} kaydına katıldı: {{ $merge['moved'] }} satır taşındı, {{ $merge['dropped'] }} bırakıldı{{ $merge['cross_customer'] ? ', yetki devri' : '' }}</p>
                        </div>
                        <span class="shrink-0 text-right text-xs text-gray-500">{{ $merge['at'] }}<span class="block">{{ $merge['by'] ?? '—' }}</span></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
