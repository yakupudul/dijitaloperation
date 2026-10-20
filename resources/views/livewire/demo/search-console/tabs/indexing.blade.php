@php
    $indexing = $data['indexing'] ?? [];
    $coverage = $indexing['coverage'] ?? [];
    $urls = $indexing['urls'] ?? [];
    $sitemaps = $indexing['sitemaps'] ?? [];
    $reconciliation = $indexing['reconciliation'] ?? [];
    $byRole = $indexing['discoverability_by_role'] ?? [];
    $num = fn (float|int|null $value): string => number_format((float) ($value ?? 0), 0, ',', '.');
    // A count the provider did not send is "—", never 0 (missing is not zero).
    $count = fn (array $source, string $key): string => array_key_exists($key, $source) && is_numeric($source[$key]) ? $num($source[$key]) : '—';
    $subTabs = ['coverage' => ['Kapsam', null], 'inspection' => ['URL denetimi', count($urls)], 'sitemaps' => ['Site haritaları', count($sitemaps)], 'reconciliation' => ['Karşılaştırma', null]];
@endphp

<div class="space-y-3">
    <div class="card">
        <div class="flex gap-1 overflow-x-auto border-b border-gray-200 px-3 no-scrollbar dark:border-gray-800" role="group" aria-label="Dizine ekleme" title="{{ $indexing['subtitle'] ?? '' }}">
            @foreach ($subTabs as $key => [$label, $n])
                <button type="button" wire:click="setIndexSub('{{ $key }}')" @class(['tab', 'tab-active' => $index_sub === $key])>{{ $label }}@if ($n !== null && $n > 0) <span class="text-gray-500">{{ $n }}</span>@endif</button>
            @endforeach
        </div>

        @if ($index_sub === 'coverage')
            <div class="grid grid-cols-2 gap-px bg-gray-200 sm:grid-cols-4 dark:bg-gray-800" title="{{ $coverage['note'] ?? '' }}">
                @foreach (['indexed' => 'Dizinde', 'not_indexed' => 'Dizinde değil', 'unknown' => 'Bilinmiyor', 'excluded' => 'Hariç tutulan'] as $key => $label)
                    @php $value = $count($coverage, $key); @endphp
                    <x-ta.metric-card :label="$label" :value="$value" :muted="$value === '—'" />
                @endforeach
            </div>
            <div class="border-t border-gray-200 dark:border-gray-800">
                @if ($byRole === [])
                    <x-ta.empty-state size="sm" title="Sayfa türüne göre kırılım yok" message="Search Console sitenin tüm dizin sayılarını vermiyor." />
                @else
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Sayfa türü</th>
                                    <th class="text-right!">Gösterim alan</th>
                                    <th class="text-right!">Sitedeki</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($byRole as $row)
                                    <tr>
                                        <td class="font-medium text-gray-900 dark:text-white">{{ $say($row['role']) }}</td>
                                        <td class="text-right tabular-nums">{{ $num($row['with_impressions']) }}</td>
                                        <td class="text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $num($row['inventory']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @elseif ($index_sub === 'inspection')
            @if ($urls === [])
                <x-ta.empty-state title="Denetlenen URL yok" message="Google'a sorulan sayfaların dizin durumu burada görünür." />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>URL</th>
                                <th>Google dizini</th>
                                <th class="hidden md:table-cell">Site haritası</th>
                                <th class="hidden md:table-cell">Kanonik</th>
                                <th class="hidden lg:table-cell">Arama görünürlüğü</th>
                                <th><span class="sr-only">İncele</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($urls as $row)
                                <tr wire:key="gsc-url-{{ $row['id'] ?? $row['path'] }}">
                                    <td class="min-w-[12rem]">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ $row['path'] }}</p>
                                        <p class="text-[13px] text-gray-500">{{ $say($row['role'] ?? '') }}</p>
                                        @if (! empty($row['attention']))
                                            <p class="mt-0.5 text-[13px] text-warning-700 dark:text-warning-400">{{ $row['attention'] }}</p>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap"><x-ta.pill :tone="match ($row['index_state'] ?? '') { 'Indexed' => 'ok', 'Unavailable' => 'info', default => 'look' }">{{ $say($row['index_state'] ?? null) }}</x-ta.pill></td>
                                    <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $say($row['sitemap'] ?? null) }}</td>
                                    <td class="hidden text-[13px] md:table-cell">
                                        <span @class(['text-warning-700 dark:text-warning-400' => ($row['canonical'] ?? '') === 'Mismatch', 'text-gray-600 dark:text-gray-300' => ($row['canonical'] ?? '') !== 'Mismatch'])>{{ $say($row['canonical'] ?? null) }}</span>
                                    </td>
                                    <td class="hidden text-[13px] text-gray-600 lg:table-cell dark:text-gray-300">{{ $say($row['search_visibility'] ?? null) }}</td>
                                    <td class="text-right"><button type="button" wire:click="openUrl('{{ $row['id'] ?? $row['path'] }}')" class="btn-quiet h-7! px-2!">İncele</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif ($index_sub === 'sitemaps')
            @if ($sitemaps === [])
                <x-ta.empty-state title="Site haritası yok" message="Search Console'a gönderilen site haritaları burada görünür." />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Site haritası</th>
                                <th class="hidden md:table-cell">Gönderildi</th>
                                <th class="hidden md:table-cell">Son okuma</th>
                                <th class="text-right!" title="Gönderilmesi dizinde olduğu anlamına gelmez">Bulunan</th>
                                <th class="hidden text-right! sm:table-cell">Uyarı</th>
                                <th class="hidden text-right! sm:table-cell">Hata</th>
                                <th>Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sitemaps as $row)
                                <tr>
                                    <td class="min-w-[10rem] font-medium break-all text-gray-900 dark:text-white">{{ $row['path'] }}</td>
                                    <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $row['submitted'] }}</td>
                                    <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $row['last_downloaded'] }}</td>
                                    <td class="text-right tabular-nums">{{ $num($row['discovered']) }}</td>
                                    <td class="hidden text-right tabular-nums sm:table-cell">{{ $row['warnings'] }}</td>
                                    <td @class(['hidden text-right tabular-nums sm:table-cell', 'text-error-600 dark:text-error-400' => (int) $row['errors'] > 0])>{{ $row['errors'] }}</td>
                                    <td class="whitespace-nowrap"><x-ta.pill :tone="($row['status'] ?? '') === 'Success' ? 'ok' : 'look'">{{ $say($row['status'] ?? null) }}</x-ta.pill></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <div class="grid grid-cols-2 gap-px bg-gray-200 sm:grid-cols-4 dark:bg-gray-800" title="{{ $reconciliation['note'] ?? '' }}">
                @foreach (['website_urls' => 'Sitedeki URL', 'sitemap_urls' => 'Site haritasındaki URL', 'index_observed' => 'Dizinde görülen', 'priority_missing_sitemap' => 'Haritada olmayan öncelikli'] as $key => $label)
                    @php $value = $count($reconciliation, $key); @endphp
                    <x-ta.metric-card :label="$label" :value="$value" :muted="$value === '—'" />
                @endforeach
            </div>
            <ul class="border-t border-gray-200 dark:border-gray-800">
                @forelse ($reconciliation['gaps'] ?? [] as $gap)
                    <li class="card-row items-start py-3">
                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-warning-500" aria-hidden="true"></span>
                        <span class="min-w-0 text-sm text-gray-800 dark:text-gray-200">{{ $gap }}</span>
                    </li>
                @empty
                    <li><x-ta.empty-state size="sm" title="Fark listesi yok" message="Site, site haritası ve dizin karşılaştırılınca farklar burada listelenir." /></li>
                @endforelse
            </ul>
        @endif
    </div>

    @if (! empty($indexing['inspection_note']) && in_array($index_sub, ['inspection', 'coverage'], true))
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ strtr($indexing['inspection_note'], ['URL Inspection is a selective sample only — never extrapolated to uninspected URLs.' => 'URL denetimi seçilmiş birkaç sayfanın örneğidir; denetlenmeyen sayfalara genellenmez.']) }}</p>
    @endif
</div>
