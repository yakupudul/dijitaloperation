@props([
    'maxHeight' => null,
])

{{--
    Sticky-header table wrapper (Table standard, docs/product/MOXDOP_DESIGN_SYSTEM.md).
    Wrap a plain <table> (thead/tbody) as the default slot; the header stays
    visible while the body scrolls.
--}}
<div
    {{ $attributes->class(['mox-entity-table']) }}
    @if (filled($maxHeight)) style="max-height: {{ $maxHeight }}" @endif
>
    {{ $slot }}
</div>
