<?php

namespace App\Services\Evidence;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;

/**
 * Production read of canonical Evidence only. Legacy JSON rows are excluded.
 * Empty means empty — no Demo fallback.
 */
final class CanonicalEvidenceReadService
{
    /**
     * @return list<CanonicalEvidenceDto>
     */
    public function forAsset(DigitalAsset $asset): array
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('is_canonical', true)
            ->whereNotNull('definition_id')
            ->whereNotNull('evidence_fingerprint')
            ->orderBy('id')
            ->get()
            ->map(static fn (Evidence $row): CanonicalEvidenceDto => CanonicalEvidenceDto::fromModel($row))
            ->all();
    }
}
