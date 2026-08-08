@php
    $values = array_values(array_filter($values ?? [], fn ($v) => $v !== null));
    $max = max($values ?: [1]);
    $min = min($values ?: [0]);
    $range = max($max - $min, 0.0001);
    $width = 280;
    $height = 64;
    $count = count($values);
@endphp

@if ($count < 2)
    <div class="mox-muted">Not enough daily points for a trend.</div>
@else
    <svg class="mox-sparkline" viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label ?? 'Trend' }}">
        @php
            $points = [];
            foreach ($values as $i => $value) {
                $x = ($i / ($count - 1)) * $width;
                $y = $height - ((($value - $min) / $range) * ($height - 8)) - 4;
                $points[] = round($x, 1).','.round($y, 1);
            }
        @endphp
        <polyline fill="none" stroke="currentColor" stroke-width="2" points="{{ implode(' ', $points) }}"></polyline>
    </svg>
@endif
