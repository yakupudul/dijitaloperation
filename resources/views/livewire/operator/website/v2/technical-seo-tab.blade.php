@php
    $card = 'min-w-0';
    $pill = fn (bool $on) => $on ? 'h-7 shrink-0 whitespace-nowrap rounded-md bg-gray-100 px-2.5 text-[13px] font-medium text-gray-900 dark:bg-white/10 dark:text-white' : 'h-7 shrink-0 whitespace-nowrap rounded-md px-2.5 text-[13px] text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
    $segment = 'inline-flex max-w-full shrink-0 rounded-lg border border-gray-200 bg-white p-0.5 shadow-card dark:border-gray-700 dark:bg-gray-900';
    $sevTone = ['critical' => 'critical', 'warning' => 'look', 'info' => 'info'];
    $fixTone = ['wordpress' => 'text-gray-500', 'developer' => 'text-gray-500', 'google' => 'text-gray-500'];
    $num = fn ($v) => number_format((int) $v, 0, ',', '.');
    $index = $data['index'];
    $tiles = [
        ['critical', 'Kritik', $data['tiles']['critical'], $data['tiles']['critical'] > 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-900 dark:text-white', 'sayfa, önce bunlar'],
        ['warning', 'Uyarı', $data['tiles']['warning'], $data['tiles']['warning'] > 0 ? 'text-warning-700 dark:text-warning-400' : 'text-gray-900 dark:text-white', 'sayfa, sıralamayı etkiler'],
        ['info', 'Bilgi', $data['tiles']['info'], 'text-gray-900 dark:text-white', 'sayfa, iyileştirme'],
        ['', 'Google doğruluyor', $data['tiles']['validating'], 'text-gray-900 dark:text-white', 'son 28 günde uygulanan düzeltme'],
    ];
    $empty = ['google' => $data['has_gsc'] ? ['Google bu filtrede sorun bildirmiyor.', null] : ['Search Console URL denetimi henüz gelmedi', 'Her gün hizmet sayfalarından başlayarak 25 sayfa denetlenir; hemen başlatmak için «Şimdi denetle».'],
        'html' => $data['has_crawl'] ? ['Bu filtrede HTML sorunu yok.', null] : ['Site henüz taranmadı.', null]];
@endphp
<div class="min-w-0 space-y-8 text-sm" data-technical-seo-tab>
    @if ($message !== '')
        <p role="status" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-2.5 text-sm text-gray-800 shadow-card dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-200"><span>{{ $message }}</span><button type="button" wire:click="$set('message', '')" aria-label="Kapat" class="btn-quiet h-7! px-2!">×</button></p>
    @endif

    {{-- 1. The answer: how many pages have a problem Google or the HTML shows, worst first. A number filters the lists. --}}
    <section class="metric-strip grid-cols-2 lg:grid-cols-4" data-tech-tiles>
        @foreach ($tiles as [$key, $label, $value, $tone, $note])
            <button type="button" @if ($key !== '') wire:click="$set('severity', '{{ $activeSeverity === $key ? '' : $key }}')" @endif data-tile="{{ $key ?: 'validating' }}" aria-pressed="{{ $key !== '' && $activeSeverity === $key ? 'true' : 'false' }}"
                    @class(['flex min-w-0 flex-col px-[18px] py-4 text-left transition', 'bg-gray-50 shadow-[inset_0_-2px_0_0_var(--color-gray-900)] dark:bg-gray-800 dark:shadow-[inset_0_-2px_0_0_var(--color-white)]' => $key !== '' && $activeSeverity === $key, 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800/60' => $key !== '' && $activeSeverity !== $key, 'cursor-default bg-white dark:bg-gray-900' => $key === ''])>
                <span class="text-[13px] text-gray-500 dark:text-gray-400">{{ $label }}</span>
                <span class="mt-1.5 text-[26px] leading-8 font-semibold tracking-[-0.02em] tabular-nums {{ $tone }}">{{ $num($value) }}</span>
                <span class="mt-1 text-xs text-gray-500">{{ $note }}</span>
            </button>
        @endforeach
    </section>

    {{-- 2. Is Google indexing the pages? --}}
    <section class="card px-4 py-3.5" data-index-bar>
        <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <div class="min-w-0 flex-1">
                <h2 class="text-sm font-medium text-gray-900 dark:text-white">Google dizini</h2>
                @if ($index['inspected'] > 0)
                    <p class="mt-0.5 text-[13px] text-gray-500">Denetlenen {{ $num($index['inspected']) }} sayfanın <span class="font-medium text-success-700 dark:text-success-400">{{ $num($index['indexed']) }}</span> dizinde, <span class="font-medium text-error-700 dark:text-error-400">{{ $num($index['not_indexed']) }}</span> dizinde değil.@if ($data['sitemaps'] > 0) {{ $data['sitemaps'] }} site haritası Search Console’da kayıtlı.@endif</p>
                @else
                    <p class="mt-0.5 text-[13px] text-gray-500">Henüz Google URL denetimi yok, her gün 25 sayfa otomatik denetlenir.@if ($data['sitemaps'] > 0) {{ $data['sitemaps'] }} site haritası Search Console’da kayıtlı.@endif</p>
                @endif
            </div>
            <button type="button" wire:click="inspectNow" class="btn w-full sm:w-auto" data-inspect-now title="Search Console URL Inspection API ile bugünkü sayfaları denetler (AI değil).">Şimdi denetle</button>
        </div>
        <div class="mt-3 flex h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
            @if ($index['inspected'] > 0)
                <div class="bg-success-500" style="width: {{ $index['indexed'] / $index['inspected'] * 100 }}%"></div>
                <div class="bg-error-400" style="width: {{ $index['not_indexed'] / $index['inspected'] * 100 }}%"></div>
            @endif
        </div>
    </section>

    {{-- 3. Filters and the two lists: what Google reports, what the HTML shows. --}}
    <section class="flex min-w-0 flex-wrap items-center gap-2" data-tech-filters>
        <div class="{{ $segment }}" role="group" aria-label="Kaynak">
            <button type="button" wire:click="$set('source', '')" class="{{ $pill($activeSource === '') }}">Tüm kaynaklar</button>
            @foreach (\App\Services\Site\Analysis\TechnicalSeoReader::SOURCES as $key => $label)
                <button type="button" wire:click="$set('source', '{{ $key }}')" class="{{ $pill($activeSource === $key) }}" data-source-filter="{{ $key }}">{{ $key === 'google' ? 'Search Console' : 'HTML' }}</button>
            @endforeach
        </div>
        <div class="{{ $segment }}" role="group" aria-label="Önem">
            <button type="button" wire:click="$set('severity', '')" class="{{ $pill($activeSeverity === '') }}">Her önem</button>
            @foreach (\App\Services\Site\Analysis\TechnicalSeoReader::SEVERITIES as $key => $label)
                <button type="button" wire:click="$set('severity', '{{ $key }}')" class="{{ $pill($activeSeverity === $key) }}">{{ $label }}</button>
            @endforeach
        </div>
    </section>

    @foreach ($lists as $sourceKey => $findings)
        <section class="{{ $card }}" data-tech-list="{{ $sourceKey }}">
            <div class="section-head">
                <h3 class="section-title" title="{{ $sourceKey === 'google' ? 'Search Console URL denetimi ve site haritaları, son durum' : 'Son taramada her sayfanın HTML’i: başlık, açıklama, H1, canonical, hata' }}">{{ $sourceKey === 'google' ? 'Google’ın bildirdikleri' : 'Sitenin HTML’inde bulduklarımız' }}</h3>
                <span class="section-count">{{ count($findings) }}</span>
            </div>
            <ul class="card">
                @forelse ($findings as $finding)
                    <li class="border-t border-gray-200 first:border-t-0 dark:border-gray-800" wire:key="finding-{{ $finding['key'] }}" data-finding="{{ $finding['key'] }}" data-severity="{{ $finding['severity'] }}">
                        <button type="button" wire:click="show('{{ $finding['key'] }}')" class="flex w-full min-w-0 flex-wrap items-center gap-x-3.5 gap-y-2 px-4 py-3.5 text-left hover:bg-gray-50/70 sm:flex-nowrap dark:hover:bg-white/[0.02]">
                            <x-ta.pill :tone="$sevTone[$finding['severity']]" class="w-16 shrink-0">{{ \App\Services\Site\Analysis\TechnicalSeoReader::SEVERITIES[$finding['severity']] }}</x-ta.pill>
                            <span class="min-w-0 flex-[1_1_16rem]">
                                <span class="block text-sm font-medium text-gray-900 dark:text-white">{{ $finding['title'] }} <span class="ml-1 text-[13px] font-normal text-gray-500">{{ $num($finding['count']) }} {{ str_starts_with($finding['key'], 'g_sitemap') ? 'site haritası' : 'sayfa' }}</span></span>
                                <span class="mt-0.5 block truncate text-[13px] text-gray-500">{{ $finding['why'] }}</span>
                            </span>
                            <span class="w-full shrink-0 text-xs sm:w-auto sm:text-right">
                                <span class="block font-medium text-gray-800 dark:text-gray-200">{{ $finding['action'] }}</span>
                                <span class="block {{ $fixTone[$finding['fixer']] }}">{{ \App\Services\Site\Analysis\TechnicalSeoReader::FIXERS[$finding['fixer']] }}</span>
                            </span>
                        </button>
                    </li>
                @empty
                    <li><x-ta.empty-state size="sm" :title="$empty[$sourceKey][0]" :message="$empty[$sourceKey][1]" /></li>
                @endforelse
            </ul>
        </section>
    @endforeach

    @if ($opened)
        <div class="fixed inset-0 z-99999 flex justify-end" role="dialog" aria-modal="true" aria-label="{{ $opened['title'] }}" data-finding-drawer="{{ $opened['key'] }}" wire:keydown.escape.window="close">
            <button type="button" class="absolute inset-0 bg-gray-900/40" wire:click="close" aria-label="Kapat"></button>
            <aside class="relative h-full w-full max-w-lg space-y-6 overflow-y-auto bg-white px-4 py-5 shadow-xl sm:px-6 dark:bg-gray-900">
                <header class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <x-ta.pill :tone="$sevTone[$opened['severity']]">{{ \App\Services\Site\Analysis\TechnicalSeoReader::SEVERITIES[$opened['severity']] }}</x-ta.pill>
                        <h2 class="mt-2 text-[17px] leading-6 font-semibold text-gray-900 dark:text-white">{{ $opened['title'] }}</h2>
                        <p class="mt-0.5 text-[13px] text-gray-500">{{ \App\Services\Site\Analysis\TechnicalSeoReader::SOURCES[$opened['source']] }}, {{ $num($opened['count']) }} {{ str_starts_with($opened['key'], 'g_sitemap') ? 'site haritası' : 'sayfa' }}</p>
                    </div>
                    <button type="button" wire:click="close" class="btn shrink-0">Kapat</button>
                </header>
                <section>
                    <h3 class="section-title">Ne oluyor?</h3>
                    <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $opened['why'] }}</p>
                </section>
                <section>
                    <h3 class="section-title">Nasıl düzeltilir?</h3>
                    <p class="mt-1 text-xs font-medium {{ $fixTone[$opened['fixer']] }}">{{ \App\Services\Site\Analysis\TechnicalSeoReader::FIXERS[$opened['fixer']] }}</p>
                    <ol class="mt-1.5 list-decimal space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">@foreach ($opened['fix'] as $step)<li>{{ $step }}</li>@endforeach</ol>
                    @if ($opened['fixer'] === 'wordpress')
                        <button type="button" wire:click="$parent.setTab('yapilacaklar')" class="link-primary mt-2.5 text-[13px]">Yapılacaklar’da WordPress’e uygulanabilir öneriler</button>
                    @endif
                </section>
                <section>
                    <h3 class="section-title">Etkilenen {{ str_starts_with($opened['key'], 'g_sitemap') ? 'site haritaları' : 'sayfalar' }}</h3>
                    <ul class="card mt-2">
                        @foreach ($opened['pages'] as $path)<li class="truncate border-t border-gray-200 px-4 py-2 text-[13px] first:border-t-0 dark:border-gray-800" title="{{ $path }}">{{ $path }}</li>@endforeach
                    </ul>
                    @if ($opened['count'] > count($opened['pages']))<p class="mt-1.5 text-xs text-gray-500">+{{ $num($opened['count'] - count($opened['pages'])) }} sayfa daha</p>@endif
                </section>
            </aside>
        </div>
    @endif
</div>
