@php
    $card = 'rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $pill = fn (bool $on) => $on ? 'h-8 rounded-lg bg-gray-900 px-3 text-xs font-semibold text-white dark:bg-white dark:text-gray-900' : 'h-8 rounded-lg px-3 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-700';
    $sevTone = ['critical' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300', 'warning' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'info' => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300'];
    $fixTone = ['wordpress' => 'text-blue-700 dark:text-blue-300', 'developer' => 'text-violet-700 dark:text-violet-300', 'google' => 'text-gray-500'];
    $num = fn ($v) => number_format((int) $v, 0, ',', '.');
    $index = $data['index'];
    $tiles = [
        ['critical', 'Kritik', $data['tiles']['critical'], 'text-rose-700 dark:text-rose-400', 'sayfa · önce bunlar'],
        ['warning', 'Uyarı', $data['tiles']['warning'], 'text-amber-700 dark:text-amber-400', 'sayfa · sıralamayı etkiler'],
        ['info', 'Bilgi', $data['tiles']['info'], 'text-gray-700 dark:text-gray-300', 'sayfa · iyileştirme'],
        ['', 'Google doğruluyor', $data['tiles']['validating'], 'text-blue-700 dark:text-blue-400', 'son 28 günde uygulanan düzeltme'],
    ];
    $empty = ['google' => $data['has_gsc'] ? 'Google bu filtrede sorun bildirmiyor.' : 'Search Console URL denetimi verisi yok (bağlantı ya da toplama bekleniyor).',
        'html' => $data['has_crawl'] ? 'Bu filtrede HTML sorunu yok.' : 'Site henüz taranmadı.'];
@endphp
<div class="space-y-5 text-sm" data-technical-seo-tab>
    <section class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-tech-tiles>
        @foreach ($tiles as [$key, $label, $value, $tone, $note])
            <button type="button" @if ($key !== '') wire:click="$set('severity', '{{ $activeSeverity === $key ? '' : $key }}')" @endif data-tile="{{ $key ?: 'validating' }}"
                    @class([$card, 'p-4 text-left', '!ring-2 !ring-gray-900 dark:!ring-white' => $key !== '' && $activeSeverity === $key, 'cursor-default' => $key === ''])>
                <span class="block text-xs text-gray-500">{{ $label }}</span>
                <span class="mt-1 block text-2xl font-semibold tabular-nums {{ $tone }}">{{ $num($value) }}</span>
                <span class="block text-[11px] text-gray-400">{{ $note }}</span>
            </button>
        @endforeach
    </section>

    <section class="{{ $card }} p-4" data-index-bar>
        <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
            <span class="font-semibold text-gray-900 dark:text-white">Google dizini</span>
            @if ($index['inspected'] > 0)
                <span class="text-gray-500">Denetlenen {{ $num($index['inspected']) }} sayfanın <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $num($index['indexed']) }}</span> dizinde, <span class="font-semibold text-rose-700 dark:text-rose-400">{{ $num($index['not_indexed']) }}</span> dizinde değil</span>
            @else
                <span class="text-gray-500">URL denetimi verisi yok</span>
            @endif
        </div>
        <div class="mt-2 flex h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
            @if ($index['inspected'] > 0)
                <div class="bg-emerald-500" style="width: {{ $index['indexed'] / $index['inspected'] * 100 }}%"></div>
                <div class="bg-rose-400" style="width: {{ $index['not_indexed'] / $index['inspected'] * 100 }}%"></div>
            @endif
        </div>
        @if ($data['sitemaps'] > 0)<p class="mt-1.5 text-[11px] text-gray-500">{{ $data['sitemaps'] }} site haritası Search Console’da kayıtlı.</p>@endif
    </section>

    <section class="flex flex-wrap items-center gap-3" data-tech-filters>
        <span class="text-xs text-gray-500">Kaynak</span>
        <div class="flex gap-1.5">
            <button type="button" wire:click="$set('source', '')" class="{{ $pill($activeSource === '') }}">Tümü</button>
            @foreach (\App\Services\Site\Analysis\TechnicalSeoReader::SOURCES as $key => $label)
                <button type="button" wire:click="$set('source', '{{ $key }}')" class="{{ $pill($activeSource === $key) }}" data-source-filter="{{ $key }}">{{ $key === 'google' ? 'Search Console' : 'HTML' }}</button>
            @endforeach
        </div>
        <span class="text-xs text-gray-500 sm:ml-4">Önem</span>
        <div class="flex gap-1.5">
            <button type="button" wire:click="$set('severity', '')" class="{{ $pill($activeSeverity === '') }}">Tümü</button>
            @foreach (\App\Services\Site\Analysis\TechnicalSeoReader::SEVERITIES as $key => $label)
                <button type="button" wire:click="$set('severity', '{{ $key }}')" class="{{ $pill($activeSeverity === $key) }}">{{ $label }}</button>
            @endforeach
        </div>
    </section>

    @foreach ($lists as $sourceKey => $findings)
        <section class="{{ $card }} overflow-hidden" data-tech-list="{{ $sourceKey }}">
            <header class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $sourceKey === 'google' ? 'Google’ın bildirdikleri' : 'Sitenin HTML’inde bulduklarımız' }}</h3>
                <p class="text-xs text-gray-500">{{ $sourceKey === 'google' ? 'Search Console URL denetimi ve site haritaları · son durum' : 'Son taramada her sayfanın HTML’i · başlık, açıklama, H1, canonical, hata' }}</p>
            </header>
            <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($findings as $finding)
                    <li wire:key="finding-{{ $finding['key'] }}" data-finding="{{ $finding['key'] }}" data-severity="{{ $finding['severity'] }}">
                        <button type="button" wire:click="show('{{ $finding['key'] }}')" class="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-white/[0.03]">
                            <span class="w-14 shrink-0 rounded-full px-2 py-0.5 text-center text-[11px] font-semibold {{ $sevTone[$finding['severity']] }}">{{ \App\Services\Site\Analysis\TechnicalSeoReader::SEVERITIES[$finding['severity']] }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-gray-900 dark:text-white">{{ $finding['title'] }} <span class="font-normal text-gray-500">· {{ $num($finding['count']) }} {{ str_starts_with($finding['key'], 'g_sitemap') ? 'site haritası' : 'sayfa' }}</span></span>
                                <span class="block truncate text-xs text-gray-500">{{ $finding['why'] }}</span>
                            </span>
                            <span class="shrink-0 text-right text-xs">
                                <span class="block font-medium text-gray-800 dark:text-gray-200">{{ $finding['action'] }}</span>
                                <span class="block {{ $fixTone[$finding['fixer']] }}">{{ \App\Services\Site\Analysis\TechnicalSeoReader::FIXERS[$finding['fixer']] }}</span>
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-xs text-gray-500">{{ $empty[$sourceKey] }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach

    @if ($opened)
        <div class="fixed inset-0 z-99999 flex justify-end" role="dialog" aria-modal="true" aria-label="{{ $opened['title'] }}" data-finding-drawer="{{ $opened['key'] }}" wire:keydown.escape.window="close">
            <button type="button" class="absolute inset-0 bg-gray-900/40" wire:click="close" aria-label="Kapat"></button>
            <aside class="relative h-full w-full max-w-lg space-y-5 overflow-y-auto bg-white p-5 shadow-xl dark:bg-gray-900">
                <header class="flex items-start justify-between gap-3">
                    <div>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $sevTone[$opened['severity']] }}">{{ \App\Services\Site\Analysis\TechnicalSeoReader::SEVERITIES[$opened['severity']] }}</span>
                        <h2 class="mt-2 text-lg font-semibold text-gray-900 dark:text-white">{{ $opened['title'] }}</h2>
                        <p class="text-xs text-gray-500">{{ \App\Services\Site\Analysis\TechnicalSeoReader::SOURCES[$opened['source']] }} · {{ $num($opened['count']) }} {{ str_starts_with($opened['key'], 'g_sitemap') ? 'site haritası' : 'sayfa' }}</p>
                    </div>
                    <button type="button" wire:click="close" class="rounded-lg px-2 py-1 text-xs ring-1 ring-inset ring-gray-300 dark:ring-gray-700">Kapat</button>
                </header>
                <section>
                    <h3 class="text-sm font-semibold">Ne oluyor?</h3>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $opened['why'] }}</p>
                </section>
                <section>
                    <h3 class="text-sm font-semibold">Nasıl düzeltilir?</h3>
                    <p class="mt-1 text-xs font-medium {{ $fixTone[$opened['fixer']] }}">{{ \App\Services\Site\Analysis\TechnicalSeoReader::FIXERS[$opened['fixer']] }}</p>
                    <ol class="mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">@foreach ($opened['fix'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                    @if ($opened['fixer'] === 'wordpress')
                        <button type="button" wire:click="$parent.setTab('yapilacaklar')" class="mt-2 text-xs font-medium text-brand-600 hover:underline">Yapılacaklar’da WordPress’e uygulanabilir öneriler →</button>
                    @endif
                </section>
                <section>
                    <h3 class="text-sm font-semibold">Etkilenen {{ str_starts_with($opened['key'], 'g_sitemap') ? 'site haritaları' : 'sayfalar' }}</h3>
                    <ul class="mt-1 divide-y divide-gray-100 text-xs dark:divide-gray-800">
                        @foreach ($opened['pages'] as $path)<li class="truncate py-1.5" title="{{ $path }}">{{ $path }}</li>@endforeach
                    </ul>
                    @if ($opened['count'] > count($opened['pages']))<p class="mt-1 text-[11px] text-gray-500">+{{ $num($opened['count'] - count($opened['pages'])) }} sayfa daha</p>@endif
                </section>
            </aside>
        </div>
    @endif
</div>
