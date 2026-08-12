@props([
    'label' => 'Data Health',
    'tone' => 'neutral',
    'wireClick' => null,
])

{{--
    Compact clickable badge summarizing Data Health (e.g. "Data Health: Good"
    / "Partial" / "Degraded"). Never expands inline — pass a `wireClick`
    action (e.g. to open a Filament modal/drawer) for the detail view.
--}}
@if (filled($wireClick))
    <button
        type="button"
        {{ $attributes->class(['mox-data-health-badge', 'mox-data-health-badge--' . strtolower((string) $tone)]) }}
        wire:click="{{ $wireClick }}"
    >
        {{ $label }}
    </button>
@else
    <span {{ $attributes->class(['mox-data-health-badge', 'mox-data-health-badge--' . strtolower((string) $tone)]) }}>
        {{ $label }}
    </span>
@endif
