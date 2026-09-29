@if ($approved->isNotEmpty())
    @php
        $awaitingSend = $approved->filter(fn ($s) => $s->action_type === 'ads_negative' && ($s->action['scope'] ?? '') === 'shared');
        $editorDrafts = $approved->reject(fn ($s) => $s->action_type === 'ads_negative' && ($s->action['scope'] ?? '') === 'shared');
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
            @foreach ($awaitingSend as $s)
                <div class="flex items-center gap-2 px-4 py-2" wire:key="approved-{{ $s->id }}">
                    <span class="text-gray-800 dark:text-gray-200">{{ $s->title }}</span>
                    @if ($canWrite)
                        <button type="button" x-on:click="if (confirm('Negatif Google Ads paylaşılan listesine eklensin mi?')) $wire.approveSuggestion({{ $s->id }})" class="ml-auto rounded bg-success-500 px-2 py-1 text-xs font-semibold text-white hover:bg-success-600">Gönder</button>
                    @else
                        <span class="ml-auto text-xs text-gray-500">Göndermeyi Admin onaylar.</span>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
