{{-- A website's content ideas in four steps (sade panel 2026-10-20: no box, underline step tabs); on the website İçerik tab. --}}
@php
    // Per-row actions stay neutral (outline / ghost); the screen's one main action is "Haftalık içerik öner".
    $btn = 'h-7 shrink-0 rounded-lg bg-white px-2.5 text-xs font-medium text-gray-800 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 disabled:opacity-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700';
    $ghost = 'h-7 shrink-0 rounded-lg px-2.5 text-xs font-medium text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/5';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
    $chip = 'text-xs text-gray-500 dark:text-gray-400';
@endphp
<section class="space-y-6" data-content-boxes>
    @if ($boxes->isEmpty())
        <x-ta.empty-state size="sm" title="Bekleyen içerik fikri yok." />
    @endif
    <div @class(['grid grid-cols-1 gap-x-10 gap-y-10', 'md:grid-cols-2 xl:grid-cols-3' => ! ($single ?? false)])>
        @foreach ($boxes as $box)
            @php $site = $box['site']; @endphp
            <article wire:key="content-box-{{ $site->id }}" data-content-box="{{ $site->id }}"
                     x-data="{ step: '{{ $box['counts']['okunacak'] > 0 ? 'okunacak' : ($box['counts']['yazilacak'] > 0 ? 'yazilacak' : ($box['counts']['taslakta'] > 0 ? 'taslakta' : 'yayinda')) }}' }"
                     class="flex min-w-0 flex-col">
                <div class="flex items-start gap-3 pb-2">
                    <div class="min-w-0 flex-1">
                        <span class="text-xs text-gray-500">{{ $box['brand'] ?? '—' }}</span>
                        <h3 class="mt-0.5 truncate text-base font-semibold leading-snug text-gray-900 dark:text-white" title="{{ $site->domain ?: $site->name }}">{{ $site->domain ?: $site->name }}</h3>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-x-2" data-site-languages>
                        @foreach ($box['languages'] as $code => $pages)
                            <span class="text-xs {{ $loop->first ? 'font-medium text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}" title="{{ ($langs[$code] ?? $code).($pages > 0 ? ', '.$pages.' sayfa' : '') }}">{{ strtoupper($code) }}</span>
                        @endforeach
                    </div>
                </div>

                <div role="tablist" class="flex flex-wrap gap-x-5 border-b border-gray-200 dark:border-gray-800">
                    @foreach (\App\Services\Work\ContentBoard::STEPS as $code => $label)
                        <button type="button" role="tab" @click="step = '{{ $code }}'" :aria-selected="(step === '{{ $code }}').toString()"
                                :class="step === '{{ $code }}' ? 'border-gray-900 text-gray-900 dark:border-white dark:text-white' : 'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400'"
                                class="-mb-px flex h-9 items-baseline gap-1.5 whitespace-nowrap border-b-2 pt-2 text-left text-sm" data-step-tab="{{ $code }}">
                            <span>{{ $label }}</span>
                            <span @class(['text-xs tabular-nums', 'text-warning-700 dark:text-warning-300' => $code === 'okunacak' && $box['counts'][$code] > 0, 'text-success-700 dark:text-success-400' => $code === 'yayinda' && $box['counts'][$code] > 0])>{{ $box['counts'][$code] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="max-h-96 flex-1 overflow-y-auto">
                    @foreach (\App\Services\Work\ContentBoard::STEPS as $code => $label)
                        <ul x-show="step === '{{ $code }}'" @if ($code !== array_key_first(\App\Services\Work\ContentBoard::STEPS)) x-cloak @endif class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-step-list="{{ $code }}">
                            @forelse ($box['steps'][$code] as $item)
                                <li class="flex flex-col gap-1.5 py-2.5 sm:flex-row sm:items-center sm:gap-3" wire:key="content-item-{{ $item['id'] }}" data-content-item="{{ $item['id'] }}">
                                    <div class="min-w-0 flex-1">
                                        <p class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5">
                                            <span class="min-w-0 break-words font-medium leading-snug text-gray-900 dark:text-white">{{ $item['title'] }}</span>
                                            @if ($code === 'yazilacak' && $item['score'] > 0)<span class="text-xs tabular-nums {{ $item['score'] >= 70 ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400' }}" title="Öncelik puanı: {{ $item['score_line'] }}" data-score="{{ $item['score'] }}">Puan {{ $item['score'] }}</span>@endif
                                            @if ($item['rank'] <= 1 && $code === 'yazilacak')<x-ta.pill tone="look">Acil</x-ta.pill>@endif
                                            @if ($item['kind'] === 'Güncelleme')<span class="{{ $chip }}">{{ $item['kind'] }}</span>@endif
                                            @if ($item['translations'] !== [] || count($box['languages']) > 1)<span class="{{ $chip }}">{{ strtoupper($item['language']) }}@foreach ($item['translations'] as $t) + {{ strtoupper($t) }}@endforeach</span>@endif
                                            @if ($item['blocked'])<x-ta.pill tone="look">Kurala takıldı</x-ta.pill>@endif
                                        </p>
                                        @include('livewire.operator.work.partials.content-target', ['item' => $item])
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
                                            @if ($item['blocked'])
                                                <button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $ghost }}" data-rewrite="{{ $item['id'] }}">Yeniden yaz</button>
                                            @endif
                                            <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $btn }}" data-read="{{ $item['id'] }}">Oku</button>
                                        @else
                                            @if ($item['link'])<a href="{{ $item['link'] }}" target="_blank" rel="noopener" class="link-neutral px-1">Sitede aç</a>@endif
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
                                <li class="py-4 text-xs text-gray-500">{{ ['yazilacak' => 'Yazılacak fikir yok.', 'okunacak' => 'Okunacak yazı yok.', 'taslakta' => 'Sitede taslakta yazı yok.', 'yayinda' => 'Yayında yazı yok.'][$code] ?? '' }}</li>
                            @endforelse
                            @if ($box['counts'][$code] > count($box['steps'][$code]))
                                <li class="py-2 text-xs text-gray-500">+{{ $box['counts'][$code] - count($box['steps'][$code]) }} daha, aşağıdaki listede</li>
                            @endif
                        </ul>
                    @endforeach
                </div>

                <footer class="flex items-center justify-between gap-2 pt-2.5 text-xs">
                    <span class="tabular-nums text-gray-500">{{ array_sum($box['counts']) }} fikir</span>
                    @unless ($single ?? false)<a href="{{ $box['url'] }}" wire:navigate class="link-neutral">İçerik sekmesi</a>@endunless
                </footer>
            </article>
        @endforeach
    </div>
</section>


@include('livewire.operator.work.partials.content-reader')
