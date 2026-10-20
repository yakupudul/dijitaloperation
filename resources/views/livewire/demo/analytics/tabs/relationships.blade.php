@php
    $rel = $data['relationships'] ?? [];
    $measures = $rel['measures'] ?? [];
    $evidenceTo = $rel['provides_evidence_to'] ?? [];
    $connection = $rel['technical_connection'] ?? $rel['connection'] ?? [];

    $assetType = static function (string $name): string {
        $n = strtolower($name);

        return match (true) {
            str_contains($n, 'website') => 'website',
            str_contains($n, 'meta') => 'meta_ads',
            str_contains($n, 'google ads') => 'google_ads',
            str_contains($n, 'search console') => 'gsc',
            str_contains($n, 'gbp') || str_contains($n, 'business') => 'gbp',
            default => 'ga4',
        };
    };
    $propertyId = $connection['property_id'] ?? $identity['property_id'] ?? null;
    $measurementId = $connection['measurement_id'] ?? $identity['measurement_id'] ?? null;
@endphp

{{-- Secondary: which site this property measures, who uses its numbers, and the property binding itself. --}}
<section data-ga4-relationships>
    <div class="section-head">
        <h2 class="section-title" title="Analytics mülkünün ölçtüğü site ve verisini kullanan kardeş varlıklar">{{ __('operator.ga4.relationship_summary') }}</h2>
    </div>
    <ul class="card">
        @foreach ($measures as $item)
            <li class="card-row flex-wrap sm:flex-nowrap">
                <x-demo.digital-asset-mark :type="$assetType($item['asset'] ?? 'website')" size="md" class="shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['asset'] }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">Ölçtüğü site{{ ! empty($item['detail']) ? ', '.$item['detail'] : '' }}</p>
                </div>
                <x-ta.pill tone="ok">Bağlı</x-ta.pill>
                @if (! empty($item['route']))
                    <a href="{{ route($item['route'], ['assetId' => $item['asset_id'] ?? $identity['website_asset_id'] ?? null]) }}" wire:navigate class="btn h-7! px-2.5! text-xs!">{{ __('operator.chrome.open_website') }}</a>
                @endif
            </li>
        @endforeach

        @foreach ($evidenceTo as $asset)
            <li class="card-row flex-wrap sm:flex-nowrap">
                <x-demo.digital-asset-mark :type="$assetType($asset['asset'] ?? '')" size="md" class="shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $asset['asset'] }}</p>
                    <p class="mt-0.5 text-[13px] text-gray-500">Bu veriyi kullanır{{ ! empty($asset['detail']) ? ', '.$asset['detail'] : '' }}</p>
                </div>
                @if (! empty($asset['route']))
                    <a href="{{ route($asset['route'], ['assetId' => $asset['asset_id'] ?? null]) }}" wire:navigate class="btn h-7! px-2.5! text-xs!">Aç</a>
                @endif
            </li>
        @endforeach

        <li class="card-row flex-wrap sm:flex-nowrap" data-ga4-connection>
            <span class="row-icon" aria-hidden="true"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></span>
            <div class="min-w-0 flex-1" title="{{ $connection['note'] ?? '' }}">
                <p class="text-sm font-medium text-gray-900 dark:text-white">Analytics mülkü</p>
                <p class="mt-0.5 text-[13px] tabular-nums text-gray-500">
                    @if ($propertyId || $measurementId)
                        Mülk {{ $propertyId ?? '—' }}, ölçüm kimliği {{ $measurementId ?? '—' }}
                    @else
                        Mülk seçilmedi
                    @endif
                </p>
            </div>
            <x-ta.pill :tone="$toneOf($connection['status'] ?? 'Connected')">{{ $say($connection['status'] ?? 'Connected') }}</x-ta.pill>
            <button type="button" wire:click="setMeasSub('streams')" class="btn h-7! px-2.5! text-xs!">Veri akışları</button>
        </li>
    </ul>
</section>
