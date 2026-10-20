@php
    // One question: on which sites is discovery waiting for a review or has never run?
    $statusLabel = fn (string $status): string => [
        'not_run' => 'Çalışmadı',
        'queued' => 'Sırada',
        'running' => 'Çalışıyor',
        'completed' => 'Tamamlandı',
        'succeeded' => 'Tamamlandı',
        'partial' => 'Kısmen tamamlandı',
        'failed' => 'Başarısız',
        'cancelled' => 'İptal edildi',
    ][$status] ?? (string) str($status)->replace('_', ' ')->ucfirst();
    $statusTone = fn (string $status): string => match ($status) {
        'completed', 'succeeded' => 'ok',
        'partial' => 'look',
        'failed' => 'critical',
        'queued', 'running' => 'live',
        default => 'info',
    };
    $filters = ['all' => 'Tümü', 'needs_review' => 'İnceleme bekliyor', 'never_run' => 'Hiç çalışmadı', 'issue' => 'Sorunlu'];
@endphp

<div class="min-w-0 space-y-8" wire:poll.10s>
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="page-title" title="{{ __('public_discovery.intro') }} {{ __('public_discovery.how') }}">{{ app()->getLocale() === 'tr' ? 'Açık Web Keşfi' : 'Public Discovery' }}</h1>
            <p class="page-sub">{{ __('public_discovery.intro') }}</p>
        </div>
        <a href="{{ route('operator.assets') }}" wire:navigate class="btn">Dijital Varlıklar</a>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm',
            'tone-ok' => $messageTone === 'success',
            'tone-info' => $messageTone !== 'success',
        ]) role="status">{{ $message }}</div>
    @endif

    {{-- 1. The answer in numbers: what waits for a review, what never ran. --}}
    <div class="metric-strip grid-cols-2 lg:grid-cols-4">
        <x-ta.metric-card label="İnceleme bekleyen" :value="$counts['needs_review']" :tone="$counts['needs_review'] > 0 ? 'look' : 'neutral'" hint="Onay bekleyen öneri" />
        <x-ta.metric-card label="Hiç çalışmadı" :value="$counts['never_run']" :muted="$counts['never_run'] === 0" hint="Keşif yapılmamış site" />
        <x-ta.metric-card label="İncelenmiş" :value="$counts['accepted']" hint="Kayda aktarılan bilgi" />
        <x-ta.metric-card label="Website" :value="$counts['websites']" hint="Portföydeki site" />
    </div>

    {{-- 2. Every site with its last discovery, what waits, and the one action. --}}
    <section>
        <div class="card overflow-hidden">
            <div class="flex flex-col gap-2 border-b border-gray-200 px-3 pt-1 sm:flex-row sm:items-center sm:justify-between sm:pt-0 dark:border-gray-800">
                <div class="-mb-px flex gap-1 overflow-x-auto no-scrollbar" role="group" aria-label="Filtre">
                    @foreach ($filters as $key => $label)
                        <button type="button" wire:click="$set('state', '{{ $key }}')" @class(['tab shrink-0', 'tab-active' => $state === $key])>{{ $label }}</button>
                    @endforeach
                </div>
                <div class="pb-2 sm:py-2">
                    <input type="search" wire:model.live.debounce.300ms="q" placeholder="Website, domain veya marka ara…" aria-label="Website ara"
                        class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none sm:w-72 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Website / Marka</th>
                            <th class="hidden md:table-cell">Hazırlık</th>
                            <th class="hidden sm:table-cell">Son keşif</th>
                            <th class="hidden text-right! lg:table-cell">Sayfa</th>
                            <th class="text-right!">Bekleyen</th>
                            <th class="hidden text-right! sm:table-cell">İncelenen</th>
                            <th class="hidden sm:table-cell"><span class="sr-only">Aksiyon</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php $busy = in_array($row['status'], ['queued', 'running'], true); @endphp
                            <tr wire:key="discovery-row-{{ $row['asset']->id }}">
                                <td class="sm:min-w-[13rem]">
                                    <a href="{{ route('operator.website.discovery', ['assetId' => $row['asset']->id]) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $row['asset']->name }}</a>
                                    <p class="mt-0.5 text-[13px] text-gray-500 dark:text-gray-400">{{ collect([$row['asset']->brand?->name, $row['asset']->brand?->customer?->name])->filter()->unique()->implode(', ') }}</p>
                                    <p class="mt-0.5 break-all text-xs text-gray-400">{{ $row['asset']->primary_url ?: $row['asset']->domain ?: 'URL/domain yok' }}</p>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 sm:hidden">
                                        <x-ta.pill :tone="$statusTone($row['status'])">{{ $statusLabel($row['status']) }}</x-ta.pill>
                                        @unless ($row['ready'])
                                            <x-ta.pill tone="look">URL gerekli</x-ta.pill>
                                        @endunless
                                        @if ($row['ready'])
                                            <button type="button" wire:click="runDiscovery({{ $row['asset']->id }})" wire:loading.attr="disabled" @disabled($busy)
                                                class="btn ml-auto h-8! px-2.5! text-xs! disabled:cursor-not-allowed disabled:opacity-40">{{ $busy ? 'Çalışıyor…' : 'Keşfi çalıştır' }}</button>
                                        @endif
                                    </div>
                                </td>
                                <td class="hidden whitespace-nowrap md:table-cell">
                                    <x-ta.pill :tone="$row['ready'] ? 'ok' : 'look'">{{ $row['ready'] ? 'Hazır' : 'URL gerekli' }}</x-ta.pill>
                                </td>
                                <td class="hidden whitespace-nowrap sm:table-cell">
                                    <x-ta.pill :tone="$statusTone($row['status'])">{{ $statusLabel($row['status']) }}</x-ta.pill>
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $row['last_run_human'] ?: 'Henüz yok' }}</p>
                                </td>
                                <td class="hidden text-right tabular-nums lg:table-cell">{{ $row['pages'] }}</td>
                                <td @class(['text-right tabular-nums', 'font-medium text-warning-700 dark:text-warning-400' => $row['pending'] > 0, 'text-gray-400' => $row['pending'] === 0])>{{ $row['pending'] }}</td>
                                <td class="hidden text-right tabular-nums sm:table-cell">{{ $row['accepted'] }}</td>
                                <td class="hidden text-right sm:table-cell">
                                    <div class="flex justify-end gap-1.5 whitespace-nowrap">
                                        <a href="{{ route('operator.website.discovery', ['assetId' => $row['asset']->id]) }}" wire:navigate class="btn-quiet hidden! h-8! px-2.5! text-xs! lg:inline-flex!">İncele</a>
                                        <button type="button" wire:click="runDiscovery({{ $row['asset']->id }})" wire:loading.attr="disabled" @disabled(! $row['ready'] || $busy)
                                            class="btn h-8! px-2.5! text-xs! disabled:cursor-not-allowed disabled:opacity-40">{{ $busy ? 'Çalışıyor…' : 'Keşfi çalıştır' }}</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-ta.empty-state size="sm" title="Filtreye uyan Website yok." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
