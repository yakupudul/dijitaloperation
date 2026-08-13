<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\RequirementLevel;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Services\Collection\Support\StartCollectionRequest;
use InvalidArgumentException;

/**
 * Builds a deterministic CollectionPlan from Data Contract Registry + scope.
 * Does not call providers.
 */
final class CollectionPlanner
{
    /**
     * Capability → provider_or_source mapping for bound resources.
     *
     * @var array<string, string>
     */
    private const CAPABILITY_PROVIDER = [
        'ga4' => 'GA4',
        'search_console' => 'GSC',
        'google_ads' => 'GOOGLE_ADS',
        'meta_ads' => 'META_ADS',
        'google_business_profile' => 'GBP',
    ];

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
    ) {}

    /**
     * @return array{
     *   resources: list<array<string, mixed>>,
     *   datasets: list<array<string, mixed>>,
     *   dispositions: list<array<string, mixed>>,
     *   contract_registry_id: string,
     *   contract_registry_version: int,
     *   contract_registry_checksum: string
     * }
     */
    public function plan(StartCollectionRequest $request): array
    {
        $this->registry->load();

        $asset = $request->digitalAsset;
        $bindings = $this->resolveBindings($asset, $request->bindingIds);

        if ($bindings === []) {
            throw new InvalidArgumentException('No active asset bindings in collection scope.');
        }

        $resources = [];
        $datasets = [];
        $dispositions = [];

        $familiesByProvider = $this->indexRequestFamilies();

        foreach ($bindings as $binding) {
            $capability = (string) $binding->capability;
            $provider = self::CAPABILITY_PROVIDER[$capability] ?? strtoupper($capability);

            if ($request->providerSources !== null && ! in_array($provider, $request->providerSources, true)) {
                $dispositions[] = [
                    'type' => 'skipped_provider_filter',
                    'binding_id' => $binding->id,
                    'provider_or_source' => $provider,
                ];

                continue;
            }

            $resourceKey = 'binding:'.$binding->id;
            $resources[$resourceKey] = [
                'key' => $resourceKey,
                'provider_or_source' => $provider,
                'resource_kind' => 'bound_provider_resource',
                'external_resource_id' => $binding->external_resource_id,
                'digital_asset_id' => $asset->id,
                'core_asset_binding_id' => $binding->id,
                'capability' => $capability,
            ];

            $families = $familiesByProvider[$provider] ?? [];
            foreach ($families as $family) {
                $familyId = (string) ($family['id'] ?? '');
                if ($familyId === '') {
                    continue;
                }

                if ($request->requestFamilyIds !== null && ! in_array($familyId, $request->requestFamilyIds, true)) {
                    continue;
                }

                $status = (string) ($family['status'] ?? '');
                if ($status === 'DEFERRED') {
                    $dispositions[] = [
                        'type' => 'deferred_request_family',
                        'request_family_id' => $familyId,
                        'provider_or_source' => $provider,
                    ];

                    continue;
                }

                $level = $this->requirementLevelForFamily($familyId);
                $eligibility = $this->eligibilityForFamily($family);

                if ($eligibility === CollectionRunStatus::NotEligible) {
                    $datasets[] = [
                        'resource_key' => $resourceKey,
                        'provider_or_source' => $provider,
                        'dataset_contract_id' => $this->primaryDatasetForFamily($familyId) ?? $familyId,
                        'request_family_id' => $familyId,
                        'requirement_level' => $level->value,
                        'planned_status' => CollectionRunStatus::NotEligible->value,
                        'depends_on_request_family_ids' => $this->familyDependencies($familyId),
                    ];

                    continue;
                }

                $datasets[] = [
                    'resource_key' => $resourceKey,
                    'provider_or_source' => $provider,
                    'dataset_contract_id' => $this->primaryDatasetForFamily($familyId) ?? $familyId,
                    'request_family_id' => $familyId,
                    'requirement_level' => $level->value,
                    'planned_status' => CollectionRunStatus::Queued->value,
                    'depends_on_request_family_ids' => $this->familyDependencies($familyId),
                ];
            }
        }

        if ($datasets === []) {
            throw new InvalidArgumentException('Collection plan produced zero datasets for the given scope.');
        }

        return [
            'resources' => array_values($resources),
            'datasets' => $datasets,
            'dispositions' => $dispositions,
            'contract_registry_id' => $this->registry->registryId(),
            'contract_registry_version' => $this->registry->version(),
            'contract_registry_checksum' => $this->registry->checksum(),
        ];
    }

    /**
     * @param  list<int>  $bindingIds
     * @return list<CoreAssetBinding>
     */
    private function resolveBindings(DigitalAsset $asset, array $bindingIds): array
    {
        $query = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE);

        if ($bindingIds !== []) {
            $query->whereIn('id', $bindingIds);
        }

        /** @var list<CoreAssetBinding> */
        return $query->get()->all();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function indexRequestFamilies(): array
    {
        $indexed = [];
        foreach ($this->registry->requestFamilies() as $family) {
            $provider = (string) ($family['provider_or_source'] ?? '');
            if ($provider === '') {
                continue;
            }
            $indexed[$provider][] = $family;
        }

        return $indexed;
    }

    private function requirementLevelForFamily(string $familyId): RequirementLevel
    {
        $levels = [];
        foreach ($this->registry->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === $familyId) {
                $levels[] = (string) ($requirement['requirement_level'] ?? 'REQUIRED');
            }
        }

        if (in_array('REQUIRED', $levels, true) || $levels === []) {
            return RequirementLevel::Required;
        }
        if (in_array('CONDITIONAL', $levels, true)) {
            return RequirementLevel::Conditional;
        }

        return RequirementLevel::Optional;
    }

    /**
     * @param  array<string, mixed>  $family
     */
    private function eligibilityForFamily(array $family): ?CollectionRunStatus
    {
        $eligibility = $family['eligibility'] ?? null;
        if (! is_array($eligibility) || $eligibility === []) {
            return null;
        }

        // Structural support only — concrete condition evaluation is provider-specific later.
        return null;
    }

    private function primaryDatasetForFamily(string $familyId): ?string
    {
        foreach ($this->registry->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) === $familyId) {
                $dataset = $requirement['dataset'] ?? null;
                if (is_string($dataset) && $dataset !== '') {
                    return $dataset;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function familyDependencies(string $familyId): array
    {
        $deps = [];
        foreach ($this->registry->requirements() as $requirement) {
            if (($requirement['request_family'] ?? null) !== $familyId) {
                continue;
            }
            foreach ($requirement['dependencies'] ?? [] as $dependency) {
                if (! is_array($dependency)) {
                    continue;
                }
                $reqId = $dependency['requirement_id'] ?? null;
                if (! is_string($reqId) || $reqId === '') {
                    continue;
                }
                foreach ($this->registry->requirements() as $depReq) {
                    if (($depReq['id'] ?? null) === $reqId && is_string($depReq['request_family'] ?? null)) {
                        $deps[] = (string) $depReq['request_family'];
                    }
                }
            }
        }

        return array_values(array_unique($deps));
    }
}
