<div class="space-y-8" wire:poll.10s>
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <h1 class="text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white" title="{{ __('public_discovery.intro') }} {{ __('public_discovery.how') }}">{{ app()->getLocale() === 'tr' ? 'Açık Web Keşfi' : 'Public Discovery' }}</h1>
        </div>
        <a href="{{ route('operator.assets') }}" wire:navigate class="text-sm text-gray-500 hover:text-gray-900 dark:hover:text-white">Dijital Varlıklar</a>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-lg px-4 py-3 text-sm',
            'tone-ok' => $messageTone === 'success',
            'tone-info' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    <div class="grid grid-cols-2 gap-x-8 gap-y-6 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ([
            ['label' => 'Website', 'value' => $counts['websites']],
            ['label' => 'Hiç çalışmadı', 'value' => $counts['never_run']],
            ['label' => 'İnceleme bekleyen', 'value' => $counts['needs_review']],
            ['label' => 'İncelenmiş', 'value' => $counts['accepted']],
        ] as $card)
            <x-ta.metric-card :label="$card['label']" :value="$card['value']" />
        @endforeach
    </div>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Website, domain veya marka ara…" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 lg:max-w-md" />
        <div class="flex flex-wrap gap-2">
            @foreach (['all' => 'Tümü', 'needs_review' => 'İnceleme bekliyor', 'never_run' => 'Hiç çalışmadı', 'issue' => 'Sorunlu'] as $key => $label)
                <button type="button" wire:click="$set('state', '{{ $key }}')" @class([
                    'rounded-lg px-3 py-1.5 text-sm',
                    'bg-gray-100 font-medium text-gray-900 dark:bg-white/10 dark:text-white' => $state === $key,
                    'text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/5' => $state !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-gray-200 text-xs text-gray-500 dark:border-gray-800">
                    <tr>
                        <th class="font-normal py-2 pr-5">Website / Marka</th>
                        <th class="font-normal px-5 py-3">Hazırlık</th>
                        <th class="font-normal px-5 py-3">Son keşif</th>
                        <th class="font-normal px-5 py-3">Sayfa</th>
                        <th class="font-normal px-5 py-3">Bekleyen</th>
                        <th class="font-normal px-5 py-3">İncelenen</th>
                        <th class="font-normal px-5 py-3 text-right">Aksiyon</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-5 py-2.5">
                                <a href="{{ route('operator.website.discovery', ['assetId' => $row['asset']->id]) }}" wire:navigate class="font-medium text-gray-900 hover:underline dark:text-white">{{ $row['asset']->name }}</a>
                                <p class="mt-1 text-xs text-gray-500">{{ $row['asset']->brand?->name }}, {{ $row['asset']->brand?->customer?->name }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $row['asset']->primary_url ?: $row['asset']->domain ?: 'URL/domain yok' }}</p>
                            </td>
                            <td class="px-5 py-2.5">
                                <x-ta.pill :tone="$row['ready'] ? 'ok' : 'look'">{{ $row['ready'] ? 'Hazır' : 'URL gerekli' }}</x-ta.pill>
                            </td>
                            <td class="px-5 py-2.5">
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ str($row['status'])->replace('_', ' ')->ucfirst() }}</span>
                                <p class="mt-1 text-xs text-gray-500">{{ $row['last_run_human'] ?: 'Henüz yok' }}</p>
                            </td>
                            <td class="px-5 py-2.5 font-medium">{{ $row['pages'] }}</td>
                            <td class="px-5 py-2.5"><span class="font-semibold {{ $row['pending'] > 0 ? 'text-warning-600' : 'text-gray-500' }}">{{ $row['pending'] }}</span></td>
                            <td class="px-5 py-2.5 font-medium">{{ $row['accepted'] }}</td>
                            <td class="px-5 py-2.5">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('operator.website.discovery', ['assetId' => $row['asset']->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-900 dark:hover:bg-white/5 dark:hover:text-white">İncele</a>
                                    <button type="button" wire:click="runDiscovery({{ $row['asset']->id }})" wire:loading.attr="disabled" @disabled(! $row['ready'] || in_array($row['status'], ['queued', 'running'], true)) class="rounded-lg px-3 py-2 text-xs font-medium text-gray-900 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-40 dark:text-white dark:ring-gray-700 dark:hover:bg-white/5">Keşfi çalıştır</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-10 text-center text-sm text-gray-500">Filtreye uyan Website yok.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
