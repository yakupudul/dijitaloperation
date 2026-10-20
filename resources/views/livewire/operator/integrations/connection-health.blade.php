@php
    $card = 'min-w-0';
@endphp
<div class="space-y-10" data-connection-health>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">Bağlantı sağlığı</h1>
            <p class="mt-1 text-sm text-gray-500" title="Gecikmiş veriyi ve taranmamış siteleri sistem her gece kendisi onarır.">"Senin işin" satırları erişim ya da hesap seçimi ister.</p>
        </div>
        <button type="button" wire:click="repairNow" wire:loading.attr="disabled" class="rounded-lg bg-brand-500 px-3 py-2 text-sm font-semibold text-white hover:bg-brand-600" data-repair-now>Şimdi onar</button>
    </div>

    @if ($message !== '')
        <p class="rounded-lg px-4 py-2 text-sm tone-info">{{ $message }}</p>
    @endif

    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3">
        <x-ta.metric-card label="Sistem onarır" :value="$summary['system']" />
        <x-ta.metric-card label="Senin işin" :value="$summary['operator']" />
        <x-ta.metric-card label="Sorunlu marka" :value="$summary['brands']" />
    </div>

    @forelse ($groups as $brand => $rows)
        <section class="{{ $card }}" wire:key="brand-{{ $rows[0]['brand_id'] }}">
            <h2 class="section-title"><a href="{{ route('operator.brand', ['brand' => $rows[0]['brand_id']]) }}" wire:navigate class="hover:underline">{{ $brand }}</a></h2>
            <ul class="mt-2 divide-y divide-gray-200 text-sm dark:divide-gray-800">
                @foreach (collect($rows)->sortBy(fn (array $r): int => $r['who'] === 'operator' ? 0 : 1) as $row)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-2.5">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 dark:text-white/90">
                                <x-ta.pill :tone="$row['who'] === 'operator' ? 'look' : 'info'" class="mr-2">{{ $row['who'] === 'operator' ? 'Senin işin' : 'Sistem onarır' }}</x-ta.pill>
                                {{ $row['title'] }}@if ($row['asset']) <span class="font-normal text-gray-500">{{ $row['asset'] }}</span>@endif
                            </p>
                            <p class="text-xs text-gray-500">{{ $row['detail'] }}</p>
                        </div>
                        @if (! empty($row['url']))
                            <a href="{{ $row['url'] }}" wire:navigate class="shrink-0 text-xs link-neutral">Aç</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="text-sm text-gray-500">Her markanın bütün bağlantıları çalışıyor.</p>
    @endforelse
</div>
