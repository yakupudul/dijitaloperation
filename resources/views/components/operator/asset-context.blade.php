@php
    $a = 'operator_asset.';
    $brand = $asset->brand;
    $customer = $brand?->customer;
    $state = (string) ($status['data_state'] ?? 'unavailable');
    $stateClass = match ($state) {
        'fresh' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'stale' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'not_applicable' => 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-white/[0.04] dark:text-gray-300 dark:ring-gray-700',
        default => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
    };
    $dot = fn (string $s): string => match ($s) {
        'fresh' => 'bg-emerald-500',
        'stale' => 'bg-amber-500',
        'not_applicable' => 'bg-gray-300',
        default => 'bg-rose-500',
    };
@endphp
<div class="flex flex-col gap-2 rounded-xl bg-gray-50 px-4 py-2.5 text-sm ring-1 ring-inset ring-gray-200 dark:bg-white/[0.02] dark:ring-gray-800 lg:flex-row lg:items-center lg:justify-between" data-asset-context>
    <nav class="flex min-w-0 flex-wrap items-center gap-1.5 text-gray-500 dark:text-gray-400" aria-label="{{ __($a.'breadcrumb') }}">
        @if ($customer)
            <a href="{{ route('operator.customer', ['customerId' => $customer->id]) }}" wire:navigate class="hover:text-brand-600">{{ $customer->name }}</a>
            <span class="text-gray-300">›</span>
        @endif
        @if ($brand)
            <a href="{{ route('operator.brand', ['brand' => $brand->id, 'tab' => 'assets']) }}" wire:navigate class="hover:text-brand-600">{{ $brand->name }}</a>
            <span class="text-gray-300">›</span>
        @endif
        <details class="relative">
            <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-md px-1.5 py-0.5 font-medium text-gray-800 hover:bg-white dark:text-gray-100 dark:hover:bg-white/[0.06]">
                <span>{{ $typeLabel }}: {{ $asset->name }}</span>
                <span class="text-xs text-gray-400">▾</span>
            </summary>
            <div class="absolute left-0 z-40 mt-1 w-72 rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                <p class="px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-400">{{ __($a.'brand_assets') }}</p>
                @foreach ($siblings as $sibling)
                    <a href="{{ $sibling['url'] }}" wire:navigate @class([
                        'flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm',
                        'bg-brand-50 font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300' => $sibling['current'],
                        'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]' => ! $sibling['current'],
                    ])>
                        <span class="h-2 w-2 shrink-0 rounded-full {{ $dot($sibling['data_state']) }}"></span>
                        <span class="min-w-0 flex-1 truncate">{{ $sibling['name'] }}</span>
                        <span class="shrink-0 text-[11px] text-gray-400">{{ $sibling['type_label'] }}</span>
                    </a>
                @endforeach
                @if ($brand)
                    <a href="{{ route('operator.asset.create', ['brandId' => $brand->id]) }}" wire:navigate class="mt-1 block rounded-lg border-t border-gray-100 px-2.5 py-2 text-sm text-brand-600 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/[0.04]">+ {{ __($a.'add_asset') }}</a>
                @endif
            </div>
        </details>
    </nav>

    <div class="flex flex-wrap items-center gap-2">
        @foreach ($accounts as $account)
            <span class="inline-flex max-w-xs items-center gap-1 truncate rounded-full bg-white px-2.5 py-0.5 text-xs text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700" title="{{ $account['label'] }}: {{ $account['resource'] }}">
                <span class="font-medium">{{ $account['label'] }}</span><span class="truncate text-gray-400">· {{ $account['resource'] }}</span>
            </span>
        @endforeach
        <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $stateClass }}">
            {{ $status['data_state_label'] ?? __('operator.states.not_collected') }}
            @if (($status['last_sync'] ?? null) !== null)
                <span class="font-normal opacity-80">· {{ __($a.'last_data') }} {{ $status['last_update'] }}</span>
            @endif
        </span>
        @if ($hasSources)
            <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate @class([
                'rounded-lg px-2.5 py-1 text-xs font-semibold',
                'bg-brand-500 text-white' => $current === 'sources',
                'text-brand-700 ring-1 ring-inset ring-brand-200 hover:bg-brand-50 dark:text-brand-300 dark:ring-brand-500/30' => $current !== 'sources',
            ])>{{ __($a.'sources') }}</a>
        @endif
        <a href="{{ route('operator.asset.edit', ['assetId' => $asset->id]) }}" wire:navigate @class([
            'rounded-lg px-2.5 py-1 text-xs font-medium',
            'bg-gray-800 text-white dark:bg-white dark:text-gray-900' => $current === 'edit',
            'text-gray-600 ring-1 ring-inset ring-gray-300 hover:bg-white dark:text-gray-300 dark:ring-gray-700' => $current !== 'edit',
        ])>{{ __($a.'edit') }}</a>
    </div>
</div>
@if ($websiteHome)
    <p class="-mt-2 rounded-b-xl bg-blue-50 px-4 py-2 text-xs text-blue-800 ring-1 ring-inset ring-blue-200 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20">
        {{ __('operator_asset.website_home') }} <a href="{{ $websiteHome }}" wire:navigate class="font-semibold underline">{{ __('operator_asset.website_home_link') }} →</a>
    </p>
@endif
