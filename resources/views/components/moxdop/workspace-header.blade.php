@props([
    'brand' => null,
    'account' => null,
    'accountId' => null,
    'business' => null,
    'currency' => null,
    'lastSync' => null,
    'historyCoverage' => null,
])

{{--
    Standardized per-asset workspace identity block (Navigation standard,
    docs/product/MOXDOP_DESIGN_SYSTEM.md). Secondary meta line is built from
    whichever of business/currency/lastSync/historyCoverage are provided —
    missing values are simply omitted, never rendered as "—" or "0".
--}}
@php
    $metaParts = array_values(array_filter([
        filled($business) ? "Business {$business}" : null,
        filled($currency) ? $currency : null,
        filled($lastSync) ? "Updated {$lastSync}" : null,
        filled($historyCoverage) ? $historyCoverage : null,
    ]));
@endphp

<div {{ $attributes->class(['mox-workspace-header']) }}>
    <div>
        @if (filled($brand))
            <p class="mox-workspace-header__brand">{{ $brand }}</p>
        @endif

        @if (filled($account))
            <h3 class="mox-workspace-header__title">
                {{ $account }}
                @if (filled($accountId))
                    <span class="mox-workspace-header__id">{{ $accountId }}</span>
                @endif
            </h3>
        @endif

        @if (! empty($metaParts))
            <p class="mox-workspace-header__meta">{{ implode(' · ', $metaParts) }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="mox-workspace-header__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
