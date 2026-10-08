@php
    $card = 'rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800';
@endphp
<div class="space-y-5" data-connection-health>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Bağlantı sağlığı</h1>
            <p class="mt-1 text-sm text-gray-500">Sistemin her markanın sitesini, reklam hesaplarını ve profillerini görüp göremediği. Gecikmiş veriyi ve taranmamış siteleri sistem her gece kendisi onarır; "Senin işin" satırları erişim ya da hesap seçimi ister.</p>
        </div>
        <button type="button" wire:click="repairNow" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600" data-repair-now>Şimdi onar</button>
    </div>

    @if ($message !== '')
        <p class="rounded-lg bg-brand-50 px-4 py-2 text-sm text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">{{ $message }}</p>
    @endif

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="{{ $card }}"><p class="text-xs text-gray-500">Sistem onarır</p><p class="text-2xl font-semibold tabular-nums text-gray-800 dark:text-white/90">{{ $summary['system'] }}</p></div>
        <div class="{{ $card }}"><p class="text-xs text-gray-500">Senin işin</p><p class="text-2xl font-semibold tabular-nums text-gray-800 dark:text-white/90">{{ $summary['operator'] }}</p></div>
        <div class="{{ $card }}"><p class="text-xs text-gray-500">Sorunlu marka</p><p class="text-2xl font-semibold tabular-nums text-gray-800 dark:text-white/90">{{ $summary['brands'] }}</p></div>
    </div>

    @forelse ($groups as $brand => $rows)
        <section class="{{ $card }}" wire:key="brand-{{ $rows[0]['brand_id'] }}">
            <h2 class="text-sm font-semibold text-gray-800 dark:text-white/90"><a href="{{ route('operator.brand', ['brand' => $rows[0]['brand_id']]) }}" wire:navigate class="hover:text-brand-600">{{ $brand }}</a></h2>
            <ul class="mt-2 divide-y divide-gray-100 text-sm dark:divide-gray-800">
                @foreach (collect($rows)->sortBy(fn (array $r): int => $r['who'] === 'operator' ? 0 : 1) as $row)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 dark:text-white/90">
                                <span @class(['mr-1 rounded px-1.5 py-0.5 text-xs', 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => $row['who'] === 'operator', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300' => $row['who'] !== 'operator'])>{{ $row['who'] === 'operator' ? 'Senin işin' : 'Sistem onarır' }}</span>
                                {{ $row['title'] }}@if ($row['asset']) <span class="font-normal text-gray-500">· {{ $row['asset'] }}</span>@endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $row['detail'] }}</p>
                        </div>
                        @if (! empty($row['url']))
                            <a href="{{ $row['url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-600 hover:underline">Aç</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="{{ $card }} text-sm text-gray-500">Her markanın bütün bağlantıları çalışıyor.</p>
    @endforelse
</div>
