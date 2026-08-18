<?php

namespace App\Services\Recommendations;

use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Recommendation;
use App\Support\Recommendations\ResolvedRecommendationSource;
use Illuminate\Validation\ValidationException;

/**
 * Application-level enforcement of the Recommendation source invariant.
 * Exactly one source: source_kind=finding with finding_id, or source_kind=opportunity
 * with opportunity_id. Never both, never neither, never a mismatched pair.
 */
final class RecommendationSourceGuard
{
    /**
     * Derives source_kind from the persisted keys when a legacy writer omitted it,
     * then asserts the XOR invariant before the row hits the database.
     */
    public function normalize(Recommendation $recommendation): void
    {
        if (blank($recommendation->source_kind)) {
            if ($recommendation->finding_id !== null && $recommendation->opportunity_id === null) {
                $recommendation->source_kind = RecommendationSourceKind::Finding->value;
            } elseif ($recommendation->opportunity_id !== null && $recommendation->finding_id === null) {
                $recommendation->source_kind = RecommendationSourceKind::Opportunity->value;
            }
        }

        $this->assertConsistent($recommendation);
    }

    public function assertConsistent(Recommendation $recommendation): void
    {
        $kind = RecommendationSourceKind::tryFrom((string) $recommendation->source_kind);

        if ($kind === null) {
            throw ValidationException::withMessages([
                'source_kind' => 'A Recommendation requires exactly one source: a Finding or an Opportunity.',
            ]);
        }

        $hasFinding = $recommendation->finding_id !== null;
        $hasOpportunity = $recommendation->opportunity_id !== null;

        if ($hasFinding && $hasOpportunity) {
            throw ValidationException::withMessages([
                'source_kind' => 'A Recommendation cannot be sourced by both a Finding and an Opportunity.',
            ]);
        }

        if ($kind === RecommendationSourceKind::Finding && ! $hasFinding) {
            throw ValidationException::withMessages([
                'finding_id' => 'A Finding-sourced Recommendation requires finding_id.',
            ]);
        }

        if ($kind === RecommendationSourceKind::Opportunity && ! $hasOpportunity) {
            throw ValidationException::withMessages([
                'opportunity_id' => 'An Opportunity-sourced Recommendation requires opportunity_id.',
            ]);
        }
    }

    /**
     * The Digital Asset a Recommendation is filed against must belong to the same Brand
     * as its source. Cross-brand writes are rejected, never silently rewritten.
     */
    public function assertTenantMatch(ResolvedRecommendationSource $source, ?int $digitalAssetId): void
    {
        if ($digitalAssetId === null) {
            return;
        }

        $asset = DigitalAsset::query()->find($digitalAssetId);

        if ($asset === null) {
            throw ValidationException::withMessages([
                'digital_asset_id' => "Digital Asset #{$digitalAssetId} does not exist.",
            ]);
        }

        $sourceBrandId = $source->viewData->brandId;
        $assetBrandId = $asset->brand_id === null ? null : (int) $asset->brand_id;

        if ($sourceBrandId === null || $assetBrandId === null) {
            return;
        }

        if ($sourceBrandId !== $assetBrandId) {
            throw ValidationException::withMessages([
                'digital_asset_id' => sprintf(
                    'Digital Asset #%d belongs to Brand #%d but the %s source belongs to Brand #%d.',
                    $digitalAssetId,
                    $assetBrandId,
                    $source->reference->kind->value,
                    $sourceBrandId,
                ),
            ]);
        }
    }
}
