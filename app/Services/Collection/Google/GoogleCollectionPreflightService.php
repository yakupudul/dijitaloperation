<?php

namespace App\Services\Collection\Google;

use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\PlanDisposition;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\CollectionBindingScope;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Support\GoogleBackfillPreflightResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Integrations\Google\GoogleCredentialBroker;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleScopeCoverageService;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Google initial-backfill preflight.
 * Reads Integration / bindings / registry / materializations only — no analytical provider HTTP.
 */
final class GoogleCollectionPreflightService
{
    /** @var array<string, string> */
    private const CAPABILITY_PROVIDER = [
        'search_console' => 'SEARCH_CONSOLE',
        'ga4' => 'GA4',
        'google_ads' => 'GOOGLE_ADS',
        'google_business_profile' => 'GBP',
    ];

    /** Capabilities with production analytical collectors (Prompts 17–19). */
    private const PRODUCTION_COLLECTABLE = [
        'search_console',
        'ga4',
        'google_ads',
    ];

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly CollectionPlanner $planner,
        private readonly GoogleScopeCoverageService $coverage,
        private readonly GoogleCredentialResolver $credentials,
        private readonly GoogleCredentialBroker $broker,
    ) {}

    public function preflight(CoreIntegration $integration): GoogleBackfillPreflightResult
    {
        return $this->aggregateBrandPreflights($this->preflightByBrand($integration));
    }

    /**
     * One Brand-scoped preflight per eligible Brand under the integration.
     * Same-brand siblings share a plan; other Brands are not discarded.
     *
     * @return list<GoogleBackfillPreflightResult>
     */
    public function preflightByBrand(CoreIntegration $integration): array
    {
        $this->registry->load();

        $authStatus = GoogleAuthStatus::for($integration);
        $bindings = $this->activeGoogleBindings($integration);
        $connectors = $this->connectorReadiness($integration, $authStatus);
        $bindingRows = [];
        $eligibleBindingIds = [];
        $dispositions = [];
        $actionRequired = [];

        foreach ($bindings as $binding) {
            $capability = (string) $binding->capability;
            $provider = self::CAPABILITY_PROVIDER[$capability] ?? strtoupper($capability);
            $readiness = $connectors[$capability] ?? [
                'capability' => $capability,
                'provider_or_source' => $provider,
                'status' => PlanDisposition::ActionRequired->value,
                'label' => 'Unknown connector',
                'ready' => false,
            ];

            $row = [
                'binding_id' => $binding->id,
                'capability' => $capability,
                'provider_or_source' => $provider,
                'digital_asset_id' => $binding->digital_asset_id,
                'external_resource_id' => $binding->external_resource_id,
                'brand_id' => $binding->digitalAsset?->brand_id,
                'resource_display' => $binding->externalResource?->display_name
                    ?? $binding->externalResource?->external_id,
                'readiness' => $readiness['status'],
                'readiness_label' => $readiness['label'],
                'eligible' => false,
            ];

            if ($capability === 'google_business_profile') {
                $row['readiness'] = PlanDisposition::CollectorUnavailable->value;
                $row['readiness_label'] = 'Data collection capability not yet available';
                $dispositions[] = [
                    'type' => PlanDisposition::CollectorUnavailable->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => 'GBP',
                    'reason' => 'No production GBP analytical collector',
                ];
                $bindingRows[] = $row;

                continue;
            }

            if (! in_array($capability, self::PRODUCTION_COLLECTABLE, true)) {
                $row['readiness'] = PlanDisposition::Unsupported->value;
                $row['readiness_label'] = 'Unsupported for Google initial backfill';
                $bindingRows[] = $row;

                continue;
            }

            if (! ($readiness['ready'] ?? false)) {
                $actionRequired[] = [
                    'capability' => $capability,
                    'provider_or_source' => $provider,
                    'status' => $readiness['status'],
                    'label' => $readiness['label'],
                    'binding_id' => $binding->id,
                ];
                $dispositions[] = [
                    'type' => PlanDisposition::ActionRequired->value,
                    'binding_id' => $binding->id,
                    'provider_or_source' => $provider,
                    'reason' => $readiness['label'],
                ];
                $bindingRows[] = $row;

                continue;
            }

            $row['eligible'] = true;
            $eligibleBindingIds[] = $binding->id;
            $bindingRows[] = $row;
        }

        if ($bindings === []) {
            return [$this->result(
                canStart: false,
                outcome: 'no_resources_selected',
                message: 'No human-confirmed Google resources are bound. Select and confirm resources before collecting data.',
                summary: $this->emptySummary(),
                bindings: [],
                connectors: array_values($connectors),
                planned: [],
                satisfied: [],
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: null,
                anchorAssetId: null,
                eligibleBindingIds: [],
            )];
        }

        if ($eligibleBindingIds === []) {
            $outcome = $this->onlyGbpBound($bindings) ? 'gbp_collector_unavailable' : 'no_eligible_connectors';

            return [$this->result(
                canStart: false,
                outcome: $outcome,
                message: $outcome === 'gbp_collector_unavailable'
                    ? 'Google Business Profile is bound, but analytical data collection is not yet available. Bind Search Console, GA4, or Google Ads to collect data.'
                    : 'No production-ready Google connectors are eligible for collection. Resolve action-required items first.',
                summary: [
                    'bound_resources' => count($bindings),
                    'eligible_resources' => 0,
                    'planned_datasets' => 0,
                    'already_satisfied_datasets' => 0,
                    'by_connector' => $this->connectorCounts($bindingRows),
                    'historical_coverage' => 'varies by dataset',
                ],
                bindings: $bindingRows,
                connectors: array_values($connectors),
                planned: [],
                satisfied: [],
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: null,
                anchorAssetId: null,
                eligibleBindingIds: [],
            )];
        }

        $brandGroups = $this->groupEligibleBindingIdsByBrand($bindings, $eligibleBindingIds);
        if ($brandGroups === []) {
            return [$this->result(
                canStart: false,
                outcome: 'no_eligible_connectors',
                message: 'Eligible bindings could not resolve a Brand / Digital Asset scope.',
                summary: $this->emptySummary(),
                bindings: $bindingRows,
                connectors: array_values($connectors),
                planned: [],
                satisfied: [],
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: null,
                anchorAssetId: null,
                eligibleBindingIds: $eligibleBindingIds,
            )];
        }

        $brandResults = [];
        foreach ($brandGroups as $brandId => $brandBindingIds) {
            $brandResults[] = $this->preflightOneBrand(
                $integration,
                $bindings,
                $bindingRows,
                array_values($connectors),
                $dispositions,
                $actionRequired,
                $brandId,
                $brandBindingIds,
            );
        }

        return $brandResults;
    }

    /**
     * @return list<CoreAssetBinding>
     */
    private function activeGoogleBindings(CoreIntegration $integration): array
    {
        /** @var list<CoreAssetBinding> */
        return CoreAssetBinding::query()
            ->with(['externalResource', 'digitalAsset.brand'])
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('capability', array_keys(self::CAPABILITY_PROVIDER))
            ->whereHas('externalResource', function ($q) use ($integration): void {
                $q->where('integration_id', $integration->id)
                    ->where('provider', ProviderRegistry::GOOGLE)
                    ->where('status', CoreExternalResource::STATUS_AVAILABLE);
            })
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function connectorReadiness(CoreIntegration $integration, string $authStatus): array
    {
        $authOk = $authStatus === GoogleAuthStatus::CONNECTED;
        $appOk = $this->credentials->isAppConfigured($integration);

        $gscReady = $authOk && $appOk && $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE);
        $ga4Ready = $authOk && $appOk && $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_GA4);
        $ads = $this->broker->adsApplicationReadiness($integration);
        $adsReady = $authOk && $ads['configured'] && $ads['developer_token_configured'] && $ads['oauth_scope_ready'];

        return [
            'search_console' => [
                'capability' => 'search_console',
                'provider_or_source' => 'SEARCH_CONSOLE',
                'label' => $gscReady ? 'Ready' : (! $authOk ? 'Google authorization required' : 'Search Console scope required'),
                'status' => $gscReady ? 'ready' : PlanDisposition::ActionRequired->value,
                'ready' => $gscReady,
                'production_collector' => true,
            ],
            'ga4' => [
                'capability' => 'ga4',
                'provider_or_source' => 'GA4',
                'label' => $ga4Ready ? 'Ready' : (! $authOk ? 'Google authorization required' : 'GA4 scope required'),
                'status' => $ga4Ready ? 'ready' : PlanDisposition::ActionRequired->value,
                'ready' => $ga4Ready,
                'production_collector' => true,
            ],
            'google_ads' => [
                'capability' => 'google_ads',
                'provider_or_source' => 'GOOGLE_ADS',
                'label' => match (true) {
                    $adsReady => 'Ready',
                    ! $ads['configured'] => 'Google application configuration required',
                    ! $ads['developer_token_configured'] => 'Developer token configuration required',
                    ! $ads['oauth_scope_ready'] => 'Google Ads scope required',
                    default => 'Google authorization required',
                },
                'status' => $adsReady ? 'ready' : PlanDisposition::ActionRequired->value,
                'ready' => $adsReady,
                'production_collector' => true,
            ],
            'google_business_profile' => [
                'capability' => 'google_business_profile',
                'provider_or_source' => 'GBP',
                'label' => 'Data collection capability not yet available',
                'status' => PlanDisposition::CollectorUnavailable->value,
                'ready' => false,
                'production_collector' => false,
            ],
        ];
    }

    /**
     * Brand-scoped Google backfill keeps same-brand / same-customer siblings
     * in that Brand's CollectionRun. Other Brands get their own preflight/run
     * instead of being silently discarded.
     *
     * @param  list<CoreAssetBinding>  $bindings
     * @param  list<int>  $eligibleBindingIds
     * @param  list<array<string, mixed>>  $bindingRows
     * @param  list<array<string, mixed>>  $dispositions
     * @return array{0: list<int>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function scopeEligibleBindingsToAnchor(
        DigitalAsset $anchor,
        array $bindings,
        array $eligibleBindingIds,
        array $bindingRows,
        array $dispositions,
    ): array {
        $anchor->loadMissing('brand');
        $anchorBrandId = $anchor->brand_id !== null ? (int) $anchor->brand_id : null;
        $anchorCustomerId = $anchor->brand?->customer_id !== null ? (int) $anchor->brand->customer_id : null;

        $bindingsById = [];
        foreach ($bindings as $binding) {
            $bindingsById[(int) $binding->id] = $binding;
        }

        $scoped = [];
        foreach ($eligibleBindingIds as $bindingId) {
            $binding = $bindingsById[(int) $bindingId] ?? null;
            $candidate = $binding?->digitalAsset;
            if (! $candidate instanceof DigitalAsset) {
                continue;
            }

            if (CollectionBindingScope::anchorMayTargetAsset(
                (int) $anchor->id,
                $anchorBrandId,
                $anchorCustomerId,
                $candidate,
                true,
                true,
            )) {
                $scoped[] = (int) $bindingId;

                continue;
            }

            foreach ($bindingRows as $index => $row) {
                if ((int) ($row['binding_id'] ?? 0) !== (int) $bindingId) {
                    continue;
                }
                $bindingRows[$index]['eligible'] = false;
                $bindingRows[$index]['readiness'] = PlanDisposition::NotEligible->value;
                $bindingRows[$index]['readiness_label'] = 'Outside same-brand, same-customer Google collection scope';
            }

            $dispositions[] = [
                'type' => PlanDisposition::NotEligible->value,
                'binding_id' => (int) $bindingId,
                'provider_or_source' => self::CAPABILITY_PROVIDER[(string) ($binding->capability ?? '')] ?? null,
                'reason' => 'Google brand-scoped backfill may only include same-brand, same-customer bindings in this CollectionRun.',
            ];
        }

        return [$scoped, $bindingRows, $dispositions];
    }

    /**
     * @param  list<CoreAssetBinding>  $bindings
     * @param  list<int>  $eligibleBindingIds
     * @return array<int, list<int>>
     */
    private function groupEligibleBindingIdsByBrand(array $bindings, array $eligibleBindingIds): array
    {
        $bindingsById = [];
        foreach ($bindings as $binding) {
            $bindingsById[(int) $binding->id] = $binding;
        }

        $groups = [];
        foreach ($eligibleBindingIds as $bindingId) {
            $binding = $bindingsById[(int) $bindingId] ?? null;
            $brandId = $binding?->digitalAsset?->brand_id;
            if ($brandId === null) {
                continue;
            }
            $groups[(int) $brandId][] = (int) $bindingId;
        }
        ksort($groups);

        return $groups;
    }

    /**
     * Prefer a website Digital Asset as the Brand collection anchor so GSC/GA4/Ads
     * siblings share one Brand-scoped CollectionRun.
     *
     * @param  list<CoreAssetBinding>  $bindings
     * @param  list<int>  $eligibleBindingIds
     */
    private function brandAnchorAsset(array $bindings, array $eligibleBindingIds): ?DigitalAsset
    {
        $candidates = [];
        foreach ($bindings as $binding) {
            if (! in_array($binding->id, $eligibleBindingIds, true)) {
                continue;
            }
            if ($binding->digitalAsset instanceof DigitalAsset) {
                $candidates[] = $binding->digitalAsset;
            }
        }

        foreach ($candidates as $asset) {
            if ((string) $asset->type === 'website') {
                return $asset;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param  list<CoreAssetBinding>  $bindings
     * @param  list<array<string, mixed>>  $bindingRows
     * @param  list<array<string, mixed>>  $connectors
     * @param  list<array<string, mixed>>  $sharedDispositions
     * @param  list<array<string, mixed>>  $actionRequired
     * @param  list<int>  $brandBindingIds
     */
    private function preflightOneBrand(
        CoreIntegration $integration,
        array $bindings,
        array $bindingRows,
        array $connectors,
        array $sharedDispositions,
        array $actionRequired,
        int $brandId,
        array $brandBindingIds,
    ): GoogleBackfillPreflightResult {
        $dispositions = $sharedDispositions;
        $anchor = $this->brandAnchorAsset($bindings, $brandBindingIds);
        if ($anchor instanceof DigitalAsset) {
            [$brandBindingIds, $bindingRows, $dispositions] = $this->scopeEligibleBindingsToAnchor(
                $anchor,
                $bindings,
                $brandBindingIds,
                $bindingRows,
                $dispositions,
            );
        }

        if (! $anchor instanceof DigitalAsset || $brandBindingIds === []) {
            return $this->result(
                canStart: false,
                outcome: 'no_eligible_connectors',
                message: 'Eligible Google bindings for this Brand could not resolve a same-brand, same-customer collection scope.',
                summary: [
                    'bound_resources' => count($bindings),
                    'eligible_resources' => 0,
                    'planned_datasets' => 0,
                    'already_satisfied_datasets' => 0,
                    'by_connector' => $this->connectorCounts($bindingRows),
                    'historical_coverage' => 'varies by dataset',
                    'brand_id' => $brandId,
                ],
                bindings: $bindingRows,
                connectors: $connectors,
                planned: [],
                satisfied: [],
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: null,
                anchorAssetId: $anchor?->id,
                eligibleBindingIds: [],
                brandId: $brandId,
            );
        }

        $plan = $this->planner->plan(new StartCollectionRequest(
            digitalAsset: $anchor,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: $brandBindingIds,
            providerSources: ['SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS'],
            forceRefresh: false,
            context: [
                'google_integration_id' => $integration->id,
                'google_brand_id' => $brandId,
                'collection_intent' => 'google_initial_backfill',
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
            $brandBindingIds,
            (int) $plan['contract_registry_version'],
            $planned,
            $brandId,
        );

        if ($planned === []) {
            return $this->result(
                canStart: false,
                outcome: 'already_satisfied',
                message: 'Initial data collection already completed for this Brand\'s eligible Google datasets. Use a deliberate refresh/recollect workflow when re-import is required.',
                summary: [
                    'bound_resources' => count($bindings),
                    'eligible_resources' => count($brandBindingIds),
                    'planned_datasets' => 0,
                    'already_satisfied_datasets' => count($satisfied),
                    'by_connector' => $this->connectorCounts($bindingRows),
                    'historical_coverage' => 'varies by dataset',
                    'brand_id' => $brandId,
                ],
                bindings: $bindingRows,
                connectors: $connectors,
                planned: [],
                satisfied: $satisfied,
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: $fingerprint,
                anchorAssetId: $anchor->id,
                eligibleBindingIds: $brandBindingIds,
                brandId: $brandId,
            );
        }

        Log::debug('google.backfill.preflight', [
            'integration_id' => $integration->id,
            'brand_id' => $brandId,
            'eligible_bindings' => count($brandBindingIds),
            'planned_datasets' => count($planned),
        ]);

        return $this->result(
            canStart: true,
            outcome: 'ready',
            message: 'Google initial collection can start in the background. You may leave this page.',
            summary: [
                'bound_resources' => count($bindings),
                'eligible_resources' => count($brandBindingIds),
                'planned_datasets' => count($planned),
                'already_satisfied_datasets' => count($satisfied),
                'by_connector' => $this->connectorCounts($bindingRows),
                'historical_coverage' => 'varies by dataset',
                'resources' => $plan['resources'],
                'brand_id' => $brandId,
            ],
            bindings: $bindingRows,
            connectors: $connectors,
            planned: $planned,
            satisfied: $satisfied,
            dispositions: $dispositions,
            actionRequired: $actionRequired,
            fingerprint: $fingerprint,
            anchorAssetId: $anchor->id,
            eligibleBindingIds: $brandBindingIds,
            brandId: $brandId,
        );
    }

    /**
     * @param  list<GoogleBackfillPreflightResult>  $brandResults
     */
    private function aggregateBrandPreflights(array $brandResults): GoogleBackfillPreflightResult
    {
        if ($brandResults === []) {
            return $this->result(
                canStart: false,
                outcome: 'no_eligible_connectors',
                message: 'Eligible bindings could not resolve a Brand / Digital Asset scope.',
                summary: $this->emptySummary(),
                bindings: [],
                connectors: [],
                planned: [],
                satisfied: [],
                dispositions: [],
                actionRequired: [],
                fingerprint: null,
                anchorAssetId: null,
                eligibleBindingIds: [],
            );
        }

        if (count($brandResults) === 1) {
            return $brandResults[0];
        }

        $canStart = false;
        $allSatisfied = true;
        $anySatisfied = false;
        $planned = [];
        $satisfied = [];
        $eligibleBindingIds = [];
        $dispositions = [];
        $actionRequired = [];
        $bindingsById = [];
        $brandPlans = [];
        $anchorAssetId = null;
        $firstReady = null;
        $connectors = $brandResults[0]->connectors;
        $boundResources = (int) ($brandResults[0]->summary['bound_resources'] ?? 0);
        $seenDispositions = [];
        $seenActionRequired = [];

        foreach ($brandResults as $result) {
            if ($result->canStart) {
                $canStart = true;
                $allSatisfied = false;
                $firstReady ??= $result;
                $anchorAssetId ??= $result->anchorDigitalAssetId;
            } elseif ($result->outcome === 'already_satisfied') {
                $anySatisfied = true;
            } else {
                $allSatisfied = false;
            }

            foreach ($result->plannedDatasets as $dataset) {
                $planned[] = $dataset;
            }
            foreach ($result->alreadySatisfied as $dataset) {
                $satisfied[] = $dataset;
            }
            foreach ($result->eligibleBindingIds as $bindingId) {
                $eligibleBindingIds[] = (int) $bindingId;
            }
            foreach ($result->dispositions as $disposition) {
                $key = json_encode($disposition);
                if (is_string($key) && ! isset($seenDispositions[$key])) {
                    $seenDispositions[$key] = true;
                    $dispositions[] = $disposition;
                }
            }
            foreach ($result->actionRequired as $item) {
                $key = json_encode($item);
                if (is_string($key) && ! isset($seenActionRequired[$key])) {
                    $seenActionRequired[$key] = true;
                    $actionRequired[] = $item;
                }
            }
            foreach ($result->bindings as $row) {
                $id = (int) ($row['binding_id'] ?? 0);
                if ($id === 0) {
                    continue;
                }
                if (! isset($bindingsById[$id]) || ($row['eligible'] ?? false) === true) {
                    $bindingsById[$id] = $row;
                }
            }

            $brandPlans[] = [
                'brand_id' => $result->brandId,
                'can_start' => $result->canStart,
                'outcome' => $result->outcome,
                'anchor_digital_asset_id' => $result->anchorDigitalAssetId,
                'eligible_binding_ids' => $result->eligibleBindingIds,
                'planned_datasets' => count($result->plannedDatasets),
                'already_satisfied_datasets' => count($result->alreadySatisfied),
            ];
        }

        $eligibleBindingIds = array_values(array_unique($eligibleBindingIds));
        $outcome = 'no_eligible_connectors';
        $message = $brandResults[0]->message;
        if ($canStart) {
            $outcome = 'ready';
            $message = count($brandPlans) > 1
                ? 'Google initial collection can start for each eligible Brand in the background. You may leave this page.'
                : 'Google initial collection can start in the background. You may leave this page.';
        } elseif ($allSatisfied && $anySatisfied) {
            $outcome = 'already_satisfied';
            $message = 'Initial data collection already completed for every eligible Brand. Use a deliberate refresh/recollect workflow when re-import is required.';
        }

        $bindingRows = array_values($bindingsById);

        return $this->result(
            canStart: $canStart,
            outcome: $outcome,
            message: $message,
            summary: [
                'bound_resources' => $boundResources,
                'eligible_resources' => count($eligibleBindingIds),
                'planned_datasets' => count($planned),
                'already_satisfied_datasets' => count($satisfied),
                'by_connector' => $this->connectorCounts($bindingRows),
                'historical_coverage' => 'varies by dataset',
                'brand_count' => count($brandPlans),
                'brand_plans' => $brandPlans,
            ],
            bindings: $bindingRows,
            connectors: $connectors,
            planned: $planned,
            satisfied: $satisfied,
            dispositions: $dispositions,
            actionRequired: $actionRequired,
            fingerprint: $firstReady?->fingerprint,
            anchorAssetId: $anchorAssetId,
            eligibleBindingIds: $eligibleBindingIds,
            brandId: $firstReady?->brandId,
        );
    }

    /**
     * @param  list<CoreAssetBinding>  $bindings
     */
    private function onlyGbpBound(array $bindings): bool
    {
        if ($bindings === []) {
            return false;
        }

        foreach ($bindings as $binding) {
            if ((string) $binding->capability !== 'google_business_profile') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $bindingRows
     * @return array<string, array{bound: int, eligible: int}>
     */
    private function connectorCounts(array $bindingRows): array
    {
        $out = [];
        foreach ($bindingRows as $row) {
            $key = (string) $row['provider_or_source'];
            $out[$key] ??= ['bound' => 0, 'eligible' => 0];
            $out[$key]['bound']++;
            if ($row['eligible'] ?? false) {
                $out[$key]['eligible']++;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $bindingIds
     * @param  list<array<string, mixed>>  $planned
     */
    private function fingerprint(int $integrationId, array $bindingIds, int $contractVersion, array $planned, ?int $brandId = null): string
    {
        $bindingIds = array_values(array_unique($bindingIds));
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
            'intent' => 'google_initial_backfill',
            'integration_id' => $integrationId,
            'brand_id' => $brandId,
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
            'by_connector' => [],
            'historical_coverage' => 'varies by dataset',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $bindings
     * @param  list<array<string, mixed>>  $connectors
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
        array $connectors,
        array $planned,
        array $satisfied,
        array $dispositions,
        array $actionRequired,
        ?string $fingerprint,
        ?int $anchorAssetId,
        array $eligibleBindingIds,
        ?int $brandId = null,
    ): GoogleBackfillPreflightResult {
        return new GoogleBackfillPreflightResult(
            canStart: $canStart,
            outcome: $outcome,
            message: $message,
            summary: $summary,
            bindings: $bindings,
            connectors: $connectors,
            plannedDatasets: $planned,
            alreadySatisfied: $satisfied,
            dispositions: $dispositions,
            actionRequired: $actionRequired,
            fingerprint: $fingerprint,
            contractRegistryVersion: $this->registry->version(),
            contractRegistryId: $this->registry->registryId(),
            anchorDigitalAssetId: $anchorAssetId,
            eligibleBindingIds: $eligibleBindingIds,
            brandId: $brandId,
        );
    }
}
