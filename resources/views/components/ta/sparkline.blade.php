@props([
    'values' => [],
    'width' => 80,
    'height' => 28,
    'tone' => 'neutral',
    'fill' => true,
])

@php
    // Direction-aware colour: ok (green), bad (red), look (amber), live (violet), neutral (gray). Never orange: orange is the action colour.
    $stroke = match ($tone) {
        'ok', 'positive', 'success' => 'text-success-500',
        'bad', 'negative', 'error', 'critical' => 'text-error-500',
        'look', 'warning' => 'text-warning-500',
        'live' => 'text-live-500',
        default => 'text-gray-400 dark:text-gray-500',
    };
    $points = array_values(array_map('floatval', is_array($values) ? $values : []));
    $path = '';
    $area = '';
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
        $area = $path.' L '.$width.','.$height.' L 0,'.$height.' Z';
    }
@endphp

@if ($path !== '')
    <svg {{ $attributes->merge(['class' => "inline-block shrink-0 $stroke", 'width' => $width, 'height' => $height, 'viewBox' => "0 0 {$width} {$height}", 'aria-hidden' => 'true']) }} fill="none">
        @if ($fill)
            <path d="{{ $area }}" fill="currentColor" fill-opacity="0.12" />
        @endif
        <path d="{{ $path }}" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="{{ explode(',', end($coords))[0] }}" cy="{{ explode(',', end($coords))[1] }}" r="2" fill="currentColor" />
    </svg>
@endif
