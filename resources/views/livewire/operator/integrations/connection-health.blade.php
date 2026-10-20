@php
    // Panel concept 2026-10-20: "Hangi markanın hangi bağlantısı eksik, hangisi benim işim?" Brands with a step that
    // waits on the operator come first; inside a brand "Senin işin" rows come before the ones the system repairs.
    $groups = collect($groups)->sortBy(fn ($rows, $brand): string => (collect($rows)->contains('who', 'operator') ? '0' : '1').mb_strtolower($brand));
    $logos = \App\Models\Brand::query()->whereIn('id', $groups->map(fn ($rows): int => (int) $rows[0]['brand_id'])->values())->pluck('logo_url', 'id');
@endphp
<div class="space-y-10" data-connection-health>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title">Bağlantı sağlığı</h1>
            <p class="page-sub" title="Gecikmiş veriyi ve taranmamış siteleri sistem her gece kendisi onarır.">"Senin işin" satırları erişim ya da hesap seçimi ister; gerisini sistem onarır.</p>
        </div>
        <button type="button" wire:click="repairNow" wire:loading.attr="disabled" class="btn-primary w-full sm:w-auto" data-repair-now>Şimdi onar</button>
    </header>

    @if ($message !== '')
        <p class="card -mt-4 px-4 py-3 text-sm text-gray-700 dark:text-gray-300" role="status">{{ $message }}</p>
    @endif

    <div class="metric-strip grid-cols-3">
        <x-ta.metric-card label="Senin işin" :value="$summary['operator']" :tone="$summary['operator'] > 0 ? 'look' : 'neutral'" />
        <x-ta.metric-card label="Sistem onarır" :value="$summary['system']" />
        <x-ta.metric-card label="Sorunlu marka" :value="$summary['brands']" />
    </div>

    @forelse ($groups as $brand => $rows)
        @php $rows = collect($rows)->sortBy(fn (array $r): int => $r['who'] === 'operator' ? 0 : 1); @endphp
        <section wire:key="brand-{{ $rows->first()['brand_id'] }}">
            <div class="section-head items-center">
                <h2 class="section-title flex min-w-0 items-center gap-2">
                    <x-ta.brand-avatar :name="$brand" :logo="$logos[$rows->first()['brand_id']] ?? null" size="sm" />
                    <a href="{{ route('operator.brand', ['brand' => $rows->first()['brand_id']]) }}" wire:navigate class="truncate hover:underline">{{ $brand }}</a>
                    <span class="section-count">{{ $rows->count() }}</span>
                </h2>
            </div>
            <ul class="card">
                @foreach ($rows as $row)
                    <li class="card-row flex-wrap items-start sm:flex-nowrap sm:items-center">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $row['title'] }}</span>
                                @if ($row['asset'])<span class="text-[13px] text-gray-500">{{ $row['asset'] }}</span>@endif
                            </p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</p>
                        </div>
                        <x-ta.pill :tone="$row['who'] === 'operator' ? 'look' : 'info'">{{ $row['who'] === 'operator' ? 'Senin işin' : 'Sistem onarır' }}</x-ta.pill>
                        @if (! empty($row['url']))
                            <a href="{{ $row['url'] }}" wire:navigate class="{{ $row['who'] === 'operator' ? 'btn' : 'btn-quiet' }} w-full sm:w-auto">Aç</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <div class="card">
            <x-ta.empty-state size="sm" title="Sorun yok" message="Her markanın bütün bağlantıları çalışıyor." />
        </div>
    @endforelse
</div>
