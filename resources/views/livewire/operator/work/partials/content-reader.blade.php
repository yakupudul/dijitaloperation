{{-- The article reader: every language version, send as WordPress drafts, write another language. --}}
@php
    $btn = 'btn-primary';
    $ghost = 'btn';
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
                    <p class="text-xs text-gray-500">Fikir: {{ $article['idea'] }}</p>
                    @include('livewire.operator.work.partials.content-target', ['item' => $article])
                    @if (count($versions) > 1)
                        <div class="mt-2 flex gap-1.5" role="tablist">
                            @foreach ($versions as $code => $version)
                                <button type="button" @click="lang = '{{ $code }}'" :class="lang === '{{ $code }}' ? 'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5'" class="h-7 rounded-md px-2.5 text-xs">{{ $langs[$code] ?? strtoupper($code) }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <button type="button" wire:click="closeReading" aria-label="Kapat" class="h-8 w-8 rounded-lg text-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800">×</button>
            </header>
            @if ($article['blocked'])<p class="tone-critical mx-5 mt-4 rounded-lg p-2.5 text-xs">@if ($article['fixing'] ?? false)Uyum kuralına takıldı; Claude kurala uyduruyor, bitince kendiliğinden gönderime hazır olur: @else Uyum kuralına takıldı; Claude düzeltemedi, gönderilemez: @endif{{ $article['blocked'] }}</p>@endif
            @if ($article['warnings'])<p class="tone-look mx-5 mt-4 rounded-lg p-2.5 text-xs">{{ $article['warnings'] }}</p>@endif
            @if (! empty($article['seo']))
                <div class="tone-look mx-5 mt-4 rounded-lg p-2.5 text-xs" data-article-seo>
                    <p class="font-medium">SEO kontrolü</p>
                    <ul class="mt-1 list-disc pl-4">@foreach ($article['seo'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
                </div>
            @elseif (($article['seo'] ?? null) === [] && $article['article'] !== null)
                <p class="mx-5 mt-4 text-xs text-success-700 dark:text-success-400" data-article-seo-ok>SEO kontrolü: ana sorgu, iç bağlantı ve bölge tamam.</p>
            @endif
            @if (! empty($article['current']))
                @php
                    $cur = $article['current'];
                    $new = $article['article'] ?? $article['blocked_draft'] ?? [];
                    $row = 'grid gap-1 sm:grid-cols-[9rem_1fr]';
                @endphp
                <section class="mx-5 mt-4 border-b border-gray-200 dark:border-gray-800" data-current-page="{{ $article['id'] }}">
                    <div class="space-y-2 py-3 text-xs">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white" title="Canlı sayfanın yerine bu yeni sürüm geçer, adres aynı kalır.">Ne değişecek</p>
                        <div class="{{ $row }}"><span class="text-gray-500">Sayfa</span><a href="{{ $article['target_url'] }}" target="_blank" rel="noopener" class="truncate font-medium text-gray-700 hover:underline dark:text-gray-300">{{ $article['target_url'] }}</a></div>
                        <div class="{{ $row }}"><span class="text-gray-500">Başlık</span><span><span class="text-gray-500 line-through decoration-error-400">{{ $cur['h1'] ?: $cur['title'] }}</span> → <span class="font-medium text-gray-900 dark:text-white">{{ $new['title'] ?? '' }}</span></span></div>
                        <div class="{{ $row }}"><span class="text-gray-500">SEO açıklaması</span><span><span class="text-gray-500 line-through decoration-error-400">{{ $cur['meta_description'] ?: '(yok)' }}</span> → <span class="text-gray-900 dark:text-white">{{ $new['meta_description'] ?? '' }}</span></span></div>
                        <div class="{{ $row }}"><span class="text-gray-500">Uzunluk</span><span class="tabular-nums">{{ number_format($cur['words'], 0, ',', '.') }} → {{ number_format($cur['new_words'], 0, ',', '.') }} kelime</span></div>
                        @if ($cur['added'] !== [])<div class="{{ $row }}"><span class="text-gray-500">Eklenen bölümler</span><ul class="text-success-700 dark:text-success-400">@foreach ($cur['added'] as $h)<li>+ {{ $h }}</li>@endforeach</ul></div>@endif
                        @if ($cur['dropped'] !== [])<div class="{{ $row }}"><span class="text-gray-500">Çıkan bölümler</span><ul class="text-error-700 dark:text-error-400">@foreach ($cur['dropped'] as $h)<li>− {{ $h }}</li>@endforeach</ul></div>@endif
                    </div>
                    <details class="border-t border-gray-200 dark:border-gray-800" data-current-text>
                        <summary class="cursor-pointer py-2 text-xs font-medium text-gray-700 dark:text-gray-300">Şimdiki sayfanın metni{{ $cur['collected'] ? ', son okuma '.$cur['collected']->format('d.m.Y') : '' }}</summary>
                        <div class="max-h-96 space-y-2 overflow-y-auto pb-3 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                            <p class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $cur['h1'] ?: $cur['title'] }}</p>
                            @forelse ($cur['blocks'] as $block)
                                @if (str_starts_with($block['tag'], 'h'))<h3 class="pt-2 font-semibold text-gray-800 dark:text-white/90">{{ $block['text'] }}</h3>
                                @elseif ($block['tag'] === 'li')<p class="pl-4">• {{ $block['text'] }}</p>
                                @else<p>{{ $block['text'] }}</p>@endif
                            @empty
                                <p class="text-gray-500">Sayfanın metni henüz okunmadı.</p>
                            @endforelse
                        </div>
                    </details>
                </section>
                <p class="mx-5 mt-4 text-sm font-semibold text-gray-900 dark:text-white">Yeni sürüm</p>
            @endif
            @foreach ($versions as $code => $version)
                <div x-show="lang === '{{ $code }}'" @if (! $loop->first) x-cloak @endif class="px-5 py-4" data-reader-version="{{ $code }}">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $version['title'] }}</h2>
                    <p class="mt-1 text-xs text-gray-500"><span>/{{ $version['slug'] ?? '' }}/</span> <span class="ml-2">{{ $version['meta_description'] ?? '' }}</span></p>
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
            @if ($article['article'] !== null && empty($article['current']) && ($article['images'] !== [] || $article['image'] !== null))
                <section class="mx-5 mb-4 border-t border-gray-200 pt-3 dark:border-gray-800" data-article-image="{{ $article['id'] }}">
                    <p class="text-xs font-medium text-gray-700 dark:text-gray-300" title="Sitenin kendi medya kütüphanesinden, öne çıkan görsel olarak gider.">Yazının görseli</p>
                    @if ($article['image'] !== null)
                        <div class="mt-2 flex items-start gap-3">
                            <img src="{{ $article['image']['url'] }}" alt="{{ $article['image']['alt'] }}" loading="lazy" class="h-24 w-36 shrink-0 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-gray-700">
                            <div class="min-w-0 text-xs text-gray-600 dark:text-gray-300">
                                <p>{{ $article['image']['why'] ?? '' }}</p>
                                @if (filled($article['image']['alt'] ?? null))<p class="mt-0.5 text-gray-500">Alt metin: {{ $article['image']['alt'] }}</p>@endif
                                @if ($article['sent'] === [])<button type="button" wire:click="pickImage({{ $article['id'] }})" class="mt-1 font-medium text-gray-700 hover:underline dark:text-gray-300" data-no-image>Görselsiz gönder</button>@endif
                            </div>
                        </div>
                    @else
                        <p class="mt-1 text-xs text-gray-500" data-image-none>Görselsiz gidecek.</p>
                    @endif
                    @if ($article['sent'] === [] && count($article['images']) > 0)
                        <div class="mt-2 flex flex-wrap gap-2" data-image-choices>
                            @foreach ($article['images'] as $choice)
                                <button type="button" wire:click="pickImage({{ $article['id'] }}, {{ $choice['media_id'] }})" title="{{ $choice['why'] }}"
                                        @class(['overflow-hidden rounded-lg', 'ring-2 ring-gray-900 dark:ring-white' => ($article['image']['media_id'] ?? null) === $choice['media_id'], 'ring-1 ring-gray-200 hover:ring-gray-400 dark:ring-gray-700' => ($article['image']['media_id'] ?? null) !== $choice['media_id']])
                                        data-image-choice="{{ $choice['media_id'] }}">
                                    <img src="{{ $choice['url'] }}" alt="{{ $choice['alt'] }}" loading="lazy" class="h-14 w-20 object-cover">
                                </button>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
            @php
                $sentAll = $article['sent'] !== [] && array_diff(array_keys($versions), $article['sent']) === [];
            @endphp
            <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 px-5 py-3 dark:border-gray-800">
                @foreach ($article['translations_blocked'] as $code => $why)<span class="mr-auto text-xs text-error-700 dark:text-error-400">{{ strtoupper($code) }} çevirisi kurala takıldı.</span>@endforeach
                @if ($article['writing'])
                    <span class="text-xs text-gray-500">Çeviri yazılıyor…</span>
                @else
                    @foreach ($article['missing'] as $code)
                        <button type="button" wire:click="writeContent({{ $article['id'] }}, '{{ $code }}')" class="{{ $ghost }}" data-translate="{{ $code }}">+ {{ $langs[$code] ?? strtoupper($code) }} yaz</button>
                    @endforeach
                @endif
                <button type="button" wire:click="closeReading" class="{{ $ghost }}">Kapat</button>
                @if ($article['article'] !== null && ! $sentAll)
                    <button type="button" wire:click="sendContent({{ $article['id'] }})" wire:confirm="{{ implode(', ', array_map('strtoupper', array_keys($versions))) }} WordPress'e taslak olarak gönderilsin mi?" class="{{ $btn }}" data-send="{{ $article['id'] }}">{{ ($article['kind'] ?? null) === 'Güncelleme' ? 'Sayfanın yeni sürümü: ' : '' }}WordPress'e taslak gönder{{ count($versions) > 1 ? ' ('.implode(' + ', array_map('strtoupper', array_keys($versions))).')' : '' }}</button>
                @elseif ($sentAll)
                    <span class="text-xs text-success-700 dark:text-success-400">WordPress'e taslak olarak gönderildi.</span>
                @endif
            </footer>
        </div>
    </div>
@endif
