<div class="space-y-5" data-workspace-tab="maps">
    <x-workspace.tab-header channel="maps" :last-run="$lastRun" :notice="$notice" :notice-tone="$noticeTone" :missing="$missing" :can-run="$operational" />

    @if ($stats !== [])
        <section aria-label="Durum" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ($stats as $stat)
                <x-workspace.stat :label="$stat['label']" :value="$stat['display']" :delta="$stat['delta_pct'] ?? null" :note="$stat['note'] ?? null"
                    :tone="$stat['id'] === 'unanswered_reviews' && $stat['value'] > 0 ? 'error' : null" />
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

    @if ($locations !== [])
        <section aria-label="Kanıt" class="space-y-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Kanıt</h2>
            <x-workspace.evidence-table title="Konumlar" :columns="['name' => 'Konum', 'rating' => 'Puan', 'reviews' => 'Yorum', 'unanswered' => 'Yanıtsız', 'maps_views' => 'Harita (28g)', 'last_post_days' => 'Son gönderi (gün)']" :rows="$locations" />
            <x-workspace.evidence-table title="Aramalar" :columns="['text' => 'Arama', 'impressions' => 'Gösterim', 'service' => 'Hizmet', 'status' => 'Durum']" :rows="$keywords" />
            @if ($standards !== [])
                <x-workspace.evidence-table title="Profil standartları" :columns="['rule' => 'Kural', 'note' => 'Bulgu']" :rows="$standards" />
            @endif
            @if ($grid !== [])
                <x-workspace.evidence-table title="Harita sıralaması" :columns="['text' => 'Kelime', 'top3_pct' => 'İlk-3 %', 'position' => 'Ort. sıra', 'leader' => 'Önde']" :rows="$grid" />
            @endif
        </section>
    @endif
</div>
