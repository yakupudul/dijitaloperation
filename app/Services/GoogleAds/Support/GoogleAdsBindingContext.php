<?php

namespace App\Services\GoogleAds\Support;

/**
 * Resolved Google Ads workspace binding. Never picks an arbitrary accessible
 * Customer by name/domain — only the human-confirmed active CoreAssetBinding.
 * Manager accounts are not analytical roots for this specialist view.
 */
final class GoogleAdsBindingContext
{
    private function __construct(
        public readonly GoogleAdsBindingMode $mode,
        public readonly string $assetId,
        public readonly ?int $digitalAssetId = null,
        public readonly ?int $externalResourceId = null,
        public readonly ?int $coreAssetBindingId = null,
        public readonly ?string $customerId = null,
        public readonly ?string $timezone = null,
        public readonly ?string $currency = null,
        public readonly ?string $reason = null,
    ) {}

    public static function demoCatalog(string $assetId): self
    {
        return new self(GoogleAdsBindingMode::DemoCatalog, $assetId);
    }

    public static function notConnected(string $assetId, int $digitalAssetId): self
    {
        return new self(
            GoogleAdsBindingMode::NotConnected,
            $assetId,
            digitalAssetId: $digitalAssetId,
            reason: 'no_active_google_ads_binding',
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
            GoogleAdsBindingMode::ActionRequired,
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
        string $customerId,
        string $timezone,
        string $currency,
    ): self {
        return new self(
            GoogleAdsBindingMode::RealBound,
            $assetId,
            digitalAssetId: $digitalAssetId,
            externalResourceId: $externalResourceId,
            coreAssetBindingId: $coreAssetBindingId,
            customerId: $customerId,
            timezone: $timezone,
            currency: $currency,
        );
    }

    public function isReal(): bool
    {
        return $this->mode === GoogleAdsBindingMode::RealBound;
    }
}
