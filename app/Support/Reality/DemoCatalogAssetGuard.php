<?php

namespace App\Support\Reality;

use App\Models\DigitalAsset;
use App\Support\Demo\DemoCatalog;

/**
 * Detects Atlas Demo catalog asset identifiers vs production numeric DigitalAsset ids.
 */
final class DemoCatalogAssetGuard
{
    public static function isDemoCatalogAssetId(string $assetId): bool
    {
        if ($assetId === '' || ctype_digit($assetId)) {
            return false;
        }

        return DemoCatalog::asset($assetId) !== null;
    }

    public static function isProductionAssetId(string $assetId): bool
    {
        return ctype_digit($assetId) && DigitalAsset::query()->whereKey((int) $assetId)->exists();
    }
}
