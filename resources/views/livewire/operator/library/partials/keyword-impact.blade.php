{{-- Keyword impact preview (KeywordInsights::impact): what the typed keyword would do before it is saved. --}}
@php
    $fmt = fn ($value) => number_format((float) $value, 0, ',', '.');
    $outcomes = ['comes' => 'bu hizmete gelir', 'stays' => 'zaten bu hizmette', 'kept' => 'değişmez (elle / AI / kilitli)', 'conflict' => 'çakışma · atanmaz', 'elsewhere' => 'daha uzun kelimeyle başka hizmette kalır'];
    $ghostButton = 'rounded-lg px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
@endphp
<div class="mt-2 w-full space-y-1 rounded-lg bg-gray-50 p-2 text-xs dark:bg-gray-800/50" data-keyword-impact>
    @if ($impact['error'])
        <p class="text-rose-600" data-impact-error>{{ $impact['error'] }}</p>
    @else
        <p class="font-medium text-gray-800 dark:text-gray-100" data-impact-summary>{{ sprintf("Bu kelime %s sorgu yakalayacak · %s'si şu an bu hizmette · %s'si başka hizmetten gelecek%s · %s'si atanmamış",
            $fmt($impact['total']), $fmt($impact['here']), $fmt($impact['fromTotal']),
            $impact['from'] !== [] ? ' ('.collect($impact['from'])->map(fn ($count, $name) => $name.': '.$fmt($count))->implode(', ').')' : '', $fmt($impact['unassigned'])) }}</p>
        @if ($impact['kept'] > 0)<p class="text-gray-600 dark:text-gray-400" data-impact-kept>{{ $fmt($impact['kept']) }} sorgu elle / AI ile atanmış veya kilitli · bu kelime onları değiştirmez.</p>@endif
        @if ($impact['elsewhere'] > 0)<p class="text-gray-600 dark:text-gray-400" data-impact-elsewhere>{{ $fmt($impact['elsewhere']) }} sorgu daha uzun bir kelimeyle başka hizmette kalır.</p>@endif
        @if ($impact['conflict'] > 0)<p class="text-amber-700 dark:text-amber-300" data-impact-conflict>{{ $fmt($impact['conflict']) }} sorgu çakışmaya düşer (iki hizmetin kelimesi, biri diğerini içermiyor) · atanmaz, Çakışmalar'da listelenir.</p>@endif
        @if ($impact['warning'])<p class="text-amber-700 dark:text-amber-300">{{ $impact['warning'] }}</p>@endif
        @if ($impact['examples'] !== [])
            <ul class="divide-y divide-gray-100 dark:divide-gray-800" data-impact-examples>
                @foreach ($impact['examples'] as $example)
                    <li class="flex flex-wrap items-center gap-2 py-0.5"><span class="flex-1 font-medium">{{ $example['text'] }}</span><span class="tabular-nums text-gray-500">{{ $fmt($example['impressions']) }}</span><span class="text-gray-500">{{ $example['current'] }} → {{ $outcomes[$example['outcome']] ?? $example['outcome'] }}</span></li>
                @endforeach
            </ul>
            @if ($impact['pages'] > 1)
                <div class="flex items-center gap-2">
                    <button type="button" wire:click="impactPage({{ $impact['page'] - 1 }})" @disabled($impact['page'] === 0) class="{{ $ghostButton }}">‹</button>
                    <span class="text-gray-500">{{ $impact['page'] + 1 }} / {{ $impact['pages'] }}</span>
                    <button type="button" wire:click="impactPage({{ $impact['page'] + 1 }})" @disabled($impact['page'] + 1 >= $impact['pages']) class="{{ $ghostButton }}">›</button>
                </div>
            @endif
        @endif
        @if ($rescanNote ?? true)<p class="text-gray-500">Kaydedince tarama başlar; değişiklikler Silinecekler › Hizmet değişikliği'nde onaya düşer.</p>@endif
    @endif
</div>
