@if ($approved->isNotEmpty())
    @php
        $awaitingSend = $approved->filter(fn ($s) => \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s));
        $editorDrafts = $approved->reject(fn ($s) => \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s) || filled($s->action['editor_batch'] ?? null));
        $editorBatches = $approved->filter(fn ($s) => ! \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s) && filled($s->action['editor_batch'] ?? null))
            ->groupBy(fn ($s) => (string) $s->action['editor_batch']);
    @endphp
    <section class="{{ $panel }}" data-testid="ads-approved">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 dark:border-gray-700">
            <h2 class="font-semibold text-gray-900 dark:text-white">Onaylı taslaklar · {{ $editorDrafts->count() }}</h2>
            @if ($editorDrafts->isNotEmpty())
                <button type="button" wire:click="downloadEditor" class="{{ $primary }}">Editor dosyası indir</button>
            @endif
        </div>
        <div class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
            @foreach ($editorDrafts as $s)
                <p class="px-4 py-2 text-gray-800 dark:text-gray-200" wire:key="approved-{{ $s->id }}">{{ $s->title }}</p>
            @endforeach
            @foreach ($editorBatches as $batch => $rows)
                <div class="flex flex-wrap items-center gap-2 px-4 py-2" wire:key="batch-{{ md5($batch) }}" data-testid="ads-editor-batch">
                    <span class="text-gray-800 dark:text-gray-200">İndirilen dosya · {{ $batch }} · {{ $rows->count() }} taslak</span>
                    <button type="button" wire:click="downloadEditor('{{ $batch }}')" class="ml-auto rounded border border-gray-300 px-2 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300">Tekrar indir</button>
                    <button type="button" x-on:click="if (confirm('Bu dosyadaki değişiklikler Google Ads Editor’dan gönderildi mi?')) $wire.confirmEditorBatch('{{ $batch }}')" class="rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Editor'a aktardım</button>
                </div>
            @endforeach
            @foreach ($awaitingSend as $s)
                <div class="flex items-center gap-2 px-4 py-2" wire:key="approved-{{ $s->id }}">
                    <span class="text-gray-800 dark:text-gray-200">{{ $s->title }}</span>
                    @if (filled($s->action['sending_write_id'] ?? null))
                        <span class="ml-auto text-xs text-gray-500">Gönderiliyor…</span>
                    @elseif ($canWrite)
                        <button type="button" x-on:click="if (confirm('Negatif Google Ads paylaşılan listesine eklensin mi?')) $wire.approveSuggestion({{ $s->id }})" class="ml-auto rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Gönder</button>
                    @else
                        <span class="ml-auto text-xs text-gray-500">Göndermeyi Admin onaylar.</span>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
