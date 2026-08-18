<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\MetaIntegrationDiscoveryAttempt;
use App\Models\User;
use App\Support\Integrations\DiscoveredExternalResource;
use App\Support\Integrations\Meta\MetaAdAccountId;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Discover Meta Ad Accounts for operator-selected Business discovery contexts.
 *
 * Reads only `owned_ad_accounts` and `client_ad_accounts` edges per selected
 * Business (zero Insights/campaign calls). Accounts are deduplicated by
 * canonical act_ id; access is tracked per business+edge in
 * metadata.access_contexts so an account visible through multiple Businesses
 * is never incorrectly marked unavailable when only one context loses access.
 *
 * completeInventory is intentionally never global for Ad Accounts: a missing
 * relationship for one business+edge only flags that access context as lost
 * (metadata), it never hard-deletes or globally disables the account.
 */
class MetaAdAccountDiscoverer
{
    private const array EDGES = ['owned_ad_accounts', 'client_ad_accounts'];

    public function __construct(
        private readonly MetaApiClient $client,
        private readonly MetaCredentialResolver $resolver,
        private readonly MetaPermissionCoverageService $coverage,
        private readonly SelectMetaDiscoveryContextService $selection,
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

        $businesses = $this->selection->selectedBusinesses($integration);
        if ($businesses->isEmpty()) {
            return $this->result(
                false,
                'setup_required',
                'Select at least one Meta Business before discovering Ad Accounts.',
                [],
            );
        }

        if (! $this->resolver->hasTenantAuthorization($integration)) {
            return $this->result(false, 'authentication_required', 'Authorize Meta before discovering Ad Accounts.', []);
        }

        $missing = $this->coverage->missingForAdAccountDiscovery($integration);
        if ($missing !== []) {
            return $this->result(
                false,
                'permission_required',
                'Missing Meta permissions for Ad Account discovery: '.implode(', ', $missing).'.',
                [],
            );
        }

        $anySuccess = false;
        $anyFailure = false;
        /** @var array<string, DiscoveredExternalResource> $allByExternalId */
        $allByExternalId = [];

        foreach ($businesses as $business) {
            foreach (self::EDGES as $edge) {
                $startedAt = now();

                try {
                    $page = $this->paginateComplete(
                        $integration,
                        $business->external_id.'/'.$edge,
                        ['fields' => MetaApiConfig::adAccountFields(), 'limit' => 100],
                    );
                } catch (MetaException $exception) {
                    $anyFailure = true;
                    $this->recordAttempt(
                        $integration,
                        $business,
                        $edge,
                        $this->attemptStatusForException($exception),
                        startedAt: $startedAt,
                        safeErrorMessage: MetaOperatorMessages::forException($exception),
                        errorCategory: $exception->kind,
                        triggeredBy: $triggeredBy,
                    );

                    continue;
                } catch (Throwable) {
                    $anyFailure = true;
                    $this->recordAttempt(
                        $integration,
                        $business,
                        $edge,
                        MetaIntegrationDiscoveryAttempt::STATUS_FAILED,
                        startedAt: $startedAt,
                        safeErrorMessage: 'Meta Ad Account discovery failed to run.',
                        errorCategory: 'unknown',
                        triggeredBy: $triggeredBy,
                    );

                    continue;
                }

                $anySuccess = true;

                /** @var array<string, DiscoveredExternalResource> $edgeAccounts */
                $edgeAccounts = [];
                foreach ($page['items'] as $row) {
                    $discovered = $this->normalize($row, $business, $edge);
                    if ($discovered !== null) {
                        $edgeAccounts[$discovered->externalId] = $discovered;
                        $allByExternalId[$discovered->externalId] = $discovered;
                    }
                }

                $outcome = $this->upsertAccounts($integration, $edgeAccounts);

                $markedLost = 0;
                if ($page['complete']) {
                    $markedLost = $this->markAccessLost($integration, $business, $edge, array_keys($edgeAccounts));
                }

                $this->recordAttempt(
                    $integration,
                    $business,
                    $edge,
                    $page['complete'] ? MetaIntegrationDiscoveryAttempt::STATUS_COMPLETED : MetaIntegrationDiscoveryAttempt::STATUS_PARTIAL,
                    startedAt: $startedAt,
                    completeInventory: $page['complete'],
                    resourcesSeen: count($edgeAccounts),
                    resourcesCreated: $outcome['created'],
                    resourcesUpdated: $outcome['updated'],
                    resourcesUnchanged: $outcome['unchanged'],
                    resourcesMarkedUnavailable: $markedLost,
                    triggeredBy: $triggeredBy,
                );
            }
        }

        if (! $anySuccess) {
            return $this->result(false, 'failed', 'Meta Ad Account discovery failed for every selected Business.', []);
        }

        $resources = array_values($allByExternalId);
        $status = $anyFailure ? 'partial' : 'completed';
        $message = count($resources).' Meta Ad Account'.(count($resources) === 1 ? '' : 's').' discovered'
            .($anyFailure ? ' (partial — at least one edge failed).' : '.');

        $config = is_array($integration->config) ? $integration->config : [];
        $config['last_ad_account_discovery_at'] = now()->toIso8601String();
        $integration->forceFill(['config' => $config, 'last_error' => null])->save();

        return $this->result($anySuccess, $status, $message, $resources);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{items: list<array<string, mixed>>, complete: bool}
     */
    private function paginateComplete(CoreIntegration $integration, string $path, array $query): array
    {
        $items = [];
        $payload = $this->client->get($integration, $path, $query);
        $page = 1;
        $complete = true;

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
                $complete = false;
                break;
            }

            $payload = $this->client->getAbsolute($integration, $next);
        }

        return ['items' => $items, 'complete' => $complete];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalize(array $row, CoreExternalResource $business, string $edge): ?DiscoveredExternalResource
    {
        $raw = null;
        if (isset($row['account_id']) && (is_string($row['account_id']) || is_numeric($row['account_id']))) {
            $raw = (string) $row['account_id'];
        } elseif (isset($row['id']) && (is_string($row['id']) || is_numeric($row['id']))) {
            $raw = (string) $row['id'];
        }

        $externalId = MetaAdAccountId::canonical($raw);
        $accountId = MetaAdAccountId::digits($raw);
        if ($externalId === null || $accountId === null) {
            return null;
        }

        $name = isset($row['name']) && is_string($row['name']) && trim($row['name']) !== ''
            ? trim($row['name'])
            : $externalId;

        $metadata = array_filter([
            'account_id' => $accountId,
            'currency' => isset($row['currency']) && is_string($row['currency']) ? $row['currency'] : null,
            'timezone_name' => isset($row['timezone_name']) && is_string($row['timezone_name']) ? $row['timezone_name'] : null,
            'account_status' => isset($row['account_status']) && is_numeric($row['account_status']) ? (int) $row['account_status'] : null,
            'business_id' => $business->external_id,
            'business_name' => $business->display_name,
            'provider_resource_type' => 'meta_ad_account',
            'selectable' => true,
            'bindable' => true,
            'access_contexts' => [[
                'business_id' => $business->external_id,
                'business_name' => $business->display_name,
                'edge' => $edge,
                'last_seen' => now()->toIso8601String(),
                'access_lost' => false,
                'access_lost_at' => null,
            ]],
        ], static fn (mixed $value): bool => $value !== null);

        return new DiscoveredExternalResource(
            resourceType: MetaResourceType::META_AD_ACCOUNT,
            externalId: $externalId,
            displayName: $name,
            parentExternalId: $business->external_id,
            metadata: $metadata,
        );
    }

    /**
     * Upsert accounts, merging metadata.access_contexts per business+edge key
     * instead of overwriting the whole array (preserves other contexts, e.g.
     * from other Businesses discovered earlier).
     *
     * @param  array<string, DiscoveredExternalResource>  $accounts
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function upsertAccounts(CoreIntegration $integration, array $accounts): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($accounts as $discovered) {
            $action = DB::transaction(function () use ($integration, $discovered): string {
                /** @var CoreExternalResource $resource */
                $resource = CoreExternalResource::query()->firstOrNew([
                    'integration_id' => $integration->id,
                    'resource_type' => MetaResourceType::META_AD_ACCOUNT,
                    'external_id' => $discovered->externalId,
                ]);

                $isNew = ! $resource->exists;
                $existingMetadata = is_array($resource->metadata) ? $resource->metadata : [];
                $existingContexts = is_array($existingMetadata['access_contexts'] ?? null)
                    ? $existingMetadata['access_contexts']
                    : [];
                $incomingContexts = is_array($discovered->metadata['access_contexts'] ?? null)
                    ? $discovered->metadata['access_contexts']
                    : [];

                $mergedContexts = $this->mergeAccessContexts($existingContexts, $incomingContexts);
                $newMetadata = array_merge($existingMetadata, $discovered->metadata, [
                    'access_contexts' => $mergedContexts,
                ]);

                $displayChanged = $resource->display_name !== $discovered->displayName;
                $metaChanged = ($resource->metadata ?? []) != $newMetadata;
                $wasUnavailable = $resource->status === CoreExternalResource::STATUS_UNAVAILABLE;

                $resource->fill([
                    'provider' => ProviderRegistry::META,
                    'display_name' => $discovered->displayName,
                    'parent_external_id' => $discovered->parentExternalId ?? $resource->parent_external_id,
                    'metadata' => $newMetadata,
                    'status' => CoreExternalResource::STATUS_AVAILABLE,
                    'last_seen_at' => now(),
                ]);

                if ($isNew || $resource->discovered_at === null) {
                    $resource->discovered_at = now();
                }

                $resource->save();

                if ($isNew) {
                    return 'created';
                }

                return ($displayChanged || $metaChanged || $wasUnavailable) ? 'updated' : 'unchanged';
            });

            match ($action) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };
        }

        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeAccessContexts(array $existing, array $incoming): array
    {
        $byKey = [];

        foreach ($existing as $context) {
            if (! is_array($context)) {
                continue;
            }
            $key = ($context['business_id'] ?? '').'|'.($context['edge'] ?? '');
            $byKey[$key] = $context;
        }

        foreach ($incoming as $context) {
            if (! is_array($context)) {
                continue;
            }
            $key = ($context['business_id'] ?? '').'|'.($context['edge'] ?? '');
            $byKey[$key] = $context;
        }

        return array_values($byKey);
    }

    /**
     * For a COMPLETE edge listing, flag access_contexts entries for this
     * business+edge that were not seen in this run as access_lost — never
     * removes the resource, never touches other businesses'/edges' contexts,
     * and never marks the account globally unavailable.
     *
     * @param  list<string>  $seenExternalIds
     */
    private function markAccessLost(
        CoreIntegration $integration,
        CoreExternalResource $business,
        string $edge,
        array $seenExternalIds,
    ): int {
        $count = 0;

        $resources = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->get();

        foreach ($resources as $resource) {
            if (in_array($resource->external_id, $seenExternalIds, true)) {
                continue;
            }

            $metadata = is_array($resource->metadata) ? $resource->metadata : [];
            $contexts = is_array($metadata['access_contexts'] ?? null) ? $metadata['access_contexts'] : [];
            $changed = false;

            foreach ($contexts as $index => $context) {
                if (! is_array($context)) {
                    continue;
                }
                if (($context['business_id'] ?? null) !== $business->external_id || ($context['edge'] ?? null) !== $edge) {
                    continue;
                }
                if (($context['access_lost'] ?? false) === true) {
                    continue;
                }

                $context['access_lost'] = true;
                $context['access_lost_at'] = now()->toIso8601String();
                $contexts[$index] = $context;
                $changed = true;
            }

            if ($changed) {
                $metadata['access_contexts'] = $contexts;
                $resource->forceFill(['metadata' => $metadata])->save();
                $count++;
            }
        }

        return $count;
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
        CoreExternalResource $business,
        string $edge,
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
            'phase' => MetaIntegrationDiscoveryAttempt::PHASE_AD_ACCOUNTS,
            'connector' => 'meta_ads',
            'business_resource_id' => $business->id,
            'edge' => $edge,
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
