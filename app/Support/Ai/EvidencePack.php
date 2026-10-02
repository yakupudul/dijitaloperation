<?php

namespace App\Support\Ai;

/**
 * Immutable Evidence Pack for grounded Agent execution (Prompt 50).
 *
 * Assembled from already-redacted ContextBuilder output via AgentContextGateway.
 * Does not grant write access to domain objects.
 *
 * @phpstan-type EvidenceItem array{
 *     id: int,
 *     type: string,
 *     revision?: string|null,
 *     fingerprint?: string|null,
 *     definition_key?: string|null,
 *     period?: string|null,
 *     integrity?: string|null,
 *     freshness?: string|null
 * }
 */
final class EvidencePack
{
    /**
     * @param  list<string>  $skillSignatures
     * @param  list<EvidenceItem>  $evidenceItems
     */
    public function __construct(
        public readonly ?int $customerId,
        public readonly ?int $brandId,
        public readonly int $digitalAssetId,
        public readonly string $subjectType,
        public readonly string $agentSlug,
        public readonly string $agentVersion,
        public readonly array $skillSignatures,
        public readonly string $routeKey,
        public readonly string $routeSignature,
        public readonly array $evidenceItems,
        public readonly string $contextFingerprint,
        public readonly string $inputFingerprint,
        public readonly string $packedAt,
    ) {}

    /**
     * @return list<int>
     */
    public function evidenceIds(): array
    {
        $ids = [];
        foreach ($this->evidenceItems as $item) {
            $ids[] = (int) $item['id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    public function evidenceTypes(): array
    {
        $types = [];
        foreach ($this->evidenceItems as $item) {
            $type = (string) ($item['type'] ?? '');
            if ($type !== '') {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    public function containsEvidenceId(int $id): bool
    {
        return in_array($id, $this->evidenceIds(), true);
    }

    /**
     * Manifest suitable for AgentExecutionRun metadata / audit (no secrets).
     *
     * @return array<string, mixed>
     */
    public function toManifestArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'brand_id' => $this->brandId,
            'digital_asset_id' => $this->digitalAssetId,
            'subject_type' => $this->subjectType,
            'agent_slug' => $this->agentSlug,
            'agent_version' => $this->agentVersion,
            'skill_signatures' => array_values($this->skillSignatures),
            'route_key' => $this->routeKey,
            'route_signature' => $this->routeSignature,
            'evidence_items' => array_values($this->evidenceItems),
            'evidence_ids' => $this->evidenceIds(),
            'evidence_types' => $this->evidenceTypes(),
            'context_fingerprint' => $this->contextFingerprint,
            'input_fingerprint' => $this->inputFingerprint,
            'packed_at' => $this->packedAt,
        ];
    }
}
