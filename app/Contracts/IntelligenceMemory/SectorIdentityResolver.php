<?php

namespace App\Contracts\IntelligenceMemory;

use App\Models\Brand;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;

interface SectorIdentityResolver
{
    /**
     * Resolve operator-confirmed sector/group identity for a Brand.
     * Must never AI-infer or keyword-infer silently.
     */
    public function resolveForBrand(Brand $brand): SectorIdentityRef;
}
