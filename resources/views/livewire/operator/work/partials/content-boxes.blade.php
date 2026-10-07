{{-- A website's content ideas as one box (the cluster card style) in three steps; on the website İçerik tab. --}}
@php
    $btn = 'h-8 shrink-0 rounded-lg bg-brand-500 px-3 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'h-8 shrink-0 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
    $chip = 'rounded px-1.5 py-0.5 text-[10px] font-semibold';
@endphp
<section class="space-y-3" data-content-boxes>
    @if ($boxes->isEmpty())
        <p class="rounded-xl bg-white p-6 text-center text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">Bekleyen içerik fikri yok.</p>
    @endif
    <div @class(['grid grid-cols-1 gap-5', 'md:grid-cols-2 xl:grid-cols-3' => ! ($single ?? false)])>
        @foreach ($boxes as $box)
            @php $site = $box['site']; @endphp
            <article wire:key="content-box-{{ $site->id }}" data-content-box="{{ $site->id }}"
                     x-data="{ step: '{{ $box['counts']['okunacak'] > 0 ? 'okunacak' : ($box['counts']['yazilacak'] > 0 ? 'yazilacak' : 'gonderildi') }}' }"
                     class="flex min-h-[24rem] flex-col overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
                <div class="flex items-start gap-3 px-4 pb-3 pt-4">
                    <div class="min-w-0 flex-1">
                        <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $box['brand'] ?? '—' }}</span>
                        <h3 class="mt-0.5 truncate text-[15px] font-semibold leading-snug text-gray-900 dark:text-white" title="{{ $site->domain ?: $site->name }}">{{ $site->domain ?: $site->name }}</h3>
                    </div>
                    <div class="flex shrink-0 flex-wrap justify-end gap-1" data-site-languages>
                        @foreach ($box['languages'] as $code => $pages)
                            <span class="{{ $chip }} {{ $loop->first ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' }}" title="{{ ($langs[$code] ?? $code).($pages > 0 ? ' · '.$pages.' sayfa' : '') }}">{{ strtoupper($code) }}</span>
                        @endforeach
                    </div>
                </div>

                <div role="tablist" class="grid grid-cols-3 gap-2 px-4 pb-3">
                    @foreach (\App\Services\Work\ContentBoard::STEPS as $code => $label)
                        <button type="button" role="tab" @click="step = '{{ $code }}'" :aria-selected="(step === '{{ $code }}').toString()"
                                :class="step === '{{ $code }}' ? 'ring-gray-900 dark:ring-white' : 'ring-transparent'"
                                class="rounded-lg bg-gray-50 px-2.5 py-2 text-left ring-2 ring-inset dark:bg-white/[0.03]" data-step-tab="{{ $code }}">
                            <span class="block text-[11px] text-gray-500">{{ $label }}</span>
                            <span @class(['block text-sm font-semibold tabular-nums', 'text-amber-700 dark:text-amber-300' => $code === 'okunacak' && $box['counts'][$code] > 0, 'text-emerald-700 dark:text-emerald-400' => $code === 'gonderildi' && $box['counts'][$code] > 0])>{{ $box['counts'][$code] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="max-h-96 flex-1 overflow-y-auto border-t border-gray-200 dark:border-gray-800">
                    @foreach (\App\Services\Work\ContentBoard::STEPS as $code => $label)
                        <ul x-show="step === '{{ $code }}'" @if ($code !== array_key_first(\App\Services\Work\ContentBoard::STEPS)) x-cloak @endif class="divide-y divide-gray-100 dark:divide-gray-800" data-step-list="{{ $code }}">
                            @forelse ($box['steps'][$code] as $item)
                                <li class="px-4 py-3" wire:key="content-item-{{ $item['id'] }}" data-content-item="{{ $item['id'] }}">
                                    <div class="mb-1 flex flex-wrap items-center gap-1.5">
                                        @if ($code === 'yazilacak' && $item['score'] > 0)<span class="{{ $chip }} tabular-nums {{ $item['score'] >= 70 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : ($item['score'] >= 45 ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300') }}" title="Öncelik puanı: {{ $item['score_line'] }}" data-score="{{ $item['score'] }}">Puan {{ $item['score'] }}</span>@endif
                                @if ($item['rank'] <= 1 && $code === 'yazilacak')<span class="{{ $chip }} bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Acil</span>@endif
                                        <span class="{{ $chip }} {{ $item['kind'] === 'Güncelleme' ? 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300' }}">{{ $item['kind'] }}</span>
                                        <span class="{{ $chip }} bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300">{{ strtoupper($item['language']) }}@foreach ($item['translations'] as $t) + {{ strtoupper($t) }}@endforeach</span>
                                        @if ($item['blocked'])<span class="{{ $chip }} bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">Kurala takıldı</span>@endif
                                    </div>
                                    <p class="font-medium leading-snug text-gray-900 dark:text-white">{{ $item['title'] }}</p>
                                    @if ($item['line'])<p @class(['mt-1 text-xs', 'text-brand-600' => $item['writing'], 'text-rose-600' => ! $item['writing']])>{{ $item['writing'] ? ($code === 'yazilacak' ? 'Yazılıyor · ' : 'Çeviri yazılıyor · ') : 'Son deneme: ' }}{{ $item['line'] }}</p>@endif
                                    <div class="mt-2 flex flex-wrap items-center justify-end gap-2 text-xs">
                                        @if ($code === 'yazilacak')
                                            @if ($item['writing'])
                                                <span class="text-brand-600">yazılıyor…</span>
                                            @else
                                                <button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $btn }}" data-write="{{ $item['id'] }}">{{ $item['approved'] ? 'Yeniden yaz' : 'Onayla ve yazdır' }}</button>
                                            @endif
                                        @elseif ($code === 'okunacak')
                                            @if ($item['translating'] !== [])
                                                <span class="mr-auto text-brand-600" data-translating="{{ $item['id'] }}">{{ implode(', ', array_map('strtoupper', $item['translating'])) }} çevirisi hazırlanıyor…</span>
                                            @endif
                                            @if ($item['blocked'])
                                                <button type="button" wire:click="writeContent({{ $item['id'] }})" class="{{ $ghost }}" data-rewrite="{{ $item['id'] }}">Yeniden yaz</button>
                                            @endif
                                            <button type="button" wire:click="read({{ $item['id'] }})" class="{{ $btn }}" data-read="{{ $item['id'] }}">Oku</button>
                                        @else
                                            <span class="mr-auto text-emerald-700 dark:text-emerald-400">Taslak gönderildi{{ $item['sent_at'] ? ' · '.$item['sent_at']->timezone('Europe/Istanbul')->format('d.m') : '' }}</span>
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
                                <li class="px-4 py-6 text-center text-xs text-gray-500">{{ $code === 'yazilacak' ? 'Yazılacak fikir yok.' : ($code === 'okunacak' ? 'Okunacak yazı yok.' : 'Son 30 günde gönderilen yok.') }}</li>
                            @endforelse
                            @if ($box['counts'][$code] > count($box['steps'][$code]))
                                <li class="px-4 py-2 text-center text-xs text-gray-500">+{{ $box['counts'][$code] - count($box['steps'][$code]) }} daha · aşağıdaki listede</li>
                            @endif
                        </ul>
                    @endforeach
                </div>

                <footer class="flex items-center justify-between gap-2 border-t border-gray-200 px-4 py-2.5 text-xs dark:border-gray-800">
                    <span class="text-gray-500">{{ array_sum($box['counts']) }} fikir</span>
                    @unless ($single ?? false)<a href="{{ $box['url'] }}" wire:navigate class="font-medium text-brand-600 hover:underline">İçerik sekmesi →</a>@endunless
                </footer>
            </article>
        @endforeach
    </div>
</section>


@include('livewire.operator.work.partials.content-reader')
