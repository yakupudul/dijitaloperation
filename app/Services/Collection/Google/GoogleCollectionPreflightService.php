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
            return $this->result(
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
            );
        }

        if ($eligibleBindingIds === []) {
            $outcome = $this->onlyGbpBound($bindings) ? 'gbp_collector_unavailable' : 'no_eligible_connectors';

            return $this->result(
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
            );
        }

        $anchor = $this->anchorAsset($bindings, $eligibleBindingIds);
        if ($anchor instanceof DigitalAsset) {
            [$eligibleBindingIds, $bindingRows, $dispositions] = $this->scopeEligibleBindingsToAnchor(
                $anchor,
                $bindings,
                $eligibleBindingIds,
                $bindingRows,
                $dispositions,
            );
        }
        if (! $anchor instanceof DigitalAsset) {
            return $this->result(
                canStart: false,
                outcome: 'no_eligible_connectors',
                message: 'Eligible bindings could not resolve a Digital Asset scope.',
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
            );
        }

        if ($eligibleBindingIds === []) {
            return $this->result(
                canStart: false,
                outcome: 'no_eligible_connectors',
                message: 'Eligible Google bindings are outside the website-anchored same-brand, same-customer collection scope.',
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
                anchorAssetId: $anchor->id,
                eligibleBindingIds: [],
            );
        }

        $plan = $this->planner->plan(new StartCollectionRequest(
            digitalAsset: $anchor,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: $eligibleBindingIds,
            providerSources: ['SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS'],
            forceRefresh: false,
            context: [
                'google_integration_id' => $integration->id,
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
            $eligibleBindingIds,
            (int) $plan['contract_registry_version'],
            $planned,
        );

        if ($planned === []) {
            return $this->result(
                canStart: false,
                outcome: 'already_satisfied',
                message: 'Initial data collection already completed for the eligible Google datasets. Use a deliberate refresh/recollect workflow when re-import is required.',
                summary: [
                    'bound_resources' => count($bindings),
                    'eligible_resources' => count($eligibleBindingIds),
                    'planned_datasets' => 0,
                    'already_satisfied_datasets' => count($satisfied),
                    'by_connector' => $this->connectorCounts($bindingRows),
                    'historical_coverage' => 'varies by dataset',
                ],
                bindings: $bindingRows,
                connectors: array_values($connectors),
                planned: [],
                satisfied: $satisfied,
                dispositions: $dispositions,
                actionRequired: $actionRequired,
                fingerprint: $fingerprint,
                anchorAssetId: $anchor->id,
                eligibleBindingIds: $eligibleBindingIds,
            );
        }

        Log::debug('google.backfill.preflight', [
            'integration_id' => $integration->id,
            'eligible_bindings' => count($eligibleBindingIds),
            'planned_datasets' => count($planned),
            // Never log tokens.
        ]);

        return $this->result(
            canStart: true,
            outcome: 'ready',
            message: 'Google initial collection can start in the background. You may leave this page.',
            summary: [
                'bound_resources' => count($bindings),
                'eligible_resources' => count($eligibleBindingIds),
                'planned_datasets' => count($planned),
                'already_satisfied_datasets' => count($satisfied),
                'by_connector' => $this->connectorCounts($bindingRows),
                'historical_coverage' => 'varies by dataset',
                'resources' => $plan['resources'],
            ],
            bindings: $bindingRows,
            connectors: array_values($connectors),
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
     * Website-anchored Google backfill may only keep same-brand / same-customer
     * bindings. Other-brand or other-customer Google bindings stay bound but
     * are not eligible for this CollectionRun.
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
                'reason' => 'Google website-anchored backfill may only include same-brand, same-customer bindings.',
            ];
        }

        return [$scoped, $bindingRows, $dispositions];
    }

    /**
     * @param  list<CoreAssetBinding>  $bindings
     * @param  list<int>  $eligibleBindingIds
     */
    private function anchorAsset(array $bindings, array $eligibleBindingIds): ?DigitalAsset
    {
        foreach ($bindings as $binding) {
            if (in_array($binding->id, $eligibleBindingIds, true) && $binding->digitalAsset instanceof DigitalAsset) {
                return $binding->digitalAsset;
            }
        }

        return null;
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
    private function fingerprint(int $integrationId, array $bindingIds, int $contractVersion, array $planned): string
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
        );
    }
}
