<?php

namespace App\Support\Reality;

use App\Models\DigitalAsset;

/**
 * Resolves a production DigitalAsset for specialist / inventory routes.
 * Demo catalog string ids and missing ids are not-found — never Atlas fallback.
 */
final class OperatorCanonicalAsset
{
    /**
     * @param  list<string>|null  $expectedTypes
     */
    public static function require(?string $assetId, ?array $expectedTypes = null): DigitalAsset
    {
        if ($assetId === null || $assetId === '' || ! ctype_digit($assetId) || DemoCatalogAssetGuard::isDemoCatalogAssetId($assetId)) {
            abort(404);
        }

        $asset = DigitalAsset::query()->with('brand.customer')->find((int) $assetId);
        abort_if($asset === null, 404);

        if ($expectedTypes !== null && $expectedTypes !== [] && ! in_array($asset->type, $expectedTypes, true)) {
            abort(404);
        }

        return $asset;
    }
}
