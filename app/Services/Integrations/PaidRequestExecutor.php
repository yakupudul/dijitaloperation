<?php

namespace App\Services\Integrations;

use App\Models\Evidence;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Provider-agnostic paid-request executor with freshness reuse + fingerprint lock.
 *
 * Prevents duplicate paid calls from concurrent identical MISS executions
 * (e.g. double-click) where Laravel's cache lock can safely serialize them.
 */
class PaidRequestExecutor
{
    public function __construct(
        private readonly EvidenceFreshnessGuard $guard,
    ) {}

    /**
     * @param  callable(): array{
     *     evidence: Evidence,
     *     reported_cost_usd: float,
     *     metadata?: array<string, mixed>
     * }  $onMiss  Executes exactly one provider call and persists Evidence
     * @return array{
     *     decision: EvidenceFreshnessDecision,
     *     evidence: Evidence|null,
     *     cache_status: string,
     *     provider_called: bool,
     *     reported_cost_usd: float,
     *     metadata: array<string, mixed>
     * }
     */
    public function executeOrReuse(
        string $fingerprint,
        callable $onMiss,
        bool $forceRefresh = false,
    ): array {
        $initial = $this->guard->evaluate($fingerprint, forceRefresh: $forceRefresh);

        if ($initial['decision']->isCacheHit() && $initial['evidence'] instanceof Evidence) {
            return [
                'decision' => $initial['decision'],
                'evidence' => $initial['evidence'],
                'cache_status' => $initial['cache_status'],
                'provider_called' => false,
                'reported_cost_usd' => 0.0,
                'metadata' => [],
            ];
        }

        $lockKey = 'paid-request:'.$fingerprint;
        $lockSeconds = 60;
        $waitSeconds = 20;

        try {
            return Cache::lock($lockKey, $lockSeconds)->block($waitSeconds, function () use ($fingerprint, $onMiss, $forceRefresh): array {
                $recheck = $this->guard->evaluate($fingerprint, forceRefresh: $forceRefresh);

                if ($recheck['decision']->isCacheHit() && $recheck['evidence'] instanceof Evidence) {
                    return [
                        'decision' => $recheck['decision'],
                        'evidence' => $recheck['evidence'],
                        'cache_status' => $recheck['cache_status'],
                        'provider_called' => false,
                        'reported_cost_usd' => 0.0,
                        'metadata' => [
                            'lock_recheck_hit' => true,
                        ],
                    ];
                }

                $result = $onMiss();
                $evidence = $result['evidence'];
                $cost = (float) ($result['reported_cost_usd'] ?? 0.0);
                $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];

                return [
                    'decision' => EvidenceFreshnessDecision::Miss,
                    'evidence' => $evidence,
                    'cache_status' => EvidenceFreshnessDecision::Miss->value,
                    'provider_called' => true,
                    'reported_cost_usd' => $cost,
                    'metadata' => $metadata,
                ];
            });
        } catch (LockTimeoutException) {
            $afterWait = $this->guard->evaluate($fingerprint, forceRefresh: false);

            if ($afterWait['decision']->isCacheHit() && $afterWait['evidence'] instanceof Evidence) {
                return [
                    'decision' => $afterWait['decision'],
                    'evidence' => $afterWait['evidence'],
                    'cache_status' => $afterWait['cache_status'],
                    'provider_called' => false,
                    'reported_cost_usd' => 0.0,
                    'metadata' => [
                        'lock_timeout_reused' => true,
                    ],
                ];
            }

            throw $this->duplicateInFlightException();
        }
    }

    private function duplicateInFlightException(): \RuntimeException
    {
        return new \RuntimeException(
            'Another identical paid DataForSEO request is already in progress. Fresh results will appear shortly — no duplicate request was sent.',
        );
    }
}
