@php
    // Panel concept 2026-10-20: "Hangi markanın hangi bağlantısı eksik, hangisi benim işim?" Brands with a step that
    // waits on the operator come first; inside a brand "Senin işin" rows come before the ones the system repairs.
    $groups = collect($groups)->sortBy(fn ($rows, $brand): string => (collect($rows)->contains('who', 'operator') ? '0' : '1').mb_strtolower($brand));
    $logos = \App\Models\Brand::query()->whereIn('id', $groups->map(fn ($rows): int => (int) $rows[0]['brand_id'])->values())->pluck('logo_url', 'id');
@endphp
<div class="space-y-10" data-connection-health>
    <header class="min-w-0">
        <h1 class="page-title">Bağlantı sağlığı</h1>
        <p class="page-sub" title="&quot;Senin işin&quot; satırları erişim ya da hesap seçimi ister; gerisini sistem onarır.">{{ $summary['brands'] > 0 ? $summary['brands'].' markada '.($summary['operator'] + $summary['system']).' eksik bağlantı.' : 'Her markanın bütün bağlantıları çalışıyor.' }}</p>
    </header>

    @if ($message !== '')
        <p class="card -mt-4 px-4 py-3 text-sm text-gray-700 dark:text-gray-300" role="status">{{ $message }}</p>
    @endif

    {{-- Living panel language: who fixes what, as two tinted cards; the repair button sits with the system's number. --}}
    <div class="grid gap-3 sm:grid-cols-2">
        <div @class(['soft-card', 'soft-amber' => $summary['operator'] > 0])>
            <span @class(['soft-icon', 'text-warning-600 dark:text-warning-400' => $summary['operator'] > 0]) aria-hidden="true">
                <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M8 13V5.5a1.5 1.5 0 0 1 3 0V12M11 11.5v-2a1.5 1.5 0 0 1 3 0V12M14 10.5a1.5 1.5 0 0 1 3 0V12M17 11.5a1.5 1.5 0 0 1 3 0V16a6 6 0 0 1-6 6h-2c-2.2 0-3.6-1-4.8-2.6L4.4 15.5a1.5 1.5 0 0 1 2.4-1.8L8 15"/></svg>
            </span>
            <p class="soft-number">{{ $summary['operator'] }}</p>
            <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">Senin işin</p>
            <p class="text-[13px] text-gray-600 dark:text-gray-300">Erişim ya da hesap seçimi ister.</p>
            @if ($summary['operator'] > 0)
                <div class="mt-auto pt-1"><a href="#senin-isin" class="btn">İlkine git</a></div>
            @endif
        </div>
        <div class="soft-card">
            <span class="soft-icon" aria-hidden="true">
                <svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
            </span>
            <p class="soft-number">{{ $summary['system'] }}</p>
            <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">Sistem onarır</p>
            <p class="text-[13px] text-gray-600 dark:text-gray-300">Gecikmiş veriyi ve taranmamış siteleri her gece kendisi onarır.</p>
            <div class="mt-auto pt-1">
                <button type="button" wire:click="repairNow" wire:loading.attr="disabled" class="btn-primary w-full sm:w-auto" data-repair-now>Şimdi onar</button>
            </div>
        </div>
    </div>

    @forelse ($groups as $brand => $rows)
        @php $rows = collect($rows)->sortBy(fn (array $r): int => $r['who'] === 'operator' ? 0 : 1); @endphp
        <section wire:key="brand-{{ $rows->first()['brand_id'] }}" @if ($loop->first && $rows->first()['who'] === 'operator') id="senin-isin" class="scroll-mt-20" @endif>
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
                            @php($assetText = $row['asset'] ? str_replace(' · ', ', ', (string) $row['asset']) : '')
                            <p class="flex min-w-0 items-baseline gap-2.5">
                                <span class="shrink-0 text-sm font-medium text-gray-900 dark:text-white">{{ str_replace(' · ', ', ', $row['title']) }}</span>
                                @if ($assetText !== '')<span class="min-w-0 truncate text-[13px] text-gray-500" title="{{ $assetText }}">{{ $assetText }}</span>@endif
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
