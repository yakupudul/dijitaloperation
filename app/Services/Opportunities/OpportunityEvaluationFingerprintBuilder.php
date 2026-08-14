<?php

namespace App\Services\Opportunities;

use App\Support\Opportunities\OpportunityRule;

/**
 * Evaluation identity: the same Opportunity may be evaluated many times.
 * Includes rule version, Evidence + Finding observation fingerprints, period, threshold config,
 * market, service context snapshot identity, and goal/offering IDs.
 * Excludes job ID and evaluated_at alone.
 */
final class OpportunityEvaluationFingerprintBuilder
{
    public const string VERSION = 'v1';

    /**
     * @param  list<string>  $evidenceObservationFingerprints
     * @param  list<string>  $findingObservationFingerprints
     * @param  array<string, mixed>  $conditionConfig
     * @param  array<string, mixed>  $period
     * @param  array<string, mixed>  $serviceContextSnapshot
     */
    public function make(
        string $opportunityFingerprint,
        OpportunityRule $rule,
        array $evidenceObservationFingerprints,
        array $findingObservationFingerprints,
        array $conditionConfig,
        array $period,
        array $serviceContextSnapshot,
        ?int $goalId,
        ?int $offeringId,
        ?string $marketIdentity,
    ): string {
        $evidenceObservations = $evidenceObservationFingerprints;
        sort($evidenceObservations);
        $findingObservations = $findingObservationFingerprints;
        sort($findingObservations);
        ksort($serviceContextSnapshot);

        $payload = [
            'version' => self::VERSION,
            'opportunity_fingerprint' => $opportunityFingerprint,
            'rule_version' => $rule->version,
            'evidence_observation_fingerprints' => $evidenceObservations,
            'finding_observation_fingerprints' => $findingObservations,
            'period' => $period,
            'condition_config' => $conditionConfig,
            'service_context_snapshot' => $serviceContextSnapshot,
            'goal_id' => $goalId,
            'offering_id' => $offeringId,
            'market_identity' => $marketIdentity,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Observation fingerprint for one Evidence revision used by a rule.
     * Includes operand values so a value refresh creates a new evaluation without changing identity.
     *
     * @param  array<string, mixed>  $operands
     */
    public function evidenceObservation(string $evidenceIdentityFingerprint, array $operands): string
    {
        ksort($operands);

        return hash('sha256', json_encode([
            'evidence_fingerprint' => $evidenceIdentityFingerprint,
            'operands' => $operands,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Observation fingerprint for one Finding used as a condition input.
     * Includes the Finding's current status/condition state so a re-evaluation after a status
     * change produces a new evaluation without changing Opportunity identity.
     */
    public function findingObservation(string $findingSemanticFingerprint, string $status, string $conditionState): string
    {
        return hash('sha256', json_encode([
            'finding_fingerprint' => $findingSemanticFingerprint,
            'status' => $status,
            'condition_state' => $conditionState,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
