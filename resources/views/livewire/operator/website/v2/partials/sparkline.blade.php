{{-- Daily series as a small inline SVG line (no JS). $points: list<array{date: string, value: int|float}>, $label, $format. --}}
@php
    $count = count($points);
    $max = max(1, ...array_map(fn (array $p) => (float) $p['value'], $points ?: [['value' => 0]]));
    $w = 300;
    $h = 64;
    $x = fn (int $i): float => $count > 1 ? round($i / ($count - 1) * $w, 2) : $w / 2;
    $y = fn (float $v): float => round($h - 2 - ($v / $max) * ($h - 6), 2);
    $line = collect($points)->map(fn (array $p, int $i) => $x($i).','.$y((float) $p['value']))->implode(' ');
    $slot = $count > 0 ? $w / $count : $w;
@endphp
<figure class="space-y-1" data-sparkline="{{ $label }}">
    <figcaption class="flex items-baseline justify-between text-xs text-gray-500">
        <span>{{ $label }} · günlük</span>
        <span>en yüksek {{ $format($max) }}</span>
    </figcaption>
    @if ($count > 1)
        <svg viewBox="0 0 {{ $w }} {{ $h }}" preserveAspectRatio="none" class="h-16 w-full text-brand-500" role="img" aria-label="{{ $label }} günlük seyir">
            <line x1="0" y1="{{ $h - 2 }}" x2="{{ $w }}" y2="{{ $h - 2 }}" class="stroke-gray-200 dark:stroke-gray-700" stroke-width="1" vector-effect="non-scaling-stroke" />
            <polygon points="0,{{ $h - 2 }} {{ $line }} {{ $w }},{{ $h - 2 }}" fill="currentColor" fill-opacity="0.08" />
            <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            @foreach ($points as $i => $p)
                <rect x="{{ round($i * $slot, 2) }}" y="0" width="{{ round($slot, 2) }}" height="{{ $h }}" fill="transparent"><title>{{ \Carbon\CarbonImmutable::parse($p['date'])->format('d.m.Y') }}: {{ $format($p['value']) }}</title></rect>
            @endforeach
        </svg>
        <div class="flex justify-between text-[10px] text-gray-400">
            <span>{{ \Carbon\CarbonImmutable::parse($points[0]['date'])->format('d.m') }}</span>
            <span>{{ \Carbon\CarbonImmutable::parse($points[$count - 1]['date'])->format('d.m') }}</span>
        </div>
    @else
        <p class="text-xs text-gray-400">Veri yok.</p>
    @endif
</figure>
