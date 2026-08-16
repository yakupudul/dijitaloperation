<?php

namespace App\Contracts\IntelligenceMemory;

use App\Support\IntelligenceMemory\Dto\BrandMemoryScope;

/**
 * Brand Memory context provider (Prompt 52 owns content).
 */
interface BrandMemoryContextProvider
{
    /**
     * @return list<array{artifact_id: string, revision: string|null, citation: string|null}>
     */
    public function listApplicableReferences(BrandMemoryScope $scope, int $boundedCount = 0): array;
}
