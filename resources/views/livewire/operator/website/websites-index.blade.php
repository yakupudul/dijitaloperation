@php
    $num = fn ($value) => number_format((float) $value, 0, ',', '.');
@endphp
<div class="space-y-6" data-websites-index>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="page-title" title="Markaya atanmış siteler">Web siteleri</h1>
            <p class="page-sub">{{ count($sites) }} site</p>
        </div>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Site, alan adı veya marka ara" aria-label="Ara" class="h-9 w-full min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm text-gray-700 shadow-card focus:border-gray-300 focus:ring-2 focus:ring-gray-200 sm:w-64 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
    </div>

    <section class="card overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr><th>Site</th><th class="hidden md:table-cell">Marka</th><th class="hidden text-right! sm:table-cell">Organik tıklama, 28 gün</th><th class="text-right!">Açık iş</th><th class="w-10"><span class="sr-only">Siteyi aç</span></th></tr>
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
                        $customerName = $site->brand?->customer?->name;
                        $showCustomer = filled($customerName) && mb_strtolower(trim($customerName)) !== mb_strtolower(trim((string) $brandName));
                        $openCount = (int) ($open[$site->id] ?? 0);
                    @endphp
                    <tr wire:key="site-{{ $site->id }}" data-website-row="{{ $site->id }}">
                        <td class="min-w-0 align-top">
                            <div class="flex min-w-0 items-start gap-2.5">
                                <x-ta.brand-avatar :name="$brandName ?? $site->name" :logo="$site->brand?->logo_url" size="sm" class="mt-0.5 shrink-0" />
                                <div class="min-w-0">
                                    <a href="{{ $siteUrl }}" wire:navigate class="font-medium text-gray-900 [overflow-wrap:anywhere] hover:underline dark:text-white">{{ $site->name }}</a>
                                    @if ($showAddress)<p class="text-[13px] text-gray-500 [overflow-wrap:anywhere]">{{ $address }}</p>@endif
                                    @if ($brandName)
                                        <p class="break-words text-[13px] text-gray-500 md:hidden"><a href="{{ route('operator.brand', ['brand' => $site->brand_id]) }}" wire:navigate class="hover:underline">{{ $brandName }}</a>@if ($showCustomer), {{ $customerName }}@endif</p>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="hidden align-top text-gray-700 md:table-cell dark:text-gray-300">
                            <a href="{{ route('operator.brand', ['brand' => $site->brand_id]) }}" wire:navigate class="break-words hover:underline">{{ $brandName }}</a>
                            @if ($showCustomer)<p class="text-[13px] text-gray-500">{{ $customerName }}</p>@endif
                        </td>
                        <td class="hidden text-right align-top tabular-nums sm:table-cell">{{ array_key_exists($site->id, $clicks) ? $num($clicks[$site->id]) : '—' }}</td>
                        <td class="text-right align-top tabular-nums" data-open-work>
                            @if ($openCount > 0)
                                <a href="{{ route('operator.website', ['assetId' => $site->id, 'tab' => 'yapilacaklar']) }}" wire:navigate class="link-neutral whitespace-nowrap font-normal" title="Sitenin Yapılacaklar listesini aç"><span class="font-medium text-gray-900 dark:text-white">{{ $num($openCount) }}</span><span class="hidden sm:inline"> açık iş</span></a>
                            @else
                                <span class="text-gray-500">0</span>
                            @endif
                        </td>
                        <td class="px-2! text-right align-top">
                            <a href="{{ $siteUrl }}" wire:navigate class="inline-flex h-7 w-7 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200" title="Siteyi aç" aria-label="Siteyi aç: {{ $site->name }}">
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.17 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-6! text-[13px] text-gray-500">Markaya atanmış web sitesi yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($unassigned > 0)
        <p class="text-[13px] text-gray-500">{{ $unassigned }} site markaya atanmamış. <a href="{{ route('operator.integrations.website') }}" wire:navigate class="link-neutral">Entegrasyonlar › Web siteleri</a></p>
    @endif
</div>
