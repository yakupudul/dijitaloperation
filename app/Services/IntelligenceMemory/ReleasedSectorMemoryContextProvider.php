<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\SectorMemoryContextProvider;
use App\Enums\SectorPrivacyDisposition;
use App\Services\SectorLearning\SectorMemoryReadService;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;

/**
 * Prompt 53: returns references to privacy-released Sector Learning artifacts only.
 *
 * Does not inject into Agents (Prompt 54 owns retrieval). No contributor IDs.
 */
final class ReleasedSectorMemoryContextProvider implements SectorMemoryContextProvider
{
    public function __construct(
        private readonly SectorMemoryReadService $sectorMemoryReadService,
    ) {}

    public function listPrivacyQualifiedReferences(
        SectorIdentityRef $sectorIdentity,
        SectorPrivacyGateDecision $privacyDecision,
        int $boundedCount = 0,
    ): array {
        if (! $privacyDecision->isEligible() || ! $sectorIdentity->isPresent()) {
            return [];
        }

        // Prompt 54 owns Agent injection; this provider only lists released refs when gate Eligible.
        // Empty candidate probes remain non-eligible, so normal gateway packs stay empty until P54.
        if ($privacyDecision->disposition !== SectorPrivacyDisposition::Eligible) {
            return [];
        }

        $limit = $boundedCount > 0 ? $boundedCount : 5;
        $dtos = $this->sectorMemoryReadService->listReleasedForSector(
            (string) $sectorIdentity->code,
            $limit
        );

        return array_map(
            static fn ($dto) => $dto->toMemoryPackReference(),
            $dtos
        );
    }
}
