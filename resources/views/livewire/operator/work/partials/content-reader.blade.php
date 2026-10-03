{{-- The article reader: every language version, send as WordPress drafts, write another language. --}}
@php
    $btn = 'h-8 shrink-0 rounded-lg bg-brand-500 px-3 text-xs font-semibold text-white hover:bg-brand-600 disabled:opacity-50';
    $ghost = 'h-8 shrink-0 rounded-lg px-3 text-xs font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $langs = \App\Services\Work\ContentBoard::LANGUAGE_LABELS;
@endphp
@if ($article !== null)
    @php
        $versions = array_filter([($article['article']['language'] ?? 'kaynak') => $article['article'] ?? $article['blocked_draft']] + $article['translations']);
    @endphp
    <div class="fixed inset-0 z-[99999] flex items-start justify-center overflow-y-auto bg-gray-900/50 p-4 sm:p-8" wire:keydown.escape.window="closeReading" role="dialog" aria-modal="true" aria-label="Yazıyı oku" data-reader="{{ $article['id'] }}">
        <div class="w-full max-w-3xl rounded-2xl bg-white shadow-xl dark:bg-gray-900" x-data="{ lang: '{{ array_key_first($versions) }}' }">
            <header class="flex items-start gap-3 border-b border-gray-200 px-5 py-4 dark:border-gray-800">
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">Fikir · {{ $article['idea'] }}</p>
                    @if (count($versions) > 1)
                        <div class="mt-2 flex gap-1.5" role="tablist">
                            @foreach ($versions as $code => $version)
                                <button type="button" @click="lang = '{{ $code }}'" :class="lang === '{{ $code }}' ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'ring-1 ring-inset ring-gray-300 dark:ring-gray-700'" class="h-7 rounded-lg px-2.5 text-xs font-semibold">{{ $langs[$code] ?? strtoupper($code) }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <button type="button" wire:click="closeReading" aria-label="Kapat" class="h-8 w-8 rounded-lg text-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">×</button>
            </header>
            @if ($article['blocked'])<p class="mx-5 mt-4 rounded-lg bg-rose-50 p-2.5 text-xs text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">Uyum kuralına takıldı; gönderilemez. «Yeniden yaz» ile yeniden yazdırabilirsin: {{ $article['blocked'] }}</p>@endif
            @if ($article['warnings'])<p class="mx-5 mt-4 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">{{ $article['warnings'] }}</p>@endif
            @foreach ($versions as $code => $version)
                <div x-show="lang === '{{ $code }}'" @if (! $loop->first) x-cloak @endif class="px-5 py-4" data-reader-version="{{ $code }}">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $version['title'] }}</h2>
                    <p class="mt-1 text-xs text-gray-500">/{{ $version['slug'] ?? '' }}/ · {{ $version['meta_description'] ?? '' }}</p>
                    <div class="mt-4 space-y-2 text-sm leading-relaxed text-gray-800 dark:text-gray-200">
                        @foreach (\App\Services\Site\SiteDiff::blocks((string) $version['html']) as $block)
                            @if (str_starts_with($block['tag'], 'h'))
                                <h3 class="pt-2 text-base font-semibold text-gray-900 dark:text-white">{{ $block['text'] }}</h3>
                            @elseif ($block['tag'] === 'li')
                                <p class="pl-4">• {{ $block['text'] }}</p>
                            @else
                                <p>{{ $block['text'] }}</p>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
            @php
                $sentAll = $article['sent'] !== [] && array_diff(array_keys($versions), $article['sent']) === [];
            @endphp
            <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-5 py-3 dark:border-gray-800">
                @foreach ($article['translations_blocked'] as $code => $why)<span class="mr-auto text-xs text-rose-600">{{ strtoupper($code) }} çevirisi kurala takıldı.</span>@endforeach
                @if ($article['writing'])
                    <span class="text-xs text-brand-600">Çeviri yazılıyor…</span>
                @else
                    @foreach ($article['missing'] as $code)
                        <button type="button" wire:click="writeContent({{ $article['id'] }}, '{{ $code }}')" class="{{ $ghost }}" data-translate="{{ $code }}">+ {{ $langs[$code] ?? strtoupper($code) }} yaz</button>
                    @endforeach
                @endif
                <button type="button" wire:click="closeReading" class="{{ $ghost }}">Kapat</button>
                @if ($article['article'] !== null && ! $sentAll)
                    <button type="button" wire:click="sendContent({{ $article['id'] }})" wire:confirm="{{ implode(', ', array_map('strtoupper', array_keys($versions))) }} WordPress'e taslak olarak gönderilsin mi?" class="{{ $btn }}" data-send="{{ $article['id'] }}">WordPress'e taslak gönder{{ count($versions) > 1 ? ' ('.implode(' + ', array_map('strtoupper', array_keys($versions))).')' : '' }}</button>
                @elseif ($sentAll)
                    <span class="text-xs text-emerald-700 dark:text-emerald-400">WordPress'e taslak olarak gönderildi.</span>
                @endif
            </footer>
        </div>
    </div>
@endif
