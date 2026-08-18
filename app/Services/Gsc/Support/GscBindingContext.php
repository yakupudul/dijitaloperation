<?php

namespace App\Services\Gsc\Support;

/**
 * Resolved GSC workspace binding for a workspace request. Never picks an arbitrary
 * available property by name — only the human-confirmed active CoreAssetBinding.
 */
final class GscBindingContext
{
    private function __construct(
        public readonly GscBindingMode $mode,
        public readonly string $assetId,
        public readonly ?int $digitalAssetId = null,
        public readonly ?int $externalResourceId = null,
        public readonly ?int $coreAssetBindingId = null,
        public readonly ?string $siteUrl = null,
        public readonly ?string $timezone = null,
        public readonly ?string $reason = null,
    ) {}

    public static function demoCatalog(string $assetId): self
    {
        return new self(GscBindingMode::DemoCatalog, $assetId);
    }

    public static function notConnected(string $assetId, int $digitalAssetId): self
    {
        return new self(
            GscBindingMode::NotConnected,
            $assetId,
            digitalAssetId: $digitalAssetId,
            reason: 'no_active_search_console_binding',
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
            GscBindingMode::ActionRequired,
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
        string $siteUrl,
        string $timezone,
    ): self {
        return new self(
            GscBindingMode::RealBound,
            $assetId,
            digitalAssetId: $digitalAssetId,
            externalResourceId: $externalResourceId,
            coreAssetBindingId: $coreAssetBindingId,
            siteUrl: $siteUrl,
            timezone: $timezone,
        );
    }

    public function isReal(): bool
    {
        return $this->mode === GscBindingMode::RealBound;
    }
}
