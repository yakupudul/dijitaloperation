<?php

namespace App\Services\Opportunities;

use App\Enums\OpportunityCommercialScopeState;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Opportunities\OpportunityCommercialContext;
use App\Support\Opportunities\OpportunityRule;

/**
 * Resolves commercial context for an Opportunity Rule evaluation:
 * - Goal/Offering IDs: inherited only from explicit Evidence or Finding scope, never inferred.
 * - Market: read from Evidence payload when present, else the Digital Asset's configured SEO market.
 * - Service Scope: read-only comparison against active Customer Service Scopes. Never creates scope.
 */
final class OpportunityContextResolver
{
    public function __construct(
        private readonly CustomerServiceScopeReadService $serviceScopes,
    ) {}

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     */
    public function resolve(
        OpportunityRule $rule,
        DigitalAsset $asset,
        array $evidence,
        array $findings,
    ): OpportunityCommercialContext {
        $goalId = $this->explicitGoal($rule, $evidence, $findings);
        $offeringId = $this->explicitOffering($rule, $evidence, $findings);
        [$marketLocation, $marketLanguage] = $this->resolveMarket($evidence, $asset);
        [$serviceCode, $scopeState, $activeCodes] = $this->resolveServiceScope($rule, $asset);

        return new OpportunityCommercialContext(
            goalId: $goalId,
            offeringId: $offeringId,
            marketLocation: $marketLocation,
            marketLanguage: $marketLanguage,
            serviceDefinitionCode: $serviceCode,
            commercialScopeState: $scopeState,
            serviceContextSnapshot: [
                'rule_service_definition_codes' => $rule->serviceDefinitionCodes,
                'active_service_codes' => $activeCodes,
            ],
        );
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     */
    private function explicitGoal(OpportunityRule $rule, array $evidence, array $findings): ?int
    {
        if ($rule->goalOfferingPolicy === 'none') {
            return null;
        }
        foreach ($evidence as $row) {
            if ($row->brandGoalId !== null) {
                return $row->brandGoalId;
            }
        }
        foreach ($findings as $finding) {
            if ($finding->brand_goal_id !== null) {
                return (int) $finding->brand_goal_id;
            }
        }

        return null;
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @param  list<Finding>  $findings
     */
    private function explicitOffering(OpportunityRule $rule, array $evidence, array $findings): ?int
    {
        if ($rule->goalOfferingPolicy === 'none') {
            return null;
        }
        foreach ($evidence as $row) {
            if ($row->brandOfferingId !== null) {
                return $row->brandOfferingId;
            }
        }
        foreach ($findings as $finding) {
            if ($finding->brand_offering_id !== null) {
                return (int) $finding->brand_offering_id;
            }
        }

        return null;
    }

    /**
     * Evidence payloads do not currently carry market_location/market_language — the Digital
     * Asset's configured SEO market is the explicit, non-inferred fallback.
     *
     * @param  list<CanonicalEvidenceDto>  $evidence
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveMarket(array $evidence, DigitalAsset $asset): array
    {
        foreach ($evidence as $row) {
            $location = data_get($row->payload, 'market_location') ?? data_get($row->payload, 'market.location');
            $language = data_get($row->payload, 'market_language') ?? data_get($row->payload, 'market.language');
            if ($location !== null || $language !== null) {
                return [
                    $location !== null ? (string) $location : null,
                    $language !== null ? (string) $language : null,
                ];
            }
        }

        $location = $asset->seo_market_location_name
            ?? ($asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null);
        $language = $asset->seo_market_language_name ?? $asset->seo_market_language_code;

        return [$location, $language];
    }

    /**
     * @return array{0: ?string, 1: string, 2: list<string>}
     */
    private function resolveServiceScope(OpportunityRule $rule, DigitalAsset $asset): array
    {
        if ($rule->serviceDefinitionCodes === []) {
            return [null, OpportunityCommercialScopeState::NotServiceRelevant->value, []];
        }

        $brand = $asset->brand;
        if ($brand === null) {
            return [$rule->serviceDefinitionCodes[0], OpportunityCommercialScopeState::ServiceScopeUnknown->value, []];
        }

        $activeCodes = collect($this->serviceScopes->forBrand($brand))
            ->whereIn('status', ['active', 'paused'])
            ->pluck('service_code')
            ->filter()
            ->map(static fn (mixed $code): string => (string) $code)
            ->unique()
            ->values()
            ->all();

        foreach ($rule->serviceDefinitionCodes as $code) {
            if (in_array($code, $activeCodes, true)) {
                return [$code, OpportunityCommercialScopeState::InCurrentScope->value, $activeCodes];
            }
        }

        // service_scope_policy=context_only_outside_allowed: the Opportunity may still exist,
        // annotated as outside the customer's current active Service Scope.
        return [$rule->serviceDefinitionCodes[0], OpportunityCommercialScopeState::OutsideCurrentScope->value, $activeCodes];
    }
}
