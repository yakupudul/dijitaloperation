<?php

namespace App\Services\Collection;

use App\Services\Collection\Support\CollectionClock;

/**
 * Provider-neutral initial coverage planner from Data Contract historical_depth.
 * Does not call providers and does not invent a universal Google history window.
 */
final class HistoricalRangeResolver
{
    /** Contract token: GSC provider availability ceiling (~16 months). */
    private const PROVIDER_16M_DAYS = 486;

    public function __construct(
        private readonly CollectionClock $clock = new CollectionClock,
    ) {}

    /**
     * @param  array<string, mixed>|null  $historicalDepth  Registry requirement historical_depth
     * @return array{
     *   kind: 'snapshot'|'historical'|'none'|'priority_only',
     *   start: ?string,
     *   end: ?string,
     *   days: ?int,
     *   source_token: ?string,
     *   minimum_token: ?string,
     *   provider_limit_applied: bool,
     *   provider_limit_token: ?string,
     *   policy: array<string, mixed>
     * }
     */
    public function resolve(?array $historicalDepth, ?string $timezone = null): array
    {
        $tz = $timezone ?? 'UTC';
        $today = $this->clock->today($tz);

        $minimum = $this->normalizeToken($historicalDepth['minimum_required'] ?? null);
        $recommended = $this->normalizeToken($historicalDepth['recommended_initial_backfill'] ?? null);

        if ($recommended === null && $minimum === null) {
            return $this->noneResult($historicalDepth);
        }

        if ($this->isSnapshotToken($recommended) || ($recommended === null && $this->isSnapshotToken($minimum))) {
            return [
                'kind' => 'snapshot',
                'start' => null,
                'end' => null,
                'days' => null,
                'source_token' => $recommended ?? $minimum,
                'minimum_token' => $minimum,
                'provider_limit_applied' => false,
                'provider_limit_token' => null,
                'policy' => $historicalDepth ?? [],
            ];
        }

        if ($this->isPriorityToken($recommended) || $this->isPriorityToken($minimum)) {
            return [
                'kind' => 'priority_only',
                'start' => null,
                'end' => null,
                'days' => null,
                'source_token' => $recommended ?? $minimum,
                'minimum_token' => $minimum,
                'provider_limit_applied' => false,
                'provider_limit_token' => null,
                'policy' => $historicalDepth ?? [],
            ];
        }

        $sourceToken = $recommended ?? $minimum;
        $days = $this->daysFromToken($sourceToken);
        if ($days === null) {
            return $this->noneResult($historicalDepth);
        }

        $providerLimitToken = null;
        $providerLimitApplied = false;
        $providerCeiling = $this->providerCeilingDays($minimum, $recommended);
        if ($providerCeiling !== null && $days > $providerCeiling) {
            $days = $providerCeiling;
            $providerLimitApplied = true;
            $providerLimitToken = $minimum === 'provider_16m_available' ? $minimum : 'provider_ceiling';
        }

        // Inclusive day count: end is yesterday in reporting TZ (common provider lag-safe default for planning).
        $end = $today->subDay();
        $start = $end->subDays($days - 1);

        return [
            'kind' => 'historical',
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'source_token' => $sourceToken,
            'minimum_token' => $minimum,
            'provider_limit_applied' => $providerLimitApplied,
            'provider_limit_token' => $providerLimitToken,
            'policy' => $historicalDepth ?? [],
        ];
    }

    /**
     * Merge per-requirement depths for one request family into a single planning coverage.
     * Uses the longest recommended historical window among COLLECTION_READY requirements.
     *
     * @param  list<array<string, mixed>>  $requirements
     * @return array<string, mixed>
     */
    public function resolveForRequirements(array $requirements, ?string $timezone = null): array
    {
        $bestHistorical = null;
        $sawSnapshot = false;

        foreach ($requirements as $requirement) {
            $depth = $requirement['historical_depth'] ?? null;
            if (! is_array($depth)) {
                continue;
            }
            $resolved = $this->resolve($depth, $timezone);
            if ($resolved['kind'] === 'snapshot') {
                $sawSnapshot = true;
            }
            if ($resolved['kind'] !== 'historical') {
                continue;
            }
            if ($bestHistorical === null || (int) $resolved['days'] > (int) $bestHistorical['days']) {
                $bestHistorical = $resolved;
            }
        }

        if ($bestHistorical !== null) {
            return $bestHistorical;
        }

        if ($sawSnapshot) {
            return $this->resolve(['minimum_required' => 'current', 'recommended_initial_backfill' => 'current'], $timezone);
        }

        return $this->noneResult(null);
    }

    private function normalizeToken(mixed $token): ?string
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        return $token;
    }

    private function isSnapshotToken(?string $token): bool
    {
        return in_array($token, ['current', 'inventory', 'first_last_seen'], true);
    }

    private function isPriorityToken(?string $token): bool
    {
        return in_array($token, ['priority_sampled', 'priority_only'], true);
    }

    private function daysFromToken(?string $token): ?int
    {
        if ($token === null) {
            return null;
        }

        if ($token === '180d' || $token === '180d_ui_minimum') {
            return 180;
        }

        if ($token === 'provider_16m_available') {
            return self::PROVIDER_16M_DAYS;
        }

        if (preg_match('/^(\d+)d$/', $token, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function providerCeilingDays(?string $minimum, ?string $recommended): ?int
    {
        foreach ([$minimum, $recommended] as $token) {
            if ($token === 'provider_16m_available') {
                return self::PROVIDER_16M_DAYS;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $historicalDepth
     * @return array{
     *   kind: 'none',
     *   start: null,
     *   end: null,
     *   days: null,
     *   source_token: null,
     *   minimum_token: ?string,
     *   provider_limit_applied: bool,
     *   provider_limit_token: null,
     *   policy: array<string, mixed>
     * }
     */
    private function noneResult(?array $historicalDepth): array
    {
        return [
            'kind' => 'none',
            'start' => null,
            'end' => null,
            'days' => null,
            'source_token' => null,
            'minimum_token' => $this->normalizeToken($historicalDepth['minimum_required'] ?? null),
            'provider_limit_applied' => false,
            'provider_limit_token' => null,
            'policy' => $historicalDepth ?? [],
        ];
    }
}
