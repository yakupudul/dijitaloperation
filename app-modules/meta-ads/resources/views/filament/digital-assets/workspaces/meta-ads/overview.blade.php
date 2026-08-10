@php
    /** @var array $summary */
    $asset = $summary['asset'];
    $integration = $summary['integration'];
    $bound = $summary['bound_resource'];
@endphp

<div class="space-y-4 text-sm">
    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Meta Ads</p>
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $asset->name }}</h3>
        <p class="mt-1 text-gray-600 dark:text-gray-300">
            Paid-media Digital Asset bound to one Meta Ad Account via the agency Meta Integration.
            Insights, Findings, and Analyst are not part of this connection milestone.
        </p>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Meta Integration</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $summary['connection_label'] }}</dd>
            @if ($integration)
                <dd class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $integration->name }}</dd>
            @endif
        </div>
        <div class="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Ad Account</dt>
            <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ $summary['account_label'] }}</dd>
            @if ($bound)
                <dd class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $bound->external_id }}</dd>
            @endif
        </div>
    </dl>
</div>
