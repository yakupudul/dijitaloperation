<?php

namespace App\Support\Opportunities;

/**
 * Resolved commercial context for one Opportunity Rule evaluation. Goal/offering IDs are
 * inherited only from explicit Evidence/Finding scope — never inferred from names or text.
 */
final class OpportunityCommercialContext
{
    /**
     * @param  array<string, mixed>  $serviceContextSnapshot
     */
    public function __construct(
        public readonly ?int $goalId,
        public readonly ?int $offeringId,
        public readonly ?string $marketLocation,
        public readonly ?string $marketLanguage,
        public readonly ?string $serviceDefinitionCode,
        public readonly string $commercialScopeState,
        public readonly array $serviceContextSnapshot,
    ) {}
}
