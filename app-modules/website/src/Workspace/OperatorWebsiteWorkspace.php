<?php

namespace MoxDop\Website\Workspace;

use App\Contracts\WebsiteOperatorWorkspace as WebsiteOperatorWorkspaceContract;
use App\Models\DigitalAsset;
use App\Models\DiscoveryCandidate;
use App\Models\User;
use Illuminate\Support\Collection;
use MoxDop\Website\Discovery\DiscoveryCandidateReviewService;
use MoxDop\Website\Discovery\DiscoveryConfig;

final class OperatorWebsiteWorkspace implements WebsiteOperatorWorkspaceContract
{
    public function __construct(
        private readonly PeriodAwareWebsiteWorkspace $periodAwareWorkspace,
        private readonly WebsiteWorkspaceData $workspace,
        private readonly DiscoveryCandidateReviewService $reviews,
    ) {}

    public function overview(
        DigitalAsset $asset,
        string $periodPreset = 'last_28',
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): array {
        if ($this->looksLikeIsoDate($periodPreset) && $periodEnd === null) {
            return $this->periodAwareWorkspace->for($asset, 'custom', $periodPreset, $periodStart);
        }

        return $this->periodAwareWorkspace->for($asset, $periodPreset, $periodStart, $periodEnd);
    }

    public function discovery(DigitalAsset $asset): array
    {
        return $this->workspace->discovery($asset);
    }

    public function discoveryResultModuleId(): string
    {
        return DiscoveryConfig::MODULE_ID;
    }

    public function connectionCards(DigitalAsset $asset): array
    {
        return $this->workspace->connectionCards($asset);
    }

    public function availableResourcesForCapability(DigitalAsset $asset, string $capability, ?int $exceptBindingId = null): Collection
    {
        return $this->workspace->availableResourcesForCapability($asset, $capability, $exceptBindingId);
    }

    public function acceptCandidate(DiscoveryCandidate $candidate, User $actor, ?string $editedValue = null): DiscoveryCandidate
    {
        return $this->reviews->accept($candidate, $actor, $editedValue);
    }

    public function ignoreCandidate(DiscoveryCandidate $candidate, User $actor): DiscoveryCandidate
    {
        return $this->reviews->ignore($candidate, $actor);
    }

    private function looksLikeIsoDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
