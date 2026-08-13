@php
    $rel = $data['relationships'] ?? [];
    $measures = $rel['measures'] ?? null;
    $evidenceTo = $rel['provides_evidence_to'] ?? $rel['evidence_to'] ?? [];
    $connection = $rel['connection'] ?? [];
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Relationships</h2>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $rel['subtitle'] ?? 'What this property measures and where evidence flows' }}</p>
        <p class="mt-1 text-xs text-gray-400">{{ $rel['note'] ?? 'Sibling Digital Assets — not children of GA4.' }}</p>
    </div>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Measures</h3>
        @if ($measures)
            <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex items-start gap-3">
                    <x-demo.digital-asset-mark :type="$measures['type'] ?? 'website'" size="lg" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $measures['name'] ?? 'Website' }}</p>
                        <p class="mt-0.5 text-xs text-gray-500">{{ $measures['detail'] ?? 'Primary Website Digital Asset' }}</p>
                        <p class="mt-2 text-[11px] text-gray-400">{{ $measures['role'] ?? 'GA4 measures Website behavior' }}</p>
                        @if (! empty($measures['route']))
                            <a href="{{ route($measures['route'], $measures['params'] ?? ['assetId' => $identity['website_asset_id'] ?? null]) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                {{ $measures['action'] ?? 'Open Website' }}
                            </a>
                        @else
                            <a href="{{ route('demo.website', ['assetId' => $identity['website_asset_id']]) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Open Website</a>
                        @endif
                    </div>
                    <x-ta.badge color="success" size="sm">{{ $measures['state'] ?? 'Linked' }}</x-ta.badge>
                </div>
            </div>
        @endif
    </section>

    <section>
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Provides evidence to</h3>
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($evidenceTo as $asset)
                <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex items-start gap-3">
                        <x-demo.digital-asset-mark :type="$asset['type'] ?? 'google_ads'" size="lg" />
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $asset['name'] }}</p>
                            <p class="mt-0.5 text-xs text-gray-500">{{ $asset['detail'] ?? '' }}</p>
                            <p class="mt-2 text-[11px] text-gray-400">{{ $asset['role'] ?? 'Sibling Digital Asset · evidence consumer' }}</p>
                            @if (! empty($asset['route']))
                                <a href="{{ route($asset['route'], $asset['params'] ?? []) }}" wire:navigate class="mt-3 inline-flex text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    {{ $asset['action'] ?? 'Open '.$asset['name'] }}
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
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $connection['property'] ?? $connection['name'] ?? 'GA4 property' }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $connection['detail'] ?? 'Provider connection · not a Digital Asset child' }}</p>
                </div>
                <x-ta.badge :color="match($connection['status'] ?? $connection['state'] ?? '') { 'Connected', 'Healthy' => 'success', 'Needs attention', 'Interrupted' => 'warning', default => 'light' }" size="sm">{{ $connection['status'] ?? $connection['state'] ?? 'Connected' }}</x-ta.badge>
            </div>
            <dl class="mt-4 grid gap-3 sm:grid-cols-3 text-sm">
                <div>
                    <dt class="text-xs text-gray-400">Property ID</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-gray-900 dark:text-white">{{ $connection['property_id'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">Last successful collection</dt>
                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $connection['last_collection'] ?? $connection['last'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-400">Binding</dt>
                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $connection['binding'] ?? 'Website connection' }}</dd>
                </div>
            </dl>
            @if (! empty($connection['note']))
                <p class="mt-3 text-[11px] text-gray-400">{{ $connection['note'] }}</p>
            @endif
            <button type="button" wire:click="setMeasSub('streams')" class="mt-3 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">Review streams →</button>
        </div>
    </section>
</div>
