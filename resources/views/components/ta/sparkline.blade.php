@props([
    'values' => [],
    'width' => 80,
    'height' => 28,
])

@php
    $points = array_values(array_map('floatval', is_array($values) ? $values : []));
    $path = '';
    if (count($points) >= 2) {
        $min = min($points);
        $max = max($points);
        $range = $max - $min;
        if ($range == 0.0) {
            $range = 1.0;
        }
        $padY = 2;
        $usableH = max($height - ($padY * 2), 1);
        $stepX = $width / (count($points) - 1);
        $coords = [];
        foreach ($points as $i => $value) {
            $x = round($i * $stepX, 2);
            $y = round($padY + ($usableH - (($value - $min) / $range) * $usableH), 2);
            $coords[] = $x.','.$y;
        }
        $path = 'M '.implode(' L ', $coords);
    }
@endphp

@if ($path !== '')
    <svg {{ $attributes->merge(['class' => 'inline-block shrink-0', 'width' => $width, 'height' => $height, 'viewBox' => "0 0 {$width} {$height}", 'aria-hidden' => 'true']) }} fill="none">
        <path d="{{ $path }}" stroke="#F97316" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
@endif
