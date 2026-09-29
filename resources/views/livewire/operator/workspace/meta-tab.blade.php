<div class="space-y-5" data-workspace-tab="meta">
    <x-workspace.tab-header channel="meta" :last-run="$lastRun" :notice="$notice" :notice-tone="$noticeTone" :missing="$missing" :can-run="$operational" />

    @if ($stats !== [])
        <section aria-label="Durum" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ($stats as $stat)
                <x-workspace.stat :label="$stat['label']" :value="$stat['display']" :delta="$stat['delta_pct'] ?? null" :note="$stat['note'] ?? null"
                    :tone="in_array($stat['id'], ['tracking', 'learning_limited'], true) && $stat['value'] > 0 ? ($stat['id'] === 'tracking' ? 'error' : 'warning') : null" />
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

    @if (array_filter($evidence) !== [])
        <section aria-label="Kanıt" class="space-y-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Kanıt</h2>
            <x-workspace.evidence-table title="Hesaplar" :columns="['name' => 'Hesap', 'spend' => 'Harcama', 'results' => 'Sonuç', 'cpr' => 'Sonuç başı', 'frequency' => 'Sıklık 7g']" :rows="$evidence['accounts']" />
            <x-workspace.evidence-table title="Kampanyalar" :columns="['name' => 'Kampanya', 'fit' => 'Hedef', 'budget' => 'Bütçe', 'spend' => 'Harcama', 'results' => 'Sonuç', 'cpr' => 'Sonuç başı']" :rows="$evidence['campaigns']" />
            @if ($evidence['regions'] !== [])
                <x-workspace.evidence-table title="Hizmet bölgesi dışı harcama" :columns="['name' => 'Bölge', 'spend' => 'Harcama', 'share' => '%', 'results' => 'Sonuç']" :rows="$evidence['regions']" />
            @endif
            @if ($evidence['ads'] !== [])
                <x-workspace.evidence-table title="Yorulan / kurala aykırı reklamlar" :columns="['name' => 'Reklam', 'status' => 'Durum', 'frequency' => 'Sıklık 7g', 'ctr' => 'CTR 7g', 'ctr_prev' => 'CTR önceki 7g', 'spend' => 'Harcama']" :rows="$evidence['ads']" />
            @endif
            @if ($evidence['tracking'] !== [])
                <x-workspace.evidence-table title="Ölçüm sorunları" :columns="['name' => 'Kaynak', 'issue' => 'Sorun', 'fix' => 'Yapılacak']" :rows="$evidence['tracking']" />
            @endif
        </section>
    @endif
</div>
