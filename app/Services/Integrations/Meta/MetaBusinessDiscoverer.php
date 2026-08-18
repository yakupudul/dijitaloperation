<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Models\MetaIntegrationDiscoveryAttempt;
use App\Models\User;
use App\Services\Integrations\ReconcileExternalResourcesService;
use App\Support\Integrations\DiscoveredExternalResource;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Discover Meta Business portfolios via GET me/businesses.
 *
 * Businesses are provider access containers — not bindable, never a DigitalAsset.
 * Full pagination is treated as complete inventory (safe to mark missing as
 * unavailable), since me/businesses always reflects the operator's full
 * current Business access.
 */
class MetaBusinessDiscoverer
{
    private const string EDGE = 'me_businesses';

    public function __construct(
        private readonly MetaApiClient $client,
        private readonly MetaCredentialResolver $resolver,
        private readonly MetaPermissionCoverageService $coverage,
        private readonly ReconcileExternalResourcesService $reconciler,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     status: string,
     *     message: string,
     *     count: int,
     *     resources: list<DiscoveredExternalResource>
     * }
     */
    public function discover(CoreIntegration $integration, ?User $triggeredBy = null): array
    {
        $this->assertMeta($integration);

        $startedAt = now();

        if (! $this->resolver->hasTenantAuthorization($integration)) {
            $message = 'Authorize Meta before discovering Businesses.';
            $this->recordAttempt(
                $integration,
                MetaIntegrationDiscoveryAttempt::STATUS_AUTHENTICATION_REQUIRED,
                startedAt: $startedAt,
                safeErrorMessage: $message,
                errorCategory: 'authentication',
                triggeredBy: $triggeredBy,
            );

            return $this->result(false, 'authentication_required', $message, []);
        }

        $missing = $this->coverage->missingForBusinessDiscovery($integration);
        if ($missing !== []) {
            $message = 'Missing Meta permissions for Business discovery: '.implode(', ', $missing).'.';
            $this->recordAttempt(
                $integration,
                MetaIntegrationDiscoveryAttempt::STATUS_PERMISSION_REQUIRED,
                startedAt: $startedAt,
                safeErrorMessage: $message,
                errorCategory: 'permission',
                triggeredBy: $triggeredBy,
            );

            return $this->result(false, 'permission_required', $message, []);
        }

        try {
            $rows = $this->paginate($integration, 'me/businesses', ['fields' => 'id,name', 'limit' => 100]);
        } catch (MetaException $exception) {
            $safeMessage = MetaOperatorMessages::forException($exception);
            $this->recordAttempt(
                $integration,
                $this->attemptStatusForException($exception),
                startedAt: $startedAt,
                safeErrorMessage: $safeMessage,
                errorCategory: $exception->kind,
                triggeredBy: $triggeredBy,
            );

            return $this->result(false, 'failed', $safeMessage, []);
        } catch (Throwable) {
            $safeMessage = 'Meta Business discovery failed to run.';
            $this->recordAttempt(
                $integration,
                MetaIntegrationDiscoveryAttempt::STATUS_FAILED,
                startedAt: $startedAt,
                safeErrorMessage: $safeMessage,
                errorCategory: 'unknown',
                triggeredBy: $triggeredBy,
            );

            return $this->result(false, 'failed', $safeMessage, []);
        }

        $resources = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = isset($row['id']) && (is_string($row['id']) || is_numeric($row['id'])) ? (string) $row['id'] : null;
            if ($id === null || $id === '') {
                continue;
            }

            $name = isset($row['name']) && is_string($row['name']) && trim($row['name']) !== ''
                ? trim($row['name'])
                : $id;

            $resources[] = new DiscoveredExternalResource(
                resourceType: MetaResourceType::META_BUSINESS,
                externalId: $id,
                displayName: $name,
                parentExternalId: null,
                metadata: [
                    'provider_resource_type' => 'meta_business',
                    'container' => true,
                    'selectable' => false,
                    'bindable' => false,
                ],
            );
        }

        $outcome = $this->reconciler->reconcile(
            $integration,
            MetaResourceType::META_BUSINESS,
            $resources,
            completeInventory: true,
            provider: ProviderRegistry::META,
        );

        $this->recordAttempt(
            $integration,
            MetaIntegrationDiscoveryAttempt::STATUS_COMPLETED,
            startedAt: $startedAt,
            completeInventory: true,
            resourcesSeen: count($resources),
            resourcesCreated: $outcome['created'],
            resourcesUpdated: $outcome['updated'],
            resourcesUnchanged: $outcome['unchanged'],
            resourcesMarkedUnavailable: $outcome['marked_unavailable'],
            triggeredBy: $triggeredBy,
        );

        $config = is_array($integration->config) ? $integration->config : [];
        $config['last_business_discovery_at'] = now()->toIso8601String();
        $integration->forceFill(['config' => $config, 'last_error' => null])->save();

        return $this->result(
            true,
            'completed',
            count($resources).' Meta Business'.(count($resources) === 1 ? '' : 'es').' discovered.',
            $resources,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(CoreIntegration $integration, string $path, array $query): array
    {
        $items = [];
        $payload = $this->client->get($integration, $path, $query);
        $page = 1;

        while (true) {
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            foreach ($data as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }

            $next = data_get($payload, 'paging.next');
            if (! is_string($next) || $next === '') {
                break;
            }

            $page++;
            if ($page > MetaApiConfig::maxPaginationPages()) {
                break;
            }

            $payload = $this->client->getAbsolute($integration, $next);
        }

        return $items;
    }

    private function attemptStatusForException(MetaException $exception): string
    {
        return match ($exception->kind) {
            MetaException::KIND_AUTH, MetaException::KIND_CONFIG => MetaIntegrationDiscoveryAttempt::STATUS_AUTHENTICATION_REQUIRED,
            MetaException::KIND_PERMISSION => MetaIntegrationDiscoveryAttempt::STATUS_PERMISSION_REQUIRED,
            default => MetaIntegrationDiscoveryAttempt::STATUS_FAILED,
        };
    }

    private function recordAttempt(
        CoreIntegration $integration,
        string $status,
        Carbon $startedAt,
        bool $completeInventory = false,
        int $resourcesSeen = 0,
        int $resourcesCreated = 0,
        int $resourcesUpdated = 0,
        int $resourcesUnchanged = 0,
        int $resourcesMarkedUnavailable = 0,
        ?string $errorCategory = null,
        ?string $safeErrorMessage = null,
        ?User $triggeredBy = null,
    ): void {
        MetaIntegrationDiscoveryAttempt::query()->create([
            'integration_id' => $integration->id,
            'phase' => MetaIntegrationDiscoveryAttempt::PHASE_BUSINESSES,
            'connector' => 'meta_ads',
            'business_resource_id' => null,
            'edge' => self::EDGE,
            'status' => $status,
            'complete_inventory' => $completeInventory,
            'resources_seen' => $resourcesSeen,
            'resources_created' => $resourcesCreated,
            'resources_updated' => $resourcesUpdated,
            'resources_unchanged' => $resourcesUnchanged,
            'resources_marked_unavailable' => $resourcesMarkedUnavailable,
            'graph_api_version' => MetaApiConfig::apiVersion(),
            'error_category' => $errorCategory,
            'safe_error_message' => $safeErrorMessage,
            'triggered_by_user_id' => $triggeredBy?->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  list<DiscoveredExternalResource>  $resources
     * @return array{ok: bool, status: string, message: string, count: int, resources: list<DiscoveredExternalResource>}
     */
    private function result(bool $ok, string $status, string $message, array $resources): array
    {
        return [
            'ok' => $ok,
            'status' => $status,
            'message' => $message,
            'count' => count($resources),
            'resources' => $resources,
        ];
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Integration is not a Meta provider.');
        }
    }
}
