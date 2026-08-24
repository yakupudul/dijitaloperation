<?php

namespace App\Services\Collection\GoogleAds;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\Collection\ProgressMode;
use App\Events\Collection\CollectionRunStarted;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\User;
use App\Services\Collection\CollectionQueueGate;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Targeted recovery collection for the Google Ads Search workspace.
 *
 * The normal smart-update policy intentionally restates a short recent window.
 * This service is used when the operator explicitly refreshes the Search tab and
 * asks for the currently selected reporting range, so missing keyword/search-term
 * history can be backfilled without re-collecting every professional dataset.
 */
final class GoogleAdsSearchRecoveryCollectionService
{
    private const string NETWORK_DAILY_FAMILY = 'GADS_CENTRAL_RF_NETWORK_DAILY';

    private const array ACTIVE_STATUSES = [
        'queued', 'running', 'retrying', 'cancellation_requested',
    ];

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
        private readonly CollectionQueueGate $queueGate,
        private readonly StartCollectionService $starter,
    ) {}

    public function start(
        CoreExternalResource $resource,
        string $requestedStart,
        string $requestedEnd,
        ?User $requestedBy = null,
    ): CollectionRun {
        $this->queueGate->assertReady();
        $resource->loadMissing('integration');
        $integration = $resource->integration;

        if ($integration === null
            || $integration->provider !== ProviderRegistry::GOOGLE
            || ! $integration->isActive()
            || $resource->provider !== ProviderRegistry::GOOGLE
            || $resource->resource_type !== GoogleResourceType::GOOGLE_ADS_CUSTOMER
            || $resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            throw new InvalidArgumentException('Google Ads arama verisi onarımı için kullanılabilir bir Google Ads müşteri hesabı gerekir.');
        }

        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        if ((bool) ($meta['is_manager'] ?? false) || (array_key_exists('selectable', $meta) && ! (bool) $meta['selectable'])) {
            throw new InvalidArgumentException('MCC/yönetici hesabı arama performansı toplama kökü olarak kullanılamaz.');
        }

        $active = CollectionResourceRun::query()
            ->where('provider_or_source', 'GOOGLE_ADS')
            ->where('external_resource_id', $resource->id)
            ->whereNull('digital_asset_id')
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();

        if ($active) {
            throw new InvalidArgumentException(($resource->display_name ?: 'Google Ads hesabı').' için veri toplama zaten devam ediyor.');
        }

        [$start, $end] = $this->effectiveRange($resource, $requestedStart, $requestedEnd);
        $registry = $this->registry->load();
        $registryId = $this->registry->registryId();
        $registryVersion = $this->registry->version();
        $registryChecksum = $this->registry->checksum();

        $entries = [
            [
                'family' => GoogleAdsCentralRequestFamilyCatalog::ENTITY_SNAPSHOT,
                'date_range' => null,
                'execution_variant' => 'search_recovery_snapshot',
            ],
            [
                'family' => GoogleAdsCentralRequestFamilyCatalog::KEYWORD,
                'date_range' => ['start' => $start, 'end' => $end],
                'execution_variant' => 'search_recovery',
            ],
            [
                'family' => GoogleAdsCentralRequestFamilyCatalog::SEARCH_TERM,
                'date_range' => ['start' => $start, 'end' => $end],
                'execution_variant' => 'search_recovery',
            ],
            [
                'family' => self::NETWORK_DAILY_FAMILY,
                'date_range' => ['start' => $start, 'end' => $end],
                'execution_variant' => 'search_recovery_coverage',
            ],
        ];

        $planFingerprint = hash('sha256', json_encode([
            'integration' => $integration->id,
            'resource' => $resource->id,
            'range' => [$start, $end],
            'families' => array_column($entries, 'family'),
        ], JSON_THROW_ON_ERROR));

        $run = DB::transaction(function () use (
            $resource,
            $integration,
            $requestedBy,
            $meta,
            $entries,
            $registry,
            $registryId,
            $registryVersion,
            $registryChecksum,
            $planFingerprint,
            $start,
            $end,
        ): CollectionRun {
            $run = CollectionRun::query()->create([
                'requested_by_user_id' => $requestedBy?->id,
                'customer_id' => null,
                'brand_id' => null,
                'digital_asset_id' => null,
                'trigger_type' => CollectionTriggerType::Incremental,
                'status' => CollectionRunStatus::Queued,
                'contract_registry_id' => $registryId,
                'contract_registry_version' => $registryVersion,
                'contract_registry_checksum' => $registryChecksum,
                'formula_registry_version' => 1,
                'idempotency_key' => hash('sha256', $planFingerprint.'|'.microtime(true).'|'.random_int(1, PHP_INT_MAX)),
                'started_at' => now(),
                'last_activity_at' => now(),
                'resources_total' => 1,
                'resources_completed' => 0,
                'datasets_total' => count($entries),
                'datasets_completed' => 0,
                'datasets_failed' => 0,
                'request_context' => [
                    'provider_sources' => ['GOOGLE_ADS'],
                    'context' => [
                        'collection_scope' => 'provider_resource_first',
                        'google_integration_id' => (int) $integration->id,
                        'asset_binding_required' => false,
                        'search_recovery' => true,
                    ],
                ],
                'plan_snapshot' => [
                    'provider_or_source' => 'GOOGLE_ADS',
                    'resources' => [(int) $resource->id],
                    'plans' => [
                        (int) $resource->id => [
                            'intent' => 'google_ads_search_recovery',
                            'families' => $entries,
                        ],
                    ],
                    'runtime_registry_amendments' => data_get($registry, 'metadata.runtime_amendments', []),
                ],
                'metadata' => [
                    'collection_scope' => 'provider_resource_first',
                    'collection_intent' => 'google_ads_search_recovery',
                    'collection_intent_label' => 'Google Ads Arama verisi onarımı',
                    'google_integration_id' => (int) $integration->id,
                    'plan_fingerprint' => $planFingerprint,
                    'requested_date_range' => ['start' => $start, 'end' => $end],
                    'search_recovery' => true,
                ],
            ]);

            $resourceRun = CollectionResourceRun::query()->create([
                'collection_run_id' => $run->id,
                'provider_or_source' => 'GOOGLE_ADS',
                'resource_kind' => 'provider_resource_first_google_ads_customer',
                'external_resource_id' => $resource->id,
                'digital_asset_id' => null,
                'core_asset_binding_id' => null,
                'status' => CollectionRunStatus::Queued,
                'last_activity_at' => now(),
                'datasets_total' => count($entries),
                'datasets_completed' => 0,
                'datasets_failed' => 0,
                'metadata' => [
                    'collection_scope' => 'provider_resource_first',
                    'collection_intent' => 'google_ads_search_recovery',
                    'customer_id' => preg_replace('/\D+/', '', (string) $resource->external_id),
                    'account_name' => $resource->display_name,
                    'currency_code' => $meta['currency_code'] ?? null,
                    'time_zone' => $meta['time_zone'] ?? $meta['timezone'] ?? null,
                    'login_customer_id' => $meta['login_customer_id'] ?? $meta['manager_customer_id'] ?? null,
                    'requested_date_range' => ['start' => $start, 'end' => $end],
                    'search_recovery' => true,
                ],
            ]);

            foreach ($entries as $entry) {
                $family = (string) $entry['family'];
                $definition = GoogleAdsCentralRequestFamilyCatalog::definition($family);
                $variant = mb_strtolower(trim((string) ($entry['execution_variant'] ?? '')));

                CollectionDatasetRun::query()->create([
                    'collection_run_id' => $run->id,
                    'collection_resource_run_id' => $resourceRun->id,
                    'provider_or_source' => 'GOOGLE_ADS',
                    'dataset_contract_id' => (string) $definition['dataset_id'],
                    'request_family_id' => $family,
                    'execution_variant' => $variant,
                    'requirement_level' => (string) $definition['requirement_level'],
                    'contract_registry_version' => $registryVersion,
                    'status' => CollectionRunStatus::Queued,
                    'attempt_count' => 0,
                    'max_attempts' => (int) config('moxdop-collection.default_max_attempts', 3),
                    'last_activity_at' => now(),
                    'progress_mode' => ProgressMode::Counted,
                    'progress_current' => 0,
                    'progress_total' => null,
                    'rows_received' => 0,
                    'rows_written' => 0,
                    'chunks_completed' => 0,
                    'chunks_failed' => 0,
                    'pages_completed' => 0,
                    'checkpoint' => [],
                    'depends_on_dataset_run_ids' => [],
                    'metadata' => [
                        'collection_scope' => 'provider_resource_first',
                        'date_range' => $entry['date_range'],
                        'source_family_id' => $definition['source_family_id'],
                        'source_layer' => $definition['layer'],
                        'dataset_label' => $definition['label'],
                        'central_definition' => $definition,
                        'execution_variant' => $variant,
                        'search_recovery' => true,
                    ],
                ]);
            }

            return $run->fresh(['resourceRuns.datasetRuns', 'datasetRuns']) ?? $run;
        });

        CollectionRunStarted::dispatch($run);
        $this->starter->dispatchEligibleRootJobs($run);

        return $run->fresh(['resourceRuns.datasetRuns', 'datasetRuns']) ?? $run;
    }

    /** @return array{0:string,1:string} */
    private function effectiveRange(CoreExternalResource $resource, string $requestedStart, string $requestedEnd): array
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        $timezone = is_string($meta['time_zone'] ?? $meta['timezone'] ?? null) && ($meta['time_zone'] ?? $meta['timezone']) !== ''
            ? (string) ($meta['time_zone'] ?? $meta['timezone'])
            : 'UTC';

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $requestedStart, $timezone)->startOfDay();
            $end = CarbonImmutable::createFromFormat('Y-m-d', $requestedEnd, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Arama verisi onarım tarih aralığı geçersiz.');
        }

        $closedEnd = CarbonImmutable::now($timezone)->startOfDay()->subDay();
        if ($end->greaterThan($closedEnd)) {
            $end = $closedEnd;
        }
        if ($start->greaterThan($end)) {
            $start = $end;
        }

        return [$start->toDateString(), $end->toDateString()];
    }
}
