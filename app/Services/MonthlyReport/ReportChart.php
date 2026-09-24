<?php

namespace App\Services\MonthlyReport;

/**
 * Tiny server-side SVG line chart for the monthly report (this month solid, previous month dashed, by day of
 * month). Plain SVG so it prints, works in the client link and in DomPDF without JavaScript.
 */
final class ReportChart
{
    /**
     * @param  array<int|string, float|int>  $current  day => value
     * @param  array<int|string, float|int>  $previous  day => value
     */
    public static function line(array $current, array $previous, string $title, int $width = 640, int $height = 170): string
    {
        $pad = ['l' => 44, 'r' => 10, 't' => 12, 'b' => 22];
        $plotW = $width - $pad['l'] - $pad['r'];
        $plotH = $height - $pad['t'] - $pad['b'];
        $max = max(1.0, (float) max([0, ...array_values($current), ...array_values($previous)]));
        $days = 31;
        $x = static fn (int $day): float => $pad['l'] + ($day - 1) / ($days - 1) * $plotW;
        $y = static fn (float $v): float => $pad['t'] + $plotH - ($v / $max) * $plotH;
        $path = static function (array $series) use ($x, $y): string {
            ksort($series);
            $points = [];
            foreach ($series as $day => $value) {
                $points[] = sprintf('%.1f,%.1f', $x((int) $day), $y((float) $value));
            }

            return implode(' ', $points);
        };
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $grid = '';
        foreach ([0, 0.5, 1] as $f) {
            $gy = $pad['t'] + $plotH - $f * $plotH;
            $grid .= sprintf('<line x1="%d" x2="%d" y1="%.1f" y2="%.1f" stroke="#e5e7eb" stroke-width="1"/>', $pad['l'], $width - $pad['r'], $gy, $gy);
            $grid .= sprintf('<text x="%d" y="%.1f" font-size="10" text-anchor="end" fill="#6b7280">%s</text>', $pad['l'] - 6, $gy + 3, $e(self::short($max * $f)));
        }
        foreach ([1, 8, 15, 22, 29] as $day) {
            $grid .= sprintf('<text x="%.1f" y="%d" font-size="10" text-anchor="middle" fill="#6b7280">%d</text>', $x($day), $height - 6, $day);
        }
        $lines = '';
        if (count($previous) > 1) {
            $lines .= '<polyline fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-dasharray="4 3" points="'.$path($previous).'"/>';
        }
        if (count($current) > 1) {
            $lines .= '<polyline fill="none" stroke="#2563eb" stroke-width="2" points="'.$path($current).'"/>';
        }

        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" role="img" aria-label="%s"><title>%s</title>%s%s</svg>', $width, $height, $e($title), $e($title), $grid, $lines);
    }

    private static function short(float $value): string
    {
        return match (true) {
            $value >= 1_000_000 => number_format($value / 1_000_000, 1, ',', '.').'M',
            $value >= 1_000 => number_format($value / 1_000, 1, ',', '.').'B',
            default => number_format($value, $value < 10 ? 1 : 0, ',', '.'),
        };
    }
}
