<div class="space-y-5" data-workspace-tab="search">
    <x-workspace.tab-header channel="search" :last-run="$lastRun" :notice="$notice" :notice-tone="$noticeTone" :missing="$missing" :can-run="$operational" />

    @if ($stats !== [])
        <section aria-label="Durum" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            @foreach ($stats as $stat)
                <x-workspace.stat :label="$stat['label']" :value="$stat['display']" :delta="$stat['delta_pct'] ?? null" :note="$stat['note'] ?? null"
                    :tone="$stat['id'] === 'technical_blockers' && $stat['value'] > 0 ? 'error' : null" />
            @endforeach
        </section>
    @endif

    <section aria-label="Yapılacaklar" class="space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Yapılacaklar</h2>
        @forelse ($decisions as $decision)
            <x-workspace.decision-card :decision="$decision" />
        @empty
            <p class="text-sm text-gray-500">Açık iş yok.</p>
        @endforelse
    </section>

    @if ($matrix !== null || $queries !== [] || $standards !== [])
        <section aria-label="Kanıt" class="space-y-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Kanıt</h2>
            @if ($matrix !== null && $matrix['total'] > 0)
                <details class="group" data-workspace-matrix>
                    <summary class="cursor-pointer list-none text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Hizmet × bölge ({{ $matrix['covered'] }}/{{ $matrix['total'] }}) <span class="group-open:hidden">▸</span><span class="hidden group-open:inline">▾</span></summary>
                    <div class="mt-2 overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="text-gray-400">
                                <tr>
                                    <th class="px-2 py-1 font-medium">Hizmet</th>
                                    @foreach ($matrix['areas'] as $area)
                                        <th class="whitespace-nowrap px-2 py-1 text-center font-medium">{{ $area }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($matrix['services'] as $serviceId => $service)
                                    <tr>
                                        <td class="whitespace-nowrap px-2 py-1 text-gray-700 dark:text-gray-300">{{ $service }}</td>
                                        @foreach ($matrix['areas'] as $areaId => $area)
                                            @php($cell = $matrix['cells'][$serviceId.':'.$areaId])
                                            <td @class(['px-2 py-1 text-center', 'text-success-600' => $cell['status'] !== 'none', 'text-gray-300' => $cell['status'] === 'none'])>
                                                @if ($cell['status'] === 'rank')✓ {{ str_replace('.', ',', (string) $cell['position']) }}@elseif ($cell['status'] === 'page')✓@else—@endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
            <x-workspace.evidence-table title="Sorgular" :columns="['text' => 'Sorgu', 'impressions' => 'Gösterim', 'clicks' => 'Tık', 'position' => 'Sıra']" :rows="$queries" />
            @if ($standards !== [])
                <x-workspace.evidence-table title="Teknik blokajlar" :columns="['rule' => 'Kural', 'urls' => 'URL', 'path' => 'Örnek']" :rows="$standards" />
            @endif
        </section>
    @endif
</div>
