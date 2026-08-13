<?php

namespace App\Services\Ga4\Support;

/**
 * Resolved GA4 binding for a workspace request. Never picks an arbitrary
 * available property by name — only the human-confirmed active CoreAssetBinding.
 */
final class Ga4BindingContext
{
    private function __construct(
        public readonly Ga4BindingMode $mode,
        public readonly string $assetId,
        public readonly ?int $digitalAssetId = null,
        public readonly ?int $externalResourceId = null,
        public readonly ?int $coreAssetBindingId = null,
        public readonly ?string $propertyId = null,
        public readonly ?string $timezone = null,
        public readonly ?string $reason = null,
    ) {}

    public static function demoCatalog(string $assetId): self
    {
        return new self(Ga4BindingMode::DemoCatalog, $assetId);
    }

    public static function notConnected(string $assetId, int $digitalAssetId): self
    {
        return new self(
            Ga4BindingMode::NotConnected,
            $assetId,
            digitalAssetId: $digitalAssetId,
            reason: 'no_active_ga4_binding',
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
            Ga4BindingMode::ActionRequired,
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
        string $propertyId,
        string $timezone,
    ): self {
        return new self(
            Ga4BindingMode::RealBound,
            $assetId,
            digitalAssetId: $digitalAssetId,
            externalResourceId: $externalResourceId,
            coreAssetBindingId: $coreAssetBindingId,
            propertyId: $propertyId,
            timezone: $timezone,
        );
    }

    public function isReal(): bool
    {
        return $this->mode === Ga4BindingMode::RealBound;
    }
}
