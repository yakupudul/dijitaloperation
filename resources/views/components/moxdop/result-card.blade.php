@props([
    'family' => 'contact',
    'label',
    'value',
    'cost' => null,
])

{{--
    Single platform-attributed result-mix entry. "family" is contact
    (contact/conversion results) or traffic (traffic/engagement signals) —
    see "Result Mix" in docs/product/META_ADS_EXPERT_WORKSPACE.md. Never sums
    distinct action types; each entry stands on its own.
--}}
<div {{ $attributes->class(['mox-result-card', 'mox-result-card--' . strtolower((string) $family)]) }}>
    <span class="mox-result-card__label">{{ $label }}</span>
    <span>
        <span class="mox-result-card__value">{{ $value === null || $value === '' ? '—' : $value }}</span>

        @if (filled($cost))
            <span class="mox-result-card__cost">{{ $cost }}</span>
        @endif
    </span>
</div>
