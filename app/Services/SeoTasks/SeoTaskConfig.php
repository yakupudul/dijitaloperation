<?php

namespace App\Services\SeoTasks;

/**
 * Typed accessors for config/moxdop-seo-tasks.php. Thresholds live in config, not here.
 */
final class SeoTaskConfig
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return config('moxdop-seo-tasks.'.$key, $default);
    }

    public static function int(string $key, int $default): int
    {
        return (int) self::get($key, $default);
    }

    public static function float(string $key, float $default): float
    {
        return (float) self::get($key, $default);
    }

    /** @return list<string> */
    public static function list(string $key): array
    {
        $value = self::get($key, []);

        return is_array($value) ? array_values($value) : [];
    }

    /** Expected organic CTR at a (fractional) position using linear interpolation on the configured curve. */
    public static function ctrAt(float $position): float
    {
        $curve = self::get('ctr_curve', []);
        if (! is_array($curve) || $curve === []) {
            return 0.0;
        }
        ksort($curve);
        $positions = array_keys($curve);
        $first = (int) $positions[0];
        $last = (int) end($positions);
        if ($position <= $first) {
            return (float) $curve[$first];
        }
        if ($position >= $last) {
            return (float) $curve[$last];
        }
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper || ! isset($curve[$lower], $curve[$upper])) {
            return (float) ($curve[$lower] ?? $curve[$upper] ?? 0.0);
        }
        $fraction = $position - $lower;

        return (float) $curve[$lower] + ((float) $curve[$upper] - (float) $curve[$lower]) * $fraction;
    }

    public static function targetCtr(): float
    {
        return self::ctrAt((float) self::int('ctr_curve_target_position', 3));
    }
}
