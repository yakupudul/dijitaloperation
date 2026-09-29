<div class="space-y-5" data-workspace-tab="google_ads">
    <x-workspace.tab-header channel="google_ads" :last-run="$lastRun" :notice="$notice" :notice-tone="$noticeTone" :missing="$missing" :can-run="$operational" />

    @if ($stats !== [])
        <section aria-label="Durum" class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            @foreach ($stats as $stat)
                <x-workspace.stat :label="$stat['label']" :value="$stat['display']" :delta="$stat['delta_pct'] ?? null" :note="$stat['note'] ?? null"
                    :tone="$stat['id'] === 'tracking' && $stat['value'] > 0 ? (($stat['broken'] ?? false) ? 'error' : 'warning') : null" />
            @endforeach
        </section>
    @endif

    @if ($negDecision !== null)
        <section aria-label="Negatif listesi" class="space-y-2 rounded-xl bg-white p-4 ring-1 ring-inset ring-brand-200 dark:bg-gray-900 dark:ring-brand-800" data-negative-review>
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Negatif listesi · {{ $negAsset?->name }}</h2>
            <p class="text-xs text-gray-500">[terim] tam eşleme, "terim" sıralı eşleme. "{{ config('moxdop-external-writes.google_ads.shared_set_name') }}" listesine eklenir, arama kampanyalarına bağlanır; geri alınabilir.</p>
            <textarea wire:model="negLines" rows="8" class="w-full rounded-lg border border-gray-300 p-2 font-mono text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"></textarea>
            <div class="flex flex-wrap gap-2">
                @if ($canWrite)
                    <button type="button" wire:click="sendNegatives" wire:loading.attr="disabled" class="inline-flex items-center rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-medium text-white hover:bg-brand-600">Onayla ve Google Ads'e gönder</button>
                @else
                    <span class="text-xs text-gray-500">Göndermek için Admin onayı gerekli.</span>
                @endif
                <button type="button" wire:click="cancelNegatives" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 dark:text-gray-300 dark:ring-gray-700">Vazgeç</button>
            </div>
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

    @if ($writes->isNotEmpty())
        <section aria-label="Google Ads gönderimleri" class="space-y-1 text-xs text-gray-600 dark:text-gray-400" data-negative-writes>
            @foreach ($writes as $write)
                <p class="flex flex-wrap items-center gap-2">
                    <span>{{ $write->created_at?->timezone(config('app.timezone'))->format('d.m H:i') }} · {{ count((array) ($write->request_payload['keywords'] ?? [])) }} negatif · {{ $write->statusLabel() }}</span>
                    @if ($canWrite && $write->isUndoable())
                        <button type="button" wire:click="undoNegativeWrite({{ $write->id }})" class="font-medium text-brand-600 hover:underline">Geri al</button>
                    @endif
                </p>
            @endforeach
        </section>
    @endif

    @if ($accounts !== [])
        <section aria-label="Kanıt" class="space-y-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Kanıt</h2>
            <x-workspace.evidence-table title="Hesaplar ({{ count($accounts) }})" :columns="['name' => 'Hesap', 'currency' => 'Para', 'cost' => 'Harcama', 'conversions' => 'Dönüşüm', 'cpa' => 'CPA', 'wasted' => 'Boşa']" :rows="$accounts" />
            <x-workspace.evidence-table title="Boşa giden terimler" :columns="['text' => 'Terim', 'list' => 'Liste', 'account' => 'Hesap', 'cost' => 'Harcama', 'clicks' => 'Tık', 'excluded' => 'Negatif']" :rows="$terms" />
            <x-workspace.evidence-table title="Kampanyalar" :columns="['name' => 'Kampanya', 'type' => 'Tür', 'cost' => 'Harcama', 'conversions' => 'Dönüşüm', 'cpa' => 'CPA', 'lost' => 'Kayıp IS (bütçe / sıra)']" :rows="$campaigns" />
        </section>
    @endif
</div>
