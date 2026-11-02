{{--
    One account's open negative proposals as paste-ready lists (yakup 2026-11-02): one list per place in Google Ads with
    the match type in Google's own notation ([tam], "sıralı", geniş), a copy button, an Ads Editor paste for the
    campaign / ad group ones, and one "Ekledim" for the whole list. Rows can still be taken out one by one.
    Needs: $neg (GoogleAdsSuggestions::negativeLists), $canAct, $doneMethod, $dismissMethod, $cur; optional $sendShared.
--}}
@php
    $sendShared ??= false;
    $fmt = fn (float $v): string => number_format($v, 0, ',', '.');
    $matchName = ['EXACT' => 'tam', 'PHRASE' => 'sıralı', 'BROAD' => 'geniş'];
    $copy = 'navigator.clipboard.writeText($refs.src.value).then(() => { copied = true; setTimeout(() => copied = false, 1600) })';
@endphp
@if (($neg['lists'] ?? []) !== [])
    <div class="card mb-4" data-testid="ads-negative-list">
        <div class="flex flex-wrap items-start gap-x-3 gap-y-2 border-b border-gray-200 px-4 py-3.5 dark:border-gray-800">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Negatif anahtar kelime listesi <span class="font-normal text-gray-500">{{ $neg['count'] }} kelime{{ $neg['cost'] > 0 ? ', bu aramalara giden '.$fmt($neg['cost']).' '.$cur : '' }}</span></p>
                <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">Eşleme türü yazımın içinde: [tam], "sıralı", geniş. Listeyi kopyalayıp Google Ads'te gösterilen yere yapıştırın, sonra “Ekledim”e basın; gece kontrolü sonucu görür.</p>
            </div>
            <div class="flex w-full shrink-0 flex-wrap items-center gap-1.5 sm:w-auto">
                @if ($neg['editor'])
                    <span x-data="{ copied: false }" class="inline-flex">
                        <textarea x-ref="src" class="hidden" readonly>{{ $neg['editor'] }}</textarea>
                        <button type="button" x-on:click="{{ $copy }}" class="btn h-8! text-xs!" title="Google Ads Editor › Hesap › Birden çok değişiklik yap › yapıştır (kampanya ve reklam grubu negatifleri)"><span x-show="!copied">Editor için kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
                    </span>
                @endif
                @if ($canAct)
                    <button type="button" x-on:click="if (confirm('Listedeki {{ $neg['count'] }} negatifi Google Ads\'e eklediniz mi? Gece kontrolü sonucu görür.')) $wire.{{ $doneMethod }}(@js($neg['ids']))" class="btn-dark h-8! px-3! text-xs!">Hepsini ekledim</button>
                @endif
            </div>
        </div>

        @foreach ($neg['lists'] as $list)
            <div class="border-t border-gray-200 px-4 py-3.5 first:border-t-0 dark:border-gray-800" wire:key="neglist-{{ md5($list['key']) }}">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="min-w-0 flex-1 break-words text-sm font-medium text-gray-900 dark:text-white">{{ $list['label'] }} <span class="font-normal text-gray-500">{{ count($list['lines']) }} kelime{{ $list['cost'] > 0 ? ', '.$fmt($list['cost']).' '.$cur : '' }}</span></p>
                    <span x-data="{ copied: false }" class="inline-flex shrink-0 items-center gap-1">
                        <textarea x-ref="src" class="hidden" readonly>{{ implode("\n", $list['lines']) }}</textarea>
                        <button type="button" x-on:click="{{ $copy }}" class="btn h-7! px-2.5! text-xs!"><span x-show="!copied">Listeyi kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
                    </span>
                    @if ($sendShared && $list['key'] === 'shared')
                        <button type="button" x-on:click="if (confirm('Bu {{ count($list['lines']) }} negatif Google Ads paylaşılan listesine eklensin mi? Sonradan geri alınabilir.')) $wire.sendNegativeIds(@js($list['ids']))" class="btn-dark h-7! px-2.5! text-xs!">Google Ads'e gönder</button>
                    @endif
                </div>
                <p class="mt-0.5 text-xs text-gray-500">Nereye: {{ $list['where'] }}</p>
                <textarea readonly rows="{{ min(12, max(2, count($list['lines']))) }}" x-on:focus="$el.select()" aria-label="{{ $list['label'] }} negatif listesi"
                    class="mt-2 block w-full resize-y rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-[13px] leading-5 text-gray-900 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-100">{{ implode("\n", $list['lines']) }}</textarea>
                <details class="mt-1.5 text-xs text-gray-500">
                    <summary class="cursor-pointer hover:text-gray-900 dark:hover:text-white">Kelimeler ve kanıt</summary>
                    <ul class="mt-1 divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($list['rows'] as $row)
                            <li class="flex flex-wrap items-start gap-x-3 gap-y-0.5 py-1.5" wire:key="negrow-{{ $row['id'] }}">
                                <span class="min-w-0 flex-1">
                                    <span class="font-mono text-gray-900 dark:text-gray-100">{{ $row['line'] }}</span>
                                    <span class="ml-1">{{ $matchName[$row['match']] ?? $row['match'] }} eşleme{{ $row['cost'] > 0 ? ', '.$fmt($row['cost']).' '.$cur : '' }}</span>
                                    @if ($row['covers'] !== [])<span class="block">Kapsadığı aramalar: {{ implode(', ', array_slice($row['covers'], 0, 8)) }}</span>@endif
                                    @if ($row['blocks'] !== [])<span class="block text-warning-700 dark:text-warning-300">Engelleyebileceği faydalı aramalar: {{ implode(', ', array_slice($row['blocks'], 0, 5)) }}</span>@endif
                                </span>
                                @if ($canAct)
                                    <button type="button" wire:click="{{ $dismissMethod }}({{ $row['id'] }})" class="btn-quiet h-6! shrink-0 px-2! text-xs!" title="Bu kelimeyi listeden çıkar (reddet)">Çıkar</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            </div>
        @endforeach
    </div>
@endif
