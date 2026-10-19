@php
    $card = 'surface min-w-0 p-4';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    // Per-row actions are neutral; orange stays on "Haftalık içerik öner" (and the send button inside an opened draft).
    $rowBtn = 'rounded-lg bg-white px-2 py-1 text-xs font-semibold text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700';
    $rowGhost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
@endphp
<div class="space-y-4" data-content @if (in_array('çalışıyor…', $statuses, true) || $draftStatus === 'çalışıyor…') wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-gray-50 p-2 text-gray-800 dark:bg-gray-950 dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif
    @if ($errors->any())<p class="text-xs text-error-600">{{ $errors->first() }}</p>@endif

    @include('livewire.operator.work.partials.content-boxes', ['single' => true])

    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <button type="button" wire:click="run('weekly_content')" class="{{ $btn }}">Haftalık içerik öner</button>
        <x-operator.ai-prompt-info operation="site.weekly_content" />
        <span class="min-w-0 text-xs text-gray-500" data-rhythm>Bu hafta {{ $rhythm['new'] }} yeni yazı + {{ $rhythm['update'] }} güncelleme ({{ $rhythm['label'] }}, kümelerin %{{ $rhythm['filled'] }}'i yeterli)@if ($contentState?->inBackfill()) · geriye dönük doldurma sürüyor @endif</span>
        <button type="button" wire:click="run('content_discovery')" class="{{ $ghost }}">Kümeler dışında fırsat keşfet</button>
        <x-operator.ai-prompt-info operation="site.content_discovery" />
        @foreach ($statuses as $op => $line)<span class="text-xs text-gray-500">{{ \App\Services\Site\SiteOperations::LABELS[$op] }}: {{ $line }}</span>@endforeach
        <select wire:model.live="status" aria-label="Durum" class="{{ $input }} w-full sm:ml-auto sm:w-auto">
            <option value="">Tümü</option>
            @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
    </section>

    @include('livewire.operator.website.v2.partials.topic-map')

    <section class="{{ $card }}" data-section="content">
        <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
            @forelse ($items as $item)
                @php $a = (array) $item->action; @endphp
                <li class="py-2" wire:key="ct-{{ $item->id }}" data-content-item="{{ $item->id }}" x-data="{ rejecting: false }">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0 flex-1">
                            <p class="break-words font-medium">{{ $item->title }}
                                <span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600 dark:bg-gray-800">{{ ($a['kind'] ?? 'new') === 'update' ? 'güncelle' : 'yeni' }} · {{ $a['page_type'] ?? '—' }}</span>
                                @if ($a['out_of_cluster'] ?? false)<span class="{{ $chip }} ml-1 bg-live-50 text-live-700">küme dışı</span>@endif
                                @if (isset($a['article_write_id']))<span class="{{ $chip }} ml-1 bg-success-50 text-success-700" data-draft-state="sent">WordPress'e taslak gönderildi</span>
                                @elseif (is_array($a['article'] ?? null))<span class="{{ $chip }} ml-1 bg-gray-50 text-gray-700" data-draft-state="ready">Taslak hazır · okumak için «Aç»</span>
                                @elseif (! empty($a['article_blocked']))<span class="{{ $chip }} ml-1 bg-error-50 text-error-700" data-draft-state="blocked">Uyum kuralına takıldı</span>@endif
                            </p>
                            <p class="wrap-anywhere text-gray-500" title="{{ $item->reason }}">{{ $item->cluster_id !== null ? ($clusterNames[$item->cluster_id] ?? '—').' · ' : '' }}{{ $a['target_url'] ?? '—' }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                            @if (($a['out_of_cluster'] ?? false) && ! isset($a['library_cluster_id']) && isset($a['service_id']))
                                <button type="button" wire:click="addToLibrary({{ $item->id }})" class="{{ $rowGhost }}">Kütüphaneye ekle</button>
                            @endif
                            @if (in_array($item->status, ['open', 'recheck'], true))
                                <button type="button" wire:click="prepareDraft({{ $item->id }})" class="{{ $rowBtn }}" title="AI; başlıklar, sorular, küme sorguları, SEO analizi reçetesi, marka dosyası ve sektör kurallarıyla makaleyi yazar.">{{ is_array($a['article'] ?? null) || ! empty($a['article_blocked']) ? 'Yeniden yaz' : 'Taslak hazırla' }}</button>
                                <x-operator.ai-prompt-info operation="site.write_article" />
                                <button type="button" x-show="! rejecting" x-on:click="rejecting = true" class="{{ $rowGhost }}">Reddet</button>
                            @endif
                            <button type="button" wire:click="open({{ $item->id }})" class="{{ $rowGhost }}">{{ $opened?->id === $item->id ? 'Kapat' : 'Aç' }}</button>
                        </div>
                    </div>
                    @if (in_array($item->status, ['open', 'recheck'], true))
                        <div x-show="rejecting" x-cloak class="mt-2 flex flex-wrap items-center gap-1.5">
                            <input type="text" wire:model="reasons.{{ $item->id }}" placeholder="Neden" aria-label="Reddetme nedeni" class="{{ $input }} min-w-0 flex-1 sm:max-w-xs">
                            <button type="button" wire:click="dismiss({{ $item->id }})" class="{{ $ghost }}">Reddet</button>
                            <button type="button" x-on:click="rejecting = false" class="{{ $rowGhost }}">Vazgeç</button>
                        </div>
                    @endif
                    @if ($item->reason && $opened?->id === $item->id)<p class="mt-1 text-gray-600 dark:text-gray-300">{{ $item->reason }}</p>@endif
                    @if ($opened?->id === $item->id)
                        <div class="mt-2 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 dark:bg-gray-950" data-detail>
                            <div><p class="font-semibold text-gray-500">Taslak başlıklar</p><ul class="list-disc pl-4">@foreach ((array) ($a['outline'] ?? []) as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                            <div><p class="font-semibold text-gray-500">AI asistanlarına sorulanlar</p><ul class="list-disc pl-4">@foreach ((array) ($a['questions'] ?? []) as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                            @if ($draftStatus)<p class="text-gray-500 sm:col-span-2">Taslak: {{ $draftStatus }}</p>@endif
                            @if (! empty($a['article_blocked']))
                                <div class="sm:col-span-2" data-article-blocked>
                                    <p class="text-error-600">{{ str_starts_with($a['article_blocked'], 'Kopya') ? '' : 'Uyum kuralına takıldı (AI bir kez düzeltmeyi denedi): ' }}{{ $a['article_blocked'] }}</p>
                                    <p class="mt-0.5 text-gray-500">Bu taslak WordPress'e gönderilemez. «Taslak hazırla» ile yeniden yazdırabilirsin; aşağıda yazılan metni görebilirsin.</p>
                                    @if (is_array($a['article_blocked_draft'] ?? null))
                                        @php preg_match_all('/«([^»]+)»/u', (string) $a['article_blocked'], $bad); $bad = array_unique($bad[1] ?? []); @endphp
                                        <p class="mt-1 font-semibold">{{ $a['article_blocked_draft']['title'] }}</p>
                                        <div class="mt-1 max-h-72 overflow-y-auto rounded bg-white p-2 dark:bg-gray-900" data-blocked-draft>
                                            @foreach (\App\Services\Site\SiteDiff::blocks((string) $a['article_blocked_draft']['html']) as $block)
                                                @php $text = e($block['text']); foreach ($bad as $phrase) { $text = preg_replace('/'.preg_quote(e($phrase), '/').'/iu', '<mark class="rounded bg-error-100 px-0.5 text-error-800">$0</mark>', $text); } @endphp
                                                <p @class(['my-1', 'font-semibold' => str_starts_with($block['tag'], 'h'), 'pl-3' => $block['tag'] === 'li'])>{{ $block['tag'] === 'li' ? '• ' : '' }}{!! $text !!}</p>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                            @if (! empty($a['article_warnings']))<p class="text-warning-700 sm:col-span-2" data-article-warnings>{{ $a['article_warnings'] }}</p>@endif
                            @if (is_array($a['article'] ?? null))
                                <div class="sm:col-span-2" data-article>
                                    <p class="font-semibold">{{ $a['article']['title'] }} <span class="text-gray-500">· /{{ $a['article']['slug'] }}/ · {{ $a['article']['meta_description'] }}</span></p>
                                    <div class="mt-1 max-h-72 overflow-y-auto rounded bg-white p-2 dark:bg-gray-900">
                                        @foreach (\App\Services\Site\SiteDiff::blocks((string) $a['article']['html']) as $block)
                                            <p @class(['my-1', 'font-semibold' => str_starts_with($block['tag'], 'h'), 'pl-3' => $block['tag'] === 'li'])>{{ $block['tag'] === 'li' ? '• ' : '' }}{{ $block['text'] }}</p>
                                        @endforeach
                                    </div>
                                    @if (! empty($a['article_note']))<p class="text-gray-500">{{ $a['article_note'] }}</p>@endif
                                    @if (! isset($a['article_write_id']))
                                        <button type="button" wire:click="sendDraft({{ $item->id }})" wire:confirm="Makale WordPress’e taslak olarak gönderilsin mi?" class="{{ $btn }} mt-2">WordPress’e taslak gönder</button>
                                    @else
                                        <p class="text-success-700">WordPress taslağı gönderildi.</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="py-3"><x-ta.empty-state size="sm" title="İçerik önerisi yok" message="«Haftalık içerik öner» ile bu haftanın yazılarını hazırlat." /></li>
            @endforelse
        </ul>
        <div class="mt-2">{{ $items->links() }}</div>
    </section>
</div>
