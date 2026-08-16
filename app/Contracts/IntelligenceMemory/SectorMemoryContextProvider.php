<?php

namespace App\Contracts\IntelligenceMemory;

use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;

/**
 * Sector Memory context provider (Prompt 53 owns content + privacy qualification).
 *
 * Consumer payloads must never include contributor Brand/Customer IDs.
 */
interface SectorMemoryContextProvider
{
    /**
     * @return list<array{artifact_id: string, revision: string|null, citation: string|null}>
     */
    public function listPrivacyQualifiedReferences(
        SectorIdentityRef $sectorIdentity,
        SectorPrivacyGateDecision $privacyDecision,
        int $boundedCount = 0,
    ): array;
}
