<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\SectorMemoryContextProvider;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;

/**
 * Sector Learning content is Prompt 53 — returns empty until then.
 */
final class NullSectorMemoryContextProvider implements SectorMemoryContextProvider
{
    public function listPrivacyQualifiedReferences(
        SectorIdentityRef $sectorIdentity,
        SectorPrivacyGateDecision $privacyDecision,
        int $boundedCount = 0,
    ): array {
        if (! $privacyDecision->isEligible()) {
            return [];
        }

        // Even if Eligible, Prompt 53 content store does not exist yet.
        return [];
    }
}
