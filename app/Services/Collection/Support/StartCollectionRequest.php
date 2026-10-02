<?php

namespace App\Services\Collection\Support;

use App\Enums\Collection\CollectionTriggerType;
use App\Models\DigitalAsset;
use App\Models\User;

final class StartCollectionRequest
{
    /**
     * @param  list<int>  $bindingIds
     * @param  list<string>|null  $requestFamilyIds
     * @param  list<string>|null  $providerSources
     * @param  array{start?: string|null, end?: string|null}|null  $dateRange
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly DigitalAsset $digitalAsset,
        public readonly CollectionTriggerType $triggerType = CollectionTriggerType::Manual,
        public readonly ?User $requestedBy = null,
        public readonly array $bindingIds = [],
        public readonly ?array $requestFamilyIds = null,
        public readonly ?array $providerSources = null,
        public readonly ?array $dateRange = null,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $forceRefresh = false,
        public readonly array $context = [],
    ) {}
}
