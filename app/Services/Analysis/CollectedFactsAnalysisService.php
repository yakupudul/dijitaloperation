<?php

namespace App\Services\Analysis;

use App\Models\DigitalAsset;
use App\Services\Analysis\Adapters\GoogleAdsCollectedCampaignAdapter;
use App\Services\Analysis\Adapters\MetaAdsCollectedCampaignAdapter;
use App\Services\Analysis\Adapters\WebsiteCollectedDocumentHeadAdapter;
use App\Services\Analysis\Support\CollectedFactsAnalysisResult;
use App\Services\Analysis\Support\DigitalAssetType;

/**
 * Phase C.1 operational synthesis: collected Data Pool facts → existing deterministic analyzers.
 *
 * Does not call providers, AI, Demo fixtures, or auto-open Tasks.
 */
final class CollectedFactsAnalysisService
{
    public const string PIPELINE = 'collected_facts_analysis';

    public function __construct(
        private readonly WebsiteCollectedDocumentHeadAdapter $website,
        private readonly GoogleAdsCollectedCampaignAdapter $googleAds,
        private readonly MetaAdsCollectedCampaignAdapter $metaAds,
    ) {}

    public function analyze(DigitalAsset $asset): CollectedFactsAnalysisResult
    {
        return match ($asset->type) {
            DigitalAssetType::Website->value => $this->website->evaluate($asset),
            DigitalAssetType::GoogleAds->value => $this->googleAds->evaluate($asset),
            DigitalAssetType::MetaAds->value => $this->metaAds->evaluate($asset),
            default => CollectedFactsAnalysisResult::skipped(
                DigitalAssetType::Unsupported,
                'unsupported_asset_type',
                ['digital_asset_id' => $asset->id, 'type' => $asset->type],
            ),
        };
    }
}
