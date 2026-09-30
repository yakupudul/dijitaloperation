@php
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
@endphp
<div class="space-y-4" data-websites-index>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Web siteleri</h1>
            <p class="mt-1 text-sm text-gray-500">Markaya atanmış siteler. Satıra tıklayınca site ekranı açılır.</p>
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Site, alan adı veya marka ara" aria-label="Ara" class="w-64 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white">
    </div>

    <section class="overflow-x-auto rounded-xl bg-white ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-100 text-xs text-gray-500 dark:border-gray-800">
                <tr><th class="px-4 py-3">Site</th><th class="px-4 py-3">Marka</th><th class="px-4 py-3 text-right">Organik tıklama · 28 gün</th><th class="px-4 py-3 text-right">Açık iş</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($sites as $site)
                    <tr wire:key="site-{{ $site->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-website-row="{{ $site->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('operator.website', ['assetId' => $site->id]) }}" wire:navigate class="font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $site->name }}</a>
                            <p class="text-xs text-gray-500">{{ $site->domain ?: $site->primary_url ?: '—' }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            <a href="{{ route('operator.brand', ['brand' => $site->brand_id]) }}" wire:navigate class="hover:underline">{{ $site->brand?->name }}</a>
                            @if ($site->brand?->customer)<p class="text-xs text-gray-400">{{ $site->brand->customer->name }}</p>@endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ array_key_exists($site->id, $clicks) ? $num($clicks[$site->id]) : '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $num($open[$site->id] ?? 0) }}</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('operator.website', ['assetId' => $site->id]) }}" wire:navigate class="rounded-lg px-3 py-1.5 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-400 dark:ring-brand-500/30">Siteyi aç</a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Markaya atanmış web sitesi yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($unassigned > 0)
        <p class="text-xs text-gray-500">{{ $unassigned }} site markaya atanmamış · <a href="{{ route('operator.integrations.website') }}" wire:navigate class="text-brand-600 hover:underline">Entegrasyonlar › Web siteleri</a></p>
    @endif
</div>
