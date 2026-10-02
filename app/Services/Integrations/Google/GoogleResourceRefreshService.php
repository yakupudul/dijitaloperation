<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Models\User;

/**
 * Backward-compatible entry point for Filament "Refresh resources".
 * Canonical orchestrator: DiscoverGoogleResourcesService.
 */
class GoogleResourceRefreshService
{
    public function __construct(
        private readonly DiscoverGoogleResourcesService $discovery,
    ) {}

    /**
     * @return array{ok: bool, message: string, results: array<string, array{status: string, message: string, count: int}>}
     */
    public function refresh(CoreIntegration $integration, ?User $triggeredBy = null): array
    {
        return $this->discovery->discover($integration, $triggeredBy);
    }
}
