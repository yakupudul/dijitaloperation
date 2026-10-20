@if ($approved->isNotEmpty())
    @php
        $awaitingSend = $approved->filter(fn ($s) => \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s));
        $editorDrafts = $approved->reject(fn ($s) => \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s) || filled($s->action['editor_batch'] ?? null));
        $editorBatches = $approved->filter(fn ($s) => ! \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s) && filled($s->action['editor_batch'] ?? null))
            ->groupBy(fn ($s) => (string) $s->action['editor_batch']);
    @endphp
    <section data-testid="ads-approved">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="section-title">Onaylı taslaklar · {{ $editorDrafts->count() }}</h2>
            @if ($editorDrafts->isNotEmpty())
                <button type="button" wire:click="downloadEditor" class="{{ $primary }}">Editor dosyası indir</button>
            @endif
        </div>
        <div class="text-sm">
            @foreach ($editorDrafts as $s)
                <p class="border-b border-gray-200 py-2.5 text-gray-800 dark:border-gray-800 dark:text-gray-200" wire:key="approved-{{ $s->id }}">{{ $s->title }}</p>
            @endforeach
            @foreach ($editorBatches as $batch => $rows)
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 py-2.5 dark:border-gray-800" wire:key="batch-{{ md5($batch) }}" data-testid="ads-editor-batch">
                    <span class="min-w-0 break-all text-gray-800 dark:text-gray-200">İndirilen dosya {{ $batch }} <span class="text-gray-500">{{ $rows->count() }} taslak</span></span>
                    <button type="button" wire:click="downloadEditor('{{ $batch }}')" class="ml-auto rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Tekrar indir</button>
                    <button type="button" x-on:click="if (confirm('Bu dosyadaki değişiklikler Google Ads Editor’dan gönderildi mi?')) $wire.confirmEditorBatch('{{ $batch }}')" class="rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Editor'a aktardım</button>
                </div>
            @endforeach
            @foreach ($awaitingSend as $s)
                <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 py-2.5 dark:border-gray-800" wire:key="approved-{{ $s->id }}">
                    <span class="text-gray-800 dark:text-gray-200">{{ $s->title }}</span>
                    @if (filled($s->action['sending_write_id'] ?? null))
                        <span class="ml-auto text-xs text-gray-500">Gönderiliyor…</span>
                    @elseif ($canWrite)
                        <button type="button" x-on:click="if (confirm('Negatif Google Ads paylaşılan listesine eklensin mi?')) $wire.approveSuggestion({{ $s->id }})" class="ml-auto rounded-md px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700 dark:hover:bg-white/[0.04]">Gönder</button>
                    @else
                        <span class="ml-auto text-xs text-gray-500">Göndermeyi Admin onaylar.</span>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
