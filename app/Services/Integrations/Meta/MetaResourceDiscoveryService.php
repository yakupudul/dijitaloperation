<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Support\Integrations\DiscoveredExternalResource;
use App\Support\Integrations\Meta\MetaAdAccountId;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Discover readable Meta Ad Accounts via official Graph paths.
 *
 * Paths (Marketing API — version from MetaApiConfig):
 * - GET /me/adaccounts
 * - GET /me/businesses → /{business-id}/owned_ad_accounts
 * - GET /me/businesses → /{business-id}/client_ad_accounts
 *
 * Deduplicates by stable act_{account_id}. Does not delete bindings on failure.
 */
class MetaResourceDiscoveryService
{
    /** @deprecated Use MetaResourceType::META_AD_ACCOUNT */
    public const string RESOURCE_TYPE = 'meta_ads';

    public function __construct(
        private readonly MetaCredentialResolver $resolver,
        private readonly MetaApiClient $client,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     count: int,
     *     resources: list<DiscoveredExternalResource>,
     *     paths: array<string, array{status: string, count: int, message?: string}>
     * }
     */
    public function discover(CoreIntegration $integration): array
    {
        $this->assertMeta($integration);

        if (! $this->resolver->isConfigured($integration)) {
            return [
                'ok' => false,
                'message' => 'Configure a Meta access token before discovering resources.',
                'count' => 0,
                'resources' => [],
                'paths' => [],
            ];
        }

        /** @var array<string, DiscoveredExternalResource> $byExternalId */
        $byExternalId = [];
        $paths = [];

        $paths['me_adaccounts'] = $this->collectPath(
            $integration,
            'me/adaccounts',
            null,
            'direct',
            $byExternalId,
        );

        $businesses = [];
        try {
            $businesses = $this->paginate($integration, 'me/businesses', ['fields' => 'id,name']);
            $paths['me_businesses'] = [
                'status' => 'ok',
                'count' => count($businesses),
            ];
        } catch (MetaException $exception) {
            $paths['me_businesses'] = [
                'status' => 'error',
                'count' => 0,
                'message' => MetaOperatorMessages::forException($exception),
            ];
        }

        foreach ($businesses as $business) {
            if (! is_array($business)) {
                continue;
            }
            $businessId = isset($business['id']) && is_string($business['id']) ? $business['id'] : null;
            $businessName = isset($business['name']) && is_string($business['name']) ? $business['name'] : null;
            if ($businessId === null) {
                continue;
            }

            // Persist Business as container ExternalResource (not bindable, not a DigitalAsset).
            $this->upsertResource($integration, new DiscoveredExternalResource(
                resourceType: MetaResourceType::META_BUSINESS,
                externalId: $businessId,
                displayName: is_string($businessName) && $businessName !== '' ? $businessName : $businessId,
                parentExternalId: null,
                metadata: [
                    'provider_resource_type' => 'meta_business',
                    'container' => true,
                    'selectable' => false,
                    'bindable' => false,
                ],
            ));

            $paths['business_'.$businessId.'_owned'] = $this->collectPath(
                $integration,
                $businessId.'/owned_ad_accounts',
                ['id' => $businessId, 'name' => $businessName],
                'owned',
                $byExternalId,
            );
            $paths['business_'.$businessId.'_client'] = $this->collectPath(
                $integration,
                $businessId.'/client_ad_accounts',
                ['id' => $businessId, 'name' => $businessName],
                'client',
                $byExternalId,
            );
        }

        $resources = array_values($byExternalId);
        $anyPathOk = collect($paths)->contains(fn (array $row): bool => ($row['status'] ?? '') === 'ok');

        if (! $anyPathOk && $resources === []) {
            $message = 'Resource discovery failed. No Meta Ad Accounts could be read.';
            $config = is_array($integration->config) ? $integration->config : [];
            $config['last_resource_refresh_at'] = now()->toIso8601String();
            $config['discovery_summary'] = [
                'ok' => false,
                'count' => 0,
                'paths' => $paths,
            ];
            $integration->forceFill([
                'config' => $config,
                'last_error' => $message,
            ])->save();

            return [
                'ok' => false,
                'message' => $message,
                'count' => 0,
                'resources' => [],
                'paths' => $paths,
            ];
        }

        $seenIds = [];
        foreach ($resources as $discovered) {
            $record = $this->upsertResource($integration, $discovered);
            $seenIds[] = $record->id;
        }

        // Only mark missing as unavailable when at least one discovery path succeeded.
        if ($anyPathOk) {
            $this->markMissingAsUnavailable($integration, $seenIds);
        }

        $config = is_array($integration->config) ? $integration->config : [];
        $config['last_resource_refresh_at'] = now()->toIso8601String();
        $config['discovery_summary'] = [
            'ok' => true,
            'count' => count($resources),
            'paths' => $paths,
        ];
        $integration->forceFill([
            'config' => $config,
            'last_success_at' => now(),
            'last_error' => null,
        ])->save();

        return [
            'ok' => true,
            'message' => count($resources).' Meta Ad Account'.(count($resources) === 1 ? '' : 's').' discovered.',
            'count' => count($resources),
            'resources' => $resources,
            'paths' => $paths,
        ];
    }

    /**
     * @param  array{id: string, name: ?string}|null  $business
     * @param  array<string, DiscoveredExternalResource>  $byExternalId
     * @return array{status: string, count: int, message?: string}
     */
    private function collectPath(
        CoreIntegration $integration,
        string $path,
        ?array $business,
        string $accessRelation,
        array &$byExternalId,
    ): array {
        try {
            $rows = $this->paginate($integration, $path, [
                'fields' => MetaApiConfig::adAccountFields(),
                'limit' => 100,
            ]);
            $added = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $discovered = $this->normalizeAccount($row, $business, $accessRelation);
                if ($discovered === null) {
                    continue;
                }
                if (! isset($byExternalId[$discovered->externalId])) {
                    $byExternalId[$discovered->externalId] = $discovered;
                    $added++;
                } else {
                    // Merge business context and discovery paths if missing.
                    $existing = $byExternalId[$discovered->externalId];
                    $meta = $existing->metadata;
                    $paths = array_values(array_unique(array_filter([
                        ...((array) ($meta['discovery_paths'] ?? [])),
                        ...((array) ($discovered->metadata['discovery_paths'] ?? [])),
                    ], fn ($v) => is_string($v) && $v !== '')));
                    $byExternalId[$discovered->externalId] = new DiscoveredExternalResource(
                        resourceType: $existing->resourceType,
                        externalId: $existing->externalId,
                        displayName: $discovered->displayName !== '' ? $discovered->displayName : $existing->displayName,
                        parentExternalId: $discovered->parentExternalId ?? $existing->parentExternalId,
                        metadata: array_filter(array_merge($meta, [
                            'business_id' => $discovered->metadata['business_id'] ?? ($meta['business_id'] ?? null),
                            'business_name' => $discovered->metadata['business_name'] ?? ($meta['business_name'] ?? null),
                            'access_relation' => $meta['access_relation'] ?? ($discovered->metadata['access_relation'] ?? null),
                            'discovery_paths' => $paths !== [] ? $paths : null,
                        ]), fn ($v) => $v !== null),
                    );
                }
            }

            return ['status' => 'ok', 'count' => $added];
        } catch (MetaException $exception) {
            return [
                'status' => 'error',
                'count' => 0,
                'message' => MetaOperatorMessages::forException($exception),
            ];
        } catch (Throwable) {
            return [
                'status' => 'error',
                'count' => 0,
                'message' => 'Unknown provider error.',
            ];
        }
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

    /**
     * @param  array<string, mixed>  $row
     * @param  array{id: string, name: ?string}|null  $business
     */
    private function normalizeAccount(array $row, ?array $business, string $accessRelation): ?DiscoveredExternalResource
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

        $businessId = $business['id'] ?? null;
        $businessName = $business['name'] ?? null;
        if (isset($row['business']) && is_array($row['business'])) {
            if (isset($row['business']['id']) && is_string($row['business']['id'])) {
                $businessId = $row['business']['id'];
            }
            if (isset($row['business']['name']) && is_string($row['business']['name'])) {
                $businessName = $row['business']['name'];
            }
        }

        $metadata = array_filter([
            'account_id' => $accountId,
            'currency' => isset($row['currency']) && is_string($row['currency']) ? $row['currency'] : null,
            'timezone_name' => isset($row['timezone_name']) && is_string($row['timezone_name']) ? $row['timezone_name'] : null,
            'account_status' => isset($row['account_status']) && is_numeric($row['account_status']) ? (int) $row['account_status'] : null,
            'business_id' => is_string($businessId) ? $businessId : null,
            'business_name' => is_string($businessName) ? $businessName : null,
            'access_relation' => $accessRelation,
            'discovery_paths' => [$accessRelation],
            'provider_resource_type' => 'meta_ad_account',
            'selectable' => true,
            'bindable' => true,
        ], static fn (mixed $value): bool => $value !== null);

        return new DiscoveredExternalResource(
            resourceType: MetaResourceType::META_AD_ACCOUNT,
            externalId: $externalId,
            displayName: $name,
            parentExternalId: is_string($businessId) ? $businessId : null,
            metadata: $metadata,
        );
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
                'provider' => ProviderRegistry::META,
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
    private function markMissingAsUnavailable(CoreIntegration $integration, array $seenIds): void
    {
        $query = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE);

        if ($seenIds !== []) {
            $query->whereNotIn('id', $seenIds);
        }

        $query->update([
            'status' => CoreExternalResource::STATUS_UNAVAILABLE,
            'updated_at' => now(),
        ]);
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new \RuntimeException('Integration is not a Meta provider.');
        }
    }
}
