<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\Integrations\Google\Discovery\CapabilityDiscoveryResult;
use App\Services\Integrations\Google\Discovery\Ga4Discoverer;
use App\Services\Integrations\Google\Discovery\GoogleAdsDiscoverer;
use App\Services\Integrations\Google\Discovery\GoogleBusinessProfileDiscoverer;
use App\Services\Integrations\Google\Discovery\SearchConsoleDiscoverer;
use App\Support\Integrations\DiscoveredExternalResource;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\DB;

class GoogleResourceRefreshService
{
    public function __construct(
        private readonly SearchConsoleDiscoverer $searchConsole,
        private readonly Ga4Discoverer $ga4,
        private readonly GoogleAdsDiscoverer $ads,
        private readonly GoogleBusinessProfileDiscoverer $gbp,
    ) {}

    /**
     * @return array{ok: bool, message: string, results: array<string, array{status: string, message: string, count: int}>}
     */
    public function refresh(CoreIntegration $integration): array
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            return ['ok' => false, 'message' => 'Not a Google integration.', 'results' => []];
        }

        if (! GoogleOAuthConfig::isConfigured()) {
            return [
                'ok' => false,
                'message' => 'Setup required: missing '.implode(', ', GoogleOAuthConfig::missingKeys()).'.',
                'results' => [],
            ];
        }

        $auth = GoogleAuthStatus::for($integration);
        if (in_array($auth, [GoogleAuthStatus::AUTHORIZATION_REQUIRED, GoogleAuthStatus::NOT_CONFIGURED, GoogleAuthStatus::DISABLED], true)) {
            return [
                'ok' => false,
                'message' => GoogleAuthStatus::label($auth).'. Authorize Google before refreshing resources.',
                'results' => [],
            ];
        }

        /** @var list<CapabilityDiscoveryResult> $capabilityResults */
        $capabilityResults = [
            $this->searchConsole->discover($integration),
            $this->ga4->discover($integration),
            $this->ads->discover($integration),
            $this->gbp->discover($integration),
        ];

        $seenIdsByType = [];
        $summary = [];
        $anySuccess = false;

        foreach ($capabilityResults as $result) {
            $summary[$result->capability] = [
                'status' => $result->status,
                'message' => $result->message,
                'count' => count($result->resources),
                'checked_at' => now()->toIso8601String(),
            ];

            if ($result->status === 'ok') {
                $anySuccess = true;
                $seenIdsByType[$result->capability] = [];

                foreach ($result->resources as $discovered) {
                    $record = $this->upsertResource($integration, $discovered);
                    $seenIdsByType[$result->capability][] = $record->id;
                }

                $this->markMissingAsUnavailable(
                    $integration,
                    $result->capability,
                    $seenIdsByType[$result->capability],
                );
            }
        }

        $config = $integration->config ?? [];
        $config['capability_health'] = $summary;
        $config['last_resource_refresh_at'] = now()->toIso8601String();
        if ($anySuccess) {
            $config['auth_status'] = GoogleAuthStatus::CONNECTED;
        }

        $safeError = null;
        foreach ($summary as $capability => $row) {
            if (in_array($row['status'], ['error', 'setup_required'], true)) {
                $safeError = ProviderRegistry::capabilityLabel($capability).': '.$row['message'];
                break;
            }
        }

        $integration->forceFill([
            'config' => $config,
            'last_success_at' => $anySuccess ? now() : $integration->last_success_at,
            'last_error' => $anySuccess ? null : $safeError,
        ])->save();

        $okCapabilities = collect($summary)->where('status', 'ok')->count();
        $message = $okCapabilities > 0
            ? "Resource refresh completed with {$okCapabilities} successful capability(ies)."
            : 'Resource refresh completed without successful discoveries. Check capability health.';

        return [
            'ok' => $anySuccess,
            'message' => $message,
            'results' => $summary,
        ];
    }

    private function upsertResource(CoreIntegration $integration, DiscoveredExternalResource $discovered): CoreExternalResource
    {
        return DB::transaction(function () use ($integration, $discovered): CoreExternalResource {
            /** @var CoreExternalResource $resource */
            $resource = CoreExternalResource::query()->firstOrNew([
                'integration_id' => $integration->id,
                'resource_type' => $discovered->resourceType,
                'external_id' => $discovered->externalId,
            ]);

            $resource->fill([
                'provider' => ProviderRegistry::GOOGLE,
                'display_name' => $discovered->displayName,
                'parent_external_id' => $discovered->parentExternalId,
                'metadata' => $discovered->metadata,
                'status' => CoreExternalResource::STATUS_AVAILABLE,
                'last_seen_at' => now(),
            ]);

            if (! $resource->exists || $resource->discovered_at === null) {
                $resource->discovered_at = now();
            }

            $resource->save();

            return $resource;
        });
    }

    /**
     * @param  list<int>  $seenIds
     */
    private function markMissingAsUnavailable(CoreIntegration $integration, string $resourceType, array $seenIds): void
    {
        $query = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', $resourceType);

        if ($seenIds !== []) {
            $query->whereNotIn('id', $seenIds);
        }

        $query->update([
            'status' => CoreExternalResource::STATUS_UNAVAILABLE,
        ]);
    }
}
