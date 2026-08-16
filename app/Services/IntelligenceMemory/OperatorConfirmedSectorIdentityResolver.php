<?php

namespace App\Services\IntelligenceMemory;

use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Models\Brand;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\Options\IndustryOptions;

/**
 * Resolves Brand sector from operator catalog fields only.
 *
 * Classification: OPERATOR_CONFIRMED_CONTEXT (IndustryOptions codes).
 * Missing stable SectorDefinition entity is documented for Prompt 53.
 */
final class OperatorConfirmedSectorIdentityResolver implements SectorIdentityResolver
{
    public function resolveForBrand(Brand $brand): SectorIdentityRef
    {
        $sector = is_string($brand->sector) ? trim($brand->sector) : '';
        if ($sector !== '' && IndustryOptions::isValid($sector)) {
            return new SectorIdentityRef(
                code: $sector,
                source: 'brand.sector',
                operatorCatalog: true,
                aiInferred: false,
            );
        }

        $customer = $brand->relationLoaded('customer')
            ? $brand->customer
            : $brand->customer()->first();

        $industry = $customer !== null && is_string($customer->industry)
            ? trim($customer->industry)
            : '';

        if ($industry !== '' && IndustryOptions::isValid($industry)) {
            return new SectorIdentityRef(
                code: $industry,
                source: 'customer.industry',
                operatorCatalog: true,
                aiInferred: false,
            );
        }

        return new SectorIdentityRef(
            code: null,
            source: 'missing',
            operatorCatalog: true,
            aiInferred: false,
        );
    }
}
