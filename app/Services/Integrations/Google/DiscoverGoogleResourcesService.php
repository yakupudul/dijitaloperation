<?php

namespace App\Services\Integrations\Google;

use App\Models\CoreIntegration;
use App\Models\GoogleIntegrationDiscoveryAttempt;
use App\Models\User;
use App\Services\Integrations\Google\Discovery\CapabilityDiscoveryResult;
use App\Services\Integrations\Google\Discovery\Ga4Discoverer;
use App\Services\Integrations\Google\Discovery\GoogleAdsDiscoverer;
use App\Services\Integrations\Google\Discovery\GoogleBusinessProfileDiscoverer;
use App\Services\Integrations\Google\Discovery\SearchConsoleDiscoverer;
use App\Services\Integrations\ReconcileExternalResourcesService;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;

/**
 * Canonical Google resource-discovery orchestrator (Prompt 15).
 *
 * Isolates Connector failures. Does not create DigitalAssets or Bindings.
 * Does not collect analytical provider data.
 */
class DiscoverGoogleResourcesService
{
    public function __construct(
        private readonly SearchConsoleDiscoverer $searchConsole,
        private readonly Ga4Discoverer $ga4,
        private readonly GoogleAdsDiscoverer $ads,
        private readonly GoogleBusinessProfileDiscoverer $gbp,
        private readonly GoogleCredentialResolver $credentials,
        private readonly ReconcileExternalResourcesService $reconcile,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     results: array<string, array{
     *         status: string,
     *         message: string,
     *         count: int,
     *         complete_inventory: bool,
     *         created: int,
     *         updated: int,
     *         unchanged: int,
     *         marked_unavailable: int,
     *         checked_at: string
     *     }>
     * }
     */
    public function discover(CoreIntegration $integration, ?User $triggeredBy = null): array
    {
        if ($integration->provider !== ProviderRegistry::GOOGLE) {
            return ['ok' => false, 'message' => 'Not a Google integration.', 'results' => []];
        }

        if ($triggeredBy instanceof User && ! $triggeredBy->hasRole(Roles::ADMIN)) {
            return ['ok' => false, 'message' => 'Only authorized operators may run Google resource discovery.', 'results' => []];
        }

        if (! $this->credentials->isAppConfigured($integration)) {
            return [
                'ok' => false,
                'message' => 'Setup required: '.implode(', ', $this->credentials->missingAppKeys($integration)).' missing.',
                'results' => [],
            ];
        }

        $auth = GoogleAuthStatus::for($integration);
        if (in_array($auth, [
            GoogleAuthStatus::AUTHORIZATION_REQUIRED,
            GoogleAuthStatus::NOT_CONFIGURED,
            GoogleAuthStatus::DISABLED,
            GoogleAuthStatus::REVOKED,
        ], true)) {
            return [
                'ok' => false,
                'message' => GoogleAuthStatus::label($auth).'. Authorize Google before discovering resources.',
                'results' => [],
            ];
        }

        $integration = $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration;

        /** @var list<CapabilityDiscoveryResult> $capabilityResults */
        $capabilityResults = [
            $this->searchConsole->discover($integration),
            $this->ga4->discover($integration),
            $this->ads->discover($integration),
            $this->gbp->discover($integration),
        ];

        $summary = [];
        $anySuccess = false;

        foreach ($capabilityResults as $result) {
            $startedAt = now();
            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $markedUnavailable = 0;

            if ($result->isSuccessfulInventory()) {
                $anySuccess = true;
                $counts = $this->reconcile->reconcile(
                    $integration,
                    $result->capability,
                    $result->resources,
                    $result->allowsNegativeReconciliation(),
                    ProviderRegistry::GOOGLE,
                );
                $created = $counts['created'];
                $updated = $counts['updated'];
                $unchanged = $counts['unchanged'];
                $markedUnavailable = $counts['marked_unavailable'];
            }

            $summary[$result->capability] = [
                'status' => $result->status,
                'message' => $result->message,
                'count' => count($result->resources),
                'complete_inventory' => $result->completeInventory,
                'created' => $created,
                'updated' => $updated,
                'unchanged' => $unchanged,
                'marked_unavailable' => $markedUnavailable,
                'checked_at' => now()->toIso8601String(),
            ];

            $this->persistAttempt(
                $integration,
                $result,
                $triggeredBy,
                $startedAt,
                $created,
                $updated,
                $unchanged,
                $markedUnavailable,
            );
        }

        $config = $integration->config ?? [];
        $config['capability_health'] = $summary;
        $config['last_resource_refresh_at'] = now()->toIso8601String();
        $config['discovery'] = [
            'last_attempt_at' => now()->toIso8601String(),
            'last_success_at' => $anySuccess
                ? now()->toIso8601String()
                : data_get($config, 'discovery.last_success_at'),
            'connectors' => collect($summary)->map(fn (array $row): array => [
                'status' => $row['status'],
                'count' => $row['count'],
                'complete_inventory' => $row['complete_inventory'],
                'message' => $row['message'],
                'checked_at' => $row['checked_at'],
            ])->all(),
        ];

        if ($anySuccess && $auth === GoogleAuthStatus::CONNECTED) {
            $config['auth_status'] = GoogleAuthStatus::CONNECTED;
        }

        $safeError = null;
        foreach ($summary as $capability => $row) {
            if (in_array($row['status'], [
                CapabilityDiscoveryResult::STATUS_ERROR,
                CapabilityDiscoveryResult::STATUS_FAILED,
                CapabilityDiscoveryResult::STATUS_SETUP_REQUIRED,
                CapabilityDiscoveryResult::STATUS_EXTERNAL_ACCESS_REQUIRED,
                CapabilityDiscoveryResult::STATUS_SCOPE_REQUIRED,
                CapabilityDiscoveryResult::STATUS_AUTHENTICATION_REQUIRED,
            ], true)) {
                $safeError = ProviderRegistry::capabilityLabel($capability).': '.$row['message'];
                break;
            }
        }

        $integration->forceFill([
            'config' => $config,
            'last_success_at' => $anySuccess ? now() : $integration->last_success_at,
            // Do not wipe last_error when other connectors succeeded.
            'last_error' => $anySuccess ? null : $safeError,
        ])->save();

        $okCapabilities = collect($summary)->filter(
            fn (array $row): bool => in_array($row['status'], [
                CapabilityDiscoveryResult::STATUS_OK,
                CapabilityDiscoveryResult::STATUS_COMPLETED,
                CapabilityDiscoveryResult::STATUS_PARTIAL,
            ], true)
        )->count();

        $message = $okCapabilities > 0
            ? "Resource discovery completed with {$okCapabilities} successful connector(s)."
            : 'Resource discovery completed without successful inventories. Check connector discovery state.';

        return [
            'ok' => $anySuccess,
            'message' => $message,
            'results' => $summary,
        ];
    }

    private function persistAttempt(
        CoreIntegration $integration,
        CapabilityDiscoveryResult $result,
        ?User $triggeredBy,
        mixed $startedAt,
        int $created,
        int $updated,
        int $unchanged,
        int $markedUnavailable,
    ): void {
        GoogleIntegrationDiscoveryAttempt::query()->create([
            'integration_id' => $integration->id,
            'connector' => $result->capability,
            'status' => $result->status,
            'complete_inventory' => $result->completeInventory,
            'resources_seen' => count($result->resources),
            'resources_created' => $created,
            'resources_updated' => $updated,
            'resources_unchanged' => $unchanged,
            'resources_marked_unavailable' => $markedUnavailable,
            'error_category' => $this->errorCategory($result->status),
            'safe_error_message' => $result->isSuccessfulInventory() ? null : $result->message,
            'triggered_by_user_id' => $triggeredBy?->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    private function errorCategory(string $status): ?string
    {
        return match ($status) {
            CapabilityDiscoveryResult::STATUS_SCOPE_REQUIRED => 'AUTHORIZATION',
            CapabilityDiscoveryResult::STATUS_AUTHENTICATION_REQUIRED => 'AUTHENTICATION',
            CapabilityDiscoveryResult::STATUS_EXTERNAL_ACCESS_REQUIRED,
            CapabilityDiscoveryResult::STATUS_SETUP_REQUIRED => 'EXTERNAL_ACCESS_REQUIRED',
            CapabilityDiscoveryResult::STATUS_ERROR,
            CapabilityDiscoveryResult::STATUS_FAILED => 'PROVIDER',
            CapabilityDiscoveryResult::STATUS_PARTIAL => 'PARTIAL',
            default => null,
        };
    }
}
