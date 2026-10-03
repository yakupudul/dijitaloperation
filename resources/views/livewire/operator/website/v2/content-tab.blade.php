@php
    $card = 'rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white';
    $btn = 'rounded-lg bg-brand-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $chip = 'rounded-full px-2 py-0.5 text-xs';
@endphp
<div class="space-y-4" data-content @if (in_array('çalışıyor…', $statuses, true) || $draftStatus === 'çalışıyor…') wire:poll.5s @endif>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between rounded-lg bg-blue-50 p-2 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
            <span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="px-2">×</button>
        </p>
    @endif
    @if ($errors->any())<p class="text-xs text-rose-600">{{ $errors->first() }}</p>@endif

    @include('livewire.operator.work.partials.content-boxes', ['single' => true])

    <section class="{{ $card }} flex flex-wrap items-center gap-2">
        <button type="button" wire:click="run('weekly_content')" class="{{ $btn }}">Haftalık içerik öner</button>
        <x-operator.ai-prompt-info operation="site.weekly_content" />
        <span class="text-xs text-gray-500">kapasite {{ $capacity }} / hafta</span>
        <button type="button" wire:click="run('content_discovery')" class="{{ $ghost }}">Kümeler dışında fırsat keşfet</button>
        <x-operator.ai-prompt-info operation="site.content_discovery" />
        @foreach ($statuses as $op => $line)<span class="text-xs text-gray-500">{{ \App\Services\Site\SiteOperations::LABELS[$op] }}: {{ $line }}</span>@endforeach
        <select wire:model.live="status" aria-label="Durum" class="{{ $input }} ml-auto">
            <option value="">Tümü</option>
            @foreach (\App\Livewire\Operator\Website\V2\SuggestionsTab::STATUS_LABELS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
    </section>

    <section class="{{ $card }}" data-section="content">
        <ul class="divide-y divide-gray-100 text-xs dark:divide-gray-800">
            @forelse ($items as $item)
                @php $a = (array) $item->action; @endphp
                <li class="py-2" wire:key="ct-{{ $item->id }}" data-content-item="{{ $item->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium">{{ $item->title }}
                                <span class="{{ $chip }} ml-1 bg-gray-100 text-gray-600 dark:bg-gray-800">{{ ($a['kind'] ?? 'new') === 'update' ? 'güncelle' : 'yeni' }} · {{ $a['page_type'] ?? '—' }}</span>
                                @if ($a['out_of_cluster'] ?? false)<span class="{{ $chip }} ml-1 bg-purple-50 text-purple-700">küme dışı</span>@endif
                                @if (isset($a['article_write_id']))<span class="{{ $chip }} ml-1 bg-emerald-50 text-emerald-700" data-draft-state="sent">WordPress'e taslak gönderildi</span>
                                @elseif (is_array($a['article'] ?? null))<span class="{{ $chip }} ml-1 bg-blue-50 text-blue-700" data-draft-state="ready">Taslak hazır · okumak için «Aç»</span>
                                @elseif (! empty($a['article_blocked']))<span class="{{ $chip }} ml-1 bg-rose-50 text-rose-700" data-draft-state="blocked">Uyum kuralına takıldı</span>@endif
                            </p>
                            <p class="text-gray-500">{{ $item->cluster_id !== null ? ($clusterNames[$item->cluster_id] ?? '—').' · ' : '' }}{{ $a['target_url'] ?? '—' }}@if ($item->reason) · {{ $item->reason }}@endif</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                            @if (($a['out_of_cluster'] ?? false) && ! isset($a['library_cluster_id']) && isset($a['service_id']))
                                <button type="button" wire:click="addToLibrary({{ $item->id }})" class="{{ $ghost }}">Kütüphaneye ekle</button>
                            @endif
                            @if (in_array($item->status, ['open', 'recheck'], true))
                                <button type="button" wire:click="prepareDraft({{ $item->id }})" class="{{ $btn }}" title="AI; başlıklar, sorular, küme sorguları, SEO analizi reçetesi, marka dosyası ve sektör kurallarıyla makaleyi yazar.">{{ is_array($a['article'] ?? null) || ! empty($a['article_blocked']) ? 'Yeniden yaz' : 'Taslak hazırla' }}</button>
                                <x-operator.ai-prompt-info operation="site.write_article" />
                                <input type="text" wire:model="reasons.{{ $item->id }}" placeholder="Neden" aria-label="Reddetme nedeni" class="{{ $input }} w-28">
                                <button type="button" wire:click="dismiss({{ $item->id }})" class="{{ $ghost }}">Reddet</button>
                            @endif
                            <button type="button" wire:click="open({{ $item->id }})" class="{{ $ghost }}">{{ $opened?->id === $item->id ? 'Kapat' : 'Aç' }}</button>
                        </div>
                    </div>
                    @if ($opened?->id === $item->id)
                        <div class="mt-2 grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 dark:bg-gray-950" data-detail>
                            <div><p class="font-semibold text-gray-500">Taslak başlıklar</p><ul class="list-disc pl-4">@foreach ((array) ($a['outline'] ?? []) as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                            <div><p class="font-semibold text-gray-500">AI asistanlarına sorulanlar</p><ul class="list-disc pl-4">@foreach ((array) ($a['questions'] ?? []) as $line)<li>{{ $line }}</li>@endforeach</ul></div>
                            @if ($draftStatus)<p class="text-gray-500 sm:col-span-2">Taslak: {{ $draftStatus }}</p>@endif
                            @if (! empty($a['article_blocked']))
                                <div class="sm:col-span-2" data-article-blocked>
                                    <p class="text-rose-600">{{ str_starts_with($a['article_blocked'], 'Kopya') ? '' : 'Uyum kuralına takıldı (AI bir kez düzeltmeyi denedi): ' }}{{ $a['article_blocked'] }}</p>
                                    <p class="mt-0.5 text-gray-500">Bu taslak WordPress'e gönderilemez. «Taslak hazırla» ile yeniden yazdırabilirsin; aşağıda yazılan metni görebilirsin.</p>
                                    @if (is_array($a['article_blocked_draft'] ?? null))
                                        @php preg_match_all('/«([^»]+)»/u', (string) $a['article_blocked'], $bad); $bad = array_unique($bad[1] ?? []); @endphp
                                        <p class="mt-1 font-semibold">{{ $a['article_blocked_draft']['title'] }}</p>
                                        <div class="mt-1 max-h-72 overflow-y-auto rounded bg-white p-2 dark:bg-gray-900" data-blocked-draft>
                                            @foreach (\App\Services\Site\SiteDiff::blocks((string) $a['article_blocked_draft']['html']) as $block)
                                                @php $text = e($block['text']); foreach ($bad as $phrase) { $text = preg_replace('/'.preg_quote(e($phrase), '/').'/iu', '<mark class="rounded bg-rose-100 px-0.5 text-rose-800">$0</mark>', $text); } @endphp
                                                <p @class(['my-1', 'font-semibold' => str_starts_with($block['tag'], 'h'), 'pl-3' => $block['tag'] === 'li'])>{{ $block['tag'] === 'li' ? '• ' : '' }}{!! $text !!}</p>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                            @if (! empty($a['article_warnings']))<p class="text-amber-700 sm:col-span-2" data-article-warnings>{{ $a['article_warnings'] }}</p>@endif
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
                <li class="py-3 text-gray-500">İçerik önerisi yok · "Haftalık içerik öner".</li>
            @endforelse
        </ul>
        <div class="mt-2">{{ $items->links() }}</div>
    </section>
</div>
