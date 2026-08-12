@props([
    'title',
    'body' => null,
    'icon' => null,
    'compact' => false,
])

{{--
    Standardized "nothing here" surface. Callers must state *why* there is
    nothing (no data collected yet / genuinely zero / filtered out) via
    title+body — never a bare blank area (Missing != zero,
    docs/product/MOXDOP_DESIGN_SYSTEM.md).
--}}
<div {{ $attributes->class(['mox-empty-state', $compact ? 'mox-empty-state--compact' : null]) }}>
    @if (filled($icon))
        <span class="mox-empty-state__icon" aria-hidden="true">{{ $icon }}</span>
    @endif

    <p class="mox-empty-state__title">{{ $title }}</p>

    @if (filled($body))
        <p class="mox-empty-state__body">{{ $body }}</p>
    @endif

    @isset($action)
        <div class="mox-empty-state__action">
            {{ $action }}
        </div>
    @endisset
</div>
