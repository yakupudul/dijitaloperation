@php
    /** @var array $summary */
    $integration = $summary['integration'];
    $bound = $summary['bound_resource'];
    $resources = $summary['bindable_resources'];
    $meta = is_array($bound?->metadata) ? $bound->metadata : [];
@endphp

<div class="mb-4 space-y-4 text-sm">
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Connections</p>
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Meta Ad Account binding</h3>
        <p class="mt-1 text-gray-600 dark:text-gray-300">
            Select a discovered Meta Ad Account from the agency Meta Integration. Do not paste tokens or account IDs here.
        </p>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Meta Integration</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                {{ $summary['integration_configured'] ? 'Connected' : 'Not connected' }}
            </dd>
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Bound Ad Account</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $summary['account_label'] }}</dd>
        </div>
    </dl>

    @if ($resources->isEmpty())
        <p class="text-gray-600 dark:text-gray-300">
            No Meta Ad Accounts discovered yet. Open Settings → Integrations → Meta → Manage resources, then Discover resources.
        </p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ $resources->count() }} discoverable Ad Account{{ $resources->count() === 1 ? '' : 's' }} available below.
        </p>
    @endif

    @if ($bound && $meta !== [])
        <div class="rounded-xl border border-gray-200 p-3 dark:border-white/10">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Account context</p>
            <ul class="mt-2 space-y-1 text-gray-700 dark:text-gray-200">
                @if (! empty($meta['currency']))
                    <li>Currency: {{ $meta['currency'] }}</li>
                @endif
                @if (! empty($meta['timezone_name']))
                    <li>Timezone: {{ $meta['timezone_name'] }}</li>
                @endif
                @if (! empty($meta['business_name']) || ! empty($meta['business_id']))
                    <li>
                        Business:
                        {{ $meta['business_name'] ?? '—' }}
                        @if (! empty($meta['business_id']))
                            <span class="text-gray-500">({{ $meta['business_id'] }})</span>
                        @endif
                    </li>
                @endif
                @if (! empty($meta['discovery_paths']) && is_array($meta['discovery_paths']))
                    <li>Discovery paths: {{ implode(', ', $meta['discovery_paths']) }}</li>
                @endif
            </ul>
        </div>
    @endif
</div>
