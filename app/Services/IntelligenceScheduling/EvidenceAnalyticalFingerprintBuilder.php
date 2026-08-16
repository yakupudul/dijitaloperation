<?php

namespace App\Services\IntelligenceScheduling;

use App\Models\Evidence;
use Illuminate\Support\Collection;

/**
 * Deterministic Evidence analytical input fingerprint (Prompt 63).
 * Excludes updated_at, queue IDs, browser session, Activity IDs.
 */
final class EvidenceAnalyticalFingerprintBuilder
{
    /**
     * @param  Collection<int, Evidence>|iterable<Evidence>  $evidenceRows
     * @return array{
     *   fingerprint: string,
     *   definition_ids: list<string>,
     *   refs: list<array<string, mixed>>
     * }
     */
    public function forEvidenceSet(iterable $evidenceRows): array
    {
        $items = [];
        foreach ($evidenceRows as $row) {
            if (! $row instanceof Evidence || ! $row->isCanonical()) {
                continue;
            }
            $items[] = $this->forEvidence($row);
        }

        usort($items, static fn (array $a, array $b): int => strcmp($a['definition_id'].'|'.$a['evidence_fingerprint'], $b['definition_id'].'|'.$b['evidence_fingerprint']));

        $payload = array_map(static fn (array $i): array => [
            'definition_id' => $i['definition_id'],
            'evidence_fingerprint' => $i['evidence_fingerprint'],
            'analytical_fingerprint' => $i['analytical_fingerprint'],
        ], $items);

        return [
            'fingerprint' => 'easet:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'definition_ids' => array_values(array_unique(array_map(static fn (array $i): string => $i['definition_id'], $items))),
            'refs' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forEvidence(Evidence $evidence): array
    {
        $payload = is_array($evidence->payload) ? $evidence->payload : [];
        $observation = [
            'definition_id' => (string) $evidence->definition_id,
            'evidence_fingerprint' => (string) $evidence->evidence_fingerprint,
            'eligibility_status' => (string) ($evidence->eligibility_status ?? ''),
            'observed_at' => optional($evidence->observed_at)?->toIso8601String(),
            'fresh_until' => optional($evidence->fresh_until)?->toIso8601String(),
            'is_derived' => (bool) $evidence->is_derived,
            'generated_by_ai' => (bool) $evidence->generated_by_ai,
            'brand_goal_id' => $evidence->brand_goal_id,
            'brand_offering_id' => $evidence->brand_offering_id,
            // Stable observation slice — not full noisy payload dump when fingerprint exists.
            'metrics_hash' => hash('sha256', json_encode($payload['metrics'] ?? $payload, JSON_THROW_ON_ERROR)),
            'period' => $payload['period'] ?? $payload['reporting_period'] ?? null,
            'integrity' => $payload['integrity'] ?? $payload['integrity_status'] ?? null,
            'freshness' => $payload['freshness'] ?? $payload['freshness_state'] ?? null,
            'completeness' => $payload['completeness'] ?? null,
            'timezone' => $payload['timezone'] ?? $payload['reporting_timezone'] ?? null,
            'currency' => $payload['currency'] ?? null,
            'attribution' => $payload['attribution'] ?? null,
        ];

        return [
            'definition_id' => (string) $evidence->definition_id,
            'evidence_id' => (int) $evidence->id,
            'evidence_fingerprint' => (string) $evidence->evidence_fingerprint,
            'digital_asset_id' => (int) $evidence->digital_asset_id,
            'analytical_fingerprint' => 'eaf:'.hash('sha256', json_encode($observation, JSON_THROW_ON_ERROR)),
            'observation' => $observation,
        ];
    }
}
