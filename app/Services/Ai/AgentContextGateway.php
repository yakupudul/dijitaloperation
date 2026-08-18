<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AgentContextGateway as AgentContextGatewayContract;
use App\Models\DigitalAsset;
use App\Support\Agents\AgentProfileDefinition;
use App\Support\Ai\AgentExecutionPlan;
use App\Support\Ai\EvidencePack;

/**
 * Bounded EvidencePack assembler (Prompt 50).
 *
 * Eloquent is allowed ONLY inside this gateway class as the persistence boundary
 * for packing already-redacted ContextBuilder payloads. Callers must not pass
 * table/model names — only typed DigitalAsset + plan + context arrays.
 *
 * Module ContextBuilders remain responsible for redaction; this class does not
 * invent Brand Intelligence — it only packs IDs already present on the asset /
 * context payload.
 */
final class AgentContextGateway implements AgentContextGatewayContract
{
    /**
     * @param  array<string, mixed>  $contextPayload
     * @param  list<int>  $evidenceIds
     * @param  list<int>  $findingIds
     */
    public function buildEvidencePackFromContext(
        DigitalAsset $asset,
        AgentProfileDefinition $profile,
        AgentExecutionPlan $plan,
        array $contextPayload,
        array $evidenceIds,
        array $findingIds,
        string $routeKey,
        string $routeSignature,
        string $inputFingerprint,
    ): EvidencePack {
        $asset->loadMissing('brand');

        $brandId = $asset->brand_id !== null ? (int) $asset->brand_id : null;
        $customerId = $asset->brand?->customer_id !== null
            ? (int) $asset->brand->customer_id
            : null;

        $allowedIds = array_fill_keys(array_map('intval', $evidenceIds), true);
        $evidenceItems = [];

        foreach ($contextPayload['evidence'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || ! isset($allowedIds[$id])) {
                continue;
            }

            $evidenceItems[] = [
                'id' => $id,
                'type' => is_string($row['type'] ?? null) ? (string) $row['type'] : '',
                'revision' => $this->nullableString($row['revision'] ?? $row['evidence_fingerprint'] ?? null),
                'fingerprint' => $this->nullableString($row['evidence_fingerprint'] ?? $row['fingerprint'] ?? null),
                'definition_key' => $this->nullableString($row['definition_id'] ?? $row['definition_key'] ?? null),
                'period' => $this->nullableString($row['period'] ?? null),
                'integrity' => $this->nullableString($row['integrity'] ?? $row['eligibility_status'] ?? null),
                'freshness' => $this->nullableString(
                    is_scalar($row['fresh_until'] ?? null)
                        ? (string) $row['fresh_until']
                        : ($row['freshness'] ?? null)
                ),
            ];
        }

        // Preserve requested ID order when context omitted a row (still pack id-only stubs).
        $packedIds = array_fill_keys(array_column($evidenceItems, 'id'), true);
        foreach ($evidenceIds as $id) {
            $id = (int) $id;
            if ($id > 0 && ! isset($packedIds[$id])) {
                $evidenceItems[] = [
                    'id' => $id,
                    'type' => '',
                    'revision' => null,
                    'fingerprint' => null,
                    'definition_key' => null,
                    'period' => null,
                    'integrity' => null,
                    'freshness' => null,
                ];
            }
        }

        $contextFingerprint = $this->fingerprint([
            'digital_asset_id' => $asset->id,
            'finding_ids' => array_values(array_map('intval', $findingIds)),
            'evidence_ids' => array_values(array_map('intval', $evidenceIds)),
            'agent' => $profile->signature(),
            'eligible_skills' => $plan->eligibleSkills,
            'subject_type' => (string) $asset->type,
        ]);

        return new EvidencePack(
            customerId: $customerId,
            brandId: $brandId,
            digitalAssetId: (int) $asset->id,
            subjectType: (string) $asset->type,
            agentSlug: $profile->slug,
            agentVersion: $profile->version,
            skillSignatures: $plan->eligibleSkills,
            routeKey: $routeKey,
            routeSignature: $routeSignature,
            evidenceItems: $evidenceItems,
            contextFingerprint: $contextFingerprint,
            inputFingerprint: $inputFingerprint,
            packedAt: now()->toIso8601String(),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $string = (string) $value;

        return $string !== '' ? $string : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($json) ? $json : '');
    }
}
