@php
    $panel = 'rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $select = 'mt-1 block rounded-lg border-white/20 bg-white/10 py-1.5 text-sm text-white [&>option]:text-gray-900';
    $medal = ['bg-[#d8b45e] text-[#14171f]', 'bg-[#d7dbe2] text-[#14171f]', 'bg-[#d9a77b] text-[#14171f]'];
@endphp

<div class="space-y-5">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.winners'])

    @if (! $ready || ! $view)
        <p class="{{ $panel }} p-4 text-sm text-gray-500">Kazananlar tabloları henüz hazır değil.</p>
    @else
        <section class="relative overflow-hidden rounded-2xl bg-[#14171f] px-6 py-7 text-white" data-testid="winner-service-hero">
            <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-[#d8b45e]/15 blur-2xl"></div>
            <p class="text-xs text-white/60"><a href="{{ route('operator.winners') }}" wire:navigate class="hover:text-[#e9cf8a]">Kazananlar</a>@if ($view['sector']) / {{ $view['sector'] }}@endif / {{ $view['name'] }}</p>
            <h1 class="mt-2 font-serif text-4xl tracking-tight">{{ $view['name'] }}</h1>
            <p class="mt-1 text-sm text-white/70">{{ $view['competing'] }} marka yarışıyor · eşiği geçen {{ $view['eligible'] }} · son 30 gün · {{ $this->city !== '' ? $this->city : 'Türkiye geneli' }}</p>
            <div class="mt-5 flex flex-wrap items-end gap-4">
                <div>
                    <p class="text-xs text-white/60">Ölçü</p>
                    <div class="mt-1 flex gap-0.5 rounded-lg bg-white/10 p-0.5">
                        @foreach ($measures as $key => $label)
                            <button type="button" wire:click="$set('measure', '{{ $key }}')" @class(['rounded-md px-3 py-1 text-sm font-medium', 'bg-[#d8b45e] text-[#14171f]' => $measure === $key, 'text-white/70' => $measure !== $key])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <label class="text-xs text-white/60">Şehir
                    <select wire:model.live="city" class="{{ $select }}"><option value="">Türkiye geneli</option>@foreach ($view['cities'] as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
                </label>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4" data-testid="winner-podium">
            @foreach ($view['podium'] as $key => $p)
                <section class="{{ $panel }} p-5" wire:key="pod-{{ $key }}">
                    <div class="flex items-baseline gap-2">
                        <h2 class="font-semibold text-gray-900 dark:text-white">{{ $p['label'] }}</h2>
                        <span class="text-xs text-gray-500">{{ $p['competing'] }} marka</span>
                    </div>
                    @forelse ($p['top'] as $i => $t)
                        @if ($i === 0)
                            <div class="mt-3 rounded-xl bg-[#fbf3dd] p-4 dark:bg-[#d8b45e]/10">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-full text-sm font-bold {{ $medal[0] }}">1</span>
                                    <p class="font-serif text-lg text-gray-900 dark:text-white">{{ $t['brand'] }}</p>
                                </div>
                                <p class="mt-2 text-sm font-semibold tabular-nums text-[#8a6a1f] dark:text-[#e9cf8a]">{{ $t['value'] }}</p>
                                <p class="mt-1 truncate text-xs text-gray-600 dark:text-gray-400" title="{{ $t['what'] }}">{{ $t['what'] }}</p>
                            </div>
                        @else
                            <div class="mt-2 flex items-center gap-2 px-1 text-sm">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold {{ $medal[$i] }}">{{ $i + 1 }}</span>
                                <span class="min-w-0 flex-1 truncate text-gray-800 dark:text-gray-200">{{ $t['brand'] }}</span>
                                <span class="text-xs tabular-nums text-gray-500">{{ $t['value'] }}</span>
                            </div>
                        @endif
                    @empty
                        <p class="mt-3 text-sm text-gray-500">Eşiği geçen marka yok.</p>
                    @endforelse
                </section>
            @endforeach
        </div>

        <section class="{{ $panel }} overflow-hidden" data-testid="winner-ranking">
            <div class="flex flex-wrap items-baseline gap-2 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <h2 class="font-serif text-xl text-gray-900 dark:text-white">Genel sıralama</h2>
                <p class="text-xs text-gray-500">Her kanalda sıraya göre puan (lider {{ \App\Services\Ads\Winners::CHANNEL_POINTS }}); kanalı olmayan o kanaldan puan almaz.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[60rem] text-sm">
                    <thead class="bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.02]">
                        <tr><th class="px-5 py-2.5">Sıra</th><th class="px-3 py-2.5">Marka</th>@foreach ($channels as $label)<th class="px-3 py-2.5">{{ $label }}</th>@endforeach<th class="px-3 py-2.5 text-right">Puan</th><th class="px-5 py-2.5 text-right">7 gün</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 tabular-nums dark:divide-gray-700">
                        @forelse ($view['ranking'] as $r)
                            <tr wire:key="rank-{{ $r['brand_id'] }}" class="text-gray-700 dark:text-gray-300">
                                <td class="px-5 py-3"><span @class(['flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold', $medal[$r['rank'] - 1] ?? 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'])>{{ $r['rank'] }}</span></td>
                                <td class="px-3 py-3 font-semibold text-gray-900 dark:text-white">{{ $r['brand'] }}</td>
                                @foreach ($channels as $key => $label)
                                    <td class="px-3 py-3 text-xs">@if (isset($r['values'][$key])){{ $r['values'][$key] }} <span class="text-gray-400">· {{ $r['channels'][$key]['rank'] }}.</span>@else<span class="text-gray-400">—</span>@endif</td>
                                @endforeach
                                <td class="px-3 py-3 text-right text-base font-semibold text-gray-900 dark:text-white">{{ $r['score'] }}</td>
                                <td @class(['px-5 py-3 text-right text-xs font-semibold', 'text-emerald-600' => ($r['move'] ?? 0) > 0, 'text-rose-600' => ($r['move'] ?? 0) < 0, 'text-gray-400' => ($r['move'] ?? 0) === 0])>
                                    {{ $r['move'] === null ? '—' : ($r['move'] > 0 ? '↑ '.$r['move'] : ($r['move'] < 0 ? '↓ '.abs($r['move']) : '→')) }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-5 py-6 text-center text-sm text-gray-500">Eşiği geçen marka yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-4 xl:grid-cols-3">
            <section class="{{ $panel }} p-5 xl:col-span-2" data-testid="winner-recipes">
                <h2 class="font-serif text-xl text-gray-900 dark:text-white">Kazananın tarifi</h2>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    @forelse ($view['recipes'] as $rc)
                        <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/[0.03]">
                            <p class="text-xs font-semibold uppercase tracking-wide text-[#a8842f]">{{ $rc['label'] }} · {{ $rc['brand'] }}</p>
                            <p class="mt-1 text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $rc['text'] }}</p>
                            @if ($rc['channel'] === 'meta' && $view['meta_type'] && in_array($view['meta_type'], ['leads', 'messages', 'purchases'], true))
                                <a href="{{ route('operator.meta-strategy', ['hizmet' => $view['service_id'], 'tur' => $view['meta_type'], 'sehir' => $this->city]) }}" wire:navigate class="mt-2 inline-block text-xs font-semibold text-brand-600 hover:underline">Meta tarifini Strateji öner’de aç →</a>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Henüz lider yok.</p>
                    @endforelse
                </div>
            </section>
            <div class="space-y-4">
                <section class="{{ $panel }} p-5" data-testid="winner-moves">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Yükselenler ve düşenler</h2>
                    @if ($view['risers'] === [] && $view['fallers'] === [])
                        <p class="mt-2 text-sm text-gray-500">{{ $this->city !== '' ? 'Şehir görünümünde geçmiş tutulmuyor.' : 'Bir hafta önceki sıralama birikince burada görünür.' }}</p>
                    @else
                        <div class="mt-2 grid grid-cols-2 gap-3 text-sm">
                            <ul class="space-y-1">@foreach ($view['risers'] as $x)<li class="flex justify-between gap-2"><span class="truncate">{{ $x['brand'] }}</span><span class="font-semibold text-emerald-600">↑ {{ $x['move'] }}</span></li>@endforeach</ul>
                            <ul class="space-y-1">@foreach ($view['fallers'] as $x)<li class="flex justify-between gap-2"><span class="truncate">{{ $x['brand'] }}</span><span class="font-semibold text-rose-600">↓ {{ abs($x['move']) }}</span></li>@endforeach</ul>
                        </div>
                    @endif
                </section>
                <section class="{{ $panel }} p-5" data-testid="winner-feed">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Liderlik değişimleri</h2>
                    @forelse ($view['feed'] as $f)
                        <p class="mt-2 text-sm text-gray-700 dark:text-gray-300"><span class="mr-2 text-xs font-semibold text-gray-500">{{ \Carbon\CarbonImmutable::parse($f['date'])->format('d.m') }}</span>{{ $f['text'] }}</p>
                    @empty
                        <p class="mt-2 text-sm text-gray-500">Son 60 günde lider değişmedi.</p>
                    @endforelse
                </section>
            </div>
        </div>

        <section class="{{ $panel }} p-5" data-testid="winner-rules">
            <h2 class="font-semibold text-gray-900 dark:text-white">Adil yarış kuralları</h2>
            <ol class="mt-2 grid gap-2 text-sm text-gray-600 md:grid-cols-2 dark:text-gray-400">
                <li><span class="mr-1 font-semibold text-[#a8842f]">1</span> Reklamda sıralamaya girmek için 30 günde {{ number_format(\App\Services\Ads\Winners::MIN_SPEND, 0, ',', '.') }} TL harcama ve {{ \App\Services\Ads\Winners::MIN_RESULTS }} sonuç gerekir (TRY hesaplar).</li>
                <li><span class="mr-1 font-semibold text-[#a8842f]">2</span> Yalnızca aynı sonuç türü ve aynı şehir ya da ülke karşılaştırılır.</li>
                <li><span class="mr-1 font-semibold text-[#a8842f]">3</span> Google Ads’te marka adını içeren anahtar kelimeler sayılmaz.</li>
                <li><span class="mr-1 font-semibold text-[#a8842f]">4</span> Web sitesi sırası hizmetin küme sorgularının ortalama sırasıdır (en az {{ \App\Services\Ads\Winners::WEB_MIN_IMPRESSIONS }} gösterim); İşletme Profili en az {{ \App\Services\Ads\Winners::GBP_MIN_REVIEWS }} yorumla ve hizmet profilde listeliyse yarışır.</li>
            </ol>
        </section>
    @endif
</div>
