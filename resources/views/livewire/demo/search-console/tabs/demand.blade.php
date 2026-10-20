@php
    $demand = $data['demand'] ?? [];
    $clusters = $demand['clusters'] ?? [];
    $momentum = $demand['momentum'] ?? [];
    $ownershipReviews = $demand['ownership_reviews'] ?? [];
    $num = fn (float|int|null $value): string => number_format((float) ($value ?? 0), 0, ',', '.');
    $subTabs = ['clusters' => ['Kümeler', count($clusters)], 'queries' => ['Aramalar', count($demand['queries'] ?? [])], 'momentum' => ['Hareket', null], 'ownership' => ['Sahiplik', count($ownershipReviews)]];
    $trendTone = fn (?string $trend): string => match ($trend) { 'declining', 'lost' => 'critical', 'growing', 'new' => 'ok', 'ctr_review', 'striking_distance' => 'look', default => 'info' };
@endphp

<div class="space-y-3">
    <div class="flex gap-1.5 overflow-x-auto no-scrollbar" role="group" aria-label="Sorgular ve arama talebi">
        @foreach ($subTabs as $key => [$label, $count])
            <button type="button" wire:click="setDemandSub('{{ $key }}')" @class(['seg', 'seg-active' => $demand_sub === $key])>{{ $label }}@if ($count !== null && $count > 0) <span @class(['tabular-nums text-xs', 'text-accent' => $demand_sub === $key, 'text-gray-400 dark:text-gray-500' => $demand_sub !== $key])>{{ $count }}</span>@endif</button>
        @endforeach
    </div>
    <div class="card">

        @if ($demand_sub === 'clusters')
            @if ($clusters === [])
                <x-ta.empty-state icon="none" title="Arama kümesi yok" message="Aramalar konu kümelerine ayrılınca burada görünür." />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Küme</th>
                                <th class="hidden sm:table-cell">Gidiş</th>
                                <th class="text-right!">Tıklama</th>
                                <th class="hidden text-right! md:table-cell">Gösterim</th>
                                <th class="hidden text-right! md:table-cell">Tıklama oranı</th>
                                <th class="hidden text-right! md:table-cell" title="Gösterime göre ağırlıklı ortalama sıra">Sıra</th>
                                <th class="hidden lg:table-cell">Ana sayfa</th>
                                <th><span class="sr-only">İncele</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($clusters as $cluster)
                                <tr wire:key="gsc-cluster-{{ $cluster['id'] }}">
                                    <td class="min-w-[12rem]">
                                        <p class="font-medium text-gray-900 dark:text-white">{{ $cluster['name'] }}</p>
                                        <p class="text-[13px] text-gray-500">{{ $cluster['intent'] ?? '' }}</p>
                                    </td>
                                    <td class="hidden whitespace-nowrap sm:table-cell"><x-ta.pill :tone="$trendTone($cluster['trend'] ?? null)">{{ $say($cluster['trend'] ?? 'stable') }}</x-ta.pill></td>
                                    <td class="text-right tabular-nums text-gray-900 dark:text-white">{{ $num($cluster['clicks']) }}</td>
                                    <td class="hidden text-right tabular-nums md:table-cell">{{ $num($cluster['impressions']) }}</td>
                                    <td class="hidden text-right tabular-nums md:table-cell">%{{ $dec($cluster['ctr']) }}</td>
                                    <td class="hidden text-right tabular-nums md:table-cell">{{ $dec($cluster['position']) }}</td>
                                    <td class="hidden max-w-[12rem] truncate text-[13px] text-gray-500 lg:table-cell" title="{{ $cluster['ownership_state'] ?? '' }}">{{ $cluster['primary_page'] }}</td>
                                    <td class="text-right"><button type="button" wire:click="openCluster('{{ $cluster['id'] }}')" class="btn-quiet h-7! px-2!">İncele</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif ($demand_sub === 'queries')
            @if (($demand['queries'] ?? []) === [])
                <x-ta.empty-state icon="none" title="Arama verisi yok" message="Search Console arama sorgularını gönderince burada görünür." />
            @else
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Arama</th>
                                <th class="text-right!">Tıklama</th>
                                <th class="hidden text-right! sm:table-cell">Gösterim</th>
                                <th class="hidden text-right! md:table-cell">Tıklama oranı</th>
                                <th class="hidden text-right! md:table-cell" title="Gösterime göre ağırlıklı ortalama sıra">Sıra</th>
                                <th class="hidden lg:table-cell">Sayfa</th>
                                <th class="hidden md:table-cell">Gidiş</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($demand['queries'] ?? [] as $row)
                                <tr>
                                    <td class="min-w-[10rem] font-medium text-gray-900 dark:text-white">{{ $row['query'] }}</td>
                                    <td class="text-right tabular-nums text-gray-900 dark:text-white">{{ $num($row['clicks']) }}</td>
                                    <td class="hidden text-right tabular-nums sm:table-cell">{{ $num($row['impressions']) }}</td>
                                    <td class="hidden text-right tabular-nums md:table-cell">%{{ $dec($row['ctr']) }}</td>
                                    <td class="hidden text-right tabular-nums md:table-cell">{{ $dec($row['position']) }}</td>
                                    <td class="hidden max-w-[12rem] truncate text-[13px] text-gray-500 lg:table-cell">{{ $row['page'] }}</td>
                                    <td class="hidden whitespace-nowrap md:table-cell"><x-ta.pill :tone="$trendTone($row['trend'] ?? null)">{{ $say($row['trend'] ?? 'stable') }}</x-ta.pill></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @elseif ($demand_sub === 'momentum')
            @if ($momentum === [])
                <x-ta.empty-state icon="none" title="Hareket hesaplanmadı" message="Aramaların önceki döneme göre hareketi burada görünür." />
            @else
                <div class="grid gap-px bg-gray-200 sm:grid-cols-2 lg:grid-cols-3 dark:bg-gray-800">
                    @foreach (['growing' => 'Büyüyen', 'declining' => 'Düşen', 'new' => 'Yeni', 'lost' => 'Kaybolan', 'ctr_review' => 'Tıklama oranı düşük', 'striking_distance' => 'İlk sayfaya yakın'] as $key => $label)
                        <div class="min-w-0 bg-white px-4 py-3.5 dark:bg-gray-900">
                            <div class="flex items-center gap-2">
                                <x-ta.pill :tone="$trendTone($key)">{{ $label }}</x-ta.pill>
                                <span class="ml-auto text-[13px] tabular-nums text-gray-500">{{ count($momentum[$key] ?? []) }}</span>
                            </div>
                            <ul class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                                @forelse ($momentum[$key] ?? [] as $query)
                                    <li class="truncate" title="{{ $query }}">{{ $query }}</li>
                                @empty
                                    <li class="text-[13px] text-gray-400">Bu grupta arama yok</li>
                                @endforelse
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            @forelse ($ownershipReviews as $review)
                <div class="border-t border-gray-200 px-4 py-4 first:border-t-0 dark:border-gray-800">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $review['state'] ?? '' }}</p>
                            <p class="mt-0.5 text-[13px] text-gray-500">{{ collect([$review['topic'] ?? null, $review['cluster'] ?? null])->filter()->implode(', ') }}</p>
                        </div>
                        <x-ta.pill tone="look">{{ $review['language'] ?? 'Bakılmalı' }}</x-ta.pill>
                    </div>
                    <div class="mt-4 grid gap-5 lg:grid-cols-2">
                        <div>
                            <p class="text-xs text-gray-500">Olması gereken sayfa</p>
                            <p class="mt-1 text-sm font-medium text-gray-900 dark:text-white">{{ $review['intended_page'] ?? '—' }}</p>
                            <p class="text-xs text-gray-500">{{ $review['intended_source'] ?? '' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Google'ın gösterdiği sayfalar</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($review['observed'] ?? [] as $obs)
                                    <li>
                                        <div class="mb-1 flex justify-between gap-3 text-[13px]">
                                            <span class="min-w-0 truncate text-gray-800 dark:text-white/90">{{ $obs['path'] }}</span>
                                            <span class="tabular-nums text-gray-500">%{{ $obs['share_pct'] }}</span>
                                        </div>
                                        <div class="h-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                                            <div class="h-full rounded-full bg-second" style="width: {{ min(100, $obs['share_pct']) }}%"></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                    @if (! empty($review['recommendation']))
                        <p class="mt-4 text-[13px] text-gray-700 dark:text-gray-300">{{ $review['recommendation'] }}</p>
                    @endif
                    @if (! empty($review['urls']))
                        <p class="mt-1 break-all text-xs text-gray-500">{{ implode(', ', $review['urls']) }}</p>
                    @endif
                </div>
            @empty
                <x-ta.empty-state title="Sahiplik çakışması yok" message="Aynı aramada birden fazla sayfa yarışınca burada görünür." />
            @endforelse
        @endif
    </div>

    @if (! empty($demand['observed_query_note']))
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $demand['observed_query_note'] }}</p>
    @endif
</div>
