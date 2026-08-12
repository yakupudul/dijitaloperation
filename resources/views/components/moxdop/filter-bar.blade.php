@props([
    'label' => 'Filters',
])

{{--
    Compact, cross-module filter toolbar (Filter standard,
    docs/product/MOXDOP_DESIGN_SYSTEM.md). Deliberately low-chrome — data
    before filter chrome. Callers supply their own filter controls as the
    default slot.
--}}
<section {{ $attributes->class(['mox-filter-bar']) }} aria-label="{{ $label }}">
    {{ $slot }}
</section>
