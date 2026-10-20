@php
    $select = 'mt-1 block rounded-lg border-gray-200 py-1.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    // Neutral heat ramp (dark = busy); colour is kept for status only.
    $ramp = ['bg-gray-100 dark:bg-white/5', 'bg-gray-200 dark:bg-white/15', 'bg-gray-300 dark:bg-white/25', 'bg-gray-500 dark:bg-white/45', 'bg-gray-800 dark:bg-white/70'];
    $typeLabels = \App\Services\Ads\AdServiceStats::TYPE_LABELS;
    $money = fn ($v, $cur = 'TRY') => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.($cur ?: 'TRY');
@endphp

<div class="space-y-8">
    <div class="space-y-1">
        <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="Kazanan kampanyalardan biriken metinler ve hedeflemeler, hizmetlerin mevsimi ve hangi markanın hangi hizmette nerede olduğu.">Kütüphaneler</h1>
    </div>
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.libraries'])

    <div class="flex flex-wrap items-end gap-3">
        <div class="flex max-w-full flex-wrap gap-0.5 rounded-lg bg-gray-100 p-0.5 dark:bg-white/[0.04]" data-testid="library-tabs">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-sm font-medium', 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' => $tab === $key, 'text-gray-600 dark:text-gray-400' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>
        <span class="hidden flex-1 sm:block"></span>
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
        <div class="divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800" data-testid="library-texts">
            @forelse ($items as $x)
                @php $p = $x['payload']; @endphp
                <article class="flex min-w-0 flex-col py-4" wire:key="text-{{ $x['id'] }}" x-data="{ text: @js(trim(($p['title'] ?? '')."\n".($p['body'] ?? ''))) }">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $x['service'] ?: 'Hizmet yok' }} <span class="ml-1 text-xs font-normal text-gray-500">{{ $x['brand'] }}, Meta, {{ $typeLabels[$x['type']] ?? $x['type'] }}, {{ ($p['video'] ?? false) ? 'video' : 'görsel' }}</span></p>
                    <p class="mt-2 max-w-3xl whitespace-pre-line break-words text-sm leading-relaxed text-gray-800 dark:text-gray-200">{{ $p['body'] ?? '' }}</p>
                    @if (($p['title'] ?? '') !== '')<p class="mt-2 text-sm"><span class="text-gray-500">Başlık:</span> <span class="font-medium text-gray-900 dark:text-white">{{ $p['title'] }}</span></p>@endif
                    <div class="mt-2 flex items-center gap-3 text-xs">
                        <span class="font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($p['cpr'] ?? $x['cpr'], $x['currency']) }}</span><span class="text-gray-500">sonuç başı</span>
                        <span class="flex-1"></span>
                        <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="link-neutral">Kopyala</button>
                        <button type="button" wire:click="remove({{ $x['id'] }})" wire:confirm="Kütüphaneden çıkarılsın mı?" class="text-gray-500 hover:text-error-700">Çıkar</button>
                    </div>
                </article>
            @empty
                <x-ta.empty-state title="Kütüphane boş." message="Strateji öner’deki kazanan kampanyalardan “Metni kütüphaneye kaydet” ile eklenir."><a href="{{ route('operator.meta-strategy') }}" wire:navigate class="link-neutral text-sm" data-empty-action>Strateji öner’e git</a></x-ta.empty-state>
            @endforelse
        </div>
    @elseif ($tab === 'hedefleme')
        <section data-testid="library-targetings">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="text-left text-xs text-gray-500">
                        <tr class="border-b border-gray-200 dark:border-gray-800"><th class="py-2 pr-4 font-normal">Hizmet</th><th class="px-3 py-2 font-normal">Konum</th><th class="px-3 py-2 font-normal">Yaş, cinsiyet</th><th class="px-3 py-2 font-normal">Kitle</th><th class="px-3 py-2 font-normal">Yerleşim</th><th class="px-3 py-2 font-normal">Kaynak</th><th class="px-3 py-2 text-right font-normal">Sonuç başı</th><th class="px-3 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 border-b border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                        @forelse ($items as $x)
                            @php $t = $x['payload']['targeting'] ?? []; @endphp
                            <tr wire:key="tg-{{ $x['id'] }}" class="align-top text-gray-700 dark:text-gray-300">
                                <td class="py-3 pr-4 font-medium text-gray-900 dark:text-white">{{ $x['service'] ?: '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ implode(', ', $t['locations'] ?? []) ?: '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ $t['age'] ?? '—' }}, {{ $t['genders'] ?? '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ implode(', ', array_merge(($t['advantage'] ?? false) ? ['Advantage+'] : [], array_slice($t['interests'] ?? [], 0, 6))) ?: '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ $t['placements'] ?? '—' }}</td>
                                <td class="px-3 py-3 text-xs">{{ $x['brand'] }}, {{ $x['campaign'] }}</td>
                                <td class="px-3 py-3 text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($x['cpr'], $x['currency']) }} <span class="text-xs font-normal text-gray-500">/ {{ $typeLabels[$x['type']] ?? $x['type'] }}</span></td>
                                <td class="px-3 py-3 text-right"><button type="button" wire:click="remove({{ $x['id'] }})" wire:confirm="Kütüphaneden çıkarılsın mı?" class="text-xs text-gray-500 hover:text-error-700">Çıkar</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-6 text-center text-sm text-gray-500">Kütüphane boş. Strateji öner’deki kazananlardan "Hedeflemeyi kaydet" ile eklenir. <a href="{{ route('operator.meta-strategy') }}" wire:navigate class="link-neutral" data-empty-action>Strateji öner’e git</a></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif ($tab === 'mevsim')
        <section data-testid="library-season">
            <h2 class="section-title" title="Hizmetin küme sorgularının aylık arama hacmi">Hizmet talebi, aylara göre</h2>
            <p class="text-xs text-gray-500">Koyu yoğun ay, çerçeveli bu ay</p>
            @if (! $season || $season['rows'] === [])
                <p class="mt-4 text-sm text-gray-500">Bu sektörde aylık arama hacmi verisi yok. Hacimler ayda bir çekilir. <a href="{{ route('operator.winners') }}" wire:navigate class="link-neutral" data-empty-action>Kazananlara git</a></p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full min-w-[48rem] text-sm">
                        <thead><tr class="text-xs text-gray-500"><th class="py-1 pr-3 text-left font-normal">Hizmet</th>@foreach ($months as $i => $m)<th @class(['px-0.5 py-1 font-normal', 'font-semibold text-gray-900 dark:text-white' => $i === $season['now']])>{{ $m }}</th>@endforeach<th class="pl-3 text-left font-normal">Tepe</th></tr></thead>
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
                @if ($season['note'])<p class="mt-4 text-sm text-gray-700 dark:text-gray-300">{{ $season['note'] }}</p>@endif
            @endif
        </section>
    @else
        <section data-testid="library-map">
            <h2 class="section-title" title="0–4: web sitesi, Google Ads, Meta, İşletme Profili. Boş hücre: markada bu hizmet yok.">Hangi marka hangi hizmette kaç kanalda</h2>
            <p class="text-xs text-gray-500">Kesik çerçeveli hücre fırsat: markanın hizmeti var ama hiçbir kanalda yok</p>
            @if (! $map || $map['rows'] === [])
                <p class="mt-4 text-sm text-gray-500">Bu sektörde marka ya da hizmet yok. <a href="{{ route('operator.brands') }}" wire:navigate class="link-neutral" data-empty-action>Markalara git</a></p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="text-sm">
                        <thead><tr class="text-xs text-gray-500"><th class="py-1 pr-3 text-left font-normal">Hizmet</th>@foreach ($map['brands'] as $b)<th class="px-1 py-1 font-normal"><span class="block max-w-[6rem] truncate" title="{{ $b }}">{{ $b }}</span></th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($map['rows'] as $row)
                                <tr>
                                    <td class="py-1 pr-3 font-medium text-gray-900 dark:text-white">{{ $row['service'] }}</td>
                                    @foreach ($row['cells'] as $v)
                                        <td class="px-1 py-1">
                                            @if ($v === null)
                                                <div class="h-8 w-16"></div>
                                            @elseif ($v === 0)
                                                <div class="flex h-8 w-16 items-center justify-center rounded border border-dashed border-warning-500 text-xs text-warning-800 dark:text-warning-300">fırsat</div>
                                            @else
                                                <div @class(['flex h-8 w-16 items-center justify-center rounded text-xs font-medium', $ramp[$v], 'text-white' => $v >= 3, 'text-gray-900' => $v < 3])>{{ $v }}</div>
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
