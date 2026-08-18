<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\BrandMemoryContextProvider;
use App\Support\IntelligenceMemory\Dto\BrandMemoryScope;

/**
 * Brand Experience content is Prompt 52 — returns empty until then.
 */
final class NullBrandMemoryContextProvider implements BrandMemoryContextProvider
{
    public function listApplicableReferences(BrandMemoryScope $scope, int $boundedCount = 0): array
    {
        return [];
    }
}
