<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationDiscoveryContext;
use App\Models\User;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Operator selection of Meta Businesses as Ad Account discovery scope.
 *
 * Control-plane only — persists CoreIntegrationDiscoveryContext rows
 * (purpose = discovery_context). Never creates a CoreAssetBinding and
 * never touches DigitalAsset.
 */
class SelectMetaDiscoveryContextService
{
    public function assertAdmin(User $user): void
    {
        if (! $user->hasRole(Roles::ADMIN)) {
            throw new RuntimeException('Only Admin users may select Meta discovery context.');
        }
    }

    public function select(CoreIntegration $integration, string $externalResourceId, User $user): CoreIntegrationDiscoveryContext
    {
        $this->assertAdmin($user);
        $resource = $this->resolveBusiness($integration, $externalResourceId);

        return $this->activate($integration, $resource);
    }

    /**
     * @param  list<string>  $externalResourceIds
     * @return list<CoreIntegrationDiscoveryContext>
     */
    public function selectMany(CoreIntegration $integration, array $externalResourceIds, User $user): array
    {
        $this->assertAdmin($user);

        $contexts = [];
        foreach ($externalResourceIds as $externalResourceId) {
            $resource = $this->resolveBusiness($integration, $externalResourceId);
            $contexts[] = $this->activate($integration, $resource);
        }

        return $contexts;
    }

    public function deselect(CoreIntegration $integration, string $externalResourceId, User $user): void
    {
        $this->assertAdmin($user);
        $resource = $this->resolveBusiness($integration, $externalResourceId);

        $this->deactivate($integration, $resource);
    }

    /**
     * @param  list<string>  $externalResourceIds
     */
    public function deselectMany(CoreIntegration $integration, array $externalResourceIds, User $user): void
    {
        $this->assertAdmin($user);

        foreach ($externalResourceIds as $externalResourceId) {
            $resource = $this->resolveBusiness($integration, $externalResourceId);
            $this->deactivate($integration, $resource);
        }
    }

    /**
     * Active discovery-context Businesses for this Meta Integration.
     *
     * @return Collection<int, CoreExternalResource>
     */
    public function selectedBusinesses(CoreIntegration $integration): Collection
    {
        return CoreIntegrationDiscoveryContext::query()
            ->where('integration_id', $integration->id)
            ->where('purpose', CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT)
            ->where('status', CoreIntegrationDiscoveryContext::STATUS_ACTIVE)
            ->with('externalResource')
            ->get()
            ->map(fn (CoreIntegrationDiscoveryContext $context): ?CoreExternalResource => $context->externalResource)
            ->filter(fn (?CoreExternalResource $resource): bool => $resource instanceof CoreExternalResource
                && $resource->resource_type === MetaResourceType::META_BUSINESS)
            ->values();
    }

    public function hasSelection(CoreIntegration $integration): bool
    {
        return CoreIntegrationDiscoveryContext::query()
            ->where('integration_id', $integration->id)
            ->where('purpose', CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT)
            ->where('status', CoreIntegrationDiscoveryContext::STATUS_ACTIVE)
            ->exists();
    }

    private function activate(CoreIntegration $integration, CoreExternalResource $resource): CoreIntegrationDiscoveryContext
    {
        /** @var CoreIntegrationDiscoveryContext $context */
        $context = CoreIntegrationDiscoveryContext::query()->updateOrCreate(
            [
                'integration_id' => $integration->id,
                'external_resource_id' => $resource->id,
                'purpose' => CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT,
            ],
            [
                'status' => CoreIntegrationDiscoveryContext::STATUS_ACTIVE,
                'selected_at' => now(),
            ],
        );

        return $context;
    }

    private function deactivate(CoreIntegration $integration, CoreExternalResource $resource): void
    {
        CoreIntegrationDiscoveryContext::query()
            ->where('integration_id', $integration->id)
            ->where('external_resource_id', $resource->id)
            ->where('purpose', CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT)
            ->update(['status' => CoreIntegrationDiscoveryContext::STATUS_INACTIVE]);
    }

    private function resolveBusiness(CoreIntegration $integration, string $externalResourceId): CoreExternalResource
    {
        $this->assertMeta($integration);

        $query = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', MetaResourceType::META_BUSINESS);

        $resource = ctype_digit($externalResourceId)
            ? (clone $query)->whereKey((int) $externalResourceId)->first()
            : null;

        $resource ??= (clone $query)->where('external_id', $externalResourceId)->first();

        if (! $resource instanceof CoreExternalResource) {
            throw new InvalidArgumentException(
                'Meta Business resource not found for this Integration: '.$externalResourceId,
            );
        }

        return $resource;
    }

    /**
     * @return list<int>
     */
    public function activeBusinessResourceIds(CoreIntegration $integration): array
    {
        return CoreIntegrationDiscoveryContext::query()
            ->where('integration_id', $integration->id)
            ->where('purpose', CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT)
            ->where('status', CoreIntegrationDiscoveryContext::STATUS_ACTIVE)
            ->pluck('external_resource_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Integration is not a Meta provider.');
        }
    }
}
