<?php

namespace App\Contracts;

use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use Illuminate\Support\Collection;

interface WebsiteOperatorWorkspace
{
    /** @return array<string, mixed> */
    public function overview(DigitalAsset $asset): array;

    /** @return array<string, mixed> */
    public function discovery(DigitalAsset $asset): array;

    /** @return list<array<string, mixed>> */
    public function connectionCards(DigitalAsset $asset): array;

    /** @return Collection<int, mixed> */
    public function availableResourcesForCapability(DigitalAsset $asset, string $capability, ?int $exceptBindingId = null): Collection;

    public function acceptCandidate(DiscoveryCandidate $candidate, User $actor, ?string $editedValue = null): DiscoveryCandidate;

    public function ignoreCandidate(DiscoveryCandidate $candidate, User $actor): DiscoveryCandidate;
}
