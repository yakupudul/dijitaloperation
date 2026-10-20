@php
    $select = 'h-8 rounded-lg border border-gray-200 bg-white py-0 pl-2.5 pr-8 text-[13px] text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $input = 'h-8 rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-700 focus:border-gray-300 focus:ring-2 focus:ring-gray-200 dark:border-gray-700 dark:bg-gray-900 dark:text-white';
    // Per-row actions are neutral; the one dark button is "Haftalık içerik öner" (and the send button inside an opened draft).
    $rowBtn = 'btn h-7! px-2.5! text-xs!';
    $rowGhost = 'btn-quiet h-7! px-2! text-xs!';
@endphp
<div class="min-w-0 space-y-10" data-content @if (in_array('çalışıyor…', $statuses, true) || $draftStatus === 'çalışıyor…') wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="btn-quiet h-7! px-2!">×</button>
        </p>
    @endif
    @if ($errors->any())<p class="text-[13px] text-error-600">{{ $errors->first() }}</p>@endif

    {{-- 1. The answer: this week's articles, step by step, and the one button that makes them. --}}
    <section class="min-w-0">
        <div class="section-head justify-between">
            <div class="flex min-w-0 flex-[1_1_20rem] flex-wrap items-baseline gap-x-3 gap-y-0.5">
                <h2 class="section-title">Bu haftanın yazıları</h2>
                <span class="min-w-0 text-[13px] text-gray-500 dark:text-gray-400" data-rhythm>Bu hafta {{ $rhythm['new'] }} yeni yazı ve {{ $rhythm['update'] }} güncelleme. {{ $rhythm['label'] }}, yeterli küme oranı %{{ $rhythm['filled'] }}@if ($contentState?->inBackfill()), geriye dönük doldurma sürüyor @endif.</span>
            </div>
            <div class="flex w-full items-center gap-1.5 sm:w-auto">
                <button type="button" wire:click="run('weekly_content')" class="btn-primary flex-1 sm:flex-none">Haftalık içerik öner</button>
                <x-operator.ai-prompt-info operation="site.weekly_content" />
            </div>
        </div>
        @if ($statuses !== [])
            <p class="mb-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                @foreach ($statuses as $op => $line)<span>{{ \App\Services\Site\SiteOperations::LABELS[$op] }}: {{ $line }}</span>@endforeach
            </p>
        @endif
        @include('livewire.operator.work.partials.content-boxes', ['single' => true])
    </section>

    {{-- 2. Where the writing stands per service. --}}
    @include('livewire.operator.website.v2.partials.topic-map')

    {{-- 3. Every content suggestion with its draft, for detail work. --}}
    <section class="min-w-0" data-section="content">
        <div class="section-head justify-between">
            <div class="flex min-w-0 flex-wrap items-baseline gap-x-3">
                <h2 class="section-title">Tüm içerik önerileri</h2>
                <span class="section-count">{{ $items->total() }}</span>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <select wire:model.live="status" aria-label="Durum" class="{{ $select }} w-full sm:w-40">
                    <option value="">Tümü</option>
                    @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                <span class="flex w-full items-center gap-1 sm:w-auto">
                    <button type="button" wire:click="run('content_discovery')" class="btn flex-1 sm:flex-none">Kümeler dışında fırsat keşfet</button>
                    <x-operator.ai-prompt-info operation="site.content_discovery" />
                </span>
            </div>
        </div>
        <ul class="card">
            @forelse ($items as $item)
                @php $a = (array) $item->action; $isOpen = $opened?->id === $item->id; @endphp
                <li class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="ct-{{ $item->id }}" data-content-item="{{ $item->id }}" x-data="{ rejecting: false }">
                    <div class="flex min-w-0 flex-wrap items-start gap-x-4 gap-y-2 sm:flex-nowrap">
                        <div class="min-w-0 flex-[1_1_16rem]">
                            <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="min-w-0 break-words text-sm font-medium text-gray-900 dark:text-white">{{ $item->title }}</span>
                                <span class="text-xs text-gray-500">{{ ($a['kind'] ?? 'new') === 'update' ? 'güncelle' : 'yeni' }}, {{ $a['page_type'] ?? '—' }}</span>
                                @if ($a['out_of_cluster'] ?? false)<span class="text-xs text-gray-500">küme dışı</span>@endif
                                @if (isset($a['article_write_id']))<x-ta.pill tone="ok" data-draft-state="sent">WordPress'e taslak gönderildi</x-ta.pill>
                                @elseif (is_array($a['article'] ?? null))<x-ta.pill tone="info" data-draft-state="ready" title="Okumak için «Aç»">Taslak hazır</x-ta.pill>
                                @elseif (! empty($a['article_blocked']))<x-ta.pill tone="critical" data-draft-state="blocked">Uyum kuralına takıldı</x-ta.pill>@endif
                            </p>
                            <p class="mt-0.5 wrap-anywhere text-[13px] text-gray-500 dark:text-gray-400" title="{{ $item->reason }}">{{ $item->cluster_id !== null ? ($clusterNames[$item->cluster_id] ?? '—').', ' : '' }}{{ isset($a['target_url']) ? \App\Services\SeoTasks\SeoText::urlPath((string) $a['target_url']) : '—' }}</p>
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
                            <button type="button" wire:click="open({{ $item->id }})" class="{{ $rowGhost }}" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">{{ $isOpen ? 'Kapat' : 'Aç' }}</button>
                        </div>
                    </div>
                    @if (in_array($item->status, ['open', 'recheck'], true))
                        <div x-show="rejecting" x-cloak class="mt-2.5 flex flex-wrap items-center gap-1.5">
                            <input type="text" wire:model="reasons.{{ $item->id }}" placeholder="Neden" aria-label="Reddetme nedeni" class="{{ $input }} min-w-0 flex-1 sm:max-w-xs">
                            <button type="button" wire:click="dismiss({{ $item->id }})" class="{{ $rowBtn }}">Reddet</button>
                            <button type="button" x-on:click="rejecting = false" class="{{ $rowGhost }}">Vazgeç</button>
                        </div>
                    @endif
                    @if ($item->reason && $isOpen)<p class="mt-2 text-[13px] text-gray-600 dark:text-gray-300">{{ $item->reason }}</p>@endif
                    @if ($isOpen)
                        <div class="mt-3 grid gap-4 border-t border-dashed border-gray-200 pt-3 text-[13px] sm:grid-cols-2 dark:border-gray-800" data-detail>
                            <div><p class="text-xs font-medium text-gray-500">Taslak başlıklar</p><ul class="mt-1 list-disc space-y-0.5 pl-4 text-gray-700 dark:text-gray-300">@foreach ((array) ($a['outline'] ?? []) as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                            <div><p class="text-xs font-medium text-gray-500">AI asistanlarına sorulanlar</p><ul class="mt-1 list-disc space-y-0.5 pl-4 text-gray-700 dark:text-gray-300">@foreach ((array) ($a['questions'] ?? []) as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                            @if ($draftStatus)<p class="text-xs text-gray-500 sm:col-span-2">Taslak: {{ $draftStatus }}</p>@endif
                            @if (! empty($a['article_blocked']))
                                <div class="sm:col-span-2" data-article-blocked>
                                    <p class="text-error-700 dark:text-error-400">{{ str_starts_with($a['article_blocked'], 'Kopya') ? '' : 'Uyum kuralına takıldı (AI bir kez düzeltmeyi denedi): ' }}{{ $a['article_blocked'] }}</p>
                                    <p class="mt-0.5 text-xs text-gray-500">Bu taslak WordPress'e gönderilemez. «Taslak hazırla» ile yeniden yazdırabilirsin; aşağıda yazılan metni görebilirsin.</p>
                                    @if (is_array($a['article_blocked_draft'] ?? null))
                                        @php preg_match_all('/«([^»]+)»/u', (string) $a['article_blocked'], $bad); $bad = array_unique($bad[1] ?? []); @endphp
                                        <p class="mt-2 font-medium text-gray-900 dark:text-white">{{ $a['article_blocked_draft']['title'] }}</p>
                                        <div class="mt-1 max-h-72 overflow-y-auto rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-800 dark:bg-gray-900" data-blocked-draft>
                                            @foreach (\App\Services\Site\SiteDiff::blocks((string) $a['article_blocked_draft']['html']) as $block)
                                                @php $text = e($block['text']); foreach ($bad as $phrase) { $text = preg_replace('/'.preg_quote(e($phrase), '/').'/iu', '<mark class="rounded bg-error-100 px-0.5 text-error-800">$0</mark>', $text); } @endphp
                                                <p @class(['my-1', 'font-semibold' => str_starts_with($block['tag'], 'h'), 'pl-3' => $block['tag'] === 'li'])>{{ $block['tag'] === 'li' ? '• ' : '' }}{!! $text !!}</p>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                            @if (! empty($a['article_warnings']))<p class="text-warning-700 sm:col-span-2 dark:text-warning-300" data-article-warnings>{{ $a['article_warnings'] }}</p>@endif
                            @if (is_array($a['article'] ?? null))
                                <div class="sm:col-span-2" data-article>
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $a['article']['title'] }}</p>
                                    <p class="text-xs text-gray-500">/{{ $a['article']['slug'] }}/</p>
                                    <p class="text-xs text-gray-500">{{ $a['article']['meta_description'] }}</p>
                                    <div class="mt-2 max-h-72 overflow-y-auto rounded-lg border border-gray-200 bg-white px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                                        @foreach (\App\Services\Site\SiteDiff::blocks((string) $a['article']['html']) as $block)
                                            <p @class(['my-1', 'font-semibold' => str_starts_with($block['tag'], 'h'), 'pl-3' => $block['tag'] === 'li'])>{{ $block['tag'] === 'li' ? '• ' : '' }}{{ $block['text'] }}</p>
                                        @endforeach
                                    </div>
                                    @if (! empty($a['article_note']))<p class="mt-1 text-xs text-gray-500">{{ $a['article_note'] }}</p>@endif
                                    @if (! isset($a['article_write_id']))
                                        <button type="button" wire:click="sendDraft({{ $item->id }})" wire:confirm="Makale WordPress’e taslak olarak gönderilsin mi?" class="btn-dark mt-3 w-full sm:w-auto">WordPress’e taslak gönder</button>
                                    @else
                                        <p class="mt-2 text-success-700 dark:text-success-400">WordPress taslağı gönderildi.</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                </li>
            @empty
                <li><x-ta.empty-state size="sm" icon="none" title="İçerik önerisi yok" message="«Haftalık içerik öner» ile bu haftanın yazılarını hazırlat." /></li>
            @endforelse
        </ul>
        @if ($items->hasPages())<div class="mt-3">{{ $items->links() }}</div>@endif
    </section>
</div>
