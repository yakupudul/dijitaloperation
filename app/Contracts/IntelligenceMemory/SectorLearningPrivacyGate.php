<?php

namespace App\Contracts\IntelligenceMemory;

use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\IntelligenceMemory\Dto\SectorPrivacyGateDecision;

/**
 * Versioned Sector privacy release policy interface.
 *
 * Prompt 51 establishes the gate; Prompt 53 implements cohort/aggregation rules.
 * No magic cohort threshold or privacy score in Prompt 51.
 */
interface SectorLearningPrivacyGate
{
    /**
     * Qualify a proposed sector learning contribution/artifact.
     *
     * @param  array<string, mixed>  $candidate  must not be treated as usable Sector Memory until Eligible
     */
    public function qualify(SectorIdentityRef $sectorIdentity, array $candidate): SectorPrivacyGateDecision;

    /**
     * One Brand alone cannot become usable Sector Memory.
     */
    public function rejectSingleBrandAsSectorLearning(int $brandCount): SectorPrivacyGateDecision;
}
