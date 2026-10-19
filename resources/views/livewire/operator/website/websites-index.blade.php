@php
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
@endphp
<div class="space-y-4" data-websites-index>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Web siteleri</h1>
            <p class="mt-1 text-sm text-gray-500">Markaya atanmış siteler. Satıra tıklayınca site ekranı açılır.</p>
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Site, alan adı veya marka ara" aria-label="Ara" class="h-9 w-full min-w-0 rounded-lg border-gray-300 text-sm sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
    </div>

    <section class="surface overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-gray-100 text-xs text-gray-500 dark:border-gray-800">
                <tr><th class="px-4 py-3">Site</th><th class="hidden px-4 py-3 md:table-cell">Marka</th><th class="hidden px-4 py-3 text-right sm:table-cell">Organik tıklama · 28 gün</th><th class="px-4 py-3 text-right">Açık iş</th><th class="w-10 px-2 py-3"><span class="sr-only">Siteyi aç</span></th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($sites as $site)
                    @php
                        $siteUrl = route('operator.website', ['assetId' => $site->id]);
                        $address = $site->domain ?: $site->primary_url;
                        // Each value once (2026-10-19): the address only when it is not the site's name, the customer only
                        // when it is not the brand's name.
                        $norm = fn (?string $v): string => mb_strtolower(rtrim(preg_replace('#^(https?://)?(www\.)?#i', '', trim((string) $v)), '/'));
                        $showAddress = filled($address) && $norm($address) !== $norm($site->name);
                        $brandName = $site->brand?->name;
                        $customerName = $site->brand?->customer?->name;
                        $showCustomer = filled($customerName) && mb_strtolower(trim($customerName)) !== mb_strtolower(trim((string) $brandName));
                        $openCount = (int) ($open[$site->id] ?? 0);
                    @endphp
                    <tr wire:key="site-{{ $site->id }}" class="hover:bg-gray-50 dark:hover:bg-white/[0.03]" data-website-row="{{ $site->id }}">
                        <td class="px-4 py-3 align-top">
                            <div class="flex min-w-0 items-start gap-2.5">
                                <x-ta.brand-avatar :name="$brandName ?? $site->name" :logo="$site->brand?->logo_url" size="sm" class="mt-0.5" />
                                <div class="min-w-0">
                                    <a href="{{ $siteUrl }}" wire:navigate class="break-all font-medium text-gray-900 hover:text-brand-600 dark:text-white">{{ $site->name }}</a>
                                    @if ($showAddress)<p class="break-all text-xs text-gray-500">{{ $address }}</p>@endif
                                    @if ($brandName)
                                        <p class="break-words text-xs text-gray-500 md:hidden"><a href="{{ route('operator.brand', ['brand' => $site->brand_id]) }}" wire:navigate class="hover:underline">{{ $brandName }}</a>@if ($showCustomer) · {{ $customerName }}@endif</p>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="hidden px-4 py-3 align-top text-gray-700 md:table-cell dark:text-gray-300">
                            <a href="{{ route('operator.brand', ['brand' => $site->brand_id]) }}" wire:navigate class="break-words hover:underline">{{ $brandName }}</a>
                            @if ($showCustomer)<p class="text-xs text-gray-400">{{ $customerName }}</p>@endif
                        </td>
                        <td class="hidden px-4 py-3 text-right align-top tabular-nums sm:table-cell">{{ array_key_exists($site->id, $clicks) ? $num($clicks[$site->id]) : '—' }}</td>
                        <td class="px-4 py-3 text-right align-top tabular-nums" data-open-work>
                            @if ($openCount > 0)
                                <a href="{{ route('operator.website', ['assetId' => $site->id, 'tab' => 'yapilacaklar']) }}" wire:navigate class="link-neutral whitespace-nowrap font-normal" title="Sitenin Yapılacaklar listesini aç"><span class="font-medium text-gray-900 dark:text-white">{{ $num($openCount) }}</span> açık iş</a>
                            @else
                                <span class="text-gray-400">0</span>
                            @endif
                        </td>
                        <td class="px-2 py-3 text-right align-top">
                            <a href="{{ $siteUrl }}" wire:navigate class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200" title="Siteyi aç" aria-label="Siteyi aç: {{ $site->name }}">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-gray-500">Markaya atanmış web sitesi yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($unassigned > 0)
        <p class="text-xs text-gray-500">{{ $unassigned }} site markaya atanmamış · <a href="{{ route('operator.integrations.website') }}" wire:navigate class="link-neutral">Entegrasyonlar › Web siteleri</a></p>
    @endif
</div>
