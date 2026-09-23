<?php

namespace App\Services\Ai;

/**
 * Converts token usage to USD from config/moxdop-ai-pricing.php. Unknown models return null.
 */
final class AiPricing
{
    /** @return array{input: float, output: float}|null */
    public function price(string $provider, string $model): ?array
    {
        if ($provider === 'openrouter' && str_ends_with($model, ':free')) {
            return ['input' => 0.0, 'output' => 0.0];
        }
        $table = config('moxdop-ai-pricing.models.'.$provider);
        if (! is_array($table)) {
            return null;
        }
        $row = $table[$model] ?? $table['*'] ?? null;
        if (! is_array($row)) {
            // Dated snapshots like "claude-haiku-4-5-20251001" fall back to their alias.
            $alias = preg_replace('/-\d{8}$/', '', $model);
            $row = is_string($alias) ? ($table[$alias] ?? null) : null;
        }

        return is_array($row) ? ['input' => (float) $row['input'], 'output' => (float) $row['output']] : null;
    }

    public function isFree(string $provider, string $model): bool
    {
        $price = $this->price($provider, $model);

        return $price !== null && $price['input'] === 0.0 && $price['output'] === 0.0;
    }

    public function cost(string $provider, string $model, int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): ?float
    {
        $price = $this->price($provider, $model);
        if ($price === null) {
            return null;
        }
        $perToken = static fn (float $perMillion): float => $perMillion / 1_000_000;
        $readMultiplier = (float) config('moxdop-ai-pricing.cache_read_multiplier', 0.1);
        $writeMultiplier = (float) config('moxdop-ai-pricing.cache_write_multiplier', 1.25);

        return round(
            $input * $perToken($price['input'])
            + $output * $perToken($price['output'])
            + $cacheRead * $perToken($price['input']) * $readMultiplier
            + $cacheWrite * $perToken($price['input']) * $writeMultiplier,
            6,
        );
    }
}
