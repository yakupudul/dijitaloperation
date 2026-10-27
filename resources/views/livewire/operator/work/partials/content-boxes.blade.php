{{-- A website's content ideas in four steps: one card per site, step tabs, one row per idea. On the website İçerik tab ($single: no site head). --}}
@php
    // Per-row actions stay neutral (outline / quiet); the screen's one main action is "Haftalık içerik öner".
    $btn = 'btn h-7! px-2.5! text-xs!';
    $ghost = 'btn-quiet h-7! px-2! text-xs!';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
    $chip = 'text-xs text-gray-500 dark:text-gray-400';
    $empty = ['yazilacak' => 'Yazılacak fikir yok.', 'okunacak' => 'Gönderime hazır yazı yok.', 'taslakta' => 'Sitede taslakta yazı yok.', 'yayinda' => 'Yayında yazı yok.'];
@endphp
<section class="min-w-0" data-content-boxes>
    @if ($boxes->isEmpty())
        <div class="card"><x-ta.empty-state size="sm" title="Bekleyen içerik fikri yok." /></div>
    @endif
    <div @class(['grid grid-cols-1 gap-6', 'md:grid-cols-2 xl:grid-cols-3' => ! ($single ?? false)])>
        @foreach ($boxes as $box)
            @php $site = $box['site']; @endphp
            <article wire:key="content-box-{{ $site->id }}" data-content-box="{{ $site->id }}"
                     x-data="{ step: '{{ $box['counts']['okunacak'] > 0 ? 'okunacak' : ($box['counts']['yazilacak'] > 0 ? 'yazilacak' : ($box['counts']['taslakta'] > 0 ? 'taslakta' : 'yayinda')) }}' }"
                     class="card flex min-w-0 flex-col">
                @unless ($single ?? false)
                    <div class="px-4 pt-3.5">
                        <span class="text-xs text-gray-500">{{ $box['brand'] ?? '—' }}</span>
                        <h3 class="section-title truncate" title="{{ $site->domain ?: $site->name }}">{{ $site->domain ?: $site->name }}</h3>
                    </div>
                @endunless

                <div class="flex min-w-0 items-end justify-between gap-3 border-b border-gray-200 px-2 dark:border-gray-800">
                    <div role="tablist" class="no-scrollbar flex min-w-0 overflow-x-auto">
                        @foreach (\App\Services\Work\ContentBoard::STEPS as $code => $label)
                            <button type="button" role="tab" @click="step = '{{ $code }}'" :aria-selected="(step === '{{ $code }}').toString()"
                                    :class="{ 'tab-active': step === '{{ $code }}' }" class="tab" data-step-tab="{{ $code }}">
                                <span>{{ $label }}</span>
                                <span @class(['text-xs tabular-nums', 'text-warning-700 dark:text-warning-300' => $code === 'okunacak' && $box['counts'][$code] > 0, 'text-success-700 dark:text-success-400' => $code === 'yayinda' && $box['counts'][$code] > 0, 'text-gray-400' => ! (($code === 'okunacak' || $code === 'yayinda') && $box['counts'][$code] > 0)])>{{ $box['counts'][$code] }}</span>
                            </button>
                        @endforeach
                    </div>
                    <div class="flex shrink-0 gap-x-2 pb-2.5 pr-2" data-site-languages>
                        @foreach ($box['languages'] as $code => $pages)
                            <span class="text-xs {{ $loop->first ? 'font-medium text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}" title="{{ ($langs[$code] ?? $code).($pages > 0 ? ', '.$pages.' sayfa' : '') }}">{{ strtoupper($code) }}</span>
                        @endforeach
                    </div>
                </div>

                <div class="max-h-[28rem] flex-1 overflow-y-auto">
                    @foreach (\App\Services\Work\ContentBoard::STEPS as $code => $label)
                        <ul x-show="step === '{{ $code }}'" @if ($code !== array_key_first(\App\Services\Work\ContentBoard::STEPS)) x-cloak @endif data-step-list="{{ $code }}">
                            @forelse ($box['steps'][$code] as $item)
                                <li class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="content-item-{{ $item['id'] }}" data-content-item="{{ $item['id'] }}">
                                    <div class="min-w-0 flex-[1_1_16rem]">
                                        <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <span class="min-w-0 break-words text-sm font-medium text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                            @if ($code === 'yazilacak' && $item['score'] > 0)<span class="text-xs tabular-nums {{ $item['score'] >= 70 ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}" title="Öncelik puanı: {{ $item['score_line'] }}" data-score="{{ $item['score'] }}">Puan {{ $item['score'] }}</span>@endif
                                            @if ($item['rank'] <= 1 && $code === 'yazilacak')<x-ta.pill tone="look">Acil</x-ta.pill>@endif
                                            @if ($item['kind'] === 'Güncelleme')<span class="{{ $chip }}">{{ $item['kind'] }}</span>@endif
                                            @if ($item['translations'] !== [] || count($box['languages']) > 1)<span class="{{ $chip }}">{{ strtoupper($item['language']) }}@foreach ($item['translations'] as $t) + {{ strtoupper($t) }}@endforeach</span>@endif
                                            @if ($item['blocked'])@if ($item['fixing'])<x-ta.pill tone="live" :title="$item['blocked']" data-check="fixing">Claude kurala uyduruyor</x-ta.pill>@else<x-ta.pill tone="look">Kurala takıldı</x-ta.pill>@endif @endif
                                            @if ($code === 'okunacak' && ! $item['blocked'] && ($item['sendable'] || $item['fixing']))@include('livewire.operator.work.partials.content-check', ['item' => $item])@endif
                                        </p>
                                        @include('livewire.operator.work.partials.content-target', ['item' => $item])
                                        @if ($item['blocked'] && ! $item['fixing'])<p class="mt-0.5 text-xs text-warning-700 dark:text-warning-400" data-blocked-reason>Claude düzeltemedi: {{ \App\Services\Work\ContentBoard::plainReason($item['blocked']) }}.</p>@endif
                                        @if ($item['line'])<p @class(['mt-0.5 text-xs', 'text-gray-500 dark:text-gray-400' => $item['writing'], 'text-error-700 dark:text-error-400' => ! $item['writing']])>{{ $item['writing'] ? ($code === 'yazilacak' ? 'Yazılıyor: ' : 'Çeviri yazılıyor: ') : 'Son deneme: ' }}{{ $item['line'] }}</p>@endif
                                        @if (! in_array($code, ['yazilacak', 'okunacak'], true))
                                            <div class="mt-1">@include('livewire.operator.work.partials.content-post', ['item' => $item, 'step' => $code])</div>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 flex-wrap items-center gap-1 text-xs">
                                        @if ($code === 'yazilacak')
                                            @if ($item['writing'])
                                                <span class="inline-flex items-center gap-1.5 text-gray-500 dark:text-gray-400"><span class="live-dot h-1.5 w-1.5 rounded-full" aria-hidden="true"></span>yazılıyor…</span>
                                            @else
                                                <button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $btn }}" data-write="{{ $item['id'] }}">{{ $item['approved'] ? 'Yeniden yaz' : 'Onayla ve yazdır' }}</button>
                                            @endif
                                        @elseif ($code === 'okunacak')
                                            @if ($item['translating'] !== [])
                                                <span class="text-gray-500 dark:text-gray-400" data-translating="{{ $item['id'] }}">{{ implode(', ', array_map('strtoupper', $item['translating'])) }} çevirisi hazırlanıyor…</span>
                                            @endif
                                            <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $ghost }}" data-read="{{ $item['id'] }}">Oku</button>
                                            @if ($item['sendable'] && ! $item['fixing'] && $item['translating'] === [])
                                                <button type="button" wire:click="sendContent({{ $item['id'] }})" wire:confirm="Yazı çevirileriyle WordPress'e taslak olarak gönderilsin mi? Geri alınabilir." class="{{ $btn }}" data-send="{{ $item['id'] }}">{{ in_array($item['check']['verdict'] ?? null, ['wrong', 'unsure'], true) ? 'Yine de gönder' : 'Siteye gönder' }}</button>
                                            @endif
                                        @else
                                            @if ($item['link'])<a href="{{ $item['link'] }}" target="_blank" rel="noopener" class="{{ $ghost }}">Sitede aç</a>@endif
                                            @if ($item['unsent'] !== [])
                                                <button type="button" wire:click="sendContent({{ $item['id'] }})" wire:confirm="Yeni dil WordPress'e taslak olarak gönderilsin mi?" class="{{ $btn }}">{{ implode(', ', array_map('strtoupper', $item['unsent'])) }} gönder</button>
                                            @elseif ($item['missing_languages'] !== [] && ! $item['writing'])
                                                <button type="button" wire:click="writeContent({{ $item['id'] }}, '{{ $item['missing_languages'][0] }}')" class="{{ $ghost }}">+ {{ $langs[$item['missing_languages'][0]] ?? strtoupper($item['missing_languages'][0]) }}</button>
                                            @endif
                                            <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $ghost }}">Oku</button>
                                        @endif
                                    </div>
                                </li>
                            @empty
                                <li class="px-4 py-6 text-[13px] text-gray-500 dark:text-gray-400">{{ $empty[$code] ?? '' }}</li>
                            @endforelse
                            @if ($box['counts'][$code] > count($box['steps'][$code]))
                                <li class="border-t border-gray-200 px-4 py-2.5 text-xs text-gray-500 dark:border-gray-800">+{{ $box['counts'][$code] - count($box['steps'][$code]) }} daha, aşağıdaki listede</li>
                            @endif
                        </ul>
                    @endforeach
                </div>

                @unless ($single ?? false)
                    <footer class="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-2.5 text-xs dark:border-gray-800">
                        <span class="tabular-nums text-gray-500">{{ array_sum($box['counts']) }} fikir</span>
                        <a href="{{ $box['url'] }}" wire:navigate class="link-neutral">İçerik sekmesi</a>
                    </footer>
                @endunless
            </article>
        @endforeach
    </div>
</section>


@include('livewire.operator.work.partials.content-reader')
