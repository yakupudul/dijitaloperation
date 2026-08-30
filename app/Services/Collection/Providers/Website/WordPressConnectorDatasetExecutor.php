<?php

namespace App\Services\Collection\Providers\Website;

use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Models\CoreConnection;
use App\Models\DataPool\DatasetWriteBatch;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\DataPool\Support\WriteReceipt;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Authenticated, read-only WordPress Connector collector.
 *
 * Full CMS HTML stays in the compressed private raw envelope. Normalized tables
 * retain inventory, provenance, hashes and safe metadata for deterministic joins.
 */
final class WordPressConnectorDatasetExecutor implements DatasetExecutor
{
    /** @var array<string, list<string>> */
    private const array DATASET_SECTIONS = [
        'website_cms_site_snapshot' => ['site'],
        'website_cms_object_snapshot' => ['content', 'media'],
        'website_cms_extension_snapshot' => ['extensions'],
        'website_cms_taxonomy_snapshot' => ['taxonomies'],
        'website_cms_seo_snapshot' => ['seo'],
    ];

    public function __construct(
        private readonly WebsiteEligibilityGuard $eligibility,
        private readonly WebsiteProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly DataPoolStorageRegistry $storage,
        private readonly RawPayloadWriter $rawWriter,
        private readonly MaterializationService $materializations,
        private readonly WordPressConnectorClient $client,
    ) {}

    /** @return list<string> */
    public function supportedRequestFamilies(): array
    {
        return [WebsiteRequestFamilyCatalog::FAMILY_WP_REST];
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }

        try {
            return $this->collect($context, $scope);
        } catch (Throwable $error) {
            return $this->errors->fromThrowable($error);
        }
    }

    /** @param array<string, mixed> $scope */
    private function collect(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $asset = $scope['asset'];
        $connection = CoreConnection::query()
            ->with('credential')
            ->where('digital_asset_id', $asset->id)
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)
            ->where('enabled', true)
            ->whereHas('credential')
            ->first();
        if (! $connection instanceof CoreConnection) {
            throw new RuntimeException('A paired WordPress Connector is required.');
        }

        $checkpoint = is_array($context->checkpoint) ? $context->checkpoint : [];
        $datasetId = (string) $context->datasetRun->dataset_contract_id;
        $sections = self::DATASET_SECTIONS[$datasetId] ?? null;
        if (! is_array($sections)) {
            throw new RuntimeException("Unsupported WordPress Connector dataset [{$datasetId}].");
        }
        $sectionIndex = max(0, (int) ($checkpoint['section_index'] ?? 0));
        $page = max(1, (int) ($checkpoint['page'] ?? 1));
        $observedAt = (string) ($checkpoint['observed_at'] ?? CarbonImmutable::now('UTC')->toDateTimeString());
        if ($sectionIndex >= count($sections)) {
            return $this->completed($checkpoint, count($sections), 0, 0);
        }

        $section = $sections[$sectionIndex];
        $payload = $this->client->snapshot($connection, $section, $page);
        $records = array_values(array_filter($payload['records'] ?? [], 'is_array'));
        $normalized = array_values(array_filter(array_map(
            fn (array $record): ?array => $this->normalize($section, $record, $observedAt),
            $records,
        )));

        $rowsWritten = $this->writeBatch(
            $context,
            $datasetId,
            (int) $asset->id,
            $normalized,
            $payload,
            $section,
            $page,
        );

        $hasMore = ($payload['has_more'] ?? false) === true;
        $nextSectionIndex = $hasMore ? $sectionIndex : $sectionIndex + 1;
        $nextPage = $hasMore ? $page + 1 : 1;
        $checkpointOut = [
            'observed_at' => $observedAt,
            'section_index' => $nextSectionIndex,
            'page' => $nextPage,
            'active_section' => $section,
            'rows_seen_total' => max(0, (int) ($checkpoint['rows_seen_total'] ?? 0)) + count($records),
            'rows_written_total' => max(0, (int) ($checkpoint['rows_written_total'] ?? 0)) + $rowsWritten,
            'connector_schema_version' => (int) ($payload['schema_version'] ?? 1),
        ];

        if (! $hasMore && $nextSectionIndex >= count($sections)) {
            $checkpointOut['completed'] = true;
            $this->finalizeSnapshot($context, $datasetId, (int) $asset->id, $observedAt);

            return $this->completed($checkpointOut, count($sections), count($records), $rowsWritten);
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $nextSectionIndex,
            progressTotal: count($sections),
            rowsReceived: count($records),
            rowsWritten: $rowsWritten,
            pagesCompleted: 1,
            stage: $section,
            checkpoint: $checkpointOut,
        );
    }

    /** @param array<string, mixed> $record @return array<string, mixed>|null */
    private function normalize(string $section, array $record, string $observedAt): ?array
    {
        $metadata = [
            'source' => 'WORDPRESS_SITE_CONNECTOR',
            'access_mode' => 'authenticated_read_only',
            'connector_version' => (string) config('moxdop-wordpress.connector_version', '1.0.0'),
        ];

        return match ($section) {
            'site' => ($record['site_key'] ?? '') === '' ? null : [
                'cms' => 'wordpress',
                'site_key' => (string) $record['site_key'],
                'site_url' => (string) ($record['site_url'] ?? ''),
                'home_url' => $this->nullable($record['home_url'] ?? null),
                'wordpress_version' => $this->nullable($record['wordpress_version'] ?? null),
                'php_version' => $this->nullable($record['php_version'] ?? null),
                'locale' => $this->nullable($record['locale'] ?? null),
                'timezone' => $this->nullable($record['timezone'] ?? null),
                'active_theme' => $this->nullable($record['active_theme'] ?? null),
                'is_multisite' => (bool) ($record['is_multisite'] ?? false),
                'rest_state' => $this->nullable($record['rest_state'] ?? null),
                'cron_state' => $this->nullable($record['cron_state'] ?? null),
                'site_health_good_count' => $this->integerOrNull($record['site_health_cached']['good'] ?? null),
                'site_health_recommended_count' => $this->integerOrNull($record['site_health_cached']['recommended'] ?? null),
                'site_health_critical_count' => $this->integerOrNull($record['site_health_cached']['critical'] ?? null),
                'observed_at' => $observedAt,
                'source_timezone' => 'UTC',
                'metadata' => array_merge($metadata, [
                    'settings' => $record['settings'] ?? [],
                    'features' => $record['features'] ?? [],
                    'active_theme_name' => $record['active_theme_name'] ?? null,
                    'active_theme_version' => $record['active_theme_version'] ?? null,
                    'core_update_available' => (bool) ($record['core_update_available'] ?? false),
                    'available_wordpress_version' => $record['available_wordpress_version'] ?? null,
                    'core_update_checked_at' => $record['core_update_checked_at'] ?? null,
                    'site_health_cached' => $record['site_health_cached'] ?? null,
                ]),
            ],
            'extensions' => ($record['extension_id'] ?? '') === '' ? null : [
                'cms' => 'wordpress',
                'extension_type' => (string) ($record['extension_type'] ?? 'plugin'),
                'extension_id' => (string) $record['extension_id'],
                'name' => $this->nullable($record['name'] ?? null),
                'version' => $this->nullable($record['version'] ?? null),
                'status' => $this->nullable($record['status'] ?? null),
                'update_available' => (bool) ($record['update_available'] ?? false),
                'available_version' => $this->nullable($record['available_version'] ?? null),
                'auto_update' => array_key_exists('auto_update', $record) ? (bool) $record['auto_update'] : null,
                'observed_at' => $observedAt,
                'source_timezone' => 'UTC',
                'metadata' => array_merge($metadata, [
                    'update_checked_at' => $record['update_checked_at'] ?? null,
                ]),
            ],
            'content', 'media' => ($record['object_id'] ?? '') === '' ? null : [
                'cms' => 'wordpress',
                'object_type' => (string) ($record['object_type'] ?? ($section === 'media' ? 'attachment' : 'post')),
                'object_id' => (string) $record['object_id'],
                'status' => $this->nullable($record['status'] ?? null),
                'slug' => $this->nullable($record['slug'] ?? null),
                'permalink' => $this->nullable($record['permalink'] ?? null),
                'title' => $this->nullable($record['title'] ?? null),
                'published_at' => $this->date($record['published_at'] ?? null),
                'modified_at' => $this->date($record['modified_at'] ?? null),
                'parent_id' => $this->nullable($record['parent_id'] ?? null),
                'template' => $this->nullable($record['template'] ?? null),
                'featured_media_id' => $this->nullable($record['featured_media_id'] ?? null),
                'observed_at' => $observedAt,
                'source_timezone' => 'UTC',
                'metadata' => array_merge($metadata, [
                    'language' => $record['language'] ?? null,
                    'translations' => $record['translations'] ?? [],
                    'content_hash' => $record['content_hash'] ?? null,
                    'content_length' => $record['content_length'] ?? null,
                    'builder_provider' => is_array($record['builder'] ?? null) ? ($record['builder']['provider'] ?? null) : null,
                    'builder_content_hash' => is_array($record['builder'] ?? null) ? ($record['builder']['content_hash'] ?? null) : null,
                    'builder_content_length' => is_array($record['builder'] ?? null) ? ($record['builder']['content_length'] ?? null) : null,
                    'mime_type' => $record['mime_type'] ?? null,
                    'alt_text' => $record['alt_text'] ?? null,
                    'width' => $record['width'] ?? null,
                    'height' => $record['height'] ?? null,
                    'file' => $record['file'] ?? null,
                    'file_size' => $record['file_size'] ?? null,
                ]),
            ],
            'taxonomies' => ($record['term_id'] ?? '') === '' ? null : [
                'cms' => 'wordpress',
                'taxonomy' => (string) ($record['taxonomy'] ?? 'category'),
                'term_id' => (string) $record['term_id'],
                'name' => $this->nullable($record['name'] ?? null),
                'slug' => $this->nullable($record['slug'] ?? null),
                'parent_id' => $this->nullable($record['parent_id'] ?? null),
                'content_count' => isset($record['content_count']) ? (int) $record['content_count'] : null,
                'language' => $this->nullable($record['language'] ?? null),
                'observed_at' => $observedAt,
                'source_timezone' => 'UTC',
                'metadata' => $metadata,
            ],
            'seo' => ($record['object_id'] ?? '') === '' ? null : [
                'cms' => 'wordpress',
                'object_type' => (string) ($record['object_type'] ?? 'post'),
                'object_id' => (string) $record['object_id'],
                'permalink' => $this->nullable($record['permalink'] ?? null),
                'seo_provider' => $this->nullable($record['seo_provider'] ?? null),
                'seo_title' => $this->nullable($record['seo_title'] ?? null),
                'meta_description' => $this->nullable($record['meta_description'] ?? null),
                'canonical_url' => $this->nullable($record['canonical_url'] ?? null),
                'robots' => $this->nullable($record['robots'] ?? null),
                'language' => $this->nullable($record['language'] ?? null),
                'observed_at' => $observedAt,
                'source_timezone' => 'UTC',
                'metadata' => $metadata,
            ],
            default => null,
        };
    }

    /** @param list<array<string, mixed>> $records @param array<string, mixed> $raw */
    private function writeBatch(
        DatasetExecutionContext $context,
        string $datasetId,
        int $assetId,
        array $records,
        array $raw,
        string $section,
        int $page,
    ): int {
        $identity = $section.':page='.$page;
        $batchKey = 'website:wordpress:'.$datasetId.':'.hash('sha256', $identity);
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'WORDPRESS_SITE_CONNECTOR',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: WebsiteRequestFamilyCatalog::FAMILY_WP_REST,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode(['data' => $raw], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', $datasetId.'|'.$identity),
            recordCount: count($records),
            providerSafeMetadata: [
                'schema_version' => (int) ($raw['schema_version'] ?? 1),
                'section' => $section,
                'page' => $page,
                'access_mode' => 'authenticated_read_only',
            ],
            capturedAt: now(),
            retentionClass: 'standard',
        );
        if ($records === []) {
            $this->rawWriter->write($envelope);

            return 0;
        }

        $receipt = $this->pipeline->commit(new NormalizedDatasetBatch(
            datasetId: $datasetId,
            datasetRunId: (int) $context->datasetRun->id,
            contractVersion: (int) $context->datasetRun->contract_registry_version,
            batchKey: $batchKey,
            records: $records,
            digitalAssetId: $assetId,
            externalResourceId: null,
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            providerOrSource: 'WORDPRESS_SITE_CONNECTOR',
        ), $envelope, rawRequired: true);

        return $this->accountedRows($receipt, $batchKey);
    }

    private function accountedRows(WriteReceipt $receipt, string $expectedBatchKey): int
    {
        if (! $receipt->isCommitted()) {
            throw new RuntimeException('WordPress Connector write receipt was not committed.');
        }
        if ($receipt->reusedExisting) {
            $existing = DatasetWriteBatch::query()->whereKey($receipt->writeBatchId)->value('batch_key');
            if ($existing !== $expectedBatchKey) {
                throw new RuntimeException('WordPress Connector batch-key collision.');
            }
        }

        return $receipt->rowsReceived;
    }

    private function finalizeSnapshot(
        DatasetExecutionContext $context,
        string $datasetId,
        int $assetId,
        string $observedAt,
    ): void
    {
        DB::transaction(function () use ($context, $datasetId, $assetId, $observedAt): void {
            $table = (string) $this->storage->physicalDataset($datasetId)['table'];
            DB::table($table)
                ->where('digital_asset_id', $assetId)
                ->where('observed_at', '!=', $observedAt)
                ->delete();

            $this->materializations->recordSuccessfulCoverageDates(
                datasetId: $datasetId,
                digitalAssetId: $assetId,
                externalResourceId: null,
                contractVersion: (int) $context->datasetRun->contract_registry_version,
                dates: [],
                collectionRunId: (int) $context->collectionRun->id,
                datasetRunId: (int) $context->datasetRun->id,
                providerOrSource: 'WORDPRESS_SITE_CONNECTOR',
                zeroRow: DB::table($table)->where('digital_asset_id', $assetId)->doesntExist(),
            );
            DatasetMaterialization::query()
                ->where('dataset_id', $datasetId)
                ->where('digital_asset_id', $assetId)
                ->whereNull('external_resource_id')
                ->where('contract_version', (int) $context->datasetRun->contract_registry_version)
                ->update(['row_count_approx' => DB::table($table)->where('digital_asset_id', $assetId)->count()]);
        });
    }

    /** @param array<string, mixed> $checkpoint */
    private function completed(array $checkpoint, int $total, int $received, int $written): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $total,
            progressTotal: $total,
            rowsReceived: $received,
            rowsWritten: $written,
            pagesCompleted: 1,
            checkpoint: $checkpoint,
        );
    }

    private function nullable(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function date(mixed $value): ?string
    {
        $value = $this->nullable($value);
        if ($value === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
