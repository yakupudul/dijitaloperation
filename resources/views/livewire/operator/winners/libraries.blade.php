@php
    $panel = 'rounded-2xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700';
    $select = 'mt-1 block rounded-lg border-gray-300 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $chip = 'rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 dark:bg-white/[0.06] dark:text-gray-300';
    $ramp = ['bg-gray-100 dark:bg-white/5', 'bg-[#dbe3ff] dark:bg-indigo-500/20', 'bg-[#a9baff] dark:bg-indigo-500/40', 'bg-[#6f86f9] dark:bg-indigo-500/60', 'bg-[#3641f5] dark:bg-indigo-500'];
    $typeLabels = \App\Services\Ads\AdServiceStats::TYPE_LABELS;
    $money = fn ($v, $cur = 'TRY') => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.($cur ?: 'TRY');
@endphp

<div class="space-y-5">
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.libraries'])

    <div class="space-y-1">
        <h1 class="font-serif text-3xl tracking-tight text-gray-900 dark:text-white">Kütüphaneler</h1>
        <p class="text-sm text-gray-500">Kazanan kampanyalardan biriken metinler ve hedeflemeler, hizmetlerin mevsimi ve hangi markanın hangi hizmette nerede olduğu.</p>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <div class="flex gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.04]" data-testid="library-tabs">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' => $tab === $key, 'text-gray-600 dark:text-gray-400' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <span class="flex-1"></span>
        <label class="text-xs text-gray-500">Sektör
            <select wire:model.live="sector" class="{{ $select }}">@if (in_array($tab, ['metin', 'hedefleme'], true))<option value="">Tüm sektörler</option>@endif @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        </label>
        @if (in_array($tab, ['metin', 'hedefleme'], true))
            <label class="text-xs text-gray-500">Hizmet
                <select wire:model.live="service" class="{{ $select }}"><option value="">Tüm hizmetler</option>@foreach ($services as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach</select>
            </label>
        @endif
    </div>

    @if ($tab === 'metin')
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" data-testid="library-texts">
            @forelse ($items as $x)
                @php $p = $x['payload']; @endphp
                <article class="{{ $panel }} flex flex-col p-5" wire:key="text-{{ $x['id'] }}" x-data="{ text: @js(trim(($p['title'] ?? '')."\n".($p['body'] ?? ''))) }">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[#a8842f]">{{ $x['service'] ?: 'Hizmet yok' }}</p>
                    <p class="text-xs text-gray-500">{{ $x['brand'] }} · Meta · {{ $typeLabels[$x['type']] ?? $x['type'] }} · {{ ($p['video'] ?? false) ? 'video' : 'görsel' }}</p>
                    <p class="mt-3 flex-1 whitespace-pre-line text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ $p['body'] ?? '' }}</p>
                    @if (($p['title'] ?? '') !== '')<p class="mt-2 text-sm"><span class="text-gray-500">Başlık:</span> <span class="font-semibold text-gray-900 dark:text-white">{{ $p['title'] }}</span></p>@endif
                    <div class="mt-3 flex items-center gap-3 border-t border-gray-100 pt-3 text-xs dark:border-gray-700">
                        <span class="font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($p['cpr'] ?? $x['cpr'], $x['currency']) }}</span><span class="text-gray-500">sonuç başı</span>
                        <span class="flex-1"></span>
                        <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="font-semibold text-brand-600 hover:underline">Kopyala</button>
                        <button type="button" wire:click="remove({{ $x['id'] }})" wire:confirm="Kütüphaneden çıkarılsın mı?" class="text-gray-500 hover:text-rose-600">Çıkar</button>
                    </div>
                </article>
            @empty
                <p class="{{ $panel }} p-4 text-sm text-gray-500 md:col-span-2 xl:col-span-3">Kütüphane boş. Strateji öner’deki kazanan kampanyalardan "Metni kütüphaneye kaydet" ile eklenir.</p>
            @endforelse
        </div>
    @elseif ($tab === 'hedefleme')
        <section class="{{ $panel }} overflow-hidden" data-testid="library-targetings">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-gray-50 text-left text-[11px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-white/[0.02]">
                        <tr><th class="px-4 py-2.5">Hizmet</th><th class="px-3 py-2.5">Konum</th><th class="px-3 py-2.5">Yaş · cinsiyet</th><th class="px-3 py-2.5">Kitle</th><th class="px-3 py-2.5">Yerleşim</th><th class="px-3 py-2.5">Kaynak</th><th class="px-3 py-2.5 text-right">Sonuç başı</th><th class="px-3 py-2.5"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($items as $x)
                            @php $t = $x['payload']['targeting'] ?? []; @endphp
                            <tr wire:key="tg-{{ $x['id'] }}" class="align-top text-gray-700 dark:text-gray-300">
                                <td class="px-4 py-3 font-medium text-gray-900 dark:text-white">{{ $x['service'] ?: '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ implode(', ', $t['locations'] ?? []) ?: '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ $t['age'] ?? '—' }} · {{ $t['genders'] ?? '—' }}</td>
                                <td class="px-3 py-3"><div class="flex flex-wrap gap-1">@if ($t['advantage'] ?? false)<span class="{{ $chip }}">Advantage+</span>@endif @foreach (array_slice($t['interests'] ?? [], 0, 6) as $i)<span class="{{ $chip }}">{{ $i }}</span>@endforeach</div></td>
                                <td class="px-3 py-3 text-xs">{{ $t['placements'] ?? '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ $x['brand'] }} · {{ $x['campaign'] }}</td>
                                <td class="px-3 py-3 text-right font-semibold tabular-nums text-gray-900 dark:text-white">{{ $money($x['cpr'], $x['currency']) }} <span class="text-xs font-normal text-gray-500">/ {{ $typeLabels[$x['type']] ?? $x['type'] }}</span></td>
                                <td class="px-3 py-3 text-right"><button type="button" wire:click="remove({{ $x['id'] }})" wire:confirm="Kütüphaneden çıkarılsın mı?" class="text-xs text-gray-500 hover:text-rose-600">Çıkar</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">Kütüphane boş. Strateji öner’deki kazananlardan "Hedeflemeyi kaydet" ile eklenir.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif ($tab === 'mevsim')
        <section class="{{ $panel }} p-5" data-testid="library-season">
            <h2 class="font-semibold text-gray-900 dark:text-white">Hizmet talebi, aylara göre</h2>
            <p class="text-xs text-gray-500">Hizmetin küme sorgularının aylık arama hacmi · koyu = yoğun ay · çerçeve = bu ay</p>
            @if (! $season || $season['rows'] === [])
                <p class="mt-4 text-sm text-gray-500">Bu sektörde aylık arama hacmi verisi yok. Hacimler ayda bir çekilir.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-sm">
                        <thead><tr class="text-xs text-gray-500"><th class="py-1 pr-3 text-left font-medium">Hizmet</th>@foreach ($months as $i => $m)<th @class(['px-0.5 py-1 font-medium', 'text-brand-600 font-bold' => $i === $season['now']])>{{ $m }}</th>@endforeach<th class="pl-3 text-left font-medium">Tepe</th></tr></thead>
                        <tbody>
                            @foreach ($season['rows'] as $row)
                                <tr>
                                    <td class="py-1 pr-3 font-medium text-gray-900 dark:text-white">{{ $row['service'] }}</td>
                                    @foreach ($row['levels'] as $i => $level)
                                        <td class="px-0.5 py-1"><div title="{{ number_format($row['volumes'][$i], 0, ',', '.') }} arama" @class(['h-7 rounded', $ramp[$level], 'ring-2 ring-gray-900 dark:ring-white' => $i === $season['now']])></div></td>
                                    @endforeach
                                    <td class="pl-3 text-xs text-gray-500">{{ $row['peak'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($season['note'])<p class="mt-4 rounded-lg bg-[#fbf3dd] px-3 py-2 text-sm text-[#5c4614] dark:bg-[#d8b45e]/10 dark:text-[#e9cf8a]">{{ $season['note'] }}</p>@endif
            @endif
        </section>
    @else
        <section class="{{ $panel }} p-5" data-testid="library-map">
            <h2 class="font-semibold text-gray-900 dark:text-white">Hangi marka hangi hizmette kaç kanalda</h2>
            <p class="text-xs text-gray-500">0–4: web sitesi, Google Ads, Meta, İşletme Profili · kesik çerçeve = markanın hizmeti var ama hiçbir kanalda yok (fırsat) · boş = markada bu hizmet yok</p>
            @if (! $map || $map['rows'] === [])
                <p class="mt-4 text-sm text-gray-500">Bu sektörde marka ya da hizmet yok.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr class="text-xs text-gray-500"><th class="py-1 pr-3 text-left font-medium">Hizmet</th>@foreach ($map['brands'] as $b)<th class="px-1 py-1 font-medium"><span class="block max-w-[6rem] truncate" title="{{ $b }}">{{ $b }}</span></th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($map['rows'] as $row)
                                <tr>
                                    <td class="py-1 pr-3 font-medium text-gray-900 dark:text-white">{{ $row['service'] }}</td>
                                    @foreach ($row['cells'] as $v)
                                        <td class="px-1 py-1">
                                            @if ($v === null)
                                                <div class="h-8 w-16"></div>
                                            @elseif ($v === 0)
                                                <div class="flex h-8 w-16 items-center justify-center rounded border border-dashed border-amber-500 text-[11px] font-semibold text-amber-700 dark:text-amber-300">fırsat</div>
                                            @else
                                                <div @class(['flex h-8 w-16 items-center justify-center rounded text-xs font-semibold', $ramp[$v], 'text-white' => $v >= 3, 'text-gray-900' => $v < 3])>{{ $v }}</div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
