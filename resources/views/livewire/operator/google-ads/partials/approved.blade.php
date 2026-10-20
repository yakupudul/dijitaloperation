@if ($approved->isNotEmpty())
    @php
        $awaitingSend = $approved->filter(fn ($s) => \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s));
        $editorDrafts = $approved->reject(fn ($s) => \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s) || filled($s->action['editor_batch'] ?? null));
        $editorBatches = $approved->filter(fn ($s) => ! \App\Services\GoogleAds\GoogleAdsSuggestions::isSharedNegative($s) && filled($s->action['editor_batch'] ?? null))
            ->groupBy(fn ($s) => (string) $s->action['editor_batch']);
    @endphp
    <section data-testid="ads-approved">
        <div class="section-head">
            <h2 class="section-title">Onaylı taslaklar <span class="section-count">{{ $editorDrafts->count() }}</span></h2>
            @if ($editorDrafts->isNotEmpty())
                <button type="button" wire:click="downloadEditor" class="{{ $primary }} sm:ml-auto">Editor dosyası indir</button>
            @endif
        </div>
        <ul class="card">
            @foreach ($editorDrafts as $s)
                <li class="card-row text-sm text-gray-900 dark:text-gray-200" wire:key="approved-{{ $s->id }}"><span class="min-w-0 break-words">{{ $s->title }}</span></li>
            @endforeach
            @foreach ($editorBatches as $batch => $rows)
                <li class="card-row flex-wrap sm:flex-nowrap" wire:key="batch-{{ md5($batch) }}" data-testid="ads-editor-batch">
                    <span class="min-w-0 flex-1">
                        <span class="block break-all text-sm text-gray-900 dark:text-gray-200">İndirilen dosya {{ $batch }}</span>
                        <span class="mt-0.5 block text-[13px] text-gray-500">{{ $rows->count() }} taslak</span>
                    </span>
                    <span class="flex shrink-0 items-center gap-1">
                        <button type="button" wire:click="downloadEditor('{{ $batch }}')" class="btn-quiet h-7! px-2! text-xs!">Tekrar indir</button>
                        <button type="button" x-on:click="if (confirm('Bu dosyadaki değişiklikler Google Ads Editor’dan gönderildi mi?')) $wire.confirmEditorBatch('{{ $batch }}')" class="{{ $small }}">Editor'a aktardım</button>
                    </span>
                </li>
            @endforeach
            @foreach ($awaitingSend as $s)
                <li class="card-row flex-wrap sm:flex-nowrap" wire:key="approved-{{ $s->id }}">
                    <span class="min-w-0 flex-1 break-words text-sm text-gray-900 dark:text-gray-200">{{ $s->title }}</span>
                    @if (filled($s->action['sending_write_id'] ?? null))
                        <span class="flex shrink-0 items-center gap-1.5 text-xs text-gray-500"><span class="live-dot"></span>Gönderiliyor…</span>
                    @elseif ($canWrite)
                        <button type="button" x-on:click="if (confirm('Negatif Google Ads paylaşılan listesine eklensin mi?')) $wire.approveSuggestion({{ $s->id }})" class="{{ $small }}">Gönder</button>
                    @else
                        <span class="shrink-0 text-xs text-gray-500">Göndermeyi Admin onaylar.</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
