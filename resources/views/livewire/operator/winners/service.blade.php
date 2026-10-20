@php
    $logo = fn (?string $name): ?string => $name !== null ? ($logos[$name] ?? null) : null;
    /** "87 TRY / form · 212 form" → the number that decides the race, and the rest in gray. */
    $split = fn (string $value): array => array_pad(explode(' · ', $value, 2), 2, '');
@endphp

<div>
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])

    <div class="space-y-10">
        @if (! $ready || ! $view)
            <header><h1 class="page-title">Kazananlar</h1></header>
            <div class="card"><x-ta.empty-state icon="none" title="Kazananlar tabloları henüz hazır değil." /></div>
        @else
            <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4" data-testid="winner-service-hero">
                <div class="min-w-0">
                    <p class="break-words text-[13px] text-gray-500"><a href="{{ route('operator.winners', ['sehir' => $view['city']]) }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">Kazananlar</a>@if ($view['sector']) / {{ $view['sector'] }}@endif</p>
                    <h1 class="page-title mt-0.5 break-words">{{ $view['name'] }}</h1>
                    <p class="page-sub">{{ $view['city'] !== '' ? $view['city'] : 'Şehir yok' }} · {{ $view['competing'] }} marka yarışıyor, eşiği geçen {{ $view['eligible'] }}, son 30 gün</p>
                </div>
                <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                    @if (count($view['cities']) > 1)
                        <select wire:model.live="city" aria-label="Şehir" class="h-8 w-full rounded-lg border border-gray-200 bg-white py-0 pl-3 text-[13px] sm:w-auto dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            @foreach ($view['cities'] as $c)<option value="{{ $c['name'] }}" @selected($view['city'] === $c['name'])>{{ $c['name'] }}, {{ $c['brands'] }} marka</option>@endforeach
                        </select>
                    @endif
                    <div class="flex gap-1.5" role="group" aria-label="Ölçü">
                        @foreach ($measures as $key => $label)
                            <button type="button" wire:click="$set('measure', '{{ $key }}')" aria-pressed="{{ $measure === $key ? 'true' : 'false' }}" @class(['seg h-8!', 'seg-active' => $measure === $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </header>

            {{-- 1. Who leads each channel here. --}}
            <section data-testid="winner-podium-grid">
                <div class="section-head">
                    <h2 class="section-title">Kanal liderleri</h2>
                    @if ($view['podium'] !== [] && $view['empty_channels'] !== [])
                        <span class="ml-auto text-[13px] text-gray-500">{{ implode(', ', $view['empty_channels']) }}: bu şehirde eşiği geçen marka yok.</span>
                    @endif
                </div>
                @if ($view['podium'] === [])
                    <div class="card"><x-ta.empty-state icon="none" size="sm" title="Bu şehirde hiçbir kanalda eşiği geçen marka yok." data-testid="winner-podium" /></div>
                @else
                    <div @class(['metric-strip grid-cols-1 sm:grid-cols-2', 'lg:grid-cols-4' => count($view['podium']) === 4, 'lg:grid-cols-3' => count($view['podium']) === 3])>
                        @foreach ($view['podium'] as $key => $p)
                            @php $first = $p['top'][0] ?? null; [$main, $rest] = $first ? $split($first['value']) : ['', '']; @endphp
                            <div class="flex min-w-0 flex-col bg-white px-[18px] py-4 dark:bg-gray-900" wire:key="pod-{{ $key }}">
                                <span class="text-[13px] text-gray-500 dark:text-gray-400">{{ $p['label'] }}, {{ $p['competing'] }} marka</span>
                                @if ($first)
                                    <span class="mt-1.5 truncate text-[22px] leading-8 font-semibold tabular-nums tracking-[-0.02em] text-gray-900 dark:text-white" title="{{ $first['value'] }}">{{ $main }}</span>
                                    <span class="text-[13px] tabular-nums text-gray-500">{{ $rest !== '' ? $rest : ' ' }}</span>
                                    <span class="mt-3 flex min-w-0 items-center gap-2" title="{{ $first['brand'] }}">
                                        <x-ta.brand-avatar :name="$first['brand']" :logo="$logo($first['brand'])" size="sm" />
                                        <span class="truncate text-sm font-medium text-gray-900 dark:text-white">{{ $first['brand'] }}</span>
                                    </span>
                                    <span class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $first['what'] }}">{{ $first['what'] }}</span>
                                    @if (count($p['top']) > 1)
                                        <ol class="mt-3 space-y-1.5 border-t border-gray-200 pt-3 text-[13px] dark:border-gray-800">
                                            @foreach (array_slice($p['top'], 1) as $i => $t)
                                                <li class="flex min-w-0 items-baseline gap-2">
                                                    <span class="w-4 shrink-0 tabular-nums text-gray-400">{{ $i + 2 }}.</span>
                                                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300" title="{{ $t['brand'] }}">{{ $t['brand'] }}</span>
                                                    <span class="shrink-0 tabular-nums text-gray-500">{{ $split($t['value'])[0] }}</span>
                                                </li>
                                            @endforeach
                                        </ol>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- 2. Every brand, every channel. --}}
            <section data-testid="winner-ranking">
                <div class="section-head">
                    <h2 class="section-title" title="Her kanalda sıraya göre puan (lider {{ \App\Services\Ads\Winners::CHANNEL_POINTS }}); kanalı olmayan o kanaldan puan almaz.">Genel sıralama <span class="section-count">{{ count($view['ranking']) }}</span></h2>
                </div>
                <div class="card overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="w-10">Sıra</th>
                                <th>Marka</th>
                                @foreach ($channels as $label)<th class="hidden lg:table-cell">{{ $label }}</th>@endforeach
                                <th class="text-right!">Puan</th>
                                <th class="text-right!" title="Bir hafta önceki sıraya göre">7 gün</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($view['ranking'] as $r)
                                <tr wire:key="rank-{{ $r['brand_id'] }}">
                                    <td class="tabular-nums text-gray-500">{{ $r['rank'] }}.</td>
                                    <td class="max-w-0 w-[60%] lg:w-[26%]">
                                        <span class="flex min-w-0 items-center gap-2.5" title="{{ $r['brand'] }}">
                                            <x-ta.brand-avatar :name="$r['brand']" :logo="$logo($r['brand'])" />
                                            <span class="truncate font-medium text-gray-900 dark:text-white">{{ $r['brand'] }}</span>
                                        </span>
                                    </td>
                                    @foreach ($channels as $key => $label)
                                        <td class="hidden text-[13px] tabular-nums lg:table-cell">
                                            @if (isset($r['values'][$key]))
                                                @php [$cellMain, $cellRest] = $split($r['values'][$key]); @endphp
                                                <span class="whitespace-nowrap text-gray-900 dark:text-white">{{ $cellMain }}</span>
                                                <span class="block whitespace-nowrap text-xs text-gray-500">{{ $r['channels'][$key]['rank'].'. sırada'.($cellRest !== '' ? ', '.$cellRest : '') }}</span>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-right text-[15px] font-semibold tabular-nums text-gray-900 dark:text-white">{{ $r['score'] }}</td>
                                    <td class="whitespace-nowrap text-right text-[13px] tabular-nums">
                                        @if ($r['move'] === null)<span class="text-gray-400">—</span>@else<span @class(['chip', 'chip-up' => $r['move'] > 0, 'chip-down' => $r['move'] < 0, 'chip-flat' => $r['move'] === 0])>{{ $r['move'] > 0 ? '▲ '.$r['move'] : ($r['move'] < 0 ? '▼ '.abs($r['move']) : '0') }}</span>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($channels) + 4 }}" class="py-6 text-sm text-gray-500">Eşiği geçen marka yok.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- 3. How the leaders win, what moved. --}}
            <div class="grid gap-10 lg:grid-cols-3">
                <section class="min-w-0 lg:col-span-2" data-testid="winner-recipes">
                    <div class="section-head"><h2 class="section-title">Kazananın tarifi</h2></div>
                    <ul class="card">
                        @forelse ($view['recipes'] as $rc)
                            <li class="card-row items-start">
                                <x-ta.brand-avatar :name="$rc['brand']" :logo="$logo($rc['brand'])" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $rc['label'] }} <span class="font-normal text-gray-500">{{ $rc['brand'] }}</span></p>
                                    <p class="mt-1 break-words text-[13px] leading-relaxed text-gray-600 dark:text-gray-300">{{ $rc['text'] }}</p>
                                    @if ($rc['channel'] === 'meta' && $view['meta_type'] && in_array($view['meta_type'], ['leads', 'messages', 'purchases'], true))
                                        <a href="{{ route('operator.meta-strategy', ['hizmet' => $view['service_id'], 'tur' => $view['meta_type'], 'sehir' => $view['city']]) }}" wire:navigate class="btn mt-2.5">Meta tarifini Strateji öner’de aç</a>
                                    @endif
                                </div>
                            </li>
                        @empty
                            <li><x-ta.empty-state icon="none" size="sm" title="Henüz lider yok." /></li>
                        @endforelse
                    </ul>
                </section>
                <div class="min-w-0 space-y-10">
                    <section data-testid="winner-moves">
                        <div class="section-head"><h2 class="section-title">Yükselenler ve düşenler</h2></div>
                        <ul class="card">
                            @if ($view['risers'] === [] && $view['fallers'] === [])
                                <li><x-ta.empty-state icon="none" size="sm" title="Henüz kıyas yok" message="Bir hafta önceki sıralama birikince burada görünür." /></li>
                            @else
                                @foreach (array_merge($view['risers'], $view['fallers']) as $x)
                                    <li class="card-row py-3!">
                                        <x-ta.brand-avatar :name="$x['brand']" :logo="$logo($x['brand'])" size="sm" />
                                        <span class="min-w-0 flex-1 truncate text-sm text-gray-900 dark:text-white" title="{{ $x['brand'] }}">{{ $x['brand'] }}</span>
                                        <span @class(['chip', 'chip-up' => $x['move'] > 0, 'chip-down' => $x['move'] < 0])>{{ $x['move'] > 0 ? '▲ '.$x['move'] : '▼ '.abs($x['move']) }}</span>
                                    </li>
                                @endforeach
                            @endif
                        </ul>
                    </section>
                    <section data-testid="winner-feed">
                        <div class="section-head"><h2 class="section-title">Liderlik değişimleri</h2></div>
                        <ul class="card">
                            @forelse ($view['feed'] as $f)
                                <li class="card-row items-start">
                                    <span class="w-10 shrink-0 pt-px text-xs tabular-nums text-gray-500">{{ \Carbon\CarbonImmutable::parse($f['date'])->format('d.m') }}</span>
                                    <p class="min-w-0 flex-1 break-words text-[13px] text-gray-700 dark:text-gray-300">{{ $f['text'] }}</p>
                                </li>
                            @empty
                                <li><x-ta.empty-state icon="none" size="sm" title="Son 60 günde lider değişmedi." /></li>
                            @endforelse
                        </ul>
                    </section>
                </div>
            </div>

            <details class="card group" data-testid="winner-rules">
                <summary class="card-row cursor-pointer list-none hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                    <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium text-gray-900 dark:text-white">Adil yarış kuralları</span>
                        <span class="mt-0.5 block text-[13px] text-gray-500">Kim sıralamaya girer, sayılar hangi şehre yazılır.</span>
                    </span>
                    <span class="btn-quiet"><span class="group-open:hidden">Göster</span><span class="hidden group-open:inline">Kapat</span></span>
                </summary>
                <ol class="grid gap-x-8 gap-y-3 border-t border-gray-200 px-4 py-4 text-[13px] text-gray-600 md:grid-cols-2 dark:border-gray-800 dark:text-gray-400">
                    <li class="flex gap-2"><span class="font-medium text-gray-900 dark:text-white">1</span><span>Reklamda sıralamaya girmek için 30 günde {{ number_format(\App\Services\Ads\Winners::MIN_SPEND, 0, ',', '.') }} TL harcama ve {{ \App\Services\Ads\Winners::MIN_RESULTS }} sonuç gerekir (TRY hesaplar).</span></li>
                    <li class="flex gap-2"><span class="font-medium text-gray-900 dark:text-white">2</span><span>Yalnızca aynı şehirdeki markalar ve aynı sonuç türü karşılaştırılır. Reklam sayıları kişilerin bulunduğu şehre (Google Ads coğrafya raporu) ya da kampanya adındaki şehre, web sayıları sorgudaki şube şehrine yazılır.</span></li>
                    <li class="flex gap-2"><span class="font-medium text-gray-900 dark:text-white">3</span><span>Google Ads’te marka adını içeren anahtar kelimeler sayılmaz.</span></li>
                    <li class="flex gap-2"><span class="font-medium text-gray-900 dark:text-white">4</span><span>Web sitesi sırası hizmetin küme sorgularının ortalama sırasıdır; yarışmak için en az {{ \App\Services\Ads\Winners::WEB_MIN_IMPRESSIONS }} gösterim, ilk sayfa (ortalama sıra en çok {{ (int) \App\Services\Ads\Winners::WEB_MAX_POSITION }}) ve tık gerekir; İşletme Profili en az {{ \App\Services\Ads\Winners::GBP_MIN_REVIEWS }} yorumla ve hizmet profilde listeliyse yarışır.</span></li>
                </ol>
            </details>
        @endif
    </div>
</div>
