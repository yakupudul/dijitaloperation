{{--
    The one compact page head of every digital asset page (App\View\Components\Operator\AssetContext), one line before the tabs:
    avatar / `mark` slot, brand › asset (the h1 when `title` is given) with the switcher, `status` slot; on the right the page's
    `actions`, the data-status dot (details on hover and in its small panel), one "Yenile" (`refresh`), "Hızlı giriş" and the "⋯"
    menu (Veri kaynakları, Düzenle). Open alerts are one chip at the end of the line (a blocking one opens under it).
    `meta` / `subtitle` sits under the title, the default slot (banners) under the line, `tabs` last.
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
    $crumb = 'min-w-0 break-words text-sm text-gray-500 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-white';
    $menuLink = 'block rounded-lg px-2.5 py-2 text-sm';
    $menuIdle = 'text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.04]';
    $menuOn = 'bg-gray-100 font-medium text-gray-900 dark:bg-white/[0.08] dark:text-white';
    $slotHtml = trim((string) $slot);
@endphp
<div class="min-w-0 space-y-3" data-asset-head>
    <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-4 gap-y-2" data-asset-context>
        <div class="flex min-w-0 flex-[1_1_20rem] items-center gap-3" @if ($hasTitle) data-asset-title-row @endif>
            @if ($hasTitle)
                @isset($mark)
                    {{ $mark }}
                @else
                    <x-ta.brand-avatar :name="$brand?->name ?? $title" :logo="$brandLogo" size="md" />
                @endisset
            @endif
            <div class="min-w-0">
                <nav class="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5" aria-label="{{ __($a.'breadcrumb') }}">
                    @if ($showCustomer)
                        <a href="{{ route('operator.customer', ['customerId' => $customer->id]) }}" wire:navigate class="{{ $crumb }}">{{ $customer->name }}</a>
                        <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
                    @endif
                    @if ($brand)
                        <a href="{{ route('operator.brand', ['brand' => $brand->id, 'tab' => $workspaceTab]) }}" wire:navigate class="{{ $crumb }}" data-brand-link title="Analiz marka ekranında">{{ $brand->name }}</a>
                        <span class="text-gray-300 dark:text-gray-600" aria-hidden="true">›</span>
                    @endif
                    @if ($hasTitle)
                        <h1 title="{{ $title }}" class="page-title line-clamp-2 min-w-0 [overflow-wrap:anywhere]">{{ $title }}</h1>
                    @endif
                    {{-- Switcher to the brand's other assets: the asset name itself, or only a chevron next to the h1. --}}
                    <details class="relative min-w-0 max-w-full" x-data @click.outside="$el.removeAttribute('open')">
                        <summary class="flex min-w-0 cursor-pointer list-none items-center gap-1 rounded-md px-1 py-0.5 text-sm text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/[0.06]" title="{{ $hasTitle ? __($a.'brand_assets') : $assetLabel }}">
                            @if ($hasTitle)
                                <span class="sr-only">{{ __($a.'brand_assets') }}</span>
                            @else
                                <span class="min-w-0 truncate">{{ $assetLabel }}</span>
                            @endif
                            <svg class="h-3.5 w-3.5 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                        </summary>
                        <div class="absolute left-0 z-40 mt-1 w-72 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                            <p class="px-2.5 py-1.5 text-xs text-gray-500">{{ __($a.'brand_assets') }}</p>
                            @foreach ($siblings as $sibling)
                                <a href="{{ $sibling['url'] }}" wire:navigate @class([
                                    'flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm',
                                    'bg-gray-100 font-medium text-gray-900 dark:bg-white/[0.08] dark:text-white' => $sibling['current'],
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
                    @isset($status){{ $status }}@endisset
                </nav>
                @isset($meta)
                    <div class="mt-0.5 min-w-0 text-[13px] text-gray-500 [overflow-wrap:anywhere] dark:text-gray-400">{{ $meta }}</div>
                @elseif (filled($subtitle))
                    <p class="mt-0.5 min-w-0 text-[13px] text-gray-500 [overflow-wrap:anywhere] dark:text-gray-400">{{ $subtitle }}</p>
                @endisset
            </div>
        </div>

        <div class="flex min-w-0 flex-wrap items-center gap-2 sm:justify-end" data-asset-actions>
            @isset($actions){{ $actions }}@endisset
            <livewire:operator.assets.data-status-strip :asset-id="$asset->id" :refresh="$refresh" :key="'data-status-'.$asset->id" />
            @if ($websiteHome)
                <a href="{{ $websiteHome }}" wire:navigate class="link-primary text-xs" title="{{ __($a.'website_home') }}" data-website-home>{{ __($a.'website_home_link') }}</a>
            @endif
            <x-operator.quick-access :links="$externalLinks" size="small" />
            <details class="relative" data-asset-menu x-data @click.outside="$el.removeAttribute('open')">
                <summary class="flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.06] dark:hover:text-white" title="{{ $hasSources ? __($a.'sources').', ' : '' }}{{ __($a.'edit') }}" aria-label="Diğer">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M4 10a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Zm7.5 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0ZM19 10a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"/></svg>
                </summary>
                <div class="absolute right-0 z-40 mt-1 w-56 max-w-[calc(100vw-2rem)] rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                    @if ($hasSources)
                        <a href="{{ route('operator.asset.sources', ['assetId' => $asset->id]) }}" wire:navigate @class([$menuLink, $current === 'sources' ? $menuOn : $menuIdle])>{{ __($a.'sources') }}</a>
                    @endif
                    <a href="{{ route('operator.asset.edit', ['assetId' => $asset->id]) }}" wire:navigate @class([$menuLink, $current === 'edit' ? $menuOn : $menuIdle])>{{ __($a.'edit') }}</a>
                </div>
            </details>
        </div>

        @if ($alerts->isNotEmpty())
            @php
                // Red only when an alert costs money or traffic (site down, spend stopped, visits dropping): then it opens under the line.
                $isBlocking = fn ($alert): bool => $alert->kind === 'site_down' || in_array($alert->severity, ['critical', 'high'], true);
                $blocking = $alerts->contains($isBlocking);
            @endphp
            <details class="group min-w-0 max-w-full text-xs open:basis-full" data-asset-alerts data-blocking="{{ $blocking ? '1' : '0' }}"@if ($blocking) open @endif>
                <summary class="-mx-1 flex min-w-0 cursor-pointer list-none items-center gap-1.5 rounded-md px-1 py-0.5 text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.04]" title="{{ $alerts->pluck('title')->unique()->implode(', ') }}">
                    <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-error-500' => $blocking, 'bg-warning-500' => ! $blocking]) aria-hidden="true"></span>
                    <span @class(['shrink-0 font-medium', 'text-error-700 dark:text-error-400' => $blocking, 'text-warning-800 dark:text-warning-400' => ! $blocking])>{{ $alerts->count() }} uyarı</span>
                    <span class="hidden min-w-0 max-w-[28rem] flex-1 truncate text-gray-500 group-open:inline">{{ $alerts->pluck('title')->unique()->implode(', ') }}</span>
                    <svg class="h-3 w-3 shrink-0 text-gray-400 group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                </summary>
                <ul class="card mt-2">
                    @foreach ($alerts as $alert)
                        <x-ta.alarm-row :tone="$isBlocking($alert) ? 'critical' : 'look'" :title="$alert->title" :brand="$brand?->name ?? ''" :logo="$brandLogo" :meta="$alert->first_detected_at?->diffForHumans()" :message="$alert->message" />
                    @endforeach
                </ul>
            </details>
        @endif
    </div>

    @if ($slotHtml !== '')
        <div class="flex min-w-0 flex-wrap items-center gap-x-5 gap-y-1.5 text-xs" data-asset-status-line>{{ $slot }}</div>
    @endif

    @isset($tabs)
        <div class="min-w-0">{{ $tabs }}</div>
    @endisset
</div>
