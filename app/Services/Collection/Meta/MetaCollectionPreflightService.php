<?php

namespace App\Services\Collection\Meta;

use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\PlanDisposition;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Support\MetaBackfillPreflightResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaPermissionCoverageService;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Meta initial-backfill preflight.
 * Reads Integration / bindings / registry / materializations only — no analytical Graph calls.
 */
final class MetaCollectionPreflightService
{
    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly CollectionPlanner $planner,
        private readonly MetaPermissionCoverageService $permissions,
        private readonly MetaCredentialResolver $credentials,
    ) {}

    public function preflight(CoreIntegration $integration): MetaBackfillPreflightResult
    {
        $this->registry->load();

        $authStatus = MetaAuthStatus::for($integration);
        $bindings = $this->activeMetaAdsBindings($integration);
        $globalReadiness = $this->integrationReadiness($integration, $authStatus);

        $bindingRows = [];
        $eligibleBindingIds = [];
        $dispositions = [];
        $actionRequired = [];

        foreach ($bindings as $binding) {
            $resource = $binding->externalResource;
            $row = [
                'binding_id' => $binding->id,
                'capability' => MetaConnectorRegistry::META_ADS,
                'provider_or_source' => 'META_ADS',
                'digital_asset_id' => $binding->digital_asset_id,
                'external_resource_id' => $binding->external_resource_id,
                'resource_display' => $resource?->display_name ?? $resource?->external_id,
                'resource_type' => $resource?->resource_type,
                'business_id' => is_array($resource?->metadata) ? ($resource->metadata['business_id'] ?? null) : null,
                'brand_id' => $binding->digitalAsset?->brand_id,
                'readiness' => $globalReadiness['status'],
                'readiness_label' => $globalReadiness['label'],
                'eligible' => false,
            ];

            if ($resource === null || $resource->resource_type !== MetaResourceType::META_AD_ACCOUNT) {
                $row['readiness'] = PlanDisposition::NotEligible->value;
                $row['readiness_label'] = 'Not a Meta Ad Account collection root';
                $dispositions[] = [
                    'type' => PlanDisposition::NotEligible->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => 'META_ADS',
                    'reason' => 'META_BUSINESS / non-ad-account resources are not analytical roots',
                ];
                $bindingRows[] = $row;

                continue;
            }

            if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
                $row['readiness'] = PlanDisposition::ActionRequired->value;
                $row['readiness_label'] = 'Ad Account access unavailable';
                $actionRequired[] = [
                    'binding_id' => $binding->id,
                    'provider_or_source' => 'META_ADS',
                    'status' => PlanDisposition::ActionRequired->value,
                    'label' => 'Ad Account access unavailable',
                ];
                $dispositions[] = [
                    'type' => PlanDisposition::ActionRequired->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => 'META_ADS',
                    'reason' => 'RESOURCE_UNAVAILABLE',
                ];
                $bindingRows[] = $row;

                continue;
            }

            if (! ($globalReadiness['ready'] ?? false)) {
                $actionRequired[] = [
                    'binding_id' => $binding->id,
                    'provider_or_source' => 'META_ADS',
                    'status' => $globalReadiness['status'],
                    'label' => $globalReadiness['label'],
                ];
                $dispositions[] = [
                    'type' => PlanDisposition::ActionRequired->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => 'META_ADS',
                    'reason' => $globalReadiness['label'],
                ];
                $bindingRows[] = $row;

                continue;
            }

            $row['eligible'] = true;
            $eligibleBindingIds[] = (int) $binding->id;
            $bindingRows[] = $row;
        }

        if ($bindings === []) {
            return $this->result(
                canStart: false,
                outcome: 'no_resources_selected',
                message: 'No human-confirmed Meta Ad Accounts are bound. Select and confirm Ad Accounts before collecting data.',
                summary: $this->emptySummary(),
                bindings: [],
                accounts: [],
                planned: [],
                satisfied: [],
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: null,
                anchorAssetId: null,
                eligibleBindingIds: [],
            );
        }

        if ($eligibleBindingIds === []) {
            $outcome = ($globalReadiness['status'] ?? '') === 'reauth_required'
                ? 'reauth_required'
                : (($globalReadiness['status'] ?? '') === PlanDisposition::ActionRequired->value
                    ? 'permission_required'
                    : 'no_eligible_accounts');

            return $this->result(
                canStart: false,
                outcome: $outcome,
                message: $globalReadiness['label'] !== 'Ready'
                    ? $globalReadiness['label']
                    : 'No production-ready Meta Ad Accounts are eligible for collection. Resolve action-required items first.',
                summary: [
                    'bound_resources' => count($bindings),
                    'eligible_resources' => 0,
                    'planned_datasets' => 0,
                    'already_satisfied_datasets' => 0,
                    'by_account' => $this->accountCounts($bindingRows),
                    'historical_coverage' => 'varies by dataset',
                    'async_insights_note' => 'Large Insights reports may run asynchronously',
                ],
                bindings: $bindingRows,
                accounts: $bindingRows,
                planned: [],
                satisfied: [],
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: null,
                anchorAssetId: null,
                eligibleBindingIds: [],
            );
        }

        $anchor = $this->anchorAsset($bindings, $eligibleBindingIds);
        if (! $anchor instanceof DigitalAsset) {
            return $this->result(
                canStart: false,
                outcome: 'no_eligible_accounts',
                message: 'Eligible bindings could not resolve a Digital Asset scope.',
                summary: $this->emptySummary(),
                bindings: $bindingRows,
                accounts: $bindingRows,
                planned: [],
                satisfied: [],
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: null,
                anchorAssetId: null,
                eligibleBindingIds: $eligibleBindingIds,
            );
        }

        $plan = $this->planner->plan(new StartCollectionRequest(
            digitalAsset: $anchor,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: $eligibleBindingIds,
            providerSources: ['META_ADS'],
            forceRefresh: false,
            context: [
                'meta_integration_id' => $integration->id,
                'collection_intent' => 'meta_initial_backfill',
                'allow_multi_asset_bindings' => true,
            ],
        ));

        $planned = [];
        $satisfied = [];
        foreach ($plan['datasets'] as $dataset) {
            $disposition = (string) ($dataset['plan_disposition'] ?? PlanDisposition::Eligible->value);
            if ($disposition === PlanDisposition::AlreadySatisfied->value
                || ($dataset['planned_status'] ?? null) === 'skipped') {
                $satisfied[] = $dataset;
            } elseif (($dataset['planned_status'] ?? null) === 'queued') {
                $planned[] = $dataset;
            }
            if (! empty($dataset['plan_disposition_detail'])) {
                $dispositions[] = $dataset['plan_disposition_detail'];
            }
        }

        foreach ($plan['dispositions'] as $d) {
            $dispositions[] = $d;
        }

        $fingerprint = $this->fingerprint(
            $integration->id,
            $eligibleBindingIds,
            (int) $plan['contract_registry_version'],
            $planned,
        );

        if ($planned === []) {
            return $this->result(
                canStart: false,
                outcome: 'already_satisfied',
                message: 'Initial Meta collection already completed for the eligible datasets. Use a deliberate refresh/recollect workflow when re-import is required.',
                summary: [
                    'bound_resources' => count($bindings),
                    'eligible_resources' => count($eligibleBindingIds),
                    'planned_datasets' => 0,
                    'already_satisfied_datasets' => count($satisfied),
                    'by_account' => $this->accountCounts($bindingRows),
                    'historical_coverage' => 'varies by dataset',
                    'async_insights_note' => 'Large Insights reports may run asynchronously',
                ],
                bindings: $bindingRows,
                accounts: $bindingRows,
                planned: [],
                satisfied: $satisfied,
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: $fingerprint,
                anchorAssetId: $anchor->id,
                eligibleBindingIds: $eligibleBindingIds,
            );
        }

        Log::debug('meta.backfill.preflight', [
            'integration_id' => $integration->id,
            'eligible_bindings' => count($eligibleBindingIds),
            'planned_datasets' => count($planned),
            // Never log tokens.
        ]);

        return $this->result(
            canStart: true,
            outcome: 'ready',
            message: 'Meta initial collection can start in the background. You may leave this page. Large Insights reports may run asynchronously.',
            summary: [
                'bound_resources' => count($bindings),
                'eligible_resources' => count($eligibleBindingIds),
                'planned_datasets' => count($planned),
                'already_satisfied_datasets' => count($satisfied),
                'by_account' => $this->accountCounts($bindingRows),
                'historical_coverage' => 'varies by dataset',
                'async_insights_note' => 'Large Insights reports may run asynchronously',
                'resources' => $plan['resources'],
            ],
            bindings: $bindingRows,
            accounts: $bindingRows,
            planned: $planned,
            satisfied: $satisfied,
            dispositions: $dispositions,
            actionRequired: $actionRequired,
            fingerprint: $fingerprint,
            anchorAssetId: $anchor->id,
            eligibleBindingIds: $eligibleBindingIds,
        );
    }

    /**
     * @return list<CoreAssetBinding>
     */
    private function activeMetaAdsBindings(CoreIntegration $integration): array
    {
        /** @var list<CoreAssetBinding> */
        return CoreAssetBinding::query()
            ->with(['externalResource', 'digitalAsset.brand'])
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->where('capability', MetaConnectorRegistry::META_ADS)
            ->whereHas('externalResource', function ($q) use ($integration): void {
                $q->where('integration_id', $integration->id)
                    ->where('provider', ProviderRegistry::META)
                    ->where('resource_type', MetaResourceType::META_AD_ACCOUNT);
            })
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return array{status: string, label: string, ready: bool}
     */
    private function integrationReadiness(CoreIntegration $integration, string $authStatus): array
    {
        if (in_array($authStatus, [
            MetaAuthStatus::NOT_CONFIGURED,
            MetaAuthStatus::AUTHORIZATION_REQUIRED,
            MetaAuthStatus::REAUTH_REQUIRED,
        ], true)) {
            return [
                'status' => 'reauth_required',
                'label' => 'Meta authorization required',
                'ready' => false,
            ];
        }

        if ($this->credentials->accessToken($integration) === null) {
            return [
                'status' => 'reauth_required',
                'label' => 'Meta access token is not available',
                'ready' => false,
            ];
        }

        if ($this->permissions->hasValidatedGrantSet($integration)) {
            $missing = $this->permissions->missingForCollection($integration);
            if ($missing !== []) {
                return [
                    'status' => PlanDisposition::ActionRequired->value,
                    'label' => 'Required Meta permission missing (ads_read)',
                    'ready' => false,
                ];
            }
        }

        if ($authStatus === MetaAuthStatus::PERMISSION_REQUIRED) {
            return [
                'status' => PlanDisposition::ActionRequired->value,
                'label' => 'Meta permission required',
                'ready' => false,
            ];
        }

        if ($authStatus === MetaAuthStatus::ISSUE) {
            return [
                'status' => PlanDisposition::ActionRequired->value,
                'label' => 'Meta Integration needs attention',
                'ready' => false,
            ];
        }

        return [
            'status' => 'ready',
            'label' => 'Ready',
            'ready' => true,
        ];
    }

    /**
     * @param  list<CoreAssetBinding>  $bindings
     * @param  list<int>  $eligibleBindingIds
     */
    private function anchorAsset(array $bindings, array $eligibleBindingIds): ?DigitalAsset
    {
        foreach ($bindings as $binding) {
            if (in_array((int) $binding->id, $eligibleBindingIds, true) && $binding->digitalAsset instanceof DigitalAsset) {
                return $binding->digitalAsset;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $bindingRows
     * @return array<string, array{bound: int, eligible: int}>
     */
    private function accountCounts(array $bindingRows): array
    {
        $out = ['META_ADS' => ['bound' => 0, 'eligible' => 0]];
        foreach ($bindingRows as $row) {
            $out['META_ADS']['bound']++;
            if ($row['eligible'] ?? false) {
                $out['META_ADS']['eligible']++;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $bindingIds
     * @param  list<array<string, mixed>>  $planned
     */
    private function fingerprint(int $integrationId, array $bindingIds, int $contractVersion, array $planned): string
    {
        $bindingIds = array_values(array_unique(array_map('intval', $bindingIds)));
        sort($bindingIds);
        $coverage = [];
        foreach ($planned as $dataset) {
            $coverage[] = [
                $dataset['core_asset_binding_id'] ?? $dataset['resource_key'] ?? null,
                $dataset['request_family_id'] ?? null,
                $dataset['dataset_contract_id'] ?? null,
                $dataset['date_range']['start'] ?? null,
                $dataset['date_range']['end'] ?? null,
            ];
        }
        sort($coverage);

        return hash('sha256', json_encode([
            'intent' => 'meta_initial_backfill',
            'integration_id' => $integrationId,
            'binding_ids' => $bindingIds,
            'contract_version' => $contractVersion,
            'coverage' => $coverage,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'bound_resources' => 0,
            'eligible_resources' => 0,
            'planned_datasets' => 0,
            'already_satisfied_datasets' => 0,
            'by_account' => [],
            'historical_coverage' => 'varies by dataset',
            'async_insights_note' => 'Large Insights reports may run asynchronously',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $bindings
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<array<string, mixed>>  $planned
     * @param  list<array<string, mixed>>  $satisfied
     * @param  list<array<string, mixed>>  $dispositions
     * @param  list<array<string, mixed>>  $actionRequired
     * @param  list<int>  $eligibleBindingIds
     */
    private function result(
        bool $canStart,
        string $outcome,
        string $message,
        array $summary,
        array $bindings,
        array $accounts,
        array $planned,
        array $satisfied,
        array $dispositions,
        array $actionRequired,
        ?string $fingerprint,
        ?int $anchorAssetId,
        array $eligibleBindingIds,
    ): MetaBackfillPreflightResult {
        return new MetaBackfillPreflightResult(
            canStart: $canStart,
            outcome: $outcome,
            message: $message,
            summary: $summary,
            bindings: $bindings,
            accounts: $accounts,
            plannedDatasets: $planned,
            alreadySatisfied: $satisfied,
            dispositions: $dispositions,
            actionRequired: $actionRequired,
            fingerprint: $fingerprint,
            contractRegistryVersion: $this->registry->version(),
            contractRegistryId: $this->registry->registryId(),
            anchorDigitalAssetId: $anchorAssetId,
            eligibleBindingIds: $eligibleBindingIds,
        );
    }
}
