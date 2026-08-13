<?php

namespace App\Services\Integrations;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Provider-neutral ExternalResource upsert + complete-inventory reconciliation.
 */
final class ReconcileExternalResourcesService
{
    /**
     * @param  list<DiscoveredExternalResource>  $resources
     * @return array{
     *     seen_ids: list<int>,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
     *     marked_unavailable: int
     * }
     */
    public function reconcile(
        CoreIntegration $integration,
        string $resourceType,
        array $resources,
        bool $completeInventory,
        string $provider,
    ): array {
        $seenIds = [];
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($resources as $discovered) {
            if (! $discovered instanceof DiscoveredExternalResource) {
                continue;
            }

            if ($discovered->resourceType !== $resourceType) {
                throw new RuntimeException(
                    "Resource type mismatch during reconcile: expected {$resourceType}, got {$discovered->resourceType}."
                );
            }

            $outcome = $this->upsert($integration, $discovered, $provider);
            $seenIds[] = $outcome['resource']->id;

            match ($outcome['action']) {
                'created' => $created++,
                'updated' => $updated++,
                default => $unchanged++,
            };
        }

        $markedUnavailable = 0;
        if ($completeInventory) {
            $markedUnavailable = $this->markMissingAsUnavailable($integration, $resourceType, $seenIds);
        }

        return [
            'seen_ids' => $seenIds,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'marked_unavailable' => $markedUnavailable,
        ];
    }

    /**
     * @return array{resource: CoreExternalResource, action: string}
     */
    public function upsert(
        CoreIntegration $integration,
        DiscoveredExternalResource $discovered,
        string $provider,
    ): array {
        return DB::transaction(function () use ($integration, $discovered, $provider): array {
            /** @var CoreExternalResource $resource */
            $resource = CoreExternalResource::query()->firstOrNew([
                'integration_id' => $integration->id,
                'resource_type' => $discovered->resourceType,
                'external_id' => $discovered->externalId,
            ]);

            $isNew = ! $resource->exists;

            if ($resource->exists && $resource->resource_type !== $discovered->resourceType) {
                throw new RuntimeException('ExternalResource type cannot silently change.');
            }

            $displayChanged = $resource->display_name !== $discovered->displayName;
            $parentChanged = $resource->parent_external_id !== $discovered->parentExternalId;
            $metaChanged = ($resource->metadata ?? []) != $discovered->metadata;
            $wasUnavailable = $resource->status === CoreExternalResource::STATUS_UNAVAILABLE;

            $resource->fill([
                'provider' => $provider,
                'display_name' => $discovered->displayName,
                'parent_external_id' => $discovered->parentExternalId,
                'metadata' => $discovered->metadata,
                'status' => CoreExternalResource::STATUS_AVAILABLE,
                'last_seen_at' => now(),
            ]);

            if ($isNew || $resource->discovered_at === null) {
                $resource->discovered_at = now();
            }

            $resource->save();

            $action = 'unchanged';
            if ($isNew) {
                $action = 'created';
            } elseif ($displayChanged || $parentChanged || $metaChanged || $wasUnavailable) {
                $action = 'updated';
            }

            return ['resource' => $resource, 'action' => $action];
        });
    }

    /**
     * @param  list<int>  $seenIds
     */
    public function markMissingAsUnavailable(CoreIntegration $integration, string $resourceType, array $seenIds): int
    {
        $query = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', $resourceType)
            ->where('status', '!=', CoreExternalResource::STATUS_UNAVAILABLE);

        if ($seenIds !== []) {
            $query->whereNotIn('id', $seenIds);
        }

        return $query->update([
            'status' => CoreExternalResource::STATUS_UNAVAILABLE,
        ]);
    }
}
