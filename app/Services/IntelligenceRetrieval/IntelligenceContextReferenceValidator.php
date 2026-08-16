<?php

namespace App\Services\IntelligenceRetrieval;

use App\Support\IntelligenceRetrieval\Dto\IntelligenceContextPack;

/**
 * Separates Evidence references from Memory/context references.
 * Memory refs cannot masquerade as Evidence.
 */
final class IntelligenceContextReferenceValidator
{
    /**
     * @param  list<int>  $claimedEvidenceIds
     * @param  list<string>  $claimedMemoryRefs
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(
        IntelligenceContextPack $pack,
        array $claimedEvidenceIds = [],
        array $claimedMemoryRefs = [],
    ): array {
        $errors = [];
        $evidencePack = $pack->evidencePack;

        foreach ($claimedEvidenceIds as $id) {
            if ($evidencePack === null || ! $evidencePack->containsEvidenceId((int) $id)) {
                $errors[] = 'UNKNOWN_EVIDENCE_REF:'.$id;
            }
        }

        $allowedMemory = $this->allowedMemoryRefs($pack);
        foreach ($claimedMemoryRefs as $ref) {
            if (! is_string($ref) || $ref === '') {
                $errors[] = 'INVALID_MEMORY_REF';

                continue;
            }
            if (str_starts_with($ref, 'contributor:') || str_contains($ref, 'lineage')) {
                $errors[] = 'SECTOR_CONTRIBUTOR_REF_FORBIDDEN';

                continue;
            }
            if (! in_array($ref, $allowedMemory, true)) {
                $errors[] = 'UNKNOWN_MEMORY_REF:'.$ref;
            }
        }

        // Memory cannot satisfy Evidence requirements
        foreach ($claimedEvidenceIds as $id) {
            if (is_string($id) && (str_starts_with((string) $id, 'brand_experience:')
                || str_starts_with((string) $id, 'sector_artifact:'))) {
                $errors[] = 'MEMORY_REF_USED_AS_EVIDENCE';
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Reject treating a memory opaque ref as an Evidence id.
     */
    public function assertMemoryCannotSatisfyEvidence(string $ref): void
    {
        if (str_starts_with($ref, 'brand_experience:')
            || str_starts_with($ref, 'sector_artifact:')
            || str_starts_with($ref, 'skill:')) {
            throw new \InvalidArgumentException('Memory reference cannot masquerade as Evidence.');
        }
    }

    /**
     * @return list<string>
     */
    private function allowedMemoryRefs(IntelligenceContextPack $pack): array
    {
        $refs = [];
        foreach ($pack->memoryContextPack->brandExperiences as $item) {
            $refs[] = $item->opaqueRef;
        }
        foreach ($pack->memoryContextPack->sectorPatterns as $item) {
            $refs[] = 'sector_artifact:'.$item->artifact->artifactStableKey;
        }
        foreach ($pack->memoryContextPack->skillKnowledge as $item) {
            $refs[] = $item->opaqueRef;
        }

        return $refs;
    }
}
