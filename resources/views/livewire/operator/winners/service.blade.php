@php
    $select = 'mt-1 block w-full rounded-lg border-gray-200 py-1.5 text-sm sm:w-auto dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $h2 = 'section-title';
    $list = 'divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800';
    $segment = 'flex h-8 items-center rounded-md px-3 whitespace-nowrap';
    $segmentOn = 'bg-white font-semibold text-gray-900 shadow-theme-xs ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-white dark:ring-gray-700';
    $segmentOff = 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white';
@endphp

<div class="space-y-10">
    @if (! $ready || ! $view)
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Kazananlar</h1>
        @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])
        <x-ta.empty-state title="Kazananlar tabloları henüz hazır değil." />
    @else
        <div class="min-w-0" data-testid="winner-service-hero">
            <p class="break-words text-xs text-gray-500"><a href="{{ route('operator.winners', ['sehir' => $view['city']]) }}" wire:navigate class="hover:text-gray-900 dark:hover:text-white">Kazananlar</a>@if ($view['sector']) / {{ $view['sector'] }}@endif / {{ $view['name'] }}</p>
            <h1 class="mt-1 break-words text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $view['name'] }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $view['city'] !== '' ? $view['city'] : 'Şehir yok' }} · {{ $view['competing'] }} marka yarışıyor, eşiği geçen {{ $view['eligible'] }}, son 30 gün</p>
        </div>
        @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <p class="text-xs text-gray-500">Ölçü</p>
                <div class="mt-1 inline-flex max-w-full flex-wrap gap-0.5 rounded-lg bg-gray-100 p-1 text-sm dark:bg-white/5">
                    @foreach ($measures as $key => $label)
                        <button type="button" wire:click="$set('measure', '{{ $key }}')" class="{{ $segment }} {{ $measure === $key ? $segmentOn : $segmentOff }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            @if (count($view['cities']) > 1)
                <label class="w-full text-xs text-gray-500 sm:w-auto">Şehir
                    <select wire:model.live="city" class="{{ $select }}">@foreach ($view['cities'] as $c)<option value="{{ $c['name'] }}" @selected($view['city'] === $c['name'])>{{ $c['name'] }}, {{ $c['brands'] }} marka</option>@endforeach</select>
                </label>
            @endif
        </div>

        @if ($view['podium'] === [])
            <x-ta.empty-state size="sm" title="Bu şehirde hiçbir kanalda eşiği geçen marka yok." data-testid="winner-podium" />
        @endif
        <div class="grid gap-x-8 gap-y-8 md:grid-cols-2 xl:grid-cols-4" data-testid="winner-podium-grid">
            @foreach ($view['podium'] as $key => $p)
                <section class="min-w-0" wire:key="pod-{{ $key }}">
                    <div class="flex items-baseline gap-2">
                        <h2 class="{{ $h2 }}">{{ $p['label'] }}</h2>
                        <span class="text-sm text-gray-500">{{ $p['competing'] }} marka</span>
                    </div>
                    @forelse ($p['top'] as $i => $t)
                        @if ($i === 0)
                            <div class="mt-3 border-b border-gray-200 pb-3 dark:border-gray-800">
                                <p class="flex min-w-0 items-baseline gap-2"><span class="text-sm tabular-nums text-gray-500">1.</span><span class="min-w-0 break-words text-base font-semibold text-gray-900 dark:text-white">{{ $t['brand'] }}</span></p>
                                <p class="mt-1 text-[26px] leading-8 font-semibold tabular-nums tracking-tight text-gray-900 dark:text-white">{{ $t['value'] }}</p>
                                <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $t['what'] }}">{{ $t['what'] }}</p>
                            </div>
                        @else
                            <div class="flex items-center gap-2 border-b border-gray-200 py-2 text-sm dark:border-gray-800">
                                <span class="w-5 shrink-0 tabular-nums text-gray-500">{{ $i + 1 }}.</span>
                                <span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200">{{ $t['brand'] }}</span>
                                <span class="text-xs tabular-nums text-gray-500">{{ $t['value'] }}</span>
                            </div>
                        @endif
                    @empty
                    @endforelse
                </section>
            @endforeach
        </div>
        @if ($view['podium'] !== [] && $view['empty_channels'] !== [])
            <p class="text-xs text-gray-500">{{ implode(', ', $view['empty_channels']) }}: bu şehirde eşiği geçen marka yok.</p>
        @endif

        <section data-testid="winner-ranking">
            <h2 class="{{ $h2 }}" title="Her kanalda sıraya göre puan (lider {{ \App\Services\Ads\Winners::CHANNEL_POINTS }}); kanalı olmayan o kanaldan puan almaz.">Genel sıralama</h2>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[60rem] text-sm">
                    <thead class="text-left text-xs text-gray-500">
                        <tr class="border-b border-gray-200 dark:border-gray-800"><th class="py-2 pr-3 font-normal">Sıra</th><th class="px-3 py-2 font-normal">Marka</th>@foreach ($channels as $label)<th class="px-3 py-2 font-normal">{{ $label }}</th>@endforeach<th class="px-3 py-2 text-right font-normal">Puan</th><th class="py-2 pl-3 text-right font-normal">7 gün</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 border-b border-gray-200 tabular-nums dark:divide-gray-800 dark:border-gray-800">
                        @forelse ($view['ranking'] as $r)
                            <tr wire:key="rank-{{ $r['brand_id'] }}" class="text-gray-700 dark:text-gray-300">
                                <td class="py-2.5 pr-3 text-gray-500">{{ $r['rank'] }}.</td>
                                <td class="px-3 py-2.5"><span class="flex items-center gap-2"><x-ta.brand-avatar :name="$r['brand']" size="xs" /><span class="font-medium text-gray-900 dark:text-white">{{ $r['brand'] }}</span></span></td>
                                @foreach ($channels as $key => $label)
                                    <td class="px-3 py-2.5 text-xs">@if (isset($r['values'][$key])){{ $r['values'][$key] }} <span class="ml-1 text-gray-500">{{ $r['channels'][$key]['rank'] }}.</span>@else<span class="text-gray-500">—</span>@endif</td>
                                @endforeach
                                <td class="px-3 py-2.5 text-right text-base font-semibold text-gray-900 dark:text-white">{{ $r['score'] }}</td>
                                <td @class(['py-2.5 pl-3 text-right text-xs', 'text-success-700' => ($r['move'] ?? 0) > 0, 'text-error-700' => ($r['move'] ?? 0) < 0, 'text-gray-500' => ($r['move'] ?? 0) === 0])>
                                    {{ $r['move'] === null ? '—' : ($r['move'] > 0 ? '↑ '.$r['move'] : ($r['move'] < 0 ? '↓ '.abs($r['move']) : '0')) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($channels) + 4 }}" class="py-6 text-center text-sm text-gray-500">Eşiği geçen marka yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-10 xl:grid-cols-3">
            <section class="min-w-0 xl:col-span-2" data-testid="winner-recipes">
                <h2 class="{{ $h2 }}">Kazananın tarifi</h2>
                <div class="mt-2 {{ $list }}">
                    @forelse ($view['recipes'] as $rc)
                        <div class="py-3">
                            <p class="text-xs text-gray-500"><span class="font-medium text-gray-900 dark:text-white">{{ $rc['label'] }}</span>, {{ $rc['brand'] }}</p>
                            <p class="mt-1 break-words text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $rc['text'] }}</p>
                            @if ($rc['channel'] === 'meta' && $view['meta_type'] && in_array($view['meta_type'], ['leads', 'messages', 'purchases'], true))
                                <a href="{{ route('operator.meta-strategy', ['hizmet' => $view['service_id'], 'tur' => $view['meta_type'], 'sehir' => $view['city']]) }}" wire:navigate class="link-neutral mt-1 inline-block text-xs">Meta tarifini Strateji öner’de aç</a>
                            @endif
                        </div>
                    @empty
                        <p class="py-3 text-sm text-gray-500">Henüz lider yok.</p>
                    @endforelse
                </div>
            </section>
            <div class="min-w-0 space-y-10">
                <section data-testid="winner-moves">
                    <h2 class="{{ $h2 }}">Yükselenler ve düşenler</h2>
                    @if ($view['risers'] === [] && $view['fallers'] === [])
                        <p class="mt-2 text-sm text-gray-500">Bir hafta önceki sıralama birikince burada görünür.</p>
                    @else
                        <div class="mt-2 grid grid-cols-2 gap-3 text-sm">
                            <ul class="space-y-1">@foreach ($view['risers'] as $x)<li class="flex justify-between gap-2"><span class="truncate">{{ $x['brand'] }}</span><span class="tabular-nums text-success-700">↑ {{ $x['move'] }}</span></li>@endforeach</ul>
                            <ul class="space-y-1">@foreach ($view['fallers'] as $x)<li class="flex justify-between gap-2"><span class="truncate">{{ $x['brand'] }}</span><span class="tabular-nums text-error-700">↓ {{ abs($x['move']) }}</span></li>@endforeach</ul>
                        </div>
                    @endif
                </section>
                <section data-testid="winner-feed">
                    <h2 class="{{ $h2 }}">Liderlik değişimleri</h2>
                    @forelse ($view['feed'] as $f)
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300"><span class="mr-2 text-xs tabular-nums text-gray-500">{{ \Carbon\CarbonImmutable::parse($f['date'])->format('d.m') }}</span>{{ $f['text'] }}</p>
                    @empty
                        <p class="mt-2 text-sm text-gray-500">Son 60 günde lider değişmedi.</p>
                    @endforelse
                </section>
            </div>
        </div>

        <details class="group" data-testid="winner-rules">
            <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-medium text-gray-900 dark:text-white">
                Adil yarış kuralları <span class="text-xs font-normal text-gray-500 group-open:hidden">göster</span>
            </summary>
            <ol class="mt-2 grid gap-2 text-sm text-gray-500 md:grid-cols-2 dark:text-gray-400">
                <li><span class="mr-1 font-medium text-gray-900 dark:text-white">1</span> Reklamda sıralamaya girmek için 30 günde {{ number_format(\App\Services\Ads\Winners::MIN_SPEND, 0, ',', '.') }} TL harcama ve {{ \App\Services\Ads\Winners::MIN_RESULTS }} sonuç gerekir (TRY hesaplar).</li>
                <li><span class="mr-1 font-medium text-gray-900 dark:text-white">2</span> Yalnızca aynı şehirdeki markalar ve aynı sonuç türü karşılaştırılır. Reklam sayıları kişilerin bulunduğu şehre (Google Ads coğrafya raporu) ya da kampanya adındaki şehre, web sayıları sorgudaki şube şehrine yazılır.</li>
                <li><span class="mr-1 font-medium text-gray-900 dark:text-white">3</span> Google Ads’te marka adını içeren anahtar kelimeler sayılmaz.</li>
                <li><span class="mr-1 font-medium text-gray-900 dark:text-white">4</span> Web sitesi sırası hizmetin küme sorgularının ortalama sırasıdır; yarışmak için en az {{ \App\Services\Ads\Winners::WEB_MIN_IMPRESSIONS }} gösterim, ilk sayfa (ortalama sıra en çok {{ (int) \App\Services\Ads\Winners::WEB_MAX_POSITION }}) ve tık gerekir; İşletme Profili en az {{ \App\Services\Ads\Winners::GBP_MIN_REVIEWS }} yorumla ve hizmet profilde listeliyse yarışır.</li>
            </ol>
        </details>
    @endif
</div>
