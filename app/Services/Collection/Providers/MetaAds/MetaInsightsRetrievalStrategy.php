<?php

namespace App\Services\Collection\Providers\MetaAds;

/**
 * Deterministic SYNC vs ASYNC Insights transport selection.
 * Does not change logical dataset identity.
 */
final class MetaInsightsRetrievalStrategy
{
    public const string MODE_SYNC = 'SYNC';

    public const string MODE_ASYNC = 'ASYNC';

    /**
     * @param  array{preferred_mode: string, high_cardinality: bool}  $definition
     */
    public function resolve(array $definition, string $level, int $inclusiveDays, ?string $forcedMode = null): string
    {
        if ($forcedMode === self::MODE_SYNC || $forcedMode === self::MODE_ASYNC) {
            return $forcedMode;
        }

        $preferred = (string) ($definition['preferred_mode'] ?? 'sync');
        if ($preferred === 'async') {
            return self::MODE_ASYNC;
        }

        if ($preferred === 'sync') {
            return self::MODE_SYNC;
        }

        // sync_then_async: escalate by day threshold + level cardinality.
        /** @var array<string, int> $thresholds */
        $thresholds = config('moxdop-meta-ads-collector.async_day_threshold', []);
        $threshold = (int) ($thresholds[$level] ?? $thresholds['campaign'] ?? 90);

        if ($inclusiveDays > $threshold || (($definition['high_cardinality'] ?? false) && $inclusiveDays > (int) ($threshold / 2))) {
            return self::MODE_ASYNC;
        }

        return self::MODE_SYNC;
    }
}
