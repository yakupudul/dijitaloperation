<div class="space-y-6">
    <div class="flex flex-col gap-4 border-b border-gray-200 pb-5 dark:border-gray-800 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-400">Brand Intelligence</p>
            <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ app()->getLocale() === 'tr' ? 'Kamu Keşif' : 'Public Discovery' }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-gray-500 dark:text-gray-400">Tüm Website varlıklarında public crawl durumunu, bekleyen Brand Context adaylarını ve son çalışma sonucunu tek yerden yönetin.</p>
        </div>
        <a href="{{ route('operator.assets') }}" wire:navigate class="rounded-lg bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">Dijital Varlıklar</a>
    </div>

    @if ($message !== '')
        <div @class([
            'rounded-xl px-4 py-3 text-sm ring-1 ring-inset',
            'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20' => $messageTone === 'success',
            'bg-blue-50 text-blue-800 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-500/20' => $messageTone !== 'success',
        ])>{{ $message }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Website', 'value' => $counts['websites']],
            ['label' => 'Hiç çalışmadı', 'value' => $counts['never_run']],
            ['label' => 'İnceleme bekleyen', 'value' => $counts['needs_review']],
            ['label' => 'Kabul edilmiş', 'value' => $counts['accepted']],
        ] as $card)
            <section class="rounded-xl bg-white p-5 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
                <p class="text-sm font-medium text-gray-500">{{ $card['label'] }}</p>
                <p class="mt-3 text-2xl font-bold text-gray-900 dark:text-white">{{ $card['value'] }}</p>
            </section>
        @endforeach
    </div>

    <div class="flex flex-col gap-3 rounded-xl bg-white p-4 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700 lg:flex-row lg:items-center lg:justify-between">
        <input type="search" wire:model.live.debounce.300ms="q" placeholder="Website, domain veya marka ara…" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 lg:max-w-md" />
        <div class="flex flex-wrap gap-2">
            @foreach (['all' => 'Tümü', 'needs_review' => 'İnceleme bekliyor', 'never_run' => 'Hiç çalışmadı', 'issue' => 'Sorunlu'] as $key => $label)
                <button type="button" wire:click="$set('state', '{{ $key }}')" @class([
                    'rounded-lg px-3 py-2 text-xs font-medium',
                    'bg-brand-500 text-white' => $state === $key,
                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $state !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="overflow-hidden rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-400 dark:bg-white/[0.03]">
                    <tr>
                        <th class="px-5 py-3">Website / Marka</th>
                        <th class="px-5 py-3">Hazırlık</th>
                        <th class="px-5 py-3">Son keşif</th>
                        <th class="px-5 py-3">Sayfa</th>
                        <th class="px-5 py-3">Bekleyen</th>
                        <th class="px-5 py-3">Kabul</th>
                        <th class="px-5 py-3 text-right">Aksiyon</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="px-5 py-4">
                                <a href="{{ route('operator.website.discovery', ['assetId' => $row['asset']->id]) }}" wire:navigate class="font-semibold text-gray-900 hover:text-brand-600 dark:text-white">{{ $row['asset']->name }}</a>
                                <p class="mt-1 text-xs text-gray-500">{{ $row['asset']->brand?->name }} · {{ $row['asset']->brand?->customer?->name }}</p>
                                <p class="mt-1 text-xs text-gray-400">{{ $row['asset']->primary_url ?: $row['asset']->domain ?: 'URL/domain yok' }}</p>
                            </td>
                            <td class="px-5 py-4">
                                <span class="text-xs font-semibold {{ $row['ready'] ? 'text-emerald-600' : 'text-rose-600' }}">{{ $row['ready'] ? 'Hazır' : 'URL gerekli' }}</span>
                            </td>
                            <td class="px-5 py-4">
                                <span class="text-xs font-semibold uppercase text-gray-600 dark:text-gray-300">{{ str($row['status'])->replace('_', ' ') }}</span>
                                <p class="mt-1 text-xs text-gray-400">{{ $row['last_run_human'] ?: 'Henüz yok' }}</p>
                            </td>
                            <td class="px-5 py-4 font-medium">{{ $row['pages'] }}</td>
                            <td class="px-5 py-4"><span class="font-semibold {{ $row['pending'] > 0 ? 'text-amber-600' : 'text-gray-500' }}">{{ $row['pending'] }}</span></td>
                            <td class="px-5 py-4 font-medium text-emerald-600">{{ $row['accepted'] }}</td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('operator.website.discovery', ['assetId' => $row['asset']->id]) }}" wire:navigate class="rounded-lg px-3 py-2 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30">İncele</a>
                                    <button type="button" wire:click="runDiscovery({{ $row['asset']->id }})" wire:loading.attr="disabled" @disabled(! $row['ready']) class="rounded-lg bg-brand-500 px-3 py-2 text-xs font-semibold text-white hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-40">Keşfi çalıştır</button>
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
