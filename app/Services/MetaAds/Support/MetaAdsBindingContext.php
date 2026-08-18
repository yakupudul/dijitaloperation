<?php

namespace App\Services\MetaAds\Support;

/**
 * Resolved Meta Ads workspace binding. Analytical root is META_AD_ACCOUNT only —
 * never META_BUSINESS, never first-accessible account.
 */
final class MetaAdsBindingContext
{
    private function __construct(
        public readonly MetaAdsBindingMode $mode,
        public readonly string $assetId,
        public readonly ?int $digitalAssetId = null,
        public readonly ?int $externalResourceId = null,
        public readonly ?int $coreAssetBindingId = null,
        public readonly ?string $accountId = null,
        public readonly ?string $actId = null,
        public readonly ?string $timezone = null,
        public readonly ?string $currency = null,
        public readonly ?string $reason = null,
    ) {}

    public static function demoCatalog(string $assetId): self
    {
        return new self(MetaAdsBindingMode::DemoCatalog, $assetId);
    }

    public static function notConnected(string $assetId, int $digitalAssetId): self
    {
        return new self(
            MetaAdsBindingMode::NotConnected,
            $assetId,
            digitalAssetId: $digitalAssetId,
            reason: 'no_active_meta_ads_binding',
        );
    }

    public static function actionRequired(
        string $assetId,
        int $digitalAssetId,
        ?int $externalResourceId,
        ?int $coreAssetBindingId,
        string $reason,
    ): self {
        return new self(
            MetaAdsBindingMode::ActionRequired,
            $assetId,
            digitalAssetId: $digitalAssetId,
            externalResourceId: $externalResourceId,
            coreAssetBindingId: $coreAssetBindingId,
            reason: $reason,
        );
    }

    public static function realBound(
        string $assetId,
        int $digitalAssetId,
        int $externalResourceId,
        int $coreAssetBindingId,
        string $accountId,
        string $actId,
        string $timezone,
        string $currency,
    ): self {
        return new self(
            MetaAdsBindingMode::RealBound,
            $assetId,
            digitalAssetId: $digitalAssetId,
            externalResourceId: $externalResourceId,
            coreAssetBindingId: $coreAssetBindingId,
            accountId: $accountId,
            actId: $actId,
            timezone: $timezone,
            currency: $currency,
        );
    }

    public function isReal(): bool
    {
        return $this->mode === MetaAdsBindingMode::RealBound;
    }
}
