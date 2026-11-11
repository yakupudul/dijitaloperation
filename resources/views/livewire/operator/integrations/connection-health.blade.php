@php
    // Panel concept 2026-10-20: "Hangi markanın hangi bağlantısı eksik, hangisi benim işim?" Brands with a step that
    // waits on the operator come first; inside a brand "Senin işin" rows come before the ones the system repairs.
    $groups = collect($groups)->sortBy(fn ($rows, $brand): string => (collect($rows)->contains('who', 'operator') ? '0' : '1').mb_strtolower($brand));
    $logos = \App\Models\Brand::query()->whereIn('id', $groups->map(fn ($rows): int => (int) $rows[0]['brand_id'])->values())->pluck('logo_url', 'id');
@endphp
<div class="space-y-10" data-connection-health>
    <header class="min-w-0">
        <h1 class="page-title">Bağlantı sağlığı</h1>
        <p class="page-sub" title="&quot;Senin işin&quot; satırları erişim ya da hesap seçimi ister; gerisini sistem onarır.">{{ $summary['brands'] > 0 ? $summary['brands'].' markada '.($summary['operator'] + $summary['system']).' eksik bağlantı.' : 'Her markanın bütün bağlantıları çalışıyor.' }}@if ($summary['passive'] > 0) {{ $summary['passive'] }} pasif reklam hesabı ayrı listede.@endif</p>
    </header>

    <nav class="-mt-4 flex flex-wrap items-center gap-2" aria-label="Görünüm" data-connection-views>
        <button type="button" wire:click="$set('view', 'eksikler')" @class(['seg', 'seg-active' => ! in_array($view, ['harita', 'yetkiler'], true)])>Eksikler <span class="tabular-nums {{ ! in_array($view, ['harita', 'yetkiler'], true) ? 'text-accent' : 'text-gray-400' }}">{{ $summary['operator'] + $summary['system'] }}</span></button>
        <button type="button" wire:click="$set('view', 'harita')" @class(['seg', 'seg-active' => $view === 'harita'])>Harita</button>
        <button type="button" wire:click="$set('view', 'yetkiler')" @class(['seg', 'seg-active' => $view === 'yetkiler'])>Yetkiler</button>
    </nav>

    @if ($message !== '')
        <p class="card -mt-4 px-4 py-3 text-sm text-gray-700 dark:text-gray-300" role="status">{{ $message }}</p>
    @endif

    @if ($view === 'yetkiler')
        @php $levelChannels = \App\Support\WriteLevels::CHANNELS; $levelLabels = \App\Support\WriteLevels::LABELS; @endphp
        <section data-write-levels>
            <div class="section-head items-center">
                <h2 class="section-title">Sistem hangi markada neye izinli</h2>
            </div>
            <p class="-mt-2 mb-3 max-w-[70ch] text-[13px] text-gray-500 dark:text-gray-400">Yalnız oku: oraya hiçbir şey yazılmaz. Öner: sistem hazırlar, senin onayınla gider. Yaz: sistemin kendi otomatik işleri de gider. Reklam kanalları her markada kilitli. Yeni marka "Öner" ile başlar.</p>
            <div class="card">
                <div class="hidden grid-cols-[minmax(0,1.5fr)_repeat(4,minmax(0,1fr))] gap-3 border-b border-gray-100 px-4 py-2.5 text-[12px] font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400 sm:grid">
                    <span>Marka</span>
                    @foreach ($levelChannels as $heading)<span>{{ $heading }}</span>@endforeach
                </div>
                @foreach ($levelBrands as $brand)
                    <div wire:key="level-{{ $brand->id }}" class="grid gap-x-3 gap-y-2.5 border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-800 sm:grid-cols-[minmax(0,1.5fr)_repeat(4,minmax(0,1fr))] sm:items-center" data-level-row>
                        <a href="{{ route('operator.brand', ['brand' => $brand->id]) }}" wire:navigate class="flex min-w-0 items-center gap-2 text-sm font-medium text-gray-900 hover:underline dark:text-white">
                            <x-ta.brand-avatar :name="$brand->name" :logo="$brand->logo_url" size="sm" />
                            <span class="truncate">{{ $brand->name }}</span>
                        </a>
                        <div class="col-span-full grid grid-cols-2 gap-x-3 gap-y-2.5 sm:col-span-4 sm:grid-cols-4">
                            @foreach ($levelChannels as $channel => $heading)
                                @php $level = \App\Support\WriteLevels::level($brand, $channel); $locked = in_array($channel, \App\Support\WriteLevels::LOCKED, true); @endphp
                                <label class="min-w-0">
                                    <span class="mb-1 block truncate text-[11px] text-gray-500 dark:text-gray-400 sm:hidden">{{ $heading }}</span>
                                    @if ($locked)
                                        <span class="inline-flex h-9 items-center gap-1.5 text-[13px] text-gray-400 dark:text-gray-500" title="Reklam hesaplarına yazma kapalı; panelden açılmaz.">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                                            Yalnız oku
                                        </span>
                                    @else
                                        <select wire:change="setLevel({{ $brand->id }}, '{{ $channel }}', $event.target.value)" aria-label="{{ $brand->name }}, {{ $heading }}" data-level="{{ $channel }}"
                                            class="h-9 w-full rounded-lg border border-gray-200 bg-white px-2.5 text-[13px] text-gray-800 focus:border-gray-900 focus:ring-0 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            @foreach ($levelLabels as $value => $label)
                                                <option value="{{ $value }}" @selected($level === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @elseif ($view === 'harita')
        @php
            $channels = \App\Services\DataStatus\ConnectionMap::CHANNELS;
            $dot = ['ok' => 'bg-success-500', 'warn' => 'bg-warning-500', 'bad' => 'bg-error-500', 'muted' => 'bg-gray-300 dark:bg-gray-600'];
        @endphp
        <section data-connection-map x-data="{ open: null }">
            <div class="section-head items-center">
                <h2 class="section-title">{{ $mapBrands }} marka, {{ count($channels) }} kanal</h2>
                <label class="ml-auto flex cursor-pointer items-center gap-2 text-[13px] text-gray-600 dark:text-gray-300">
                    <input type="checkbox" wire:model.live="onlyGaps" class="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-900 dark:border-gray-600 dark:bg-gray-900">
                    Yalnız sorunlu markalar
                </label>
            </div>
            <p class="-mt-2 mb-3 flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['ok'] }}"></span>{{ $mapTotals['ok'] }} çalışıyor</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['warn'] }}"></span>{{ $mapTotals['warn'] }} eksik ya da gecikmiş</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['bad'] }}"></span>{{ $mapTotals['bad'] }} kopuk</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full {{ $dot['muted'] }}"></span>gri: o kanal bu markada yok ya da pasif</span>
            </p>
            <div class="card">
                <div class="hidden grid-cols-[minmax(0,1.5fr)_repeat(6,minmax(0,1fr))] gap-2 border-b border-gray-100 px-4 py-2.5 text-[12px] font-medium text-gray-500 dark:border-gray-800 dark:text-gray-400 lg:grid">
                    <span>Marka</span>
                    @foreach ($channels as $heading)<span>{{ $heading }}</span>@endforeach
                </div>
                @forelse ($map as $row)
                    <div wire:key="map-{{ $row['brand_id'] }}" class="border-b border-gray-100 px-4 py-3 last:border-b-0 dark:border-gray-800" data-map-row>
                        <div class="grid gap-x-2 gap-y-2.5 lg:grid-cols-[minmax(0,1.5fr)_repeat(6,minmax(0,1fr))] lg:items-center">
                            <a href="{{ route('operator.brand', ['brand' => $row['brand_id']]) }}" wire:navigate class="flex min-w-0 items-center gap-2 text-sm font-medium text-gray-900 hover:underline dark:text-white">
                                <x-ta.brand-avatar :name="$row['brand']" :logo="$row['logo']" size="sm" />
                                <span class="truncate">{{ $row['brand'] }}</span>
                            </a>
                            <div class="col-span-full grid grid-cols-3 gap-x-2 gap-y-2.5 lg:col-span-6 lg:grid-cols-6">
                                @foreach ($row['cells'] as $key => $cell)
                                    @php $id = $row['brand_id'].'-'.$key; @endphp
                                    <button type="button" @click="open = open === '{{ $id }}' ? null : '{{ $id }}'" :aria-expanded="open === '{{ $id }}'"
                                        class="min-w-0 rounded-lg px-2 py-1.5 text-left transition hover:bg-gray-50 dark:hover:bg-white/[0.04]" :class="open === '{{ $id }}' && 'bg-gray-50 dark:bg-white/[0.04]'" data-map-cell="{{ $key }}" data-tone="{{ $cell['tone'] }}">
                                        <span class="block truncate text-[11px] text-gray-500 dark:text-gray-400 lg:hidden">{{ $channels[$key] }}</span>
                                        <span class="flex min-w-0 items-center gap-1.5 text-[13px] {{ $cell['tone'] === 'muted' ? 'text-gray-400 dark:text-gray-500' : 'text-gray-800 dark:text-gray-200' }}">
                                            <span class="h-2 w-2 shrink-0 rounded-full {{ $dot[$cell['tone']] }}" aria-hidden="true"></span>
                                            <span class="truncate">{{ $cell['label'] }}@if ($cell['count'] > 1) <span class="text-gray-400">({{ $cell['count'] }})</span>@endif</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        @foreach ($row['cells'] as $key => $cell)
                            <div x-show="open === '{{ $row['brand_id'].'-'.$key }}'" x-cloak class="mt-2.5 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/[0.04]">
                                <p class="min-w-0 flex-1 basis-60 text-[13px] text-gray-700 dark:text-gray-300"><span class="font-medium text-gray-900 dark:text-white">{{ $channels[$key] }}.</span> {{ $cell['detail'] }}</p>
                                @if ($cell['who'] !== null)
                                    <x-ta.pill :tone="$cell['who'] === 'Sen' ? 'look' : 'info'">{{ $cell['who'] === 'Sen' ? 'Senin işin' : 'Sistem onarır' }}</x-ta.pill>
                                @endif
                                @if ($cell['url'] !== null)
                                    <a href="{{ $cell['url'] }}" wire:navigate class="{{ $cell['who'] === 'Sen' ? 'btn' : 'btn-quiet' }}">Aç</a>
                                @endif
                            </div>
                        @endforeach
                        @if ($accessBrand === $row['brand_id'] || collect($row['cells'])->except('site')->contains(fn (array $c): bool => $c['tone'] !== 'ok'))
                            @if ($accessBrand === $row['brand_id'])
                                <livewire:operator.integrations.access-link-panel :brand-id="$row['brand_id']" :key="'access-'.$row['brand_id']" />
                            @else
                                <button type="button" wire:click="$set('accessBrand', {{ $row['brand_id'] }})" class="mt-2 text-[12px] text-gray-500 underline decoration-gray-300 underline-offset-2 hover:text-gray-900 dark:hover:text-white" data-access-link-open>Müşteriden erişim iste</button>
                            @endif
                        @endif
                    </div>
                @empty
                    <x-ta.empty-state size="sm" title="Sorunlu marka yok" message="Her markanın bütün kanalları çalışıyor." />
                @endforelse
            </div>
        </section>
    @else
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

    {{-- Bağlantı Düzeni 1.2: an ads account without spend is not a missing connection; it is listed apart and not counted. --}}
    @if (count($passive) > 0)
        <section data-passive-accounts>
            <div class="section-head items-center">
                <h2 class="section-title">Pasif reklam hesapları <span class="section-count">{{ count($passive) }}</span></h2>
            </div>
            <p class="-mt-2 mb-3 text-[13px] text-gray-500 dark:text-gray-400">Harcama yapmayan hesaplar eksik bağlantı sayılmaz. Harcama başlayınca kendiliğinden aktif olur.</p>
            <ul class="card">
                @foreach ($passive as $row)
                    <li class="card-row flex-wrap items-start sm:flex-nowrap sm:items-center">
                        <div class="min-w-0 flex-1">
                            <p class="flex min-w-0 items-baseline gap-2.5">
                                <span class="shrink-0 text-sm font-medium text-gray-900 dark:text-white">{{ $row['brand'] }}</span>
                                <span class="min-w-0 truncate text-[13px] text-gray-500">{{ str_replace(' · ', ', ', (string) $row['asset']) }}</span>
                            </p>
                            <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::before($row['detail'], ' · pasif:') }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @endif
</div>
