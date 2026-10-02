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
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('operator.ga4.relationship_summary') }}</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">What this property measures and where evidence flows</p>
        <p class="mt-1 text-xs text-gray-400">Sibling Digital Assets — not children of GA4.</p>
    </div>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Measures</h3>
        <div class="grid gap-3">
            @foreach ($measures as $item)
                <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-start gap-3">
                        <x-demo.digital-asset-mark :type="$assetType($item['asset'] ?? 'website')" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $item['asset'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $item['detail'] ?? '' }}</p>
                            <p class="mt-2 text-[11px] text-gray-400">{{ $item['relationship'] ?? 'Measures' }} · Website Digital Asset</p>
                            @if (! empty($item['route']))
                                <a href="{{ route($item['route'], ['assetId' => $item['asset_id'] ?? $identity['website_asset_id'] ?? null]) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    {{ __('operator.chrome.open_website') }}
                                </a>
                            @endif
                        </div>
                        <x-ta.badge color="success" size="sm">Linked</x-ta.badge>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Provides evidence to</h3>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($evidenceTo as $asset)
                <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-start gap-3">
                        <x-demo.digital-asset-mark :type="$assetType($asset['asset'] ?? '')" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $asset['asset'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $asset['detail'] ?? '' }}</p>
                            <p class="mt-2 text-[11px] text-gray-400">Sibling Digital Asset · evidence consumer</p>
                            @if (! empty($asset['route']))
                                <a href="{{ route($asset['route'], ['assetId' => $asset['asset_id'] ?? null]) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    Open {{ $asset['asset'] }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Technical connection</h3>
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $connection['type'] ?? 'GA4 property binding' }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">Provider connection · not a Digital Asset child</p>
                </div>
                <x-ta.badge :color="match($connection['status'] ?? '') { 'Connected', 'Healthy' => 'success', 'Needs attention', 'Interrupted' => 'warning', default => 'light' }" size="sm">{{ $connection['status'] ?? 'Connected' }}</x-ta.badge>
            </div>
            <dl class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
                <div>
                    <dt class="text-xs text-gray-400">Property ID</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-gray-900 dark:text-white">{{ $connection['property_id'] ?? $identity['property_id'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">Measurement ID</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-gray-900 dark:text-white">{{ $connection['measurement_id'] ?? $identity['measurement_id'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">Binding</dt>
                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">Website connection</dd>
                </div>
            </dl>
            @if (! empty($connection['note']))
                <p class="mt-3 text-[11px] text-gray-400">{{ $connection['note'] }}</p>
            @endif
            <button type="button" wire:click="setMeasSub('streams')" class="mt-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review streams →</button>
        </div>
    </section>
</div>
