<?php

namespace App\Services\Collection\Providers\Website;

use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Models\DataPool\DatasetWriteBatch;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\DataPool\Support\WriteReceipt;
use Carbon\CarbonImmutable;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use Throwable;

/**
 * Public WordPress REST inventory for a Website Digital Asset.
 *
 * This executor intentionally collects only data WordPress exposes publicly:
 * posts, pages and public REST-enabled CPTs. It never guesses plugin/theme/server
 * state. Privileged WordPress facts belong to the authenticated Site Connector.
 */
final class WordPressRestDatasetExecutor implements DatasetExecutor
{
    private const int PER_PAGE = 25;

    private const int MAX_PAGES_PER_TYPE = 250;

    /** @var list<string> */
    private const array SYSTEM_TYPES = [
        'attachment',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
    ];

    public function __construct(
        private readonly WebsiteEligibilityGuard $eligibility,
        private readonly WebsiteNormalizer $normalizer,
        private readonly WebsiteProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly PublicHttpFetcher $fetcher = new PublicHttpFetcher,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
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
            return $this->executeInventory($context, $scope);
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeInventory(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $checkpoint = is_array($context->checkpoint) ? $context->checkpoint : [];
        $observedAt = (string) ($checkpoint['observed_at'] ?? CarbonImmutable::now('UTC')->toDateTimeString());
        $seed = (string) $scope['seed_url'];
        $assetId = (int) $scope['asset']->id;
        $apiRoot = rtrim($this->origin($seed), '/').'/wp-json/';

        $types = is_array($checkpoint['types'] ?? null) ? $checkpoint['types'] : null;
        if ($types === null) {
            $bootstrap = $this->discoverTypes($apiRoot);
            if (($bootstrap['wordpress'] ?? false) !== true) {
                return $this->completed(
                    current: 1,
                    total: 1,
                    checkpoint: [
                        'observed_at' => $observedAt,
                        'wordpress_detected' => false,
                        'reason' => $bootstrap['reason'] ?? 'wp_rest_not_detected',
                        'rows_written_total' => 0,
                    ],
                );
            }

            $types = $bootstrap['types'];
            if ($types === []) {
                return $this->completed(
                    current: 1,
                    total: 1,
                    checkpoint: [
                        'observed_at' => $observedAt,
                        'wordpress_detected' => true,
                        'rest_reachable' => true,
                        'types' => [],
                        'rows_written_total' => 0,
                    ],
                );
            }

            $checkpoint['types'] = $types;
            $checkpoint['type_index'] = 0;
            $checkpoint['page'] = 1;
            $checkpoint['rows_written_total'] = 0;
            $checkpoint['objects_seen_total'] = 0;
        }

        $typeIndex = max(0, (int) ($checkpoint['type_index'] ?? 0));
        $page = max(1, (int) ($checkpoint['page'] ?? 1));
        $rowsWrittenTotal = max(0, (int) ($checkpoint['rows_written_total'] ?? 0));
        $objectsSeenTotal = max(0, (int) ($checkpoint['objects_seen_total'] ?? 0));
        $totalTypes = count($types);

        if ($typeIndex >= $totalTypes) {
            return $this->completed($totalTypes, $totalTypes, array_merge($checkpoint, [
                'wordpress_detected' => true,
                'rest_reachable' => true,
                'completed' => true,
            ]));
        }

        $type = is_array($types[$typeIndex] ?? null) ? $types[$typeIndex] : [];
        $objectType = trim((string) ($type['type'] ?? ''));
        $restBase = trim((string) ($type['rest_base'] ?? ''));

        if ($objectType === '' || $restBase === '') {
            return $this->continueWithNextType($checkpoint, $types, $typeIndex, $rowsWrittenTotal, $objectsSeenTotal);
        }

        if ($page > self::MAX_PAGES_PER_TYPE) {
            $checkpoint['limits'][] = [
                'type' => $objectType,
                'reason' => 'max_pages_per_type',
                'max_pages' => self::MAX_PAGES_PER_TYPE,
            ];

            return $this->continueWithNextType($checkpoint, $types, $typeIndex, $rowsWrittenTotal, $objectsSeenTotal);
        }

        $endpoint = rtrim($apiRoot, '/').'/wp/v2/'.rawurlencode($restBase);
        $url = $endpoint.'?'.http_build_query([
            'context' => 'view',
            'per_page' => self::PER_PAGE,
            'page' => $page,
            '_fields' => 'id,date_gmt,modified_gmt,slug,status,type,link,title,content,excerpt,parent,template,featured_media',
        ], '', '&', PHP_QUERY_RFC3986);

        $fetch = $this->fetcher->fetch($url);
        if (($fetch['ok'] ?? false) !== true) {
            // WordPress returns HTTP 400 when page exceeds the last page and the
            // prior page happened to contain exactly PER_PAGE records.
            if ((int) ($fetch['status_code'] ?? 0) === 400 && $page > 1) {
                return $this->continueWithNextType($checkpoint, $types, $typeIndex, $rowsWrittenTotal, $objectsSeenTotal);
            }

            throw new \RuntimeException(sprintf(
                'WordPress REST fetch failed for type [%s] page [%d]: %s',
                $objectType,
                $page,
                (string) ($fetch['error'] ?? 'unknown_error'),
            ));
        }

        $decoded = json_decode((string) ($fetch['body'] ?? ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('WordPress REST returned invalid JSON for content inventory.');
        }

        $objects = array_values(array_filter($decoded, 'is_array'));
        $cmsRecords = [];
        $urlRecords = [];
        $contentRecords = [];

        foreach ($objects as $object) {
            $permalink = $this->normalizer->normalizeUrl((string) ($object['link'] ?? ''));
            $objectId = isset($object['id']) ? (string) $object['id'] : '';
            if ($objectId === '') {
                continue;
            }

            $renderedTitle = is_array($object['title'] ?? null)
                ? trim(html_entity_decode(strip_tags((string) ($object['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : null;
            $contentHtml = is_array($object['content'] ?? null)
                ? (string) ($object['content']['rendered'] ?? '')
                : '';

            $cmsRecords[] = [
                'digital_asset_id' => $assetId,
                'external_resource_id' => null,
                'cms' => 'wordpress',
                'object_type' => $objectType,
                'object_id' => $objectId,
                'status' => $this->nullableString($object['status'] ?? null),
                'slug' => $this->nullableString($object['slug'] ?? null),
                'permalink' => $permalink,
                'title' => $renderedTitle !== '' ? $renderedTitle : null,
                'published_at' => $this->wpDate($object['date_gmt'] ?? null),
                'modified_at' => $this->wpDate($object['modified_gmt'] ?? null),
                'parent_id' => isset($object['parent']) && (int) $object['parent'] > 0 ? (string) $object['parent'] : null,
                'template' => $this->nullableString($object['template'] ?? null),
                'featured_media_id' => isset($object['featured_media']) && (int) $object['featured_media'] > 0
                    ? (string) $object['featured_media']
                    : null,
                'observed_at' => $observedAt,
                'source_timezone' => 'UTC',
                'metadata' => [
                    'source' => 'WORDPRESS_SITE_CONNECTOR',
                    'access_mode' => 'public_rest',
                    'rest_base' => $restBase,
                    'page' => $page,
                    'excerpt' => is_array($object['excerpt'] ?? null)
                        ? trim(strip_tags((string) ($object['excerpt']['rendered'] ?? '')))
                        : null,
                    'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
                ],
            ];

            if ($permalink !== null) {
                $urlRecords[] = $this->normalizer->urlRecord($assetId, $permalink, 'wordpress_rest', $observedAt);
                $contentRecords[] = $this->contentStats($assetId, $seed, $permalink, $contentHtml, $observedAt, $objectType, $objectId);
            }
        }

        $batchIdentity = $objectType.':page='.$page;
        $rowsWritten = 0;
        if ($cmsRecords !== []) {
            $rowsWritten += $this->writeBatch(
                $context,
                'website_cms_object_snapshot',
                'wp_objects',
                $assetId,
                $cmsRecords,
                $objects,
                $url,
                $batchIdentity,
            );
        }
        if ($urlRecords !== []) {
            $rowsWritten += $this->writeBatch(
                $context,
                'website_url',
                'wp_urls',
                $assetId,
                $urlRecords,
                $objects,
                $url,
                $batchIdentity,
            );
        }
        if ($contentRecords !== []) {
            $rowsWritten += $this->writeBatch(
                $context,
                'website_content_stats',
                'wp_content',
                $assetId,
                $contentRecords,
                $objects,
                $url,
                $batchIdentity,
            );
        }

        $rowsWrittenTotal += $rowsWritten;
        $objectsSeenTotal += count($cmsRecords);
        $lastPageForType = count($objects) < self::PER_PAGE;

        $checkpointOut = array_merge($checkpoint, [
            'observed_at' => $observedAt,
            'wordpress_detected' => true,
            'rest_reachable' => true,
            'types' => $types,
            'type_index' => $lastPageForType ? $typeIndex + 1 : $typeIndex,
            'page' => $lastPageForType ? 1 : $page + 1,
            'active_type' => $objectType,
            'active_rest_base' => $restBase,
            'rows_written_total' => $rowsWrittenTotal,
            'objects_seen_total' => $objectsSeenTotal,
        ]);

        if ($lastPageForType && $typeIndex + 1 >= $totalTypes) {
            return $this->completed(
                $totalTypes,
                $totalTypes,
                array_merge($checkpointOut, ['completed' => true]),
                $rowsWritten,
                count($cmsRecords),
            );
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::PageBased,
            progressCurrent: min($lastPageForType ? $typeIndex + 1 : $typeIndex, $totalTypes),
            progressTotal: $totalTypes,
            rowsReceived: count($cmsRecords),
            rowsWritten: $rowsWritten,
            pagesCompleted: 1,
            checkpoint: $checkpointOut,
        );
    }

    /** @return array{wordpress: bool, reason?: string, types: list<array{type: string, rest_base: string}>} */
    private function discoverTypes(string $apiRoot): array
    {
        $indexFetch = $this->fetcher->fetch($apiRoot);
        if (($indexFetch['ok'] ?? false) !== true) {
            return ['wordpress' => false, 'reason' => (string) ($indexFetch['error'] ?? 'wp_json_unreachable'), 'types' => []];
        }

        $index = json_decode((string) ($indexFetch['body'] ?? ''), true);
        $namespaces = is_array($index) && is_array($index['namespaces'] ?? null) ? $index['namespaces'] : [];
        $hasWpV2 = in_array('wp/v2', $namespaces, true);
        if (! $hasWpV2) {
            return ['wordpress' => false, 'reason' => 'wp_v2_namespace_missing', 'types' => []];
        }

        $typesFetch = $this->fetcher->fetch(rtrim($apiRoot, '/').'/wp/v2/types?context=view');
        if (($typesFetch['ok'] ?? false) !== true) {
            return ['wordpress' => true, 'reason' => 'wp_types_unavailable', 'types' => []];
        }

        $decoded = json_decode((string) ($typesFetch['body'] ?? ''), true);
        if (! is_array($decoded)) {
            return ['wordpress' => true, 'reason' => 'wp_types_invalid_json', 'types' => []];
        }

        $types = [];
        foreach ($decoded as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }
            $type = trim((string) ($definition['slug'] ?? $key));
            $restBase = trim((string) ($definition['rest_base'] ?? ''));
            $viewable = ($definition['viewable'] ?? true) === true;
            if ($type === '' || $restBase === '' || ! $viewable || in_array($type, self::SYSTEM_TYPES, true)) {
                continue;
            }
            $types[] = ['type' => $type, 'rest_base' => $restBase];
        }

        usort($types, static function (array $a, array $b): int {
            $priority = static fn (string $type): int => match ($type) {
                'page' => 0,
                'post' => 1,
                default => 2,
            };
            $cmp = $priority($a['type']) <=> $priority($b['type']);

            return $cmp !== 0 ? $cmp : strcmp($a['type'], $b['type']);
        });

        return ['wordpress' => true, 'types' => $types];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     * @param  list<array<string, mixed>>  $types
     */
    private function continueWithNextType(
        array $checkpoint,
        array $types,
        int $typeIndex,
        int $rowsWrittenTotal,
        int $objectsSeenTotal,
    ): DatasetExecutionResult {
        $next = $typeIndex + 1;
        $out = array_merge($checkpoint, [
            'types' => $types,
            'type_index' => $next,
            'page' => 1,
            'rows_written_total' => $rowsWrittenTotal,
            'objects_seen_total' => $objectsSeenTotal,
        ]);

        if ($next >= count($types)) {
            return $this->completed(count($types), count($types), array_merge($out, ['completed' => true]));
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $next,
            progressTotal: count($types),
            checkpoint: $out,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contentStats(
        int $assetId,
        string $seed,
        string $url,
        string $html,
        string $observedAt,
        string $objectType,
        string $objectId,
    ): array {
        $withoutNoise = preg_replace('/<(script|style|noscript|svg)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($withoutNoise), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $words = $text === '' ? [] : preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $headingCounts = [];
        for ($level = 1; $level <= 6; $level++) {
            preg_match_all('/<h'.$level.'\b[^>]*>/i', $html, $matches);
            $headingCounts['h'.$level] = count($matches[0] ?? []);
        }

        preg_match_all('/<p\b[^>]*>/i', $html, $paragraphMatches);
        preg_match_all('/<img\b[^>]*>/i', $html, $imageMatches);
        $imageTags = $imageMatches[0] ?? [];
        $missingAlt = 0;
        foreach ($imageTags as $imageTag) {
            if (! preg_match('/\balt\s*=\s*(["\'])\s*.*?\1/i', $imageTag)) {
                $missingAlt++;
            }
        }

        $internal = [];
        $external = [];
        preg_match_all('/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1/is', $html, $linkMatches);
        foreach ($linkMatches[2] ?? [] as $href) {
            $resolved = $this->urls->resolve($url, html_entity_decode(trim((string) $href), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($resolved === null) {
                continue;
            }
            if ($this->urls->sameSite($seed, $resolved)) {
                $internal[$resolved] = true;
            } else {
                $external[$resolved] = true;
            }
        }

        return [
            'digital_asset_id' => $assetId,
            'external_resource_id' => null,
            'url' => $url,
            'observed_at' => $observedAt,
            'source_timezone' => 'UTC',
            'metadata' => [
                'source' => 'WORDPRESS_SITE_CONNECTOR',
                'cms' => 'wordpress',
                'cms_object_type' => $objectType,
                'cms_object_id' => $objectId,
                'word_count' => is_array($words) ? count($words) : 0,
                'text_length' => mb_strlen($text),
                'paragraph_count' => count($paragraphMatches[0] ?? []),
                'heading_counts' => $headingCounts,
                'image_count' => count($imageTags),
                'images_missing_alt_count' => $missingAlt,
                'internal_link_count' => count($internal),
                'external_link_count' => count($external),
                'internal_links_sample' => array_slice(array_keys($internal), 0, 50),
                'external_links_sample' => array_slice(array_keys($external), 0, 25),
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<mixed>  $rawRows
     */
    private function writeBatch(
        DatasetExecutionContext $context,
        string $datasetId,
        string $suffix,
        int $assetId,
        array $records,
        array $rawRows,
        string $query,
        string $identity,
    ): int {
        if ($records === []) {
            return 0;
        }

        $batchKey = 'website:wp:'.$datasetId.':'.$suffix.':'.hash('sha256', $identity);
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'WORDPRESS_SITE_CONNECTOR',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: WebsiteRequestFamilyCatalog::FAMILY_WP_REST,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode(['data' => $rawRows], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', $query.'|'.$datasetId.'|'.$identity),
            recordCount: count($records),
            providerSafeMetadata: [
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
                'request_family' => WebsiteRequestFamilyCatalog::FAMILY_WP_REST,
                'access_mode' => 'public_rest',
            ],
            capturedAt: now(),
            retentionClass: 'standard',
        );

        $receipt = $this->pipeline->commit(
            new NormalizedDatasetBatch(
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
            ),
            $envelope,
        );

        return $this->accountedRows($receipt, $batchKey);
    }

    private function accountedRows(WriteReceipt $receipt, string $expectedBatchKey): int
    {
        if (! $receipt->isCommitted()) {
            throw new \RuntimeException('WordPress Website write receipt not committed; checkpoint not advanced.');
        }

        if ($receipt->reusedExisting) {
            $existingKey = DatasetWriteBatch::query()->whereKey($receipt->writeBatchId)->value('batch_key');
            if ($existingKey !== $expectedBatchKey) {
                throw new \RuntimeException('WordPress Website warehouse batch-key collision; checkpoint not advanced.');
            }
        }

        return $receipt->rowsReceived;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function wpDate(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    }

    /** @param array<string, mixed> $checkpoint */
    private function completed(
        int $current,
        int $total,
        array $checkpoint,
        int $rowsWritten = 0,
        int $rowsReceived = 0,
    ): DatasetExecutionResult {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $current,
            progressTotal: max(1, $total),
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            pagesCompleted: 1,
            checkpoint: $checkpoint,
        );
    }
}
