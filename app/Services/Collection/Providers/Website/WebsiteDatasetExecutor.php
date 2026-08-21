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
use MoxDop\Website\Discovery\DiscoveryConfig;
use MoxDop\Website\Discovery\PublicHttpFetcher;
use MoxDop\Website\Discovery\PublicUrlNormalizer;
use Throwable;

/**
 * Production Website DatasetExecutor — Registry WEB_RF_* COLLECTION_READY families.
 * Reuses public HTTP fetcher, head/canonical parsers, and TLS probe. No Evidence, Findings, or CMS writes.
 */
final class WebsiteDatasetExecutor implements DatasetExecutor
{
    public function __construct(
        private readonly WebsiteEligibilityGuard $eligibility,
        private readonly WebsiteNormalizer $normalizer,
        private readonly WebsiteProviderErrorMapper $errors,
        private readonly DatasetWritePipeline $pipeline,
        private readonly RawPayloadWriter $rawWriter,
        private readonly PublicHttpFetcher $fetcher = new PublicHttpFetcher,
        private readonly PublicUrlNormalizer $urls = new PublicUrlNormalizer,
        private readonly SslCertificateProbe $tls = new SslCertificateProbe,
    ) {}

    public function supportedRequestFamilies(): array
    {
        return WebsiteRequestFamilyCatalog::supportedFamilies();
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

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeHttpHtmlDiagnosis(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $steps = ['homepage', 'robots', 'sitemap'];
        $checkpoint = $context->checkpoint;
        $stepIndex = (int) ($checkpoint['step_index'] ?? 0);
        $observedAt = (string) ($checkpoint['observed_at'] ?? CarbonImmutable::now('UTC')->toDateTimeString());
        $rowsWritten = (int) ($checkpoint['rows_written_total'] ?? 0);

        if ($stepIndex >= count($steps)) {
            return $this->completedCounted(count($steps), count($steps), [
                'step_index' => $stepIndex,
                'observed_at' => $observedAt,
                'rows_written_total' => $rowsWritten,
            ]);
        }

        $step = $steps[$stepIndex];
        $assetId = (int) $scope['asset']->id;
        $seed = (string) $scope['seed_url'];
        $rowsBefore = $rowsWritten;

        if ($step === 'homepage') {
            $fetch = $this->fetcher->fetch($seed);
            $rowsWritten += $this->persistPage($context, $assetId, $fetch, $observedAt, 'diagnosis_homepage', $seed);
        } elseif ($step === 'robots') {
            $robotsUrl = rtrim($this->origin($seed), '/').'/robots.txt';
            $fetch = $this->fetcher->fetch($robotsUrl);
            $rowsWritten += $this->writeOne($context, 'website_http_snapshot', 'robots', $assetId, [
                $this->normalizer->httpSnapshot($assetId, $fetch, $observedAt),
            ], [$fetch], $robotsUrl);
        } else {
            $sitemapUrl = rtrim($this->origin($seed), '/').'/sitemap.xml';
            $fetch = $this->fetcher->fetch($sitemapUrl);
            $rowsWritten += $this->writeOne($context, 'website_http_snapshot', 'sitemap', $assetId, [
                $this->normalizer->httpSnapshot($assetId, $fetch, $observedAt),
            ], [$fetch], $sitemapUrl);
            $locs = $this->extractSitemapLocs(is_string($fetch['body'] ?? null) ? $fetch['body'] : null, 20);
            $urlRecords = [];
            foreach ($locs as $loc) {
                $normalized = $this->normalizer->normalizeUrl($loc);
                if ($normalized === null) {
                    continue;
                }
                $urlRecords[] = $this->normalizer->urlRecord($assetId, $normalized, 'sitemap', $observedAt);
            }
            if ($urlRecords !== []) {
                $rowsWritten += $this->writeOne($context, 'website_url', 'sitemap_urls', $assetId, $urlRecords, $locs, $sitemapUrl);
            }
        }

        $checkpointOut = [
            'step_index' => $stepIndex + 1,
            'observed_at' => $observedAt,
            'rows_written_total' => $rowsWritten,
        ];
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

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executePublicCrawl(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $checkpoint = $context->checkpoint;
        $observedAt = (string) ($checkpoint['observed_at'] ?? CarbonImmutable::now('UTC')->toDateTimeString());
        $seed = (string) $scope['seed_url'];
        $queue = is_array($checkpoint['queue'] ?? null) ? array_values(array_map('strval', $checkpoint['queue'])) : $this->seedQueue($seed);
        $visited = is_array($checkpoint['visited'] ?? null) ? array_values(array_map('strval', $checkpoint['visited'])) : [];
        $pages = (int) ($checkpoint['pages'] ?? 0);
        $rowsWritten = (int) ($checkpoint['rows_written_total'] ?? 0);
        $bytesDownloaded = (int) ($checkpoint['bytes_downloaded_total'] ?? 0);
        $assetId = (int) $scope['asset']->id;
        $maxPages = DiscoveryConfig::MAX_PAGES;

        if ($queue === [] || $pages >= $maxPages || $bytesDownloaded >= DiscoveryConfig::MAX_TOTAL_BYTES) {
            return $this->completedCounted($pages, $maxPages, $this->crawlCheckpoint(
                $observedAt,
                [],
                $visited,
                $pages,
                $rowsWritten,
                $bytesDownloaded,
            ));
        }

        $url = array_shift($queue);
        if ($url === null || in_array($url, $visited, true)) {
            return new DatasetExecutionResult(
                outcome: DatasetExecutionOutcome::Continue,
                progressMode: ProgressMode::PageBased,
                progressCurrent: $pages,
                progressTotal: $maxPages,
                checkpoint: $this->crawlCheckpoint(
                    $observedAt,
                    $queue,
                    $visited,
                    $pages,
                    $rowsWritten,
                    $bytesDownloaded,
                ),
            );
        }

        $visited[] = $url;
        $fetch = $this->fetcher->fetch($url);
        $bytesDownloaded += (int) ($fetch['bytes'] ?? 0);

        if ($bytesDownloaded > DiscoveryConfig::MAX_TOTAL_BYTES) {
            return $this->completedCounted($pages, $maxPages, $this->crawlCheckpoint(
                $observedAt,
                $queue,
                $visited,
                $pages,
                $rowsWritten,
                $bytesDownloaded,
            ));
        }

        $written = $this->persistPage($context, $assetId, $fetch, $observedAt, 'public_crawl', $url);
        $pages++;
        $rowsWritten += $written;

        if ($fetch['ok'] && is_string($fetch['body'] ?? null) && $pages < $maxPages) {
            $resolutionBase = is_string($fetch['final_url'] ?? null) && trim((string) $fetch['final_url']) !== ''
                ? (string) $fetch['final_url']
                : $url;
            foreach ($this->extractSameSiteHrefs((string) $fetch['body'], $seed, $resolutionBase) as $href) {
                if (! in_array($href, $visited, true) && ! in_array($href, $queue, true)) {
                    $queue[] = $href;
                }
            }
        }

        $checkpointOut = $this->crawlCheckpoint(
            $observedAt,
            $queue,
            $visited,
            $pages,
            $rowsWritten,
            $bytesDownloaded,
        );

        if ($queue === [] || $pages >= $maxPages || $bytesDownloaded >= DiscoveryConfig::MAX_TOTAL_BYTES) {
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

    /**
     * @param  array<string, mixed>  $scope
     */
    private function executeDnsTls(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $observedAt = (string) ($context->checkpoint['observed_at'] ?? CarbonImmutable::now('UTC')->toDateTimeString());
        $host = (string) $scope['host'];
        $tls = $this->tls->probe($host, CarbonImmutable::parse($observedAt));
        $record = $this->normalizer->infraSnapshot((int) $scope['asset']->id, $host, $tls, $observedAt);
        $this->writeOne($context, 'website_infra_snapshot', 'tls', (int) $scope['asset']->id, [$record], [$tls], $host);

        return $this->completedCounted(1, 1, ['observed_at' => $observedAt, 'host' => $host], 1, 1);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
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
        $apiKey = is_array($payload) && isset($payload['api_key']) && is_string($payload['api_key'])
            ? trim($payload['api_key'])
            : '';
        if ($apiKey === '') {
            return DatasetExecutionResult::failed(
                CollectionErrorCategory::Authentication,
                'PageSpeed connection is missing an API key.',
                'PAGESPEED_KEY_MISSING',
            );
        }

        $config = is_array($connection->config) ? $connection->config : [];
        $strategy = isset($config['strategy']) && is_string($config['strategy'])
            ? strtolower(trim($config['strategy']))
            : 'mobile';
        if (! in_array($strategy, ['mobile', 'desktop'], true)) {
            $strategy = 'mobile';
        }
        $url = isset($config['url']) && is_string($config['url']) && trim($config['url']) !== ''
            ? trim($config['url'])
            : (string) $scope['seed_url'];
        $observedAt = (string) ($context->checkpoint['observed_at'] ?? CarbonImmutable::now('UTC')->toDateTimeString());

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
        $lighthouse = is_array($body) && isset($body['lighthouseResult']) && is_array($body['lighthouseResult'])
            ? $body['lighthouseResult']
            : [];
        $audits = is_array($lighthouse['audits'] ?? null) ? $lighthouse['audits'] : [];
        $lab = [
            'final_url' => is_string($lighthouse['finalUrl'] ?? null) ? $lighthouse['finalUrl'] : $url,
            'fetch_time' => is_string($lighthouse['fetchTime'] ?? null) ? $lighthouse['fetchTime'] : null,
            'lcp_ms' => isset($audits['largest-contentful-paint']['numericValue']) && is_numeric($audits['largest-contentful-paint']['numericValue'])
                ? $audits['largest-contentful-paint']['numericValue']
                : null,
            'lab_data' => $lighthouse !== [],
        ];
        $record = $this->normalizer->performanceMeasurement((int) $scope['asset']->id, $url, $strategy, $lab, $observedAt);
        $this->writeOne($context, 'website_performance_measurement', 'psi', (int) $scope['asset']->id, [$record], is_array($body) ? [$body] : [], $url);

        return $this->completedCounted(1, 1, ['observed_at' => $observedAt, 'strategy' => $strategy], 1, 1);
    }

    /**
     * @param  array<string, mixed>  $fetch
     */
    private function persistPage(
        DatasetExecutionContext $context,
        int $assetId,
        array $fetch,
        string $observedAt,
        string $source,
        string $requestedUrl,
    ): int {
        $pageIdentity = $this->stablePageIdentity($requestedUrl, $fetch);
        $rowsWritten = 0;
        $normalized = $this->normalizer->normalizeUrl((string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? $requestedUrl));
        if ($normalized !== null) {
            $rowsWritten += $this->writeOne($context, 'website_url', $source.'_url', $assetId, [
                $this->normalizer->urlRecord($assetId, $normalized, $source, $observedAt),
            ], [$fetch], $requestedUrl, $pageIdentity);
        }
        $httpRows = $this->writeOne($context, 'website_http_snapshot', $source.'_http', $assetId, [
            $this->normalizer->httpSnapshot($assetId, $fetch, $observedAt),
        ], [$fetch], $requestedUrl, $pageIdentity);
        $rowsWritten += $httpRows;
        if (($fetch['ok'] ?? false) === true && is_string($fetch['body'] ?? null)) {
            [$metadata, $heading, $schema] = $this->normalizer->htmlSnapshots($assetId, $fetch, $observedAt);
            $rowsWritten += $this->writeOne($context, 'website_metadata_snapshot', $source.'_meta', $assetId, [$metadata], [$fetch], $requestedUrl, $pageIdentity);
            $rowsWritten += $this->writeOne($context, 'website_heading_snapshot', $source.'_h1', $assetId, [$heading], [$fetch], $requestedUrl, $pageIdentity);
            $rowsWritten += $this->writeOne($context, 'website_schema_snapshot', $source.'_schema', $assetId, [$schema], [$fetch], $requestedUrl, $pageIdentity);
        }

        return $rowsWritten;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  list<mixed>  $rawRows
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

    /**
     * @param  array<string, mixed>|null  $fetch
     */
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

    /**
     * @return list<string>
     */
    private function seedQueue(string $seed): array
    {
        $queue = [$seed];
        foreach (DiscoveryConfig::preferredPathHints() as $hint) {
            if ($hint === '/') {
                continue;
            }
            $candidate = $this->urls->resolve($seed, $hint);
            if ($candidate !== null && $this->urls->sameSite($seed, $candidate)) {
                $queue[] = $candidate;
            }
        }

        return array_values(array_unique($queue));
    }

    /**
     * @param  list<string>  $queue
     * @param  list<string>  $visited
     * @return array<string, mixed>
     */
    private function crawlCheckpoint(
        string $observedAt,
        array $queue,
        array $visited,
        int $pages,
        int $rowsWritten,
        int $bytesDownloaded,
    ): array {
        return [
            'observed_at' => $observedAt,
            'queue' => array_values($queue),
            'visited' => array_values($visited),
            'pages' => $pages,
            'rows_written_total' => $rowsWritten,
            'bytes_downloaded_total' => $bytesDownloaded,
        ];
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return list<string>
     */
    private function extractSitemapLocs(?string $xml, int $limit): array
    {
        if ($xml === null || trim($xml) === '') {
            return [];
        }
        preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $xml, $matches);
        $out = [];
        foreach ($matches[1] ?? [] as $loc) {
            $url = trim(html_entity_decode((string) $loc));
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

    /**
     * @param  array<string, mixed>  $checkpoint
     */
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
