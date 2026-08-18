<?php

namespace MoxDop\Website\Workspace;

use App\Contracts\WebsiteOperatorWorkspace as WebsiteOperatorWorkspaceContract;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use Illuminate\Support\Collection;
use MoxDop\Website\Discovery\DiscoveryCandidateReviewService;

final class OperatorWebsiteWorkspace implements WebsiteOperatorWorkspaceContract
{
    public function __construct(
        private readonly WebsiteWorkspaceData $workspace,
        private readonly DiscoveryCandidateReviewService $reviews,
    ) {}

    public function overview(DigitalAsset $asset): array
    {
        return $this->workspace->for($asset);
    }

    public function discovery(DigitalAsset $asset): array
    {
        return $this->workspace->discovery($asset);
    }

    public function connectionCards(DigitalAsset $asset): array
    {
        return $this->workspace->connectionCards($asset);
    }

    public function availableResourcesForCapability(DigitalAsset $asset, string $capability): Collection
    {
        return $this->workspace->availableResourcesForCapability($asset, $capability);
    }

    public function acceptCandidate(DiscoveryCandidate $candidate, User $actor, ?string $editedValue = null): DiscoveryCandidate
    {
        return $this->reviews->accept($candidate, $actor, $editedValue);
    }

    public function ignoreCandidate(DiscoveryCandidate $candidate, User $actor): DiscoveryCandidate
    {
        return $this->reviews->ignore($candidate, $actor);
    }
}
