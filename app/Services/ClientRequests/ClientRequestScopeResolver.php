<?php

namespace App\Services\ClientRequests;

use App\Enums\ClientRequestScopeState;
use App\Enums\ServiceScopeStatus;
use App\Models\Brand;
use App\Models\ClientRequest;
use App\Models\CustomerServiceScope;
use App\Models\ServiceDefinition;
use App\Support\ClientRequests\ClientRequestScopeResolution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves current Service Scope awareness for a Client Request.
 *
 * Never mutates Service Scope. Never infers Service from Request text.
 * Missing Service classification ⇒ UNCLASSIFIED (not outside scope).
 */
final class ClientRequestScopeResolver
{
    public function resolve(ClientRequest $request): ClientRequestScopeResolution
    {
        $request->loadMissing(['serviceDefinition', 'brand', 'customer']);

        $evaluatedAt = Carbon::now();
        $serviceId = $request->service_definition_id;

        if ($serviceId === null) {
            return new ClientRequestScopeResolution(
                state: ClientRequestScopeState::Unclassified,
                relevantServiceDefinitionIds: [],
                coveringCustomerServiceScopeIds: [],
                nonCoveredServiceDefinitionIds: [],
                evaluatedAt: $evaluatedAt,
                reasonCodes: ['NO_SERVICE_CLASSIFICATION'],
            );
        }

        $brand = $request->brand;
        if ($brand === null) {
            return new ClientRequestScopeResolution(
                state: ClientRequestScopeState::Unclassified,
                relevantServiceDefinitionIds: [(int) $serviceId],
                coveringCustomerServiceScopeIds: [],
                nonCoveredServiceDefinitionIds: [(int) $serviceId],
                evaluatedAt: $evaluatedAt,
                reasonCodes: ['BRAND_MISSING'],
            );
        }

        $covering = $this->coveringScopesFor(
            customerId: (int) $request->customer_id,
            brand: $brand,
            serviceDefinitionId: (int) $serviceId,
        );

        if ($covering->isNotEmpty()) {
            return new ClientRequestScopeResolution(
                state: ClientRequestScopeState::InScope,
                relevantServiceDefinitionIds: [(int) $serviceId],
                coveringCustomerServiceScopeIds: $covering->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                nonCoveredServiceDefinitionIds: [],
                evaluatedAt: $evaluatedAt,
                reasonCodes: ['ACTIVE_OR_PAUSED_SCOPE_COVERS_SERVICE'],
            );
        }

        return new ClientRequestScopeResolution(
            state: ClientRequestScopeState::OutsideCurrentScope,
            relevantServiceDefinitionIds: [(int) $serviceId],
            coveringCustomerServiceScopeIds: [],
            nonCoveredServiceDefinitionIds: [(int) $serviceId],
            evaluatedAt: $evaluatedAt,
            reasonCodes: ['NO_APPLICABLE_ACTIVE_OR_PAUSED_SCOPE'],
        );
    }

    /**
     * Batch resolve for a Customer Request list (avoids N+1 Service Scope queries).
     *
     * @param  Collection<int, ClientRequest>  $requests
     * @return array<int, ClientRequestScopeResolution> keyed by ClientRequest id
     */
    public function resolveMany(Collection $requests): array
    {
        if ($requests->isEmpty()) {
            return [];
        }

        $customerIds = $requests->pluck('customer_id')->unique()->filter()->values()->all();
        $scopes = CustomerServiceScope::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', [
                ServiceScopeStatus::Active->value,
                ServiceScopeStatus::Paused->value,
            ])
            ->with('brands')
            ->get()
            ->groupBy('customer_id');

        $evaluatedAt = Carbon::now();
        $out = [];

        foreach ($requests as $request) {
            $serviceId = $request->service_definition_id;
            if ($serviceId === null) {
                $out[$request->id] = new ClientRequestScopeResolution(
                    state: ClientRequestScopeState::Unclassified,
                    relevantServiceDefinitionIds: [],
                    coveringCustomerServiceScopeIds: [],
                    nonCoveredServiceDefinitionIds: [],
                    evaluatedAt: $evaluatedAt,
                    reasonCodes: ['NO_SERVICE_CLASSIFICATION'],
                );

                continue;
            }

            $brand = $request->brand;
            $customerScopes = $scopes->get($request->customer_id, collect());
            $coveringIds = [];

            foreach ($customerScopes as $scope) {
                /** @var CustomerServiceScope $scope */
                if ((int) $scope->service_definition_id !== (int) $serviceId) {
                    continue;
                }
                if ($brand !== null && $scope->appliesToBrand($brand)) {
                    $coveringIds[] = (int) $scope->id;
                }
            }

            if ($coveringIds !== []) {
                $out[$request->id] = new ClientRequestScopeResolution(
                    state: ClientRequestScopeState::InScope,
                    relevantServiceDefinitionIds: [(int) $serviceId],
                    coveringCustomerServiceScopeIds: $coveringIds,
                    nonCoveredServiceDefinitionIds: [],
                    evaluatedAt: $evaluatedAt,
                    reasonCodes: ['ACTIVE_OR_PAUSED_SCOPE_COVERS_SERVICE'],
                );
            } else {
                $out[$request->id] = new ClientRequestScopeResolution(
                    state: ClientRequestScopeState::OutsideCurrentScope,
                    relevantServiceDefinitionIds: [(int) $serviceId],
                    coveringCustomerServiceScopeIds: [],
                    nonCoveredServiceDefinitionIds: [(int) $serviceId],
                    evaluatedAt: $evaluatedAt,
                    reasonCodes: ['NO_APPLICABLE_ACTIVE_OR_PAUSED_SCOPE'],
                );
            }
        }

        return $out;
    }

    /**
     * Snapshot shape stored at intake (and when Service is later classified).
     *
     * @return array<string, mixed>
     */
    public function snapshotArray(ClientRequestScopeResolution $resolution, ?ServiceDefinition $service = null): array
    {
        return array_merge($resolution->toArray(), [
            'service_definition_id' => $service?->id,
            'service_definition_code' => $service?->code,
        ]);
    }

    /**
     * @return Collection<int, CustomerServiceScope>
     */
    private function coveringScopesFor(int $customerId, Brand $brand, int $serviceDefinitionId): Collection
    {
        return CustomerServiceScope::query()
            ->where('customer_id', $customerId)
            ->where('service_definition_id', $serviceDefinitionId)
            ->whereIn('status', [
                ServiceScopeStatus::Active->value,
                ServiceScopeStatus::Paused->value,
            ])
            ->with('brands')
            ->get()
            ->filter(fn (CustomerServiceScope $scope): bool => $scope->appliesToBrand($brand))
            ->values();
    }
}
