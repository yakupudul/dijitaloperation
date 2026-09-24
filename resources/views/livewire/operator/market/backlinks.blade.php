@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
    $input = 'mt-1 w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900';
    $num = fn ($v): string => $v === null ? '—' : number_format((float) $v, 0, ',', '.');
@endphp
<div class="space-y-5">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Backlink fırsatları</h1>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">Markanın link profili, rakiplerle karşılaştırma, yeni ve kaybedilen yönlendiren alan adları; en az iki onaylı rakibe link veren ama markaya vermeyen kaliteli siteler ve Türkiye rehber/atıf listesi. Veri DataForSEO Backlinks'ten (ücretli, aylık tavan içinde). Sağlık markalarında içerik bilgilendirici kalmalı.</p>
    </div>

    @if ($message !== '')<p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $message }}</p>@endif
    @if ($error !== '')<p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-800">{{ $error }}</p>@endif

    <div class="flex flex-wrap items-end gap-3">
        <label class="block min-w-64 text-sm"><span class="text-xs text-gray-500">Marka</span>
            <select wire:model.live="brand" class="{{ $input }}">@foreach ($brands as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach</select>
        </label>
        @if ($settings !== null && $isAdmin)
            <x-ta.button type="button" wire:click="refresh" size="sm">Şimdi yenile (≈ {{ number_format($estimate, 2) }} USD)</x-ta.button>
            <x-ta.button type="button" wire:click="toggleEnabled" size="sm" variant="outline">{{ $settings->backlinks_enabled ? 'Aylık yenileme açık' : 'Aylık yenileme kapalı' }}</x-ta.button>
        @endif
        <x-ta.button type="button" wire:click="addCitations" size="sm" variant="outline">Rehber listesini ekle</x-ta.button>
        @if ($settings !== null)
            <span class="text-xs text-gray-500">Bu ay {{ number_format($spent, 2) }} / {{ number_format($settings->monthly_usd, 2) }} USD · son yenileme {{ $settings->backlinks_refreshed_at?->format('d.m.Y') ?? '—' }} · {{ count($competitors) }} onaylı rakip</span>
        @endif
    </div>

    @if ($latest->isNotEmpty())
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Profil karşılaştırması</h2>
            <table class="mt-2 w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-500"><th class="py-1">Alan adı</th><th>Puan</th><th>Yönlendiren alan adı</th><th>Backlink</th><th>Spam</th><th>Tarih</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($latest->sortBy(fn ($r) => $r['now']->is_competitor) as $target => $row)
                        <tr @class(['font-semibold' => ! $row['now']->is_competitor])>
                            <td class="py-1.5">{{ $target }}{{ $row['now']->is_competitor ? '' : ' (marka)' }}</td>
                            <td>{{ $num($row['now']->rank) }}</td>
                            <td>{{ $num($row['now']->referring_domains) }}@if ($row['before'] !== null) <span class="text-xs text-gray-500">({{ ($row['now']->referring_domains - $row['before']->referring_domains) >= 0 ? '+' : '' }}{{ $row['now']->referring_domains - $row['before']->referring_domains }})</span>@endif</td>
                            <td>{{ $num($row['now']->backlinks) }}</td><td>{{ $row['now']->spam_score ?? '—' }}</td>
                            <td class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($row['now']->observed_on)->format('d.m.Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Yeni yönlendiren alan adları (45 gün)</h2>
            @forelse ($newDomains as $row)
                <p class="mt-1 flex justify-between text-sm"><span>{{ $row->domain }}</span><span class="text-xs text-gray-500">puan {{ $row->rank ?? '—' }}{{ $row->dofollow ? '' : ' · nofollow' }}</span></p>
            @empty
                <p class="mt-2 text-sm text-gray-500">Yok.</p>
            @endforelse
        </section>
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Kaybedilenler (90 gün)</h2>
            @forelse ($lostDomains as $row)
                <p class="mt-1 flex justify-between text-sm"><span>{{ $row->domain }}</span><span class="text-xs text-gray-500">puan {{ $row->rank ?? '—' }} · {{ \Illuminate\Support\Carbon::parse($row->lost_on)->format('d.m.Y') }}</span></p>
            @empty
                <p class="mt-2 text-sm text-gray-500">Yok.</p>
            @endforelse
        </section>
    </div>

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90">Fırsatlar</h2>
            <select wire:model.live="status" class="rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="open">Açık (yeni, iletişim, bekliyor)</option>
                @foreach ($statuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                <option value="all">Hepsi</option>
            </select>
        </div>
        @if ($opportunities->isEmpty())
            <p class="mt-2 text-sm text-gray-500">Fırsat yok. Yenileme en az iki onaylı rakip ister; rehber listesi ücretsiz eklenir.</p>
        @else
            <div class="mt-2 divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($opportunities as $row)
                    <div wire:key="opp-{{ $row->id }}" class="py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ $row->name ?: $row->domain }}</span>
                                <span class="ml-1 text-xs text-gray-500">{{ $row->source === 'citation' ? 'rehber/atıf' : $row->competitors_linking.' rakibe link veriyor · puan '.($row->rank ?? '—').' · spam '.($row->spam_score ?? '—') }}</span>
                                @if ($row->link_found !== null)<span @class(['ml-1 text-xs', 'text-emerald-600' => $row->link_found, 'text-rose-600' => ! $row->link_found])>{{ $row->link_found ? 'link bulundu' : 'link yok' }}{{ $row->last_checked_at ? ' · '.\Illuminate\Support\Carbon::parse($row->last_checked_at)->format('d.m.Y') : '' }}</span>@endif
                            </div>
                            <select wire:change="setStatus({{ $row->id }}, $event.target.value)" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                                @foreach ($statuses as $key => $label)<option value="{{ $key }}" @selected($row->status === $key)>{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            <input type="url" wire:model="edit.{{ $row->id }}.link_url" placeholder="Linkin olacağı sayfa (URL)" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                            <input type="text" wire:model="edit.{{ $row->id }}.contact" placeholder="İletişim" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                            <input type="text" wire:model="edit.{{ $row->id }}.note" placeholder="Not" class="rounded-lg border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                        </div>
                        <div class="mt-1 flex gap-2">
                            <button type="button" wire:click="saveDetails({{ $row->id }})" class="text-xs text-brand-600 hover:underline">Kaydet</button>
                            @if ($row->link_url)<button type="button" wire:click="checkLink({{ $row->id }})" class="text-xs text-brand-600 hover:underline">Linki kontrol et</button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
