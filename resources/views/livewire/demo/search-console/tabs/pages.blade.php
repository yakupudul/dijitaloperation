@php
    $pages = $data['pages'] ?? [];
    $directory = $pages['directory'] ?? [];
    $num = fn (float|int|null $value): string => number_format((float) ($value ?? 0), 0, ',', '.');
@endphp

<section>
    <div class="section-head">
        <h2 class="section-title" title="{{ $pages['subtitle'] ?? '' }}">Sayfalar <span class="section-count">{{ count($directory) }}</span></h2>
        @if (! empty($pages['attribution_note']))
            <span class="text-[13px] text-gray-500" title="{{ $pages['attribution_note'] }}">Analytics rakamları sayfa düzeyinde</span>
        @endif
    </div>
    <div class="card overflow-x-auto">
        @if ($directory === [])
            <x-ta.empty-state title="Sayfa verisi yok" message="Search Console sayfa bazında tıklama gönderince burada görünür." />
        @else
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Sayfa</th>
                        <th class="hidden md:table-cell">İçerik rolü</th>
                        <th class="hidden lg:table-cell">Hizmet</th>
                        <th class="text-right!">Tıklama</th>
                        <th class="hidden text-right! sm:table-cell" title="Analytics: oturum ve eşlenen eylem">Analytics</th>
                        <th class="hidden lg:table-cell">Site uyarısı</th>
                        <th><span class="sr-only">İncele</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($directory as $row)
                        @php $ga4 = $row['ga4_context'] ?? []; @endphp
                        <tr wire:key="gsc-page-{{ $row['path'] }}">
                            <td class="max-w-0 w-[34%]">
                                <p class="truncate font-medium text-gray-900 dark:text-white" title="{{ $row['path'] }}">{{ $row['path'] }}</p>
                                <p class="truncate text-[13px] text-gray-500">{{ $row['title'] ?? '' }}</p>
                            </td>
                            <td class="hidden text-[13px] text-gray-600 md:table-cell dark:text-gray-300">{{ $say($row['content_role'] ?? null) }}</td>
                            <td class="hidden text-[13px] text-gray-600 lg:table-cell dark:text-gray-300">{{ $row['offering'] ?? '—' }}</td>
                            <td class="text-right tabular-nums font-medium text-gray-900 dark:text-white">{{ $num($row['clicks'] ?? 0) }}</td>
                            <td class="hidden text-right text-[13px] tabular-nums text-gray-600 sm:table-cell dark:text-gray-300" title="%{{ $ga4['engagement_rate'] ?? '—' }} etkileşim. {{ $ga4['note'] ?? $pages['attribution_note'] ?? '' }}">
                                {{ $num($ga4['sessions'] ?? 0) }} oturum
                                <span class="block text-xs text-gray-500">{{ $num($ga4['mapped_actions'] ?? 0) }} eylem</span>
                            </td>
                            <td class="hidden text-[13px] lg:table-cell">
                                @if (! empty($row['website_attention']))
                                    <span class="text-warning-700 dark:text-warning-400">{{ $row['website_attention'] }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="text-right"><button type="button" wire:click="openPage('{{ $row['path'] }}')" class="btn-quiet h-7! px-2!">İncele</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</section>
