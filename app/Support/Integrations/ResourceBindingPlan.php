<?php

namespace App\Support\Integrations;

use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\User;

/**
 * Proposed human-confirmed Binding operation (ephemeral DTO).
 */
final readonly class ResourceBindingPlan
{
    public const string MODE_CREATE_ASSET = 'create_asset';

    public const string MODE_EXISTING_ASSET = 'existing_asset';

    public function __construct(
        public CoreExternalResource $resource,
        public Brand $brand,
        public string $mode,
        public ?DigitalAsset $existingAsset,
        public string $assetName,
        public User $confirmedBy,
    ) {}
}
