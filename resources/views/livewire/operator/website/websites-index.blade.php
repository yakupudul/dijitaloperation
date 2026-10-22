@php
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
@endphp
@php
    // Living panel (2026-10-20): the head says where work waits, the list stays a table (up to ~100 sites).
    $openTotal = array_sum($open);
    $openSites = count(array_filter($open));
    $clickTotal = array_sum($clicks);
@endphp
<div class="space-y-8" data-websites-index>
    <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            @if ($openTotal > 0)
                <span class="eyebrow mb-3.5" data-websites-eyebrow>
                    <span class="live-dot" aria-hidden="true"></span>
                    <span><b class="font-semibold text-gray-900 tabular-nums dark:text-white">{{ $num($openTotal) }} açık iş</b> {{ $openSites }} sitede bekliyor</span>
                </span>
            @endif
            <h1 class="page-title" title="Markaya atanmış siteler">Web <span class="highlight">siteleri</span></h1>
            <p class="page-sub">{{ count($sites) }} site{{ $clickTotal > 0 ? ', son 28 günde '.$num($clickTotal).' organik tıklama' : '' }}</p>
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Site, alan adı veya marka ara" aria-label="Ara" class="h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
    </header>

    <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Durum" data-website-filters>
        @foreach (\App\Livewire\Operator\Website\WebsitesIndex::FILTERS as $key => $label)
            <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" class="{{ $filter === $key ? 'seg seg-active' : 'seg' }} h-8! px-3!" data-website-filter="{{ $key }}">{{ $label }} <span class="tabular-nums text-gray-500">{{ $num($counts[$key] ?? 0) }}</span></button>
        @endforeach
    </div>

    @if ($message !== '')
        <p class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300" data-websites-message>{{ $message }}</p>
    @endif

    <section class="card overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Site</th><th class="hidden md:table-cell">Marka</th><th class="hidden lg:table-cell">Eklenti</th><th class="hidden lg:table-cell">Search Console</th><th class="hidden lg:table-cell">GA4</th><th class="hidden md:table-cell">Son tarama</th><th class="hidden text-right! sm:table-cell">Tık, 28 gün</th><th class="text-right!">Açık iş</th><th>Durum</th><th class="w-10"><span class="sr-only">Siteyi aç</span></th></tr>
            </thead>
            <tbody>
                @forelse ($sites as $site)
                    @php
                        $siteUrl = route('operator.website', ['assetId' => $site->id]);
                        $address = $site->domain ?: $site->primary_url;
                        // Each value once (2026-10-19): the address only when it is not the site's name, the customer only
                        // when it is not the brand's name.
                        $norm = fn (?string $v): string => mb_strtolower(rtrim(preg_replace('#^(https?://)?(www\.)?#i', '', trim((string) $v)), '/'));
                        $showAddress = filled($address) && $norm($address) !== $norm($site->name);
                        $brandName = $site->brand?->name;
                        $openCount = (int) ($open[$site->id] ?? 0);
                        $st = $status[$site->id];
                        $yes = '<span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-success-500" aria-hidden="true"></span>Bağlı</span>';
                        $no = '<span class="inline-flex items-center gap-1.5 text-gray-500"><span class="h-1.5 w-1.5 rounded-full bg-warning-500" aria-hidden="true"></span>Yok</span>';
                    @endphp
                    <tr wire:key="site-{{ $site->id }}" data-website-row="{{ $site->id }}">
                        <td class="min-w-0 align-top">
                            <div class="flex min-w-0 items-start gap-2.5">
                                <x-ta.brand-avatar :name="$brandName ?? $site->name" :logo="$site->brand?->logo_url" size="sm" class="mt-0.5 shrink-0" />
                                <div class="min-w-0">
                                    <a href="{{ $siteUrl }}" wire:navigate class="font-medium text-gray-900 [overflow-wrap:anywhere] hover:underline dark:text-white">{{ $site->name }}</a>
                                    @if ($showAddress)<p class="text-[13px] text-gray-500 [overflow-wrap:anywhere]">{{ $address }}</p>@endif
                                    @if ($brandName)
                                        <p class="break-words text-[13px] text-gray-500 md:hidden"><a href="{{ route('operator.brand', ['brand' => $site->brand_id]) }}" wire:navigate class="hover:underline">{{ $brandName }}</a></p>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="hidden align-top text-gray-700 md:table-cell dark:text-gray-300">
                            @if ($brandName)
                                <a href="{{ route('operator.brand', ['brand' => $site->brand_id]) }}" wire:navigate class="break-words hover:underline">{{ $brandName }}</a>
                            @else
                                <span class="text-gray-500">Markasız</span>
                            @endif
                        </td>
                        <td class="hidden align-top whitespace-nowrap lg:table-cell" data-connector="{{ $st['version'] }}">
                            @if (! $st['paired'])
                                {!! $no !!}
                            @elseif ($st['outdated'])
                                <span class="tabular-nums">{{ $st['version'] ?: '?' }}</span>
                                @if ($isAdmin && $st['updatable'] && $site->brand_id !== null)
                                    <button type="button" wire:click="updateConnector({{ $site->id }})" wire:confirm="Bu sitede eklenti {{ $latestConnector }} sürümüne güncellensin mi?" class="btn-quiet ml-1 h-6! px-2! text-xs!" data-connector-update="{{ $site->id }}">Güncelle</button>
                                @else
                                    <span class="chip chip-flat ml-1" title="Tek tık güncelleme için eklentiyi bir kez elle yükleyin">eski</span>
                                @endif
                            @else
                                <span class="tabular-nums">{{ $st['version'] ?: '—' }}</span>
                            @endif
                        </td>
                        <td class="hidden align-top whitespace-nowrap lg:table-cell">{!! $st['gsc'] ? $yes : $no !!}</td>
                        <td class="hidden align-top whitespace-nowrap lg:table-cell">{!! $st['ga4'] ? $yes : $no !!}</td>
                        <td class="hidden align-top whitespace-nowrap text-gray-700 md:table-cell dark:text-gray-300">
                            @if ($st['crawl_at'])
                                <span class="{{ $st['crawl_failed'] ? 'text-error-600' : '' }}" title="{{ $st['crawl_failed'] ? 'Son tarama başarısız' : '' }}">{{ $st['crawl_at']->timezone('Europe/Istanbul')->format('d.m.Y') }}</span>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>
                        <td class="hidden text-right align-top tabular-nums sm:table-cell">{{ array_key_exists($site->id, $clicks) ? $num($clicks[$site->id]) : '—' }}</td>
                        <td class="text-right align-top tabular-nums" data-open-work>
                            @if ($openCount > 0)
                                <a href="{{ route('operator.website', ['assetId' => $site->id, 'tab' => 'yapilacaklar']) }}" wire:navigate class="chip chip-accent whitespace-nowrap hover:brightness-95" title="Sitenin Yapılacaklar listesini aç">{{ $num($openCount) }}<span class="hidden sm:inline">&nbsp;açık iş</span></a>
                            @else
                                <span class="text-gray-500">0</span>
                            @endif
                        </td>
                        <td class="align-top" data-site-state="{{ $site->brand_id === null ? 'brandless' : ($st['problems'] === [] ? 'ok' : 'problem') }}">
                            @if ($site->brand_id === null)
                                <span class="chip chip-flat" title="Markaya atanmamış sitede otomatik iş yapılmaz">Markasız</span>
                            @elseif ($st['problems'] === [])
                                <span class="chip chip-up">Yolunda</span>
                            @else
                                <span class="chip chip-down whitespace-nowrap" title="{{ \Illuminate\Support\Str::ucfirst(implode(', ', $st['problems'])) }}">{{ count($st['problems']) === 1 ? \Illuminate\Support\Str::ucfirst($st['problems'][0]) : count($st['problems']).' sorun' }}</span>
                            @endif
                        </td>
                        <td class="px-2! text-right align-top">
                            <a href="{{ $siteUrl }}" wire:navigate class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200" title="Siteyi aç" aria-label="Siteyi aç: {{ $site->name }}">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="py-6! text-[13px] text-gray-500">{{ $filter === 'tumu' ? 'Markaya atanmış web sitesi yok.' : 'Bu süzgeçte site yok.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($unassigned > 0)
        <p class="text-[13px] text-gray-500">{{ $unassigned }} site markaya atanmamış; markasız sitede otomatik iş yapılmaz. <button type="button" wire:click="setFilter('markasiz')" class="link-neutral">Markasız siteleri göster</button></p>
    @endif
</div>
