@props([
    'label',
    'tone' => 'neutral',
])

{{--
    Small rounded status indicator. "tone" accepts either operator-facing
    status words (active|paused|attention|ok) or the seven semantic families
    (primary|result|traffic|efficiency|warning|critical|neutral) — both sets
    of modifiers exist on .mox-status-pill (theme.css).
--}}
<span {{ $attributes->class(['mox-status-pill', 'mox-status-pill--' . strtolower((string) $tone)]) }}>
    {{ $label }}
</span>
