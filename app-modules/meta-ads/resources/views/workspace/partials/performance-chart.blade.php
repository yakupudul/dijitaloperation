@php
    $trend = $trend ?? ['available' => false, 'points' => [], 'labels' => [], 'values' => []];
    $metric = $trend['metric'] ?? 'spend';
    $type = $trend['type'] ?? 'currency';
    $points = $trend['points'] ?? [];
    $values = array_values(array_filter($trend['values'] ?? [], fn ($v) => $v !== null));
    $labels = $trend['labels'] ?? [];
    $count = count($values);
    $max = max($values ?: [1]);
    $min = min($values ?: [0]);
    $range = max($max - $min, 0.0001);
    $width = 720;
    $height = 220;
    $padX = 12;
    $padY = 16;
    $plotW = $width - ($padX * 2);
    $plotH = $height - ($padY * 2);

    $format = function ($value) use ($type) {
        if (! is_numeric($value)) {
            return '—';
        }

        return match ($type) {
            'percentage_point' => number_format((float) $value, 2).'%',
            'currency' => number_format((float) $value, 2),
            'decimal' => number_format((float) $value, 2),
            default => number_format((float) $value, 0),
        };
    };
@endphp

@if (! ($trend['available'] ?? false))
    <div class="mox-chart-empty">
        <p class="mox-muted">{{ $trend['note'] ?? 'Trend unavailable for this period.' }}</p>
        @if ($trend['needs_analyze'] ?? false)
            <button type="button" class="mox-btn mox-btn--primary" wire:click="analyzeMetaSelectedPeriod">
                Analyze this period
            </button>
        @endif
    </div>
@else
    <div
        class="mox-performance-chart"
        x-data="{
            hover: null,
            points: @js(array_values(array_map(fn ($i) => [
                'date' => $labels[$i] ?? '',
                'value' => $values[$i] ?? null,
                'label' => $format($values[$i] ?? null),
            ], array_keys($values)))),
            coords: @js(array_values(array_map(function ($i) use ($values, $count, $padX, $plotW, $padY, $plotH, $min, $range) {
                $x = $count > 1 ? $padX + (($i / ($count - 1)) * $plotW) : $padX + ($plotW / 2);
                $y = $padY + ($plotH - (((($values[$i] - $min) / $range) * $plotH)));

                return ['x' => round($x, 1), 'y' => round($y, 1)];
            }, array_keys($values)))),
        }"
    >
        <svg class="mox-performance-chart__svg" viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $trend['label'] ?? 'Performance trend' }}">
            <line x1="{{ $padX }}" y1="{{ $height - $padY }}" x2="{{ $width - $padX }}" y2="{{ $height - $padY }}" class="mox-chart-axis" />
            @php
                $poly = [];
                foreach ($values as $i => $value) {
                    $x = $count > 1 ? $padX + (($i / ($count - 1)) * $plotW) : $padX + ($plotW / 2);
                    $y = $padY + ($plotH - ((($value - $min) / $range) * $plotH));
                    $poly[] = round($x, 1).','.round($y, 1);
                }
            @endphp
            <polyline fill="none" class="mox-chart-line" stroke-width="2.5" points="{{ implode(' ', $poly) }}" />
            @foreach ($values as $i => $value)
                @php
                    $x = $count > 1 ? $padX + (($i / ($count - 1)) * $plotW) : $padX + ($plotW / 2);
                    $y = $padY + ($plotH - ((($value - $min) / $range) * $plotH));
                @endphp
                <circle
                    class="mox-chart-dot"
                    cx="{{ round($x, 1) }}"
                    cy="{{ round($y, 1) }}"
                    r="4"
                    x-on:mouseenter="hover = {{ $i }}"
                    x-on:mouseleave="hover = null"
                />
            @endforeach
        </svg>
        <div class="mox-chart-tooltip" x-show="hover !== null" x-cloak>
            <template x-if="hover !== null && points[hover]">
                <div>
                    <strong x-text="points[hover].date"></strong>
                    <span x-text="points[hover].label"></span>
                </div>
            </template>
        </div>
        <div class="mox-chart-foot">
            <span>{{ $labels[0] ?? '' }}</span>
            <span>{{ $labels[count($labels) - 1] ?? '' }}</span>
        </div>
    </div>
@endif
