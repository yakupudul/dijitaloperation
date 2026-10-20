{{--
    The one compact page head of every digital asset page (App\View\Components\Operator\AssetContext):
    breadcrumb line (small, gray), title row (when `title` is given: avatar / `mark` slot, h1, `status` slot, `actions` slot),
    one-line "Veri durumu", alerts as one collapsible line, default slot, `tabs` slot (always under the head).
--}}
@php
    $a = 'operator_asset.';
    $brand = $asset->brand;
    $customer = $brand?->customer;
    $dot = fn (string $tone): string => match ($tone) {
        'ok' => 'bg-success-500',
        'warn' => 'bg-warning-500',
        'bad' => 'bg-error-500',
        default => 'bg-gray-300',
    };
    // Analysis lives on the brand workspace: the brand name in the breadcrumb opens the matching brand tab.
    $workspaceTab = ['website' => 'arama', 'google_business_profile' => 'harita', 'gbp' => 'harita', 'google_ads' => 'google_ads', 'meta_ads' => 'meta'][$asset->type] ?? 'assets';
    $hasTitle = filled($title);
    $headLink = 'rounded-md px-1.5 py-0.5 text-xs';
@endphp
<div class="min-w-0 space-y-2.5" data-asset-head>
    <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs" data-asset-context>
        <nav class="flex min-w-0 flex-wrap items-center gap-1 text-gray-500 dark:text-gray-400" aria-label="{{ __($a.'breadcrumb') }}">
            @if ($showCustomer)
                <a href="{{ route('operator.customer', ['customerId' => $customer->id]) }}" wire:navigate class="break-words hover:text-gray-900 hover:underline dark:hover:text-white">{{ $customer->name }}</a>
                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
            @endif
            @if ($brand)
                <a href="{{ route('operator.brand', ['brand' => $brand->id, 'tab' => $workspaceTab]) }}" wire:navigate class="break-words hover:text-gray-900 hover:underline dark:hover:text-white" data-brand-link title="Analiz marka ekranında">{{ $brand->name }}</a>
                <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
            @endif
            <details class="relative min-w-0">
                <summary class="flex min-w-0 cursor-pointer list-none items-center gap-1 rounded-md px-1 py-0.5 text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.06]">
                    <span class="min-w-0 break-words">{{ $assetLabel }}</span>
                    <svg class="h-3 w-3 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                </summary>
                <div class="absolute left-0 z-40 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-gray-700">
                    <p class="px-2.5 py-1.5 text-xs text-gray-500">{{ __($a.'brand_assets') }}</p>
                    @foreach ($siblings as $sibling)
                        <a href="{{ $sibling['url'] }}" wire:navigate @class([
                            'flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm',
                            'bg-gray-100 font-semibold text-gray-900 dark:bg-white/[0.08] dark:text-white' => $sibling['current'],
                            'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]' => ! $sibling['current'],
                        ])>
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot($sibling['tone']) }}"></span>
                            <span class="min-w-0 flex-1 truncate" title="{{ $sibling['name'] }}">{{ $sibling['name'] }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $sibling['type_label'] }}</span>
                        </a>
                    @endforeach
                    @if ($brand)
                        <a href="{{ route('operator.asset.create', ['brandId' => $brand->id]) }}" wire:navigate class="mt-1 block rounded-lg border-t border-gray-100 px-2.5 py-2 text-sm text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:border-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.04]">+ {{ __($a.'add_asset') }}</a>
                    @endif
                </div>
            </details>
        </nav>

        <div class="flex shrink-0 items-center gap-1">
            @if ($hasSources)
                <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate @class([
                    $headLink,
                    'bg-gray-100 font-semibold text-gray-900 dark:bg-white/[0.08] dark:text-white' => $current === 'sources',
                    'link-neutral' => $current !== 'sources',
                ])>{{ __($a.'sources') }}</a>
            @endif
            <a href="{{ route('operator.asset.edit', ['assetId' => $asset->id]) }}" wire:navigate @class([
                $headLink,
                'bg-gray-100 font-semibold text-gray-900 dark:bg-white/[0.08] dark:text-white' => $current === 'edit',
                'link-neutral' => $current !== 'edit',
            ])>{{ __($a.'edit') }}</a>
        </div>
    </div>

    @if ($hasTitle)
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between" data-asset-title-row>
            <div class="flex min-w-0 items-start gap-3">
                @isset($mark)
                    {{ $mark }}
                @else
                    <x-ta.brand-avatar :name="$brand?->name ?? $title" :logo="$brandLogo" size="lg" class="mt-0.5" />
                @endisset
                <div class="min-w-0">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <h1 title="{{ $title }}" class="min-w-0 break-words text-[28px] leading-9 font-semibold tracking-tight text-gray-900 dark:text-white">{{ $title }}</h1>
                        @isset($status){{ $status }}@endisset
                    </div>
                    @isset($meta)
                        <div class="mt-0.5 min-w-0 break-words text-sm text-gray-500 dark:text-gray-400">{{ $meta }}</div>
                    @elseif (filled($subtitle))
                        <p class="mt-0.5 min-w-0 break-words text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
                    @endisset
                </div>
            </div>
            @isset($actions)
                <div class="flex min-w-0 flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end" data-asset-actions>{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <livewire:operator.assets.data-status-strip :asset-id="$asset->id" :key="'data-status-'.$asset->id" />

    @if ($websiteHome)
        <p class="flex min-w-0 flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400" data-website-home>
            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-gray-400" aria-hidden="true"></span>
            <span class="min-w-0">{{ __('operator_asset.website_home') }}</span>
            <a href="{{ $websiteHome }}" wire:navigate class="link-primary font-medium">{{ __('operator_asset.website_home_link') }}</a>
        </p>
    @endif

    @if ($alerts->isNotEmpty())
        @php
            // Red only when an alert costs money or traffic (site down, spend stopped, visits dropping): then the line opens.
            $isBlocking = fn ($alert): bool => $alert->kind === 'site_down' || in_array($alert->severity, ['critical', 'high'], true);
            $blocking = $alerts->contains($isBlocking);
        @endphp
        <details class="group min-w-0 text-xs" data-asset-alerts data-blocking="{{ $blocking ? '1' : '0' }}"@if ($blocking) open @endif>
            <summary class="flex min-w-0 cursor-pointer list-none items-center gap-2 rounded-lg px-2 py-1 text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/[0.04]">
                <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-error-500' => $blocking, 'bg-warning-500' => ! $blocking]) aria-hidden="true"></span>
                <span @class(['shrink-0 font-medium', 'text-error-700 dark:text-error-400' => $blocking, 'text-warning-800 dark:text-warning-400' => ! $blocking])>{{ $alerts->count() }} uyarı</span>
                <span class="min-w-0 flex-1 truncate text-gray-500" title="{{ $alerts->pluck('title')->unique()->implode(', ') }}">{{ $alerts->pluck('title')->unique()->implode(', ') }}</span>
                <svg class="h-3 w-3 shrink-0 text-gray-400 group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
            </summary>
            <ul class="mt-1 divide-y divide-gray-200 border-y border-gray-200 dark:divide-gray-800 dark:border-gray-800">
                @foreach ($alerts as $alert)
                    <x-ta.alarm-row :tone="$isBlocking($alert) ? 'critical' : 'look'" :title="$alert->title" :brand="$brand?->name ?? ''" :meta="$alert->first_detected_at?->diffForHumans()" :message="$alert->message" />
                @endforeach
            </ul>
        </details>
    @endif

    {{ $slot }}

    @isset($tabs)
        <div class="min-w-0 pt-1">{{ $tabs }}</div>
    @endisset
</div>
