<?php

namespace App\Services\Integrations;

use App\Models\Evidence;
use App\Models\Run;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Generic Evidence TTL cost guard.
 *
 * Collectors declare TTL when writing Evidence.fresh_until.
 * This guard never invents product-specific TTL values.
 */
class EvidenceFreshnessGuard
{
    /**
     * @return array{
     *     decision: EvidenceFreshnessDecision,
     *     evidence: Evidence|null,
     *     cache_status: string,
     *     reported_cost_usd: float|null
     * }
     */
    public function evaluate(
        string $fingerprint,
        ?CarbonInterface $now = null,
        bool $forceRefresh = false,
    ): array {
        $now ??= Carbon::now();

        if ($forceRefresh) {
            return [
                'decision' => EvidenceFreshnessDecision::BypassAllowed,
                'evidence' => null,
                'cache_status' => EvidenceFreshnessDecision::BypassAllowed->value,
                'reported_cost_usd' => null,
            ];
        }

        /** @var Evidence|null $evidence */
        $evidence = Evidence::query()
            ->where('request_fingerprint', $fingerprint)
            ->whereNotNull('fresh_until')
            ->where('fresh_until', '>', $now)
            ->whereHas('run', function ($query): void {
                $query->where('status', 'completed');
            })
            ->orderByDesc('fresh_until')
            ->orderByDesc('id')
            ->first();

        if ($evidence === null || ! $this->isReusable($evidence)) {
            return [
                'decision' => EvidenceFreshnessDecision::Miss,
                'evidence' => null,
                'cache_status' => EvidenceFreshnessDecision::Miss->value,
                'reported_cost_usd' => null,
            ];
        }

        return [
            'decision' => EvidenceFreshnessDecision::HitFresh,
            'evidence' => $evidence,
            'cache_status' => EvidenceFreshnessDecision::HitFresh->value,
            'reported_cost_usd' => 0.0,
        ];
    }

    /**
     * Build Run metadata for a cache HIT (provider call skipped).
     *
     * @return array<string, mixed>
     */
    public function cacheHitRunMetadata(
        string $provider,
        string $useCase,
        string $fingerprint,
        Evidence $evidence,
    ): array {
        return [
            'provider' => $provider,
            'use_case' => $useCase,
            'request_fingerprint' => $fingerprint,
            'cache_status' => EvidenceFreshnessDecision::HitFresh->value,
            'reported_cost_usd' => 0.0,
            'provider_call_skipped' => true,
            'reused_evidence_id' => $evidence->id,
            'reused_run_id' => $evidence->run_id,
        ];
    }

    /**
     * Build Run metadata for a provider MISS / executed call.
     *
     * @param  array<string, mixed>  $providerMetadata
     * @return array<string, mixed>
     */
    public function providerCallRunMetadata(
        string $provider,
        string $useCase,
        string $fingerprint,
        string $cacheStatus,
        array $providerMetadata = [],
    ): array {
        return array_merge([
            'provider' => $provider,
            'use_case' => $useCase,
            'request_fingerprint' => $fingerprint,
            'cache_status' => $cacheStatus,
            'provider_call_skipped' => false,
        ], $providerMetadata);
    }

    private function isReusable(Evidence $evidence): bool
    {
        $payload = is_array($evidence->payload) ? $evidence->payload : [];

        if (array_key_exists('ok', $payload) && $payload['ok'] === false) {
            return false;
        }

        if (array_key_exists('success', $payload) && $payload['success'] === false) {
            return false;
        }

        $run = $evidence->relationLoaded('run')
            ? $evidence->run
            : $evidence->run()->first();

        if (! $run instanceof Run || $run->status !== 'completed') {
            return false;
        }

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        if (($metadata['probe_ok'] ?? null) === false) {
            return false;
        }

        if (isset($metadata['error']) && filled($metadata['error'])) {
            return false;
        }

        return true;
    }
}
