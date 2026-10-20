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

    {{-- 1. The answer first: what waits for a review, what never ran, what is already in the records. One button each filters the list. --}}
    @php
        $softCards = [
            ['key' => 'needs_review', 'tone' => $counts['needs_review'] > 0 ? 'soft-amber' : '', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>', 'number' => number_format($counts['needs_review'], 0, ',', '.').' öneri', 'title' => 'İnceleme bekleyen', 'line' => 'Onay bekleyen öneri', 'label' => 'İncelemeye bak'],
            ['key' => 'never_run', 'tone' => '', 'icon' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>', 'number' => number_format($counts['never_run'], 0, ',', '.').' site', 'title' => 'Hiç çalışmadı', 'line' => 'Keşif yapılmamış site', 'label' => 'Siteleri göster'],
            ['key' => 'all', 'tone' => '', 'icon' => '<path d="m5 12 4 4L19 6"/>', 'number' => number_format($counts['accepted'], 0, ',', '.').' bilgi', 'title' => 'İncelenmiş', 'line' => 'Kayda aktarılan bilgi, portföydeki '.number_format($counts['websites'], 0, ',', '.').' Website içinde', 'label' => 'Tümünü göster'],
        ];
    @endphp
    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ($softCards as $card)
            <div @class(['soft-card', $card['tone']]) data-discovery-count="{{ $card['key'] }}">
                <span @class(['soft-icon hidden! sm:flex!', 'text-warning-600 dark:text-warning-400' => $card['tone'] === 'soft-amber']) aria-hidden="true"><svg class="h-[17px] w-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $card['icon'] !!}</svg></span>
                <p class="soft-number">{{ $card['number'] }}</p>
                <div>
                    <p class="text-[15px] font-semibold tracking-[-0.01em] text-gray-900 dark:text-white">{{ $card['title'] }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-600 dark:text-gray-300">{{ $card['line'] }}</p>
                </div>
                <div class="mt-auto pt-1">
                    <button type="button" wire:click="$set('state', '{{ $card['key'] }}')" class="btn">{{ $card['label'] }}</button>
                </div>
            </div>
        @endforeach
    </div>

    {{-- 2. Every site with its last discovery, what waits, and the one action. --}}
    <section>
        <div class="section-head">
            <h2 class="section-title">Siteler <span class="section-count">{{ count($rows) }}</span></h2>
        </div>
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex gap-1.5 overflow-x-auto no-scrollbar" role="group" aria-label="Filtre">
                @foreach ($filters as $key => $label)
                    <button type="button" wire:click="$set('state', '{{ $key }}')" @class(['seg', 'seg-active' => $state === $key])>{{ $label }}</button>
                @endforeach
            </div>
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="Website, domain veya marka ara…" aria-label="Website ara"
                class="h-9 w-full rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-900 placeholder:text-gray-400 focus:border-gray-400 focus:outline-none sm:w-72 dark:border-gray-700 dark:bg-gray-900 dark:text-white" />
        </div>
        <div class="card overflow-hidden">

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
                                    <div class="flex items-start gap-3">
                                    <span class="mt-0.5 hidden shrink-0 sm:block"><x-ta.brand-avatar :name="$row['asset']->brand?->name ?? $row['asset']->name" :logo="$row['asset']->brand?->logo_url" /></span>
                                    <div class="min-w-0">
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
                                    </div>
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
                            <tr><td colspan="7"><x-ta.empty-state icon="none" size="sm" title="Filtreye uyan Website yok." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
