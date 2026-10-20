@php
    $select = 'h-8 w-full rounded-lg border border-gray-200 bg-white py-0 pl-3 text-[13px] text-gray-800 sm:w-auto dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    // Heat ramp in the second (chart) colour, so Ayarlar › Görünüm reaches it: deeper = busier.
    $ramp = ['bg-second/10', 'bg-second/25', 'bg-second/45', 'bg-second/70', 'bg-second'];
    $typeLabels = \App\Services\Ads\AdServiceStats::TYPE_LABELS;
    $money = fn ($v, $cur = 'TRY') => $v === null ? '—' : number_format((float) $v, 0, ',', '.').' '.($cur ?: 'TRY');
    $logo = fn (?string $name): ?string => $name !== null ? ($logos[$name] ?? null) : null;
@endphp

<div>
    @include('livewire.operator.winners.partials.nav', ['current' => 'operator.libraries'])

    <div class="space-y-10">
        <header>
            <h1 class="page-title">Metinler ve takvim</h1>
            <p class="page-sub">Kazanan kampanyalardan biriken metinler ve hedeflemeler, hizmetlerin mevsimi ve hangi markanın hangi hizmette olduğu.</p>
        </header>

        <div class="-mt-4 space-y-4">
            <div class="no-scrollbar flex gap-1.5 overflow-x-auto" role="tablist" data-testid="library-tabs">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" @class(['seg', 'seg-active' => $tab === $key])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <select wire:model.live="sector" aria-label="Sektör" class="{{ $select }}">@if (in_array($tab, ['metin', 'hedefleme'], true))<option value="">Tüm sektörler</option>@endif @foreach ($sectors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                @if (in_array($tab, ['metin', 'hedefleme'], true))
                    <select wire:model.live="service" aria-label="Hizmet" class="{{ $select }}"><option value="">Tüm hizmetler</option>@foreach ($services as $s)<option value="{{ $s['id'] }}">{{ $s['name'] }}</option>@endforeach</select>
                @endif
            </div>
        </div>

        @if ($tab === 'metin')
            <section data-testid="library-texts">
                <div class="section-head">
                    <h2 class="section-title">Kazanan metinler <span class="section-count">{{ count($items) }}</span></h2>
                    <span class="ml-auto text-[13px] text-gray-500">En son kaydedilen önce</span>
                </div>
                <ul class="card">
                    @forelse ($items as $x)
                        @php $p = $x['payload']; @endphp
                        <li class="card-row flex-wrap items-start sm:flex-nowrap" wire:key="text-{{ $x['id'] }}" x-data="{ text: @js(trim(($p['title'] ?? '')."\n".($p['body'] ?? ''))) }">
                            <x-ta.brand-avatar :name="$x['brand']" :logo="$logo($x['brand'])" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $x['service'] ?: 'Hizmet yok' }} <span class="font-normal text-gray-500">{{ $x['brand'] }}, Meta, {{ $typeLabels[$x['type']] ?? $x['type'] }}, {{ ($p['video'] ?? false) ? 'video' : 'görsel' }}</span></p>
                                @if (($p['title'] ?? '') !== '')<p class="mt-2 text-sm font-medium text-gray-900 dark:text-white"><span class="sr-only">Başlık: </span>{{ $p['title'] }}</p>@endif
                                <p class="mt-1 max-w-3xl whitespace-pre-line break-words text-sm leading-relaxed text-gray-700 dark:text-gray-300">{{ $p['body'] ?? '' }}</p>
                            </div>
                            <div class="flex w-full shrink-0 flex-wrap items-center gap-2 sm:w-auto sm:flex-col sm:items-end">
                                <p class="text-[13px] tabular-nums text-gray-900 sm:text-right dark:text-white"><span class="font-medium">{{ $money($p['cpr'] ?? $x['cpr'], $x['currency']) }}</span> <span class="text-gray-500">sonuç başı</span></p>
                                <span class="flex-1 sm:hidden"></span>
                                <span class="flex gap-1.5">
                                    <button type="button" x-on:click="navigator.clipboard.writeText(text)" class="btn">Kopyala</button>
                                    <button type="button" wire:click="remove({{ $x['id'] }})" wire:confirm="Listeden çıkarılsın mı?" class="btn-quiet hover:text-error-700">Çıkar</button>
                                </span>
                            </div>
                        </li>
                    @empty
                        <li><x-ta.empty-state icon="none" title="Henüz kayıt yok." message="Strateji öner’deki kazanan kampanyalardan “Metni kaydet” ile eklenir."><a href="{{ route('operator.meta-strategy') }}" wire:navigate class="btn" data-empty-action>Strateji öner’e git</a></x-ta.empty-state></li>
                    @endforelse
                </ul>
            </section>
        @elseif ($tab === 'hedefleme')
            <section data-testid="library-targetings">
                <div class="section-head"><h2 class="section-title">Kazanan hedeflemeler <span class="section-count">{{ count($items) }}</span></h2></div>
                @if ($items === [])
                    <div class="card">
                        <x-ta.empty-state icon="none" title="Henüz kayıt yok." message="Strateji öner’deki kazananlardan “Hedeflemeyi kaydet” ile eklenir."><a href="{{ route('operator.meta-strategy') }}" wire:navigate class="btn" data-empty-action>Strateji öner’e git</a></x-ta.empty-state>
                    </div>
                @else
                    <div class="card overflow-x-auto">
                        <table class="data-table min-w-[56rem]">
                            <thead><tr><th>Hizmet</th><th>Konum</th><th>Yaş, cinsiyet</th><th>Kitle</th><th>Yerleşim</th><th>Kaynak</th><th class="text-right!">Sonuç başı</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($items as $x)
                                    @php $t = $x['payload']['targeting'] ?? []; @endphp
                                    <tr wire:key="tg-{{ $x['id'] }}" class="align-top">
                                        <td class="whitespace-nowrap font-medium text-gray-900 dark:text-white">{{ $x['service'] ?: '—' }}</td>
                                        <td class="text-[13px] text-gray-700 dark:text-gray-300">{{ implode(', ', $t['locations'] ?? []) ?: '—' }}</td>
                                        <td class="text-[13px] text-gray-700 dark:text-gray-300">{{ $t['age'] ?? '—' }}, {{ $t['genders'] ?? '—' }}</td>
                                        <td class="text-[13px] text-gray-700 dark:text-gray-300">{{ implode(', ', array_merge(($t['advantage'] ?? false) ? ['Advantage+'] : [], array_slice($t['interests'] ?? [], 0, 6))) ?: '—' }}</td>
                                        <td class="text-[13px] text-gray-700 dark:text-gray-300">{{ $t['placements'] ?? '—' }}</td>
                                        <td class="text-[13px] text-gray-500">{{ $x['brand'] }}, {{ $x['campaign'] }}</td>
                                        <td class="whitespace-nowrap text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($x['cpr'], $x['currency']) }} <span class="block text-xs font-normal text-gray-500">/ {{ $typeLabels[$x['type']] ?? $x['type'] }}</span></td>
                                        <td class="text-right"><button type="button" wire:click="remove({{ $x['id'] }})" wire:confirm="Listeden çıkarılsın mı?" class="btn-quiet hover:text-error-700">Çıkar</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @elseif ($tab === 'mevsim')
            <section data-testid="library-season">
                <div class="section-head">
                    <h2 class="section-title" title="Hizmetin küme sorgularının aylık arama hacmi">Hizmet talebi, aylara göre</h2>
                    <span class="ml-auto text-[13px] text-gray-500">Koyu yoğun ay, çerçeveli bu ay</span>
                </div>
                @if (! $season || $season['rows'] === [])
                    <div class="card">
                        <x-ta.empty-state icon="none" title="Aylık arama hacmi yok" message="Bu sektörde aylık arama hacmi verisi yok. Hacimler ayda bir çekilir."><a href="{{ route('operator.winners') }}" wire:navigate class="btn" data-empty-action>Kazananlara git</a></x-ta.empty-state>
                    </div>
                @else
                    @if ($season['note'])
                        <div class="card mb-4">
                            <div class="card-row">
                                <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg></span>
                                <p class="min-w-0 flex-1 text-sm text-gray-900 dark:text-white">{{ $season['note'] }}</p>
                            </div>
                        </div>
                    @endif
                    <div class="card overflow-x-auto">
                        <table class="w-full min-w-[48rem] text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-xs text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]">
                                    <th class="px-4 py-2.5 text-left font-medium">Hizmet</th>
                                    @foreach ($months as $i => $m)<th @class(['px-0.5 py-2.5 font-medium', 'text-gray-900 dark:text-white' => $i === $season['now']])>{{ $m }}</th>@endforeach
                                    <th class="px-4 py-2.5 text-left font-medium">Tepe</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($season['rows'] as $row)
                                    <tr class="border-b border-gray-200 last:border-b-0 dark:border-gray-800">
                                        <td class="px-4 py-2 font-medium whitespace-nowrap text-gray-900 dark:text-white">{{ $row['service'] }}</td>
                                        @foreach ($row['levels'] as $i => $level)
                                            <td class="px-0.5 py-2"><div title="{{ number_format($row['volumes'][$i], 0, ',', '.') }} arama" @class(['h-7 rounded', $ramp[$level], 'ring-2 ring-gray-900 ring-offset-1 dark:ring-white dark:ring-offset-gray-900' => $i === $season['now']])></div></td>
                                        @endforeach
                                        <td class="px-4 py-2 text-[13px] text-gray-500">{{ $row['peak'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @else
            <section data-testid="library-map">
                <div class="section-head">
                    <h2 class="section-title" title="0–4: web sitesi, Google Ads, Meta, İşletme Profili. Boş hücre: markada bu hizmet yok.">Hangi marka hangi hizmette kaç kanalda</h2>
                    <span class="ml-auto text-[13px] text-gray-500">Kesik çerçeve fırsat: hizmet var, hiçbir kanalda yok</span>
                </div>
                @if (! $map || $map['rows'] === [])
                    <div class="card">
                        <x-ta.empty-state icon="none" title="Marka ya da hizmet yok" message="Bu sektörde marka ya da hizmet yok."><a href="{{ route('operator.brands') }}" wire:navigate class="btn" data-empty-action>Markalara git</a></x-ta.empty-state>
                    </div>
                @else
                    <div class="card w-fit max-w-full overflow-x-auto">
                        <table class="text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50 text-xs text-gray-500 dark:border-gray-800 dark:bg-white/[0.02]">
                                    <th class="px-4 py-2.5 text-left align-middle font-medium">Hizmet</th>
                                    @foreach ($map['brands'] as $b)<th class="px-1 py-2.5 align-middle font-medium"><span class="mx-auto block max-w-[4rem] truncate" title="{{ $b }}">{{ $b }}</span></th>@endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($map['rows'] as $row)
                                    <tr class="border-b border-gray-200 last:border-b-0 dark:border-gray-800">
                                        <td class="px-4 py-1.5 font-medium whitespace-nowrap text-gray-900 dark:text-white">{{ $row['service'] }}</td>
                                        @foreach ($row['cells'] as $v)
                                            <td class="px-1 py-1.5">
                                                @if ($v === null)
                                                    <div class="h-8 w-16"></div>
                                                @elseif ($v === 0)
                                                    <div class="flex h-8 w-16 items-center justify-center rounded border border-dashed border-accent bg-accent-soft text-xs font-medium text-gray-900 dark:text-white">fırsat</div>
                                                @else
                                                    <div @class(['flex h-8 w-16 items-center justify-center rounded text-xs font-medium', $ramp[$v], 'text-white' => $v >= 3, 'text-gray-900 dark:text-white' => $v < 3])>{{ $v }}</div>
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
</div>
