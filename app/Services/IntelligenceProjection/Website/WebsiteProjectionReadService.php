<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsiteEntityProfile;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsiteOutcomeProfile;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\IntelligenceProjection\WebsiteSearchTermProfile;
use Illuminate\Database\Eloquent\Builder;

final class WebsiteProjectionReadService
{
    public function latestRun(DigitalAsset $asset): ?WebsiteIntelligenceProjectionRun
    {
        return WebsiteIntelligenceProjectionRun::query()
            ->where('website_asset_id', $asset->getKey())
            ->whereIn('status', [
                WebsiteIntelligenceProjectionRun::STATUS_COMPLETED,
                WebsiteIntelligenceProjectionRun::STATUS_PARTIAL,
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Builder<WebsitePageProfile> */
    public function pages(DigitalAsset $asset): Builder
    {
        return WebsitePageProfile::query()->where('website_asset_id', $asset->getKey());
    }

    /** @return Builder<WebsiteSearchTermProfile> */
    public function searchTerms(DigitalAsset $asset): Builder
    {
        return WebsiteSearchTermProfile::query()->where('website_asset_id', $asset->getKey());
    }

    /** @return Builder<WebsiteEntityProfile> */
    public function entities(DigitalAsset $asset): Builder
    {
        return WebsiteEntityProfile::query()->where('website_asset_id', $asset->getKey());
    }

    /** @return Builder<WebsiteOutcomeProfile> */
    public function outcomes(DigitalAsset $asset): Builder
    {
        return WebsiteOutcomeProfile::query()->where('website_asset_id', $asset->getKey());
    }

    /** @return array<string,mixed> */
    public function summary(DigitalAsset $asset): array
    {
        $run = $this->latestRun($asset);
        $runSummary = $run?->summary ?? [];

        return [
            'available' => $run !== null,
            'projection_run_id' => $run?->getKey(),
            'status' => $run?->status,
            'period' => $run !== null ? [
                'start' => $run->period_start?->toDateString(),
                'end' => $run->period_end?->toDateString(),
            ] : null,
            'source_watermarks' => $run?->source_watermarks ?? [],
            'coverage_state' => $run?->coverage_state ?? [],
            'profile_counts' => $runSummary['profile_counts'] ?? [
                'pages' => 0,
                'search_terms' => 0,
                'entities' => 0,
                'outcomes' => 0,
            ],
            'completed_at' => $run?->completed_at?->toIso8601String(),
        ];
    }
}
