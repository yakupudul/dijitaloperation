<?php

namespace App\Services\Findings;

use App\Support\Findings\FindingRule;

/**
 * Evaluation identity: same Finding may be evaluated many times.
 * Includes rule version + observation fingerprints. Excludes job ID and evaluated_at.
 */
final class FindingEvaluationFingerprintBuilder
{
    public const string VERSION = 'v1';

    /**
     * @param  list<string>  $observationFingerprints
     * @param  array<string, mixed>  $conditionConfig
     * @param  array<string, mixed>  $period
     */
    public function make(
        string $findingFingerprint,
        FindingRule $rule,
        array $observationFingerprints,
        array $conditionConfig,
        array $period,
    ): string {
        $observations = $observationFingerprints;
        sort($observations);

        $payload = [
            'version' => self::VERSION,
            'finding_fingerprint' => $findingFingerprint,
            'rule_version' => $rule->version,
            'evidence_observation_fingerprints' => $observations,
            'period' => $period,
            'condition_config' => $conditionConfig,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Observation fingerprint for one Evidence revision used by a rule.
     * Includes operand values so a value refresh creates a new evaluation without changing Finding identity.
     *
     * @param  array<string, mixed>  $operands
     */
    public function observation(string $evidenceIdentityFingerprint, array $operands): string
    {
        ksort($operands);

        return hash('sha256', json_encode([
            'evidence_fingerprint' => $evidenceIdentityFingerprint,
            'operands' => $operands,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
