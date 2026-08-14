<?php

namespace App\Services\Recommendations;

use App\Enums\RecommendationSourceKind;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Recommendations\Dto\RecommendationSourceViewData;
use App\Support\Recommendations\RecommendationSourceReference;
use App\Support\Recommendations\ResolvedRecommendationSource;
use Illuminate\Validation\ValidationException;

/**
 * Server-side resolution of a Recommendation source. Never trusts a client-supplied
 * source shape, never reads Demo fixtures, never mutates the source.
 */
final class RecommendationSourceResolver
{
    public function resolve(RecommendationSourceReference $reference): ResolvedRecommendationSource
    {
        return match ($reference->kind) {
            RecommendationSourceKind::Finding => $this->resolveFinding($reference),
            RecommendationSourceKind::Opportunity => $this->resolveOpportunity($reference),
        };
    }

    /**
     * Batch resolution for list rendering — one query per source kind, no N+1.
     *
     * @param  list<RecommendationSourceReference>  $references
     * @return array<string, RecommendationSourceViewData>
     */
    public function resolveManyViewData(array $references): array
    {
        $findingIds = [];
        $opportunityIds = [];

        foreach ($references as $reference) {
            if ($reference->isFinding()) {
                $findingIds[] = $reference->id;
            } else {
                $opportunityIds[] = $reference->id;
            }
        }

        $resolved = [];

        if ($findingIds !== []) {
            $findings = Finding::query()
                ->with(['brand', 'customer', 'latestEvaluation.evidence', 'digitalAsset'])
                ->whereIn('id', array_values(array_unique($findingIds)))
                ->get();

            foreach ($findings as $finding) {
                $reference = RecommendationSourceReference::fromFinding($finding);
                $resolved[$reference->key()] = $this->findingViewData($finding);
            }
        }

        if ($opportunityIds !== []) {
            $opportunities = Opportunity::query()
                ->with(['brand', 'customer', 'latestEvaluation.evidence', 'digitalAsset'])
                ->whereIn('id', array_values(array_unique($opportunityIds)))
                ->get();

            foreach ($opportunities as $opportunity) {
                $reference = RecommendationSourceReference::fromOpportunity($opportunity);
                $resolved[$reference->key()] = $this->opportunityViewData($opportunity);
            }
        }

        return $resolved;
    }

    private function resolveFinding(RecommendationSourceReference $reference): ResolvedRecommendationSource
    {
        $finding = Finding::query()
            ->with(['brand', 'customer', 'latestEvaluation.evidence', 'digitalAsset'])
            ->find($reference->id);

        if (! $finding instanceof Finding) {
            throw ValidationException::withMessages([
                'finding_id' => "Finding #{$reference->id} does not exist.",
            ]);
        }

        return new ResolvedRecommendationSource($reference, $finding, $this->findingViewData($finding));
    }

    private function resolveOpportunity(RecommendationSourceReference $reference): ResolvedRecommendationSource
    {
        $opportunity = Opportunity::query()
            ->with(['brand', 'customer', 'latestEvaluation.evidence', 'digitalAsset'])
            ->find($reference->id);

        if (! $opportunity instanceof Opportunity) {
            throw ValidationException::withMessages([
                'opportunity_id' => "Opportunity #{$reference->id} does not exist.",
            ]);
        }

        return new ResolvedRecommendationSource($reference, $opportunity, $this->opportunityViewData($opportunity));
    }

    private function findingViewData(Finding $finding): RecommendationSourceViewData
    {
        $brandId = $finding->brand_id ?? $finding->digitalAsset?->brand_id;
        $customerId = $finding->customer_id ?? $finding->digitalAsset?->brand?->customer_id;

        return new RecommendationSourceViewData(
            kind: RecommendationSourceKind::Finding,
            id: (int) $finding->id,
            customerId: $customerId === null ? null : (int) $customerId,
            brandId: $brandId === null ? null : (int) $brandId,
            digitalAssetId: $finding->digital_asset_id === null ? null : (int) $finding->digital_asset_id,
            title: (string) $finding->title,
            status: (string) $finding->status,
            category: $finding->category,
            ruleId: $finding->rule_id,
            goalIds: $finding->brand_goal_id !== null ? [(int) $finding->brand_goal_id] : [],
            offeringIds: $finding->brand_offering_id !== null ? [(int) $finding->brand_offering_id] : [],
            market: $this->assetMarket($finding),
            serviceContext: null,
            supportingEvidenceCount: $this->supportingEvidenceCount($finding),
        );
    }

    private function opportunityViewData(Opportunity $opportunity): RecommendationSourceViewData
    {
        $brandId = $opportunity->brand_id ?? $opportunity->digitalAsset?->brand_id;
        $customerId = $opportunity->customer_id ?? $opportunity->digitalAsset?->brand?->customer_id;

        $market = collect([$opportunity->market_location, $opportunity->market_language])
            ->filter(static fn (?string $value): bool => $value !== null && $value !== '')
            ->implode(' · ');

        $serviceContext = $opportunity->service_definition_code === null
            ? null
            : AgencyServiceOptions::label($opportunity->service_definition_code);

        return new RecommendationSourceViewData(
            kind: RecommendationSourceKind::Opportunity,
            id: (int) $opportunity->id,
            customerId: $customerId === null ? null : (int) $customerId,
            brandId: $brandId === null ? null : (int) $brandId,
            digitalAssetId: $opportunity->digital_asset_id === null ? null : (int) $opportunity->digital_asset_id,
            title: (string) $opportunity->title,
            status: (string) $opportunity->status,
            category: $opportunity->category,
            ruleId: $opportunity->rule_id,
            goalIds: $opportunity->brand_goal_id !== null ? [(int) $opportunity->brand_goal_id] : [],
            offeringIds: $opportunity->brand_offering_id !== null ? [(int) $opportunity->brand_offering_id] : [],
            market: $market !== '' ? $market : null,
            serviceContext: $serviceContext,
            supportingEvidenceCount: $this->supportingEvidenceCount($opportunity),
        );
    }

    private function assetMarket(Finding $finding): ?string
    {
        $asset = $finding->digitalAsset;

        if ($asset === null) {
            return null;
        }

        $market = collect([$asset->seo_market_location_name, $asset->seo_market_language_name])
            ->filter(static fn (?string $value): bool => $value !== null && $value !== '')
            ->implode(' · ');

        return $market !== '' ? $market : null;
    }

    private function supportingEvidenceCount(Finding|Opportunity $source): int
    {
        $evaluation = $source->latestEvaluation;

        if ($evaluation === null) {
            return 0;
        }

        return $evaluation->evidence->count();
    }
}
