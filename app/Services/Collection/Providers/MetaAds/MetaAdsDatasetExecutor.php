<?php

namespace App\Services\Collection\Providers\MetaAds;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Enums\DataPool\MaterializationStatus;
use App\Models\CoreIntegration;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\Integrations\Meta\MetaApiClient;
use RuntimeException;
use Throwable;

/**
 * Authoritative collector for the two remaining V1 Meta snapshot families.
 *
 * Professional V2 owns performance/actions/breakdowns. This executor only owns
 * ad-account metadata plus the entity inventory contracts that V2 does not
 * replace. Entity collection is dataset-aware: a campaign DatasetRun only reads
 * campaigns, an ad-set DatasetRun only reads ad sets, and a creative DatasetRun
 * reads Ads to discover creative relations before reading account creatives.
 */
final class MetaAdsDatasetExecutor implements DatasetExecutor
{
    private const ENTITY_COLLECTOR_VERSION = 'meta-entity-v3';

    public function __construct(
        private readonly MetaAdsEligibilityGuard $eligibility,
        private readonly MetaApiClient $client,
        private readonly MetaAdsNormalizer $normalizer,
        private readonly MetaAdsProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
    ) {}

    /** @return list<string> */
    public function supportedRequestFamilies(): array
    {
        return [
            MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META,
            MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
        ];
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        $familyId = (string) $context->datasetRun->request_family_id;
        if (! in_array($familyId, $this->supportedRequestFamilies(), true)) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                "Meta Ads V1 family [{$familyId}] is retired or not owned by the snapshot executor.",
                'UNIMPLEMENTED_CAPABILITY',
            );
        }

        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }

        try {
            return match ($familyId) {
                MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META => $this->executeAdAccountMeta($context, $scope),
                MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT => $this->executeEntitySnapshot($context, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported Meta Ads snapshot family.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /** @param array<string, mixed> $scope */
    private function executeAdAccountMeta(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $fields = 'id,name,account_status,currency,timezone_name,business{id,name}';
        $path = (string) $scope['act_id'];
        $query = ['fields' => $fields];

        /** @var CoreIntegration $integration */
        $integration = $scope['integration'];
        $payload = $this->client->get($integration, $path, $query);
        $timezone = (string) ($payload['timezone_name'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = is_string($payload['currency'] ?? null)
            ? (string) $payload['currency']
            : ($scope['currency'] ?? null);

        $records = $this->normalizer->normalizeAdAccountSnapshot(
            (string) $scope['account_id'],
            $payload,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
            $timezone,
        );

        $this->writeRecords(
            $context,
            'meta_ad_account_snapshot',
            'account_meta',
            $records,
            [$payload],
            $path.'?'.$this->queryString($query),
            $scope,
            $this->safeRequestId($payload),
            [
                'collector_version' => self::ENTITY_COLLECTOR_VERSION,
                'retrieval' => 'sync',
                'graph_api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
            ],
        );

        $this->persistProviderLimitation($context, 'meta_ad_account_snapshot', $timezone, [
            'currency' => $currency,
        ]);

        return $this->completedCounted(1, 1, [
            'collector_version' => self::ENTITY_COLLECTOR_VERSION,
            'timezone' => $timezone,
            'currency' => $currency,
        ], 1, count($records));
    }

    /** @param array<string, mixed> $scope */
    private function executeEntitySnapshot(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $datasetId = (string) $context->datasetRun->dataset_contract_id;
        $checkpoint = $this->entityCheckpoint($context, $datasetId);

        return match ($datasetId) {
            'meta_campaign_snapshot' => $this->executeCampaignSnapshot($context, $scope),
            'meta_adset_snapshot' => $this->executeAdSetSnapshot($context, $scope),
            'meta_creative_snapshot' => $this->executeCreativeSnapshot($context, $scope, $checkpoint),
            default => DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                "RF_META_ENTITY_SNAPSHOT does not own dataset [{$datasetId}].",
                'UNIMPLEMENTED_CAPABILITY',
            ),
        };
    }

    /** @param array<string, mixed> $scope */
    private function executeCampaignSnapshot(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $fields = 'id,name,objective,status,effective_status,buying_type,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time';
        $path = (string) $scope['act_id'].'/campaigns';
        $query = ['fields' => $fields, 'limit' => 250];
        [$rows, $requestId] = $this->paginateList($scope['integration'], $path, $query);

        $records = $this->normalizer->normalizeCampaignSnapshots(
            (string) $scope['account_id'],
            (string) ($scope['time_zone'] ?? 'UTC'),
            $rows,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );

        $this->writeRecords($context, 'meta_campaign_snapshot', 'campaigns', $records, $rows, $path, $scope, $requestId, [
            'entity' => 'campaign',
            'collector_version' => self::ENTITY_COLLECTOR_VERSION,
            'filtering_strategy' => 'account_edge_cursor_pagination',
            'provider_id_in_filter_used' => false,
        ]);

        return $this->completedCounted(1, 1, $this->completedEntityCheckpoint($scope, 'meta_campaign_snapshot', 'campaigns'), count($rows), count($records));
    }

    /** @param array<string, mixed> $scope */
    private function executeAdSetSnapshot(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $fields = 'id,name,campaign_id,optimization_goal,billing_event,destination_type,status,effective_status,daily_budget,lifetime_budget';
        $path = (string) $scope['act_id'].'/adsets';
        $query = ['fields' => $fields, 'limit' => 250];
        [$rows, $requestId] = $this->paginateList($scope['integration'], $path, $query);

        $records = $this->normalizer->normalizeAdSetSnapshots(
            (string) $scope['account_id'],
            (string) ($scope['time_zone'] ?? 'UTC'),
            $rows,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );

        $this->writeRecords($context, 'meta_adset_snapshot', 'adsets', $records, $rows, $path, $scope, $requestId, [
            'entity' => 'adset',
            'collector_version' => self::ENTITY_COLLECTOR_VERSION,
            'filtering_strategy' => 'account_edge_cursor_pagination',
            'provider_id_in_filter_used' => false,
        ]);

        return $this->completedCounted(1, 1, $this->completedEntityCheckpoint($scope, 'meta_adset_snapshot', 'adsets'), count($rows), count($records));
    }

    /**
     * Creative inventory is intentionally two ticks. First collect Ads to resolve
     * creative relations; then enumerate /adcreatives with cursor pagination and
     * filter the returned rows in-process. No unsupported Graph id-IN filtering is
     * ever sent to Meta.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $checkpoint
     */
    private function executeCreativeSnapshot(DatasetExecutionContext $context, array $scope, array $checkpoint): DatasetExecutionResult
    {
        $stepIndex = (int) ($checkpoint['step_index'] ?? 0);
        $timezone = (string) ($checkpoint['timezone'] ?? $scope['time_zone'] ?? 'UTC');
        $currency = (string) ($checkpoint['currency'] ?? $scope['currency'] ?? 'XXX');

        if ($stepIndex <= 0) {
            $path = (string) $scope['act_id'].'/ads';
            $query = [
                'fields' => 'id,name,campaign_id,adset_id,status,effective_status,creative{id}',
                'limit' => 250,
            ];
            [$rows, $requestId] = $this->paginateList($scope['integration'], $path, $query);

            $creativeIds = [];
            foreach ($rows as $row) {
                $creativeId = data_get($row, 'creative.id');
                if (is_scalar($creativeId)) {
                    $creativeId = trim((string) $creativeId);
                    if ($creativeId !== '') {
                        $creativeIds[$creativeId] = $creativeId;
                    }
                }
            }
            $creativeIds = array_values($creativeIds);

            // Keep provider evidence for the relation-discovery step without writing
            // a different normalized dataset from this DatasetRun.
            $this->writeRecords($context, 'meta_creative_snapshot', 'ads_creative_refs', [], $rows, $path, $scope, $requestId, [
                'entity' => 'ad',
                'collector_version' => self::ENTITY_COLLECTOR_VERSION,
                'creative_id_count' => count($creativeIds),
                'provider_id_in_filter_used' => false,
                'note' => 'Ads are read only to discover creative relations for meta_creative_snapshot.',
            ]);

            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::Counted,
                progressCurrent: 1,
                progressTotal: 2,
                checkpoint: [
                    'entity_collector_version' => self::ENTITY_COLLECTOR_VERSION,
                    'entity_dataset_id' => 'meta_creative_snapshot',
                    'step_index' => 1,
                    'timezone' => $timezone,
                    'currency' => $currency,
                    'creative_ids' => $creativeIds,
                    'last_step' => 'ads',
                ],
                rowsReceived: count($rows),
                rowsWritten: 0,
            );
        }

        /** @var list<string> $creativeIds */
        $creativeIds = is_array($checkpoint['creative_ids'] ?? null)
            ? array_values(array_unique(array_filter(array_map(
                static fn (mixed $id): string => is_scalar($id) ? trim((string) $id) : '',
                $checkpoint['creative_ids'],
            ))))
            : [];

        $path = (string) $scope['act_id'].'/adcreatives';
        $query = [
            'fields' => 'id,name,object_type,status,title,body,call_to_action_type,link_url,thumbnail_url,image_hash,video_id,object_story_spec,instagram_actor_id,actor_id',
            'limit' => 250,
        ];
        [$allCreativeRows, $requestId] = $this->paginateList($scope['integration'], $path, $query);

        $allowed = array_fill_keys($creativeIds, true);
        $rows = [];
        foreach ($allCreativeRows as $row) {
            $id = is_scalar($row['id'] ?? null) ? trim((string) $row['id']) : '';
            if ($id !== '' && isset($allowed[$id])) {
                $rows[] = $row;
            }
        }

        $records = $this->normalizer->normalizeCreativeSnapshots(
            (string) $scope['account_id'],
            $timezone,
            $rows,
            (int) $scope['asset']->id,
            (int) $scope['resource']->id,
        );

        $this->writeRecords($context, 'meta_creative_snapshot', 'creatives', $records, $rows, $path, $scope, $requestId, [
            'entity' => 'creative',
            'collector_version' => self::ENTITY_COLLECTOR_VERSION,
            'filtering_strategy' => 'account_edge_cursor_pagination_then_application_id_filter',
            'provider_id_in_filter_used' => false,
            'referenced_creative_ids' => count($creativeIds),
            'account_creatives_seen' => count($allCreativeRows),
            'matched_creatives' => count($rows),
            'binary_media_downloaded' => false,
            'instagram_digital_asset_created' => false,
            'instagram_binding_created' => false,
        ]);

        return $this->completedCounted(2, 2, [
            'entity_collector_version' => self::ENTITY_COLLECTOR_VERSION,
            'entity_dataset_id' => 'meta_creative_snapshot',
            'step_index' => 2,
            'timezone' => $timezone,
            'currency' => $currency,
            'last_step' => 'creatives',
        ], count($rows), count($records));
    }

    /**
     * Old RF_META_ENTITY_SNAPSHOT checkpoints represented one four-step composite
     * plan shared by every dataset. They must never be reused after this change:
     * a failed campaign run may otherwise resume at the old creative step and be
     * incorrectly marked complete. Only checkpoints stamped by this dataset-aware
     * collector are accepted.
     *
     * @return array<string, mixed>
     */
    private function entityCheckpoint(DatasetExecutionContext $context, string $datasetId): array
    {
        $checkpoint = is_array($context->checkpoint) ? $context->checkpoint : [];
        if (($checkpoint['entity_collector_version'] ?? null) !== self::ENTITY_COLLECTOR_VERSION) {
            return [];
        }
        if (($checkpoint['entity_dataset_id'] ?? null) !== $datasetId) {
            return [];
        }

        return $checkpoint;
    }

    /** @param array<string, mixed> $scope @return array<string, mixed> */
    private function completedEntityCheckpoint(array $scope, string $datasetId, string $step): array
    {
        return [
            'entity_collector_version' => self::ENTITY_COLLECTOR_VERSION,
            'entity_dataset_id' => $datasetId,
            'step_index' => 1,
            'timezone' => (string) ($scope['time_zone'] ?? 'UTC'),
            'currency' => (string) ($scope['currency'] ?? 'XXX'),
            'last_step' => $step,
        ];
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function paginateList(CoreIntegration $integration, string $path, array $query): array
    {
        $rows = [];
        $requestId = null;
        $maxPages = max(1, (int) config(
            'moxdop-meta-ads-collector.max_entity_pages_per_tick',
            config('moxdop-meta-ads-collector.max_insight_pages_per_tick', 25),
        ));
        $pages = 0;
        $nextUrl = null;
        $next = null;

        while ($pages < $maxPages) {
            $pages++;
            $payload = $nextUrl === null
                ? $this->client->get($integration, $path, $query)
                : $this->client->getAbsolute($integration, $nextUrl);
            $requestId = $this->safeRequestId($payload) ?? $requestId;

            foreach ($payload['data'] ?? [] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $next = data_get($payload, 'paging.next');
            if (! is_string($next) || $next === '') {
                $next = null;
                break;
            }
            $nextUrl = $next;
        }

        if ($next !== null) {
            throw new RuntimeException(
                "Meta entity pagination for [{$path}] reached the configured safety cap before paging was exhausted; refusing a partial snapshot."
            );
        }

        return [$rows, $requestId];
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param list<array<string, mixed>> $rawRows
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $extraMeta
     */
    private function writeRecords(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchSuffix,
        array $records,
        array $rawRows,
        string $query,
        array $scope,
        ?string $requestId,
        array $extraMeta = [],
    ): void {
        if ($records === []) {
            $envelope = $this->rawEnvelope($context, $datasetId, $batchSuffix, $query, $rawRows, $scope, $requestId, $extraMeta);
            try {
                $this->rawWriter->write($envelope);
            } catch (Throwable) {
                // Raw capture is optional for an empty normalized snapshot.
            }

            return;
        }

        $batchSize = max(1, (int) config('moxdop-meta-ads-collector.write_batch_size', 500));
        foreach (array_chunk($records, $batchSize) as $index => $chunk) {
            if ($chunk === []) {
                continue;
            }

            $batchKey = sprintf('meta:%s:%s:chunk=%d', $datasetId, $batchSuffix, $index);
            $rawChunk = $index === 0
                ? $rawRows
                : array_slice($rawRows, $index * $batchSize, $batchSize);
            $envelope = $this->rawEnvelope(
                $context,
                $datasetId,
                $batchKey,
                $query,
                $rawChunk,
                $scope,
                $requestId,
                array_merge($extraMeta, [
                    'chunk_index' => $index,
                    'write_batch_size' => $batchSize,
                ]),
            );

            $receipt = $this->pipeline->commit(
                new NormalizedDatasetBatch(
                    datasetId: $datasetId,
                    datasetRunId: (int) $context->datasetRun->id,
                    contractVersion: (int) $context->datasetRun->contract_registry_version,
                    batchKey: $batchKey,
                    records: $chunk,
                    digitalAssetId: (int) $scope['asset']->id,
                    externalResourceId: (int) $scope['resource']->id,
                    collectionRunId: (int) $context->collectionRun->id,
                    resourceRunId: (int) $context->resourceRun->id,
                    providerOrSource: 'META_ADS',
                ),
                $envelope,
            );

            if (! $receipt->isCommitted()) {
                throw new RuntimeException('Meta Ads write receipt not committed; checkpoint not advanced.');
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $extraMeta
     */
    private function rawEnvelope(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchKey,
        string $query,
        array $rows,
        array $scope,
        ?string $requestId,
        array $extraMeta = [],
    ): RawPayloadEnvelope {
        $safeMeta = array_merge([
            'api_version' => MetaAdsProviderCapabilities::GRAPH_API_VERSION,
            'account_id' => (string) $scope['account_id'],
            'act_id_present' => true,
            'request_id' => $requestId,
            'request_family' => (string) $context->datasetRun->request_family_id,
            'dataset_contract_id' => (string) $context->datasetRun->dataset_contract_id,
            'row_count' => count($rows),
            'provider_completeness' => MetaAdsProviderCapabilities::PROVIDER_COMPLETENESS,
            'verification_date' => MetaAdsProviderCapabilities::VERIFICATION_DATE,
            'money_unit_note' => MetaAdsProviderCapabilities::MONEY_UNIT_NOTE,
            'async_post_note' => MetaAdsProviderCapabilities::ASYNC_POST_NOTE,
        ], $extraMeta);

        return new RawPayloadEnvelope(
            providerOrSource: 'META_ADS',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: (string) $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode(['data' => $rows, 'request_id' => $requestId], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', json_encode([
                'account' => (string) $scope['account_id'],
                'query' => $query,
                'family' => (string) $context->datasetRun->request_family_id,
                'dataset' => (string) $context->datasetRun->dataset_contract_id,
            ], JSON_THROW_ON_ERROR)),
            recordCount: count($rows),
            providerSafeMetadata: $safeMeta,
            capturedAt: now(),
            retentionClass: (string) config('moxdop-meta-ads-collector.raw_retention_class'),
        );
    }

    /** @param array<string, mixed> $checkpoint */
    private function completedCounted(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::Counted,
            progressCurrent: $current,
            progressTotal: $total,
            checkpoint: $checkpoint,
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            stage: 'COMPLETED',
        );
    }

    /** @param array<string, mixed> $extra */
    private function persistProviderLimitation(
        DatasetExecutionContext $context,
        string $datasetId,
        string $timezone,
        array $extra = [],
    ): void {
        $mat = DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->where('digital_asset_id', $context->collectionRun->digital_asset_id)
            ->where('external_resource_id', $context->resourceRun->external_resource_id)
            ->first();

        if ($mat === null) {
            return;
        }

        $freshness = is_array($mat->freshness_metadata) ? $mat->freshness_metadata : [];
        $currentStatus = $mat->status instanceof MaterializationStatus
            ? $mat->status
            : MaterializationStatus::tryFrom((string) $mat->status);

        $mat->forceFill([
            'status' => $currentStatus === MaterializationStatus::Partial
                ? MaterializationStatus::Partial
                : ($currentStatus ?? MaterializationStatus::Available),
            'freshness_metadata' => array_merge($freshness, [
                'provider_completeness' => MetaAdsProviderCapabilities::PROVIDER_COMPLETENESS,
                'account_timezone' => $timezone,
                'missing_row_neq_zero' => true,
                'read_only' => true,
                'verification_date' => MetaAdsProviderCapabilities::VERIFICATION_DATE,
            ], $extra),
        ])->save();
    }

    /** @param array<string, mixed> $payload */
    private function safeRequestId(array $payload): ?string
    {
        foreach (['request_id', 'x-fb-request-id', 'id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $value = (string) $payload[$key];
                if ($value !== '' && ! str_starts_with($value, 'EAA')) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param array<string, scalar|null> $query */
    private function queryString(array $query): string
    {
        ksort($query);

        return http_build_query($query);
    }
}
