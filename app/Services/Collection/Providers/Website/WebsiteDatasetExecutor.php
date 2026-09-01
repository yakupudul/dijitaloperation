<?php

namespace App\Services\Collection\Providers\Website;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\Collection\ProgressMode;
use App\Models\CoreConnection;
use App\Models\DataPool\DatasetWriteBatch;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RawPayloadWriter;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use App\Services\DataPool\Support\WriteReceipt;
use App\Support\SslCertificateProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MoxDop\Website\Discovery\DiscoveryConfig;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use Throwable;

/**
 * Production Website DatasetExecutor — Registry WEB_RF_* COLLECTION_READY families.
 * Reuses the hardened public fetcher and writes externally observable Website Intelligence
 * into the canonical Data Pool. No Findings/Recommendations are created here.
 */
final class WebsiteDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly WebsiteEligibilityGuard $eligibility,
        private readonly WebsiteNormalizer $normalizer,
        private readonly WebsitePageAnalyzer $pageAnalyzer,
        private readonly WebsiteProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly PublicHttpFetcher $fetcher = new PublicHttpFetcher,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
        private readonly SslCertificateProbe $tls = new SslCertificateProbe,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return WebsiteRequestFamilyCatalog::publicFamilies();
    }

    public function execute(DatasetExecutionContext $context): DatasetExecutionResult
    {
        try {
            $definition = WebsiteRequestFamilyCatalog::definition($context->datasetRun->request_family_id);
        } catch (Throwable $e) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::UnimplementedCapability,
                $e->getMessage(),
                'UNIMPLEMENTED_CAPABILITY',
            );
        }

        $scope = $this->eligibility->assertEligible($context->collectionRun, $context->resourceRun);
        if ($scope instanceof DatasetExecutionResult) {
            return $scope;
        }

        try {
            return match ($definition['kind']) {
                'http_html_diagnosis' => $this->executeHttpHtmlDiagnosis($context, $scope),
                'public_crawl' => $this->executePublicCrawl($context, $scope),
                'dns_tls' => $this->executeDnsTls($context, $scope),
                'pagespeed' => $this->executePagespeed($context, $scope),
                default => DatasetExecutionResult::failed(
                    CollectionErrorCategory::UnimplementedCapability,
                    'Unsupported Website request kind.',
                    'UNIMPLEMENTED_CAPABILITY',
                ),
            };
        } catch (Throwable $e) {
            return $this->errors->fromThrowable($e);
        }
    }

    /** @param array<string, mixed> $scope */
    private function executeHttpHtmlDiagnosis(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $steps = ['homepage', 'robots', 'sitemap'];
        $checkpoint = $context->checkpoint;
        $stepIndex = (int) ($checkpoint['step_index'] ?? 0);
        $observedAt = (string) ($checkpoint['observed_at'] ?? $this->collectionObservedAt());
        $rowsWritten = (int) ($checkpoint['rows_written_total'] ?? 0);

        if ($stepIndex >= count($steps)) {
            return $this->completedCounted(count($steps), count($steps), [
                'step_index' => $stepIndex,
                'observed_at' => $observedAt,
                'rows_written_total' => $rowsWritten,
                'sitemap_candidates' => $this->checkpointStringList($checkpoint['sitemap_candidates'] ?? []),
                'sitemap_files_checked' => (int) ($checkpoint['sitemap_files_checked'] ?? 0),
                'sitemap_urls_discovered' => (int) ($checkpoint['sitemap_urls_discovered'] ?? 0),
            ]);
        }

        $step = $steps[$stepIndex];
        $assetId = (int) $scope['asset']->id;
        $seed = (string) $scope['seed_url'];
        $rowsBefore = $rowsWritten;
        $checkpointExtra = [
            'sitemap_candidates' => $this->checkpointStringList($checkpoint['sitemap_candidates'] ?? []),
            'sitemap_files_checked' => (int) ($checkpoint['sitemap_files_checked'] ?? 0),
            'sitemap_urls_discovered' => (int) ($checkpoint['sitemap_urls_discovered'] ?? 0),
        ];

        if ($step === 'homepage') {
            $fetch = $this->fetchForCollection($seed);
            $rowsWritten += $this->persistPage($context, $assetId, $fetch, $observedAt, 'diagnosis_homepage', $seed, $seed);
        } elseif ($step === 'robots') {
            $robotsUrl = rtrim($this->origin($seed), '/').'/robots.txt';
            $fetch = $this->fetchForCollection($robotsUrl);
            $rowsWritten += $this->writeOne($context, 'website_http_snapshot', 'robots', $assetId, [
                $this->normalizer->httpSnapshot($assetId, $fetch, $observedAt),
            ], [$fetch], $robotsUrl);
            $checkpointExtra['sitemap_candidates'] = $this->extractRobotsSitemapUrls(
                is_string($fetch['body'] ?? null) ? $fetch['body'] : null,
                $seed,
            );
        } else {
            $candidates = $checkpointExtra['sitemap_candidates'];
            foreach (DiscoveryConfig::sitemapFallbackPaths() as $path) {
                $candidate = $this->urls->resolve($seed, $path);
                if ($candidate !== null && $this->urls->sameSite($seed, $candidate)) {
                    $candidates[] = $candidate;
                }
            }
            $candidates = array_values(array_unique($candidates));

            $inventory = $this->discoverSitemapInventory($seed, $candidates);
            $checkpointExtra['sitemap_candidates'] = $candidates;
            $checkpointExtra['sitemap_files_checked'] = count($inventory['documents']);
            $checkpointExtra['sitemap_urls_discovered'] = count($inventory['pages']);

            foreach ($inventory['documents'] as $index => $document) {
                $documentUrl = (string) $document['url'];
                /** @var array<string, mixed> $documentFetch */
                $documentFetch = $document['fetch'];
                $rowsWritten += $this->writeOne(
                    $context,
                    'website_http_snapshot',
                    'sitemap_doc_'.($index + 1),
                    $assetId,
                    [$this->normalizer->httpSnapshot($assetId, $documentFetch, $observedAt)],
                    [$documentFetch],
                    $documentUrl,
                );
            }

            foreach (array_chunk($inventory['pages'], 500) as $chunkIndex => $pageUrls) {
                $urlRecords = [];
                foreach ($pageUrls as $pageUrl) {
                    $normalized = $this->normalizer->normalizeUrl($pageUrl);
                    if ($normalized === null) {
                        continue;
                    }
                    $urlRecords[] = $this->normalizer->urlRecord($assetId, $normalized, 'sitemap', $observedAt);
                }
                if ($urlRecords === []) {
                    continue;
                }

                $rowsWritten += $this->writeOne(
                    $context,
                    'website_url',
                    'sitemap_urls_'.($chunkIndex + 1),
                    $assetId,
                    $urlRecords,
                    $pageUrls,
                    $this->origin($seed).'|sitemap-inventory|'.$chunkIndex,
                );
            }
        }

        $checkpointOut = array_merge([
            'step_index' => $stepIndex + 1,
            'observed_at' => $observedAt,
            'rows_written_total' => $rowsWritten,
        ], $checkpointExtra);
        $tickRows = $rowsWritten - $rowsBefore;

        if ($stepIndex + 1 >= count($steps)) {
            return $this->completedCounted(count($steps), count($steps), $checkpointOut, $tickRows, $tickRows, 1);
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $stepIndex + 1,
            progressTotal: count($steps),
            rowsReceived: $tickRows,
            rowsWritten: $tickRows,
            pagesCompleted: 1,
            checkpoint: $checkpointOut,
        );
    }

    /** @param array<string, mixed> $scope */
    private function executePublicCrawl(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $checkpoint = $context->checkpoint;
        $observedAt = (string) ($checkpoint['observed_at'] ?? $this->collectionObservedAt());
        $seed = (string) $scope['seed_url'];
        $targetedUrls = $this->targetedVerificationUrls($context, $seed);
        $targeted = $targetedUrls !== null;
        if ($targeted && $targetedUrls === []) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::InvalidRequest,
                'Targeted Website verification did not contain an eligible same-site URL.',
                'TARGETED_VERIFICATION_SCOPE_INVALID',
            );
        }
        $queue = is_array($checkpoint['queue'] ?? null)
            ? array_values(array_map('strval', $checkpoint['queue']))
            : ($targeted ? $targetedUrls : $this->crawlSeedQueue((int) $scope['asset']->id, $seed));
        $visited = is_array($checkpoint['visited'] ?? null) ? array_values(array_map('strval', $checkpoint['visited'])) : [];
        $pages = (int) ($checkpoint['pages'] ?? 0);
        $urlsPlanned = max((int) ($checkpoint['urls_planned'] ?? 0), count($queue) + count($visited));
        $rowsWritten = (int) ($checkpoint['rows_written_total'] ?? 0);
        $bytesDownloaded = (int) ($checkpoint['bytes_downloaded_total'] ?? 0);
        $assetId = (int) $scope['asset']->id;
        $maxPages = $targeted ? count($targetedUrls) : DiscoveryConfig::MAX_COLLECTION_PAGES;

        if ($queue === [] || $pages >= $maxPages || $bytesDownloaded >= DiscoveryConfig::MAX_COLLECTION_TOTAL_BYTES) {
            return $this->completedCounted($pages, $maxPages, $this->crawlCheckpoint(
                $observedAt, [], $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned,
            ));
        }

        $url = array_shift($queue);
        if ($url === null || in_array($url, $visited, true)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::PageBased,
                progressCurrent: $pages,
                progressTotal: $maxPages,
                checkpoint: $this->crawlCheckpoint($observedAt, $queue, $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned),
            );
        }

        $visited[] = $url;
        $fetch = $this->fetchForCollection($url);
        $bytesDownloaded += (int) ($fetch['bytes'] ?? 0);

        if ($bytesDownloaded > DiscoveryConfig::MAX_COLLECTION_TOTAL_BYTES) {
            return $this->completedCounted($pages, $maxPages, $this->crawlCheckpoint(
                $observedAt, $queue, $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned,
            ));
        }

        $written = $this->persistPage($context, $assetId, $fetch, $observedAt, 'public_crawl', $url, $seed);
        $pages++;
        $rowsWritten += $written;

        if (! $targeted && $this->pageAnalyzer->isInventoryEligible($fetch) && $pages < $maxPages) {
            $resolutionBase = is_string($fetch['final_url'] ?? null) && trim((string) $fetch['final_url']) !== ''
                ? (string) $fetch['final_url']
                : $url;
            foreach ($this->extractSameSiteHrefs((string) $fetch['body'], $seed, $resolutionBase) as $href) {
                if (! in_array($href, $visited, true) && ! in_array($href, $queue, true)) {
                    $queue[] = $href;
                }
            }
            $urlsPlanned = max($urlsPlanned, count($visited) + count($queue));
        }

        $checkpointOut = $this->crawlCheckpoint($observedAt, $queue, $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned);

        if ($queue === [] || $pages >= $maxPages || $bytesDownloaded >= DiscoveryConfig::MAX_COLLECTION_TOTAL_BYTES) {
            return $this->completedCounted($pages, $maxPages, $checkpointOut, $written, $written, 1);
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $pages,
            progressTotal: $maxPages,
            rowsReceived: $written,
            rowsWritten: $written,
            pagesCompleted: 1,
            checkpoint: $checkpointOut,
        );
    }

    /** @param array<string, mixed> $scope */
    private function executeDnsTls(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $observedAt = (string) ($context->checkpoint['observed_at'] ?? $this->collectionObservedAt());
        $host = (string) $scope['host'];
        $tls = $this->tls->probe($host, CarbonImmutable::parse($observedAt));
        $record = $this->normalizer->infraSnapshot((int) $scope['asset']->id, $host, $tls, $observedAt);
        $this->writeOne($context, 'website_infra_snapshot', 'tls', (int) $scope['asset']->id, [$record], [$tls], $host);

        return $this->completedCounted(1, 1, ['observed_at' => $observedAt, 'host' => $host], 1, 1);
    }

    /** @param array<string, mixed> $scope */
    private function executePagespeed(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $connection = $scope['pagespeed_connection'] ?? null;
        if (! $connection instanceof CoreConnection) {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authorization,
                'PageSpeed collection requires an enabled Website PageSpeed connection.',
                'PAGESPEED_CONNECTION_REQUIRED',
            );
        }

        $payload = $connection->credential?->encrypted_payload;
        $apiKey = is_array($payload) && isset($payload['api_key']) && is_string($payload['api_key']) ? trim($payload['api_key']) : '';
        if ($apiKey === '') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'PageSpeed connection is missing an API key.',
                'PAGESPEED_KEY_MISSING',
            );
        }

        $config = is_array($connection->config) ? $connection->config : [];
        $strategy = isset($config['strategy']) && is_string($config['strategy']) ? strtolower(trim($config['strategy'])) : 'mobile';
        if (! in_array($strategy, ['mobile', 'desktop'], true)) {
            $strategy = 'mobile';
        }
        $url = isset($config['url']) && is_string($config['url']) && trim($config['url']) !== '' ? trim($config['url']) : (string) $scope['seed_url'];
        $observedAt = (string) ($context->checkpoint['observed_at'] ?? $this->collectionObservedAt());

        $response = Http::timeout(60)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'MoxDOP-WebsiteCollector/1.0'])
            ->get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed', [
                'url' => $url,
                'strategy' => $strategy,
                'category' => 'performance',
                'key' => $apiKey,
            ]);

        if ($response->failed()) {
            return DatasetExecutionResult::retry(
                $response->status() >= 500 ? CollectionErrorCategory::Provider5xx : CollectionErrorCategory::InvalidRequest,
                'PageSpeed HTTP '.$response->status(),
                30,
                'PAGESPEED_HTTP',
            );
        }

        $body = $response->json();
        $lighthouse = is_array($body) && isset($body['lighthouseResult']) && is_array($body['lighthouseResult']) ? $body['lighthouseResult'] : [];
        $audits = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];
        $lab = [
            'final_url' => is_string($lighthouse['finalUrl'] ?? null) ? $lighthouse['finalUrl'] : $url,
            'fetch_time' => is_string($lighthouse['fetchTime'] ?? null) ? $lighthouse['fetchTime'] : null,
            'lcp_ms' => isset($audits['largest-contentful-paint']['numericValue']) && is_numeric($audits['largest-contentful-paint']['numericValue'])
                ? $audits['largest-contentful-paint']['numericValue'] : null,
            'lab_data' => $lighthouse !== [],
        ];
        $record = $this->normalizer->performanceMeasurement((int) $scope['asset']->id, $url, $strategy, $lab, $observedAt);
        $this->writeOne($context, 'website_performance_measurement', 'psi', (int) $scope['asset']->id, [$record], is_array($body) ? [$body] : [], $url);

        return $this->completedCounted(1, 1, ['observed_at' => $observedAt, 'strategy' => $strategy], 1, 1);
    }

    /** @param array<string, mixed> $fetch */
    private function persistPage(
        DatasetExecutionContext $context,
        int $assetId,
        array $fetch,
        string $observedAt,
        string $source,
        string $requestedUrl,
        string $siteSeed,
    ): int {
        $pageIdentity = $this->stablePageIdentity($requestedUrl, $fetch);
        $rowsWritten = 0;
        $compactRaw = [$this->compactFetch($fetch)];

        // HTTP evidence and crawl issues are retained even when the URL is not a valid page.
        $rowsWritten += $this->writeOne($context, 'website_http_snapshot', $source.'_http', $assetId, [
            $this->normalizer->httpSnapshot($assetId, $fetch, $observedAt),
        ], $compactRaw, $requestedUrl, $pageIdentity);

        $issues = $this->pageAnalyzer->issueSnapshots($assetId, $fetch, $observedAt);
        if ($issues !== []) {
            $rowsWritten += $this->writeOne(
                $context,
                'website_crawl_issue_snapshot',
                $source.'_issues',
                $assetId,
                $issues,
                $compactRaw,
                $requestedUrl,
                $pageIdentity,
            );
        }

        // Never promote HTTP failures, guessed paths, or soft/error templates into page inventory.
        if (! $this->pageAnalyzer->isInventoryEligible($fetch)) {
            return $rowsWritten;
        }

        $rowsWritten += $this->writeHtmlSnapshot($context, $assetId, $fetch, $observedAt, $source, $pageIdentity);

        $normalized = $this->normalizer->normalizeUrl((string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? $requestedUrl));
        if ($normalized !== null) {
            $rowsWritten += $this->writeOne($context, 'website_url', $source.'_url', $assetId, [
                $this->normalizer->urlRecord($assetId, $normalized, $source, $observedAt),
            ], $compactRaw, $requestedUrl, $pageIdentity);
        }

        [$metadata, $heading, $schema] = $this->normalizer->htmlSnapshots($assetId, $fetch, $observedAt);
        $rowsWritten += $this->writeOne($context, 'website_metadata_snapshot', $source.'_meta', $assetId, [$metadata], $compactRaw, $requestedUrl, $pageIdentity);
        $rowsWritten += $this->writeOne($context, 'website_heading_snapshot', $source.'_h1', $assetId, [$heading], $compactRaw, $requestedUrl, $pageIdentity);
        $rowsWritten += $this->writeOne($context, 'website_schema_snapshot', $source.'_schema', $assetId, [$schema], $compactRaw, $requestedUrl, $pageIdentity);

        $contentStats = $this->pageAnalyzer->contentStats($assetId, $fetch, $observedAt);
        if ($contentStats !== null) {
            $rowsWritten += $this->writeOne($context, 'website_content_stats', $source.'_content', $assetId, [$contentStats], $compactRaw, $requestedUrl, $pageIdentity);
        }

        $resolutionBase = is_string($fetch['final_url'] ?? null) && trim((string) $fetch['final_url']) !== ''
            ? (string) $fetch['final_url']
            : $requestedUrl;
        $edges = $this->pageAnalyzer->linkEdges($assetId, (string) $fetch['body'], $siteSeed, $resolutionBase, $observedAt);
        if ($edges !== []) {
            $rowsWritten += $this->writeOne($context, 'website_link_edge', $source.'_links', $assetId, $edges, $compactRaw, $requestedUrl, $pageIdentity);
        }

        return $rowsWritten;
    }

    /** @param array<string, mixed> $fetch */
    private function writeHtmlSnapshot(
        DatasetExecutionContext $context,
        int $assetId,
        array $fetch,
        string $observedAt,
        string $source,
        string $pageIdentity,
    ): int {
        $body = $fetch['body'] ?? null;
        if (! is_string($body) || $body === '') {
            return 0;
        }

        $url = $this->normalizer->normalizeUrl((string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? ''));
        if ($url === null) {
            return 0;
        }

        $previousHtmlHash = null;
        if (Schema::hasTable('website_html_snapshot')) {
            $previousHtmlHash = DB::table('website_html_snapshot')
                ->where('digital_asset_id', $assetId)
                ->where('url', $url)
                ->where('observed_at', '<', $observedAt)
                ->orderByDesc('observed_at')
                ->value('html_hash');
            $previousHtmlHash = is_string($previousHtmlHash) && $previousHtmlHash !== '' ? $previousHtmlHash : null;
        }

        $record = $this->normalizer->htmlSnapshot($assetId, $fetch, $observedAt, $previousHtmlHash);
        if ($record === null) {
            return 0;
        }

        $htmlHash = (string) $record['html_hash'];
        $batchKey = $this->pageBatchKey('website_html_snapshot', $source.'_html', $pageIdentity);
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'WEBSITE_DIRECT',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: 'website_html_snapshot',
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: (string) ($fetch['content_type'] ?? 'text/html'),
            payload: $body,
            providerRequestFingerprint: hash('sha256', $url.'|'.$htmlHash),
            recordCount: 1,
            providerSafeMetadata: [
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
                'digital_asset_id' => $assetId,
                'url_hash' => hash('sha256', $url),
                'html_hash' => $htmlHash,
                'change_state' => $record['change_state'],
            ],
            capturedAt: now(),
            retentionClass: 'website_html_version',
        );

        $receipt = $this->pipeline->commit(
            new NormalizedDatasetBatch(
                datasetId: 'website_html_snapshot',
                datasetRunId: (int) $context->datasetRun->id,
                contractVersion: (int) $context->datasetRun->contract_registry_version,
                batchKey: $batchKey,
                records: [$record],
                digitalAssetId: $assetId,
                externalResourceId: null,
                collectionRunId: (int) $context->collectionRun->id,
                resourceRunId: (int) $context->resourceRun->id,
                providerOrSource: 'WEBSITE_DIRECT',
            ),
            $envelope,
            rawRequired: true,
        );

        return $this->accountedRows($receipt, $batchKey);
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param list<mixed> $rawRows
     */
    private function writeOne(
        DatasetExecutionContext $context,
        string $datasetId,
        string $batchSuffix,
        int $assetId,
        array $records,
        array $rawRows,
        string $query,
        ?string $pageIdentity = null,
    ): int {
        if ($records === []) {
            return 0;
        }

        $identity = $pageIdentity ?? $this->stablePageIdentity($query);
        $batchKey = $this->pageBatchKey($datasetId, $batchSuffix, $identity);

        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'WEBSITE_DIRECT',
            collectionRunId: (int) $context->collectionRun->id,
            resourceRunId: (int) $context->resourceRun->id,
            datasetRunId: (int) $context->datasetRun->id,
            logicalDatasetId: $datasetId,
            requestFamilyId: $context->datasetRun->request_family_id,
            batchKey: $batchKey,
            contentType: 'application/json',
            payload: json_encode(['data' => $rawRows], JSON_THROW_ON_ERROR),
            providerRequestFingerprint: hash('sha256', $query.'|'.$datasetId.'|'.$batchSuffix.'|'.$identity),
            recordCount: count($records),
            providerSafeMetadata: [
                'collector_version' => WebsiteProviderCapabilities::COLLECTOR_VERSION,
                'request_family' => $context->datasetRun->request_family_id,
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
                providerOrSource: $context->datasetRun->provider_or_source,
            ),
            $envelope,
        );

        return $this->accountedRows($receipt, $batchKey);
    }

    private function accountedRows(WriteReceipt $receipt, string $expectedBatchKey): int
    {
        if (! $receipt->isCommitted()) {
            throw new \RuntimeException('Website write receipt not committed; checkpoint not advanced.');
        }

        if ($receipt->reusedExisting) {
            $existingKey = DatasetWriteBatch::query()->whereKey($receipt->writeBatchId)->value('batch_key');
            if ($existingKey !== $expectedBatchKey) {
                throw new \RuntimeException('Website warehouse skipped a distinct page via batch-key collision; checkpoint not advanced.');
            }
        }

        return $receipt->rowsReceived;
    }

    /** @param array<string, mixed>|null $fetch */
    private function stablePageIdentity(string $url, ?array $fetch = null): string
    {
        $candidate = $url;
        if ($fetch !== null) {
            $fromFetch = (string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? '');
            if ($fromFetch !== '') {
                $candidate = $fromFetch;
            }
        }

        $normalized = $this->normalizer->normalizeUrl($candidate);
        if ($normalized !== null) {
            return $normalized;
        }

        $fromUrl = $this->normalizer->normalizeUrl($url);

        return $fromUrl ?? $url;
    }

    private function pageBatchKey(string $datasetId, string $batchSuffix, string $pageIdentity): string
    {
        return 'website:'.$datasetId.':'.$batchSuffix.':url='.hash('sha256', $pageIdentity);
    }

    /** @return list<string> */
    private function crawlSeedQueue(int $assetId, string $seed): array
    {
        $candidates = [$this->urls->normalizeAbsolute($seed) ?? $seed];

        if (Schema::hasTable('website_cms_object_snapshot')) {
            $candidates = array_merge($candidates, DB::table('website_cms_object_snapshot')
                ->where('digital_asset_id', $assetId)
                ->where('status', 'publish')
                ->where('object_type', '!=', 'attachment')
                ->whereNotNull('permalink')
                ->select('permalink')
                ->distinct()
                ->orderBy('permalink')
                ->limit(DiscoveryConfig::MAX_COLLECTION_PAGES)
                ->pluck('permalink')
                ->map('strval')
                ->all());
        }

        if (Schema::hasTable('website_url')) {
            $candidates = array_merge($candidates, DB::table('website_url')
                ->where('digital_asset_id', $assetId)
                ->orderBy('normalized_url')
                ->limit(DiscoveryConfig::MAX_COLLECTION_PAGES)
                ->pluck('normalized_url')
                ->map('strval')
                ->all());
        }

        $sitemapCandidates = [];
        foreach (DiscoveryConfig::sitemapFallbackPaths() as $path) {
            $candidate = $this->urls->resolve($seed, $path);
            if ($candidate !== null && $this->urls->sameSite($seed, $candidate)) {
                $sitemapCandidates[] = $candidate;
            }
        }
        $sitemapInventory = $this->discoverSitemapInventory($seed, $sitemapCandidates);
        $candidates = array_merge($candidates, $sitemapInventory['pages']);

        $queue = [];
        foreach ($candidates as $candidate) {
            $normalized = $this->urls->normalizeAbsolute((string) $candidate);
            if ($normalized === null || ! $this->urls->sameSite($seed, $normalized)) {
                continue;
            }
            $queue[$normalized] = true;
            if (count($queue) >= DiscoveryConfig::MAX_COLLECTION_PAGES) {
                break;
            }
        }

        return array_keys($queue);
    }

    /** @return list<string>|null */
    private function targetedVerificationUrls(DatasetExecutionContext $context, string $seed): ?array
    {
        $urls = data_get($context->collectionRun->request_context, 'context.targeted_verification.urls');
        if (! is_array($urls)) {
            return null;
        }

        $validated = [];
        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $normalized = $this->urls->normalizeAbsolute($url);
            if ($normalized === null || ! $this->urls->sameSite($seed, $normalized)) {
                continue;
            }

            $validated[$normalized] = true;
        }

        return array_keys($validated);
    }

    /**
     * @param list<string> $queue
     * @param list<string> $visited
     * @return array<string, mixed>
     */
    private function crawlCheckpoint(
        string $observedAt,
        array $queue,
        array $visited,
        int $pages,
        int $rowsWritten,
        int $bytesDownloaded,
        int $urlsPlanned,
    ): array
    {
        return [
            'observed_at' => $observedAt,
            'queue' => array_values($queue),
            'visited' => array_values($visited),
            'pages' => $pages,
            'urls_planned' => max($urlsPlanned, count($queue) + count($visited)),
            'limit_reached' => ($pages >= DiscoveryConfig::MAX_COLLECTION_PAGES && $queue !== [])
                || $bytesDownloaded >= DiscoveryConfig::MAX_COLLECTION_TOTAL_BYTES,
            'rows_written_total' => $rowsWritten,
            'bytes_downloaded_total' => $bytesDownloaded,
        ];
    }

    /** @param array<string, mixed> $fetch @return array<string, mixed> */
    private function compactFetch(array $fetch): array
    {
        $body = $fetch['body'] ?? null;
        unset($fetch['body']);

        $fetch['body_sha256'] = is_string($body) ? hash('sha256', $body) : null;
        $fetch['body_bytes'] = is_string($body) ? strlen($body) : 0;
        $fetch['body_stored_in'] = is_string($body) && $body !== '' ? 'website_html_snapshot' : null;

        return $fetch;
    }

    /** @return array<string, mixed> */
    private function fetchForCollection(string $url): array
    {
        return $this->fetcher->fetch($url, DiscoveryConfig::MAX_COLLECTION_RESPONSE_BYTES);
    }

    private function collectionObservedAt(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
    }

    /** @return list<string> */
    private function extractSameSiteHrefs(string $html, string $seed, string $resolutionBase): array
    {
        preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches);
        $out = [];
        $base = trim($resolutionBase) !== '' ? $resolutionBase : $seed;
        foreach ($matches[1] ?? [] as $href) {
            $resolved = $this->urls->resolve($base, (string) $href);
            if ($resolved !== null && $this->urls->sameSite($seed, $resolved)) {
                $out[] = $resolved;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function extractRobotsSitemapUrls(?string $robots, string $seed): array
    {
        if ($robots === null || trim($robots) === '') {
            return [];
        }

        preg_match_all('/^\s*sitemap\s*:\s*(\S+)\s*$/im', $robots, $matches);
        $urls = [];
        foreach ($matches[1] ?? [] as $candidate) {
            $decoded = trim(html_entity_decode((string) $candidate, ENT_QUOTES | ENT_HTML5));
            $resolved = $this->urls->resolve($seed, $decoded);
            if ($resolved !== null && $this->urls->sameSite($seed, $resolved)) {
                $urls[] = $resolved;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param list<string> $candidates
     * @return array{pages: list<string>, documents: list<array{url: string, fetch: array<string, mixed>}>, bytes: int}
     */
    private function discoverSitemapInventory(string $seed, array $candidates): array
    {
        $queue = [];
        foreach ($candidates as $candidate) {
            $normalized = $this->urls->normalizeAbsolute($candidate);
            if ($normalized !== null && $this->urls->sameSite($seed, $normalized)) {
                $queue[] = ['url' => $normalized, 'depth' => 0];
            }
        }

        $visited = [];
        $pages = [];
        $documents = [];
        $bytes = 0;

        while ($queue !== []
            && count($visited) < DiscoveryConfig::MAX_SITEMAP_FILES
            && count($pages) < DiscoveryConfig::MAX_SITEMAP_URLS
            && $bytes < DiscoveryConfig::MAX_TOTAL_BYTES) {
            $item = array_shift($queue);
            if (! is_array($item)) {
                continue;
            }

            $url = (string) ($item['url'] ?? '');
            $depth = (int) ($item['depth'] ?? 0);
            if ($url === '' || isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            $fetch = $this->fetchForCollection($url);
            $bytes += (int) ($fetch['bytes'] ?? 0);
            $documents[] = ['url' => $url, 'fetch' => $fetch];

            if (($fetch['ok'] ?? false) !== true || ! is_string($fetch['body'] ?? null) || trim((string) $fetch['body']) === '') {
                continue;
            }

            $parsed = $this->parseSitemapDocument((string) $fetch['body']);
            if ($parsed['type'] === 'index') {
                if ($depth >= DiscoveryConfig::MAX_SITEMAP_DEPTH) {
                    continue;
                }
                foreach ($parsed['locs'] as $child) {
                    $normalized = $this->urls->normalizeAbsolute($child);
                    if ($normalized === null || ! $this->urls->sameSite($seed, $normalized) || isset($visited[$normalized])) {
                        continue;
                    }
                    $queue[] = ['url' => $normalized, 'depth' => $depth + 1];
                }

                continue;
            }

            if ($parsed['type'] !== 'urlset') {
                continue;
            }

            foreach ($parsed['locs'] as $pageUrl) {
                $normalized = $this->urls->normalizeAbsolute($pageUrl);
                if ($normalized === null || ! $this->urls->sameSite($seed, $normalized)) {
                    continue;
                }
                $pages[$normalized] = true;
                if (count($pages) >= DiscoveryConfig::MAX_SITEMAP_URLS) {
                    break;
                }
            }
        }

        return [
            'pages' => array_keys($pages),
            'documents' => $documents,
            'bytes' => $bytes,
        ];
    }

    /** @return array{type: 'index'|'urlset'|null, locs: list<string>} */
    private function parseSitemapDocument(string $xml): array
    {
        $trimmed = ltrim($xml);
        $type = match (true) {
            preg_match('/<sitemapindex\b/i', $trimmed) === 1 => 'index',
            preg_match('/<urlset\b/i', $trimmed) === 1 => 'urlset',
            default => null,
        };

        if ($type === null) {
            return ['type' => null, 'locs' => []];
        }

        $limit = $type === 'index' ? DiscoveryConfig::MAX_SITEMAP_FILES : DiscoveryConfig::MAX_SITEMAP_URLS;

        return ['type' => $type, 'locs' => $this->extractSitemapLocs($xml, $limit)];
    }

    /** @param mixed $value @return list<string> */
    private function checkpointStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($item): string => is_scalar($item) ? trim((string) $item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /** @return list<string> */
    private function extractSitemapLocs(?string $xml, int $limit): array
    {
        if ($xml === null || trim($xml) === '') {
            return [];
        }
        preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $xml, $matches);
        $out = [];
        foreach ($matches[1] ?? [] as $loc) {
            $url = trim(html_entity_decode((string) $loc, ENT_QUOTES | ENT_HTML5));
            if ($url !== '') {
                $out[] = $url;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
    }

    /** @param array<string, mixed> $checkpoint */
    private function completedCounted(int $current, int $total, array $checkpoint, int $rowsReceived = 0, int $rowsWritten = 0, int $pagesCompleted = 0): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Completed,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $current,
            progressTotal: $total,
            rowsReceived: $rowsReceived,
            rowsWritten: $rowsWritten,
            pagesCompleted: $pagesCompleted,
            checkpoint: $checkpoint,
        );
    }
}
