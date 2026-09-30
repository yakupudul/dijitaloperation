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
use App\Services\DataPool\WebsitePageStateStore;
use App\Services\SeoTasks\SeoText;
use App\Support\SslCertificateProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    /** WordPress object types that are not pages a visitor lands on. */
    public const NON_PAGE_CMS_TYPES = ['attachment', 'elementor_library', 'e-landing-page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'nav_menu_item', 'revision', 'custom_css', 'oembed_cache', 'wp_global_styles', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'jet-theme-core', 'jet-engine', 'ct_template', 'fl-builder-template', 'et_pb_layout'];

    /** Re-fetch pages without a modified date after this many days. */
    private const UNKNOWN_RECHECK_DAYS = 7;

    /** Re-fetch every page at least this often, whatever its modified date says. */
    private const MAX_RECHECK_DAYS = 30;

    /** Upper bound of URLs one crawl step fetches; the real size comes from WebsiteCrawlPoliteness (default 6). */
    public const CRAWL_BATCH_SIZE = 15;

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
        private readonly WebsitePageStateStore $pageState = new WebsitePageStateStore,
        private readonly WebsiteCrawlPoliteness $politeness = new WebsiteCrawlPoliteness,
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

    /**
     * "Nazik mod": the crawl waits while the site is backing off and runs one step per host at a time.
     *
     * @param  array<string, mixed>  $scope
     */
    private function executePublicCrawl(DatasetExecutionContext $context, array $scope): DatasetExecutionResult
    {
        $host = $this->politeness->host((string) $scope['seed_url']);
        $crawlDelay = isset($context->checkpoint['robots_crawl_delay']) ? (int) $context->checkpoint['robots_crawl_delay'] : null;
        $wait = $this->politeness->waitSeconds($host);
        if ($wait > 0) {
            return $this->politeWait($context->checkpoint, $wait, $this->politeness->view($host, $crawlDelay));
        }
        $lock = $this->politeness->lock($host);
        if ($lock === null) {
            // Another worker is fetching from this site right now.
            return $this->politeWait($context->checkpoint, 30, $this->politeness->view($host, $crawlDelay));
        }

        try {
            return $this->crawlStep($context, $scope, $host);
        } finally {
            $lock->release();
        }
    }

    /**
     * Keeps the checkpoint as it is and comes back later.
     *
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $politeness
     */
    private function politeWait(array $checkpoint, int $seconds, array $politeness): DatasetExecutionResult
    {
        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            stage: 'polite_wait',
            checkpoint: array_merge($checkpoint, ['politeness' => $politeness]),
            backoffSeconds: max(1, $seconds),
        );
    }

    /** @param array<string, mixed> $scope */
    private function crawlStep(DatasetExecutionContext $context, array $scope, string $host): DatasetExecutionResult
    {
        $checkpoint = $context->checkpoint;
        $crawlDelay = isset($checkpoint['robots_crawl_delay']) ? (int) $checkpoint['robots_crawl_delay'] : null;
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
        $skippedUnchanged = (int) ($checkpoint['skipped_unchanged'] ?? 0);
        if (is_array($checkpoint['queue'] ?? null)) {
            $queue = array_values(array_map('strval', $checkpoint['queue']));
            $visited = is_array($checkpoint['visited'] ?? null) ? array_values(array_map('strval', $checkpoint['visited'])) : [];
        } elseif ($targeted) {
            $queue = $targetedUrls;
            $visited = [];
        } else {
            // `refetch_unchanged` (default: force_refresh) — a manual collection re-fetches only changed pages.
            $requestContext = (array) $context->collectionRun->request_context;
            // robots.txt Crawl-delay slows this crawl down further (one page per step, that many seconds apart).
            $robots = $this->fetchForCollection(rtrim($this->origin($seed), '/').'/robots.txt');
            $crawlDelay = $this->politeness->robotsCrawlDelay(($robots['ok'] ?? false) && is_string($robots['body'] ?? null) ? $robots['body'] : null);
            $seedQueue = $this->crawlSeedQueue((int) $scope['asset']->id, $seed, (bool) ($requestContext['refetch_unchanged'] ?? $requestContext['force_refresh'] ?? false));
            $queue = $seedQueue['queue'];
            // Pages whose modified date says they did not change since the last fetch keep their stored copy.
            $visited = $seedQueue['unchanged'];
            $skippedUnchanged = count($seedQueue['unchanged']);
        }
        $pages = (int) ($checkpoint['pages'] ?? 0);
        $urlsPlanned = max((int) ($checkpoint['urls_planned'] ?? 0), count($queue) + count($visited) - $skippedUnchanged);
        $rowsWritten = (int) ($checkpoint['rows_written_total'] ?? 0);
        $bytesDownloaded = (int) ($checkpoint['bytes_downloaded_total'] ?? 0);
        $assetId = (int) $scope['asset']->id;
        $maxPages = $targeted ? count($targetedUrls) : DiscoveryConfig::MAX_COLLECTION_PAGES;
        $politenessExtra = ['robots_crawl_delay' => $crawlDelay, 'politeness' => $this->politeness->view($host, $crawlDelay)];

        if ($queue === [] || $pages >= $maxPages || $bytesDownloaded >= DiscoveryConfig::MAX_COLLECTION_TOTAL_BYTES) {
            return $this->completedCounted($pages, $maxPages, $this->crawlCheckpoint(
                $observedAt, [], $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned, $skippedUnchanged, $politenessExtra,
            ));
        }

        // One step fetches a small batch, a few URLs at a time (WebsiteCrawlPoliteness). The checkpoint only advances
        // after every page of the batch is stored; a retried step fetches the same batch again and its per-page batch
        // keys are reused.
        $batchSize = min(self::CRAWL_BATCH_SIZE, $this->politeness->batchSize($host, $crawlDelay));
        $batch = [];
        while ($queue !== [] && count($batch) < min($batchSize, $maxPages - $pages)) {
            $candidate = (string) array_shift($queue);
            if ($candidate !== '' && ! in_array($candidate, $visited, true) && ! in_array($candidate, $batch, true)) {
                $batch[] = $candidate;
            }
        }
        if ($batch === []) {
            return $this->completedCounted($pages, $maxPages, $this->crawlCheckpoint(
                $observedAt, $queue, $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned, $skippedUnchanged, $politenessExtra,
            ));
        }

        $fetches = $this->fetcher->fetchMany($batch, DiscoveryConfig::MAX_COLLECTION_RESPONSE_BYTES, $this->politeness->concurrency($host, $crawlDelay));
        $distress = $this->politeness->distress($fetches);
        if ($distress !== null) {
            $backoff = $this->politeness->backOff($host, $distress, count($batch));
            if ($backoff['give_up']) {
                $this->politeness->forget($host);

                return DatasetExecutionResult::failed(
                    CollectionErrorCategory::Provider5xx,
                    'Site uzun süredir yanıt veremiyor ('.self::distressLabel($distress).'); çekim durduruldu, site düzelince yeniden başlatın.',
                    'WEBSITE_HOST_STRUGGLING',
                );
            }
            if (! $backoff['skip_page']) {
                // Nothing of this batch is stored: the same pages are fetched again, one at a time, after the wait.
                $politenessExtra['politeness'] = $this->politeness->view($host, $crawlDelay);

                return new DatasetExecutionResult(
                    outcome: DatasetExecutionOutcome::Continue,
                    progressMode: ProgressMode::PageBased,
                    progressCurrent: $pages,
                    progressTotal: $maxPages,
                    stage: 'polite_backoff',
                    checkpoint: $this->crawlCheckpoint(
                        $observedAt, array_values(array_merge($batch, $queue)), $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned, $skippedUnchanged, $politenessExtra,
                    ),
                    backoffSeconds: $backoff['wait_seconds'],
                );
            }
        } else {
            $this->politeness->recovered($host);
        }
        $politenessExtra['politeness'] = $this->politeness->view($host, $crawlDelay);
        // A site with the WordPress Connector: its page list comes from WordPress (+ sitemap); links are not followed.
        $followLinks = ! $targeted && ! $this->hasCmsInventory($assetId);
        $written = 0;
        $pagesThisStep = 0;
        $limitReached = false;

        foreach ($batch as $index => $url) {
            $fetch = $fetches[$url] ?? $this->fetchForCollection($url);
            $visited[] = $url;
            $bytesDownloaded += (int) ($fetch['bytes'] ?? 0);

            if ($bytesDownloaded > DiscoveryConfig::MAX_COLLECTION_TOTAL_BYTES) {
                // The page that crosses the aggregate byte limit is not stored; the rest of the batch is left unvisited.
                $queue = array_values(array_merge(array_slice($batch, $index + 1), $queue));
                $limitReached = true;

                break;
            }

            $pageRows = $this->persistPage($context, $assetId, $fetch, $observedAt, 'public_crawl', $url, $seed);
            $pages++;
            $pagesThisStep++;
            $written += $pageRows;
            $rowsWritten += $pageRows;

            if ($followLinks && $this->pageAnalyzer->isInventoryEligible($fetch) && $pages < $maxPages) {
                $resolutionBase = is_string($fetch['final_url'] ?? null) && trim((string) $fetch['final_url']) !== ''
                    ? (string) $fetch['final_url']
                    : $url;
                foreach ($this->extractSameSiteHrefs((string) $fetch['body'], $seed, $resolutionBase) as $href) {
                    if (! in_array($href, $visited, true) && ! in_array($href, $queue, true) && ! in_array($href, $batch, true)) {
                        $queue[] = $href;
                    }
                }
                $urlsPlanned = max($urlsPlanned, count($visited) + count($queue));
            }
        }

        $checkpointOut = $this->crawlCheckpoint($observedAt, $queue, $visited, $pages, $rowsWritten, $bytesDownloaded, $urlsPlanned, $skippedUnchanged, $politenessExtra);

        if ($limitReached || $queue === [] || $pages >= $maxPages || $bytesDownloaded >= DiscoveryConfig::MAX_COLLECTION_TOTAL_BYTES) {
            return $this->completedCounted($pages, $maxPages, $checkpointOut, $written, $written, $pagesThisStep);
        }

        return new DatasetExecutionResult(
            outcome: DatasetExecutionOutcome::Continue,
            progressMode: ProgressMode::PageBased,
            progressCurrent: $pages,
            progressTotal: $maxPages,
            rowsReceived: $written,
            rowsWritten: $written,
            pagesCompleted: $pagesThisStep,
            checkpoint: $checkpointOut,
            // A short pause before the next step of the same site.
            backoffSeconds: $this->politeness->stepDelay($crawlDelay),
        );
    }

    /** Operator wording of why the site is being given a break. */
    public static function distressLabel(string $reason): string
    {
        return match ($reason) {
            'database' => 'veritabanı bağlantı hatası',
            'rate_limited' => 'çok fazla istek (429)',
            'unavailable' => 'geçici olarak hizmet dışı (503)',
            'gateway' => 'sunucu geç yanıt veriyor (502/504)',
            'timeout' => 'zaman aşımı',
            'server_error' => 'sunucu hatası (500)',
            default => 'yavaş yanıt',
        };
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
            // Real-user Core Web Vitals (Chrome UX Report) that PageSpeed returns with the same call: the page's
            // own data when it has enough traffic, otherwise the whole site's (origin).
            'field' => $this->fieldVitals(is_array($body) ? $body : []),
        ];
        $record = $this->normalizer->performanceMeasurement((int) $scope['asset']->id, $url, $strategy, $lab, $observedAt);
        $this->writeOne($context, 'website_performance_measurement', 'psi', (int) $scope['asset']->id, [$record], is_array($body) ? [$body] : [], $url);

        return $this->completedCounted(1, 1, ['observed_at' => $observedAt, 'strategy' => $strategy], 1, 1);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{scope: string, category: ?string, lcp_ms: ?int, inp_ms: ?int, cls: ?float}|null
     */
    private function fieldVitals(array $body): ?array
    {
        foreach (['loadingExperience' => 'page', 'originLoadingExperience' => 'origin'] as $key => $scope) {
            $metrics = $body[$key]['metrics'] ?? null;
            if (! is_array($metrics) || $metrics === []) {
                continue;
            }
            $value = static fn (string $metric): ?float => is_numeric($metrics[$metric]['percentile'] ?? null) ? (float) $metrics[$metric]['percentile'] : null;
            $cls = $value('CUMULATIVE_LAYOUT_SHIFT_SCORE');

            return [
                'scope' => $scope,
                'category' => is_string($body[$key]['overall_category'] ?? null) ? $body[$key]['overall_category'] : null,
                'lcp_ms' => ($lcp = $value('LARGEST_CONTENTFUL_PAINT_MS')) !== null ? (int) $lcp : null,
                'inp_ms' => ($inp = $value('INTERACTION_TO_NEXT_PAINT')) !== null ? (int) $inp : null,
                // CrUX reports CLS × 100 as the percentile.
                'cls' => $cls !== null ? round($cls / 100, 2) : null,
            ];
        }

        return null;
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
        // Latest state: a page whose response did not change since its stored copy is not written again.
        if ($this->keepUnchangedPage($context, $assetId, $fetch, $observedAt)) {
            return 0;
        }

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
        // A page's links are replaced, not appended: the edges of its earlier fetches are removed once the current
        // ones are stored (after the write, so a retried step never loses edges it already committed).
        $this->pageState->dropEarlierLinkEdges($assetId, $this->urls->normalizeAbsolute($resolutionBase) ?? $resolutionBase, $observedAt);

        return $rowsWritten;
    }

    /**
     * True when the page answered exactly as its latest stored copy (same status, final URL, error and body hash).
     * Its stored rows in every per-page table are then moved to this observation (observed_at, last run), so
     * readers that take each page's latest row stay current without a new copy being appended.
     *
     * @param  array<string, mixed>  $fetch
     */
    private function keepUnchangedPage(DatasetExecutionContext $context, int $assetId, array $fetch, string $observedAt): bool
    {
        $httpUrl = (string) ($fetch['requested_url'] ?? $fetch['final_url'] ?? '');
        // Nothing stored yet, or this observation already wrote the page (a retried step): write it normally,
        // the per-page batch keys keep that idempotent.
        $stored = $this->pageState->latestResponse($assetId, $httpUrl, $observedAt);
        if ($stored === null) {
            return false;
        }
        foreach (['status_code', 'final_url', 'error'] as $key) {
            if ((string) ($stored[$key] ?? '') !== (string) ($fetch[$key] ?? '')) {
                return false;
            }
        }

        $final = (string) ($fetch['final_url'] ?? $fetch['requested_url'] ?? '');
        $body = is_string($fetch['body'] ?? null) && $fetch['body'] !== '' ? $fetch['body'] : null;
        $bodyHash = $body !== null ? hash('sha256', $body) : null;
        if (array_key_exists('body_sha256', $stored)) {
            if ($stored['body_sha256'] !== $bodyHash) {
                return false;
            }
        } elseif ($bodyHash !== null) {
            // Rows stored before the body hash was recorded: compare with the stored HTML copy.
            $htmlUrl = $this->normalizer->normalizeUrl($final);
            $storedHash = $htmlUrl !== null ? $this->pageState->latestHtmlHash($assetId, $htmlUrl) : null;
            if ($storedHash === null || ! hash_equals($storedHash, $bodyHash)) {
                return false;
            }
        }

        $urls = array_values(array_unique(array_filter([
            $httpUrl,
            $final,
            $this->normalizer->normalizeUrl($final),
            $this->urls->normalizeAbsolute($final),
        ], static fn ($url): bool => is_string($url) && $url !== '')));
        $this->pageState->touchUnchangedPage($assetId, $urls, $observedAt, (int) $context->collectionRun->id, (int) $context->datasetRun->id);

        return true;
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
        $previousSemanticMetadata = null;
        if (Schema::hasTable('website_html_snapshot')) {
            $previous = DB::table('website_html_snapshot')
                ->where('digital_asset_id', $assetId)
                ->where('url', $url)
                ->where('observed_at', '<', $observedAt)
                ->orderByDesc('observed_at')
                ->first(['html_hash', 'metadata']);
            $previousHtmlHash = $previous?->html_hash;
            $previousHtmlHash = is_string($previousHtmlHash) && $previousHtmlHash !== '' ? $previousHtmlHash : null;
            $previousSemanticMetadata = $this->jsonArray($previous?->metadata);
        }

        $record = $this->normalizer->htmlSnapshot(
            $assetId,
            $fetch,
            $observedAt,
            $previousHtmlHash,
            $previousSemanticMetadata,
        );
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
                'semantic_hash' => data_get($record, 'metadata.semantic_hash'),
                'semantic_change_state' => data_get($record, 'metadata.semantic_change_state'),
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

    private function hasCmsInventory(int $assetId): bool
    {
        return Schema::hasTable('website_cms_object_snapshot')
            && DB::table('website_cms_object_snapshot')->where('digital_asset_id', $assetId)->whereNotNull('permalink')->exists();
    }

    /**
     * The crawl queue. Only SEO pages are queued (no media, feeds, tag/author archives or
     * page-builder templates). A page that was fetched before and whose modified date
     * (WordPress modified_at or sitemap lastmod) is not newer than that fetch is not fetched
     * again; its stored copy stays current. Pages without a modified date are re-fetched
     * after UNKNOWN_RECHECK_DAYS, and every page at least every MAX_RECHECK_DAYS.
     *
     * @return array{queue: list<string>, unchanged: list<string>}
     */
    private function crawlSeedQueue(int $assetId, string $seed, bool $forceRefresh = false): array
    {
        $home = $this->urls->normalizeAbsolute($seed) ?? $seed;
        $candidates = [$home];
        /** @var array<string, string> $modified */
        $modified = [];

        if (Schema::hasTable('website_cms_object_snapshot')) {
            foreach (DB::table('website_cms_object_snapshot')
                ->where('digital_asset_id', $assetId)
                ->where('status', 'publish')
                ->whereNotIn('object_type', self::NON_PAGE_CMS_TYPES)
                ->whereNotNull('permalink')
                ->orderBy('permalink')
                ->limit(DiscoveryConfig::MAX_COLLECTION_PAGES)
                ->get(['permalink', 'modified_at']) as $row) {
                $candidates[] = (string) $row->permalink;
                $key = $this->urls->normalizeAbsolute((string) $row->permalink);
                if ($key !== null && $row->modified_at !== null) {
                    $modified[$key] = (string) $row->modified_at;
                }
            }
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
        foreach ($sitemapInventory['lastmods'] as $url => $lastmod) {
            $current = isset($modified[$url]) ? $this->parseDate($modified[$url]) : null;
            $candidate = $this->parseDate($lastmod);
            if ($candidate !== null && ($current === null || $candidate->gt($current))) {
                $modified[$url] = $candidate->toIso8601String();
            }
        }

        $queue = [];
        foreach ($candidates as $candidate) {
            $normalized = $this->urls->normalizeAbsolute((string) $candidate);
            if ($normalized === null || ! $this->urls->sameSite($seed, $normalized) || ($normalized !== $home && ! SeoText::isCrawlablePage($normalized))) {
                continue;
            }
            $queue[$normalized] = true;
            if (count($queue) >= DiscoveryConfig::MAX_COLLECTION_PAGES) {
                break;
            }
        }

        $unchanged = $forceRefresh ? [] : $this->unchangedSinceLastFetch($assetId, array_keys($queue), $modified, $home);

        return [
            'queue' => array_values(array_diff(array_keys($queue), $unchanged)),
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param  list<string>  $urls
     * @param  array<string, string>  $modified
     * @return list<string>
     */
    private function unchangedSinceLastFetch(int $assetId, array $urls, array $modified, string $home): array
    {
        if ($urls === [] || ! Schema::hasTable('website_html_snapshot')) {
            return [];
        }
        $lastFetched = [];
        foreach (array_chunk($urls, 500) as $chunk) {
            foreach (DB::table('website_html_snapshot')->where('digital_asset_id', $assetId)->whereIn('url', $chunk)
                ->groupBy('url')->selectRaw('url, MAX(observed_at) as fetched_at')->get() as $row) {
                $lastFetched[(string) $row->url] = CarbonImmutable::parse((string) $row->fetched_at);
            }
        }

        $now = CarbonImmutable::now('UTC');
        $unchanged = [];
        foreach ($urls as $url) {
            $fetchedAt = $lastFetched[$url] ?? null;
            // The homepage is always fetched: it carries the site-wide links and head.
            if ($url === $home || $fetchedAt === null || $fetchedAt->lt($now->subDays(self::MAX_RECHECK_DAYS))) {
                continue;
            }
            $modifiedAt = isset($modified[$url]) ? $this->parseDate($modified[$url]) : null;
            $fresh = $modifiedAt !== null
                ? $modifiedAt->lte($fetchedAt)
                : $fetchedAt->gte($now->subDays(self::UNKNOWN_RECHECK_DAYS));
            if ($fresh) {
                $unchanged[] = $url;
            }
        }

        return $unchanged;
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * url → lastmod (or null) of a urlset sitemap. Image entries (<image:loc>) are ignored.
     *
     * @return array<string, ?string>
     */
    private function sitemapEntries(string $xml): array
    {
        $entries = [];
        preg_match_all('/<url>(.*?)<\/url>/is', $xml, $blocks);
        foreach ($blocks[1] ?? [] as $block) {
            if (preg_match('/<loc>\s*([^<]+)\s*<\/loc>/i', (string) $block, $loc) !== 1) {
                continue;
            }
            $url = trim(html_entity_decode($loc[1], ENT_QUOTES | ENT_HTML5));
            if ($url === '') {
                continue;
            }
            $entries[$url] = preg_match('/<lastmod>\s*([^<]+)\s*<\/lastmod>/i', (string) $block, $lastmod) === 1 ? trim($lastmod[1]) : null;
            if (count($entries) >= DiscoveryConfig::MAX_SITEMAP_URLS) {
                break;
            }
        }

        return $entries;
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
            if ($normalized === null || ! $this->urls->sameSite($seed, $normalized) || ! SeoText::isCrawlablePage($normalized)) {
                continue;
            }

            $validated[$normalized] = true;
        }

        return array_keys($validated);
    }

    /**
     * @param  list<string>  $queue
     * @param  list<string>  $visited
     * @param  array<string, mixed>  $extra
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
        int $skippedUnchanged = 0,
        array $extra = [],
    ): array {
        return $extra + [
            'skipped_unchanged' => $skippedUnchanged,
            'observed_at' => $observedAt,
            'queue' => array_values($queue),
            'visited' => array_values($visited),
            'pages' => $pages,
            'urls_planned' => max($urlsPlanned, count($queue) + count($visited) - $skippedUnchanged),
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
        // Only <a> links: <link href> points at stylesheets, feeds, oEmbed and REST alternates.
        preg_match_all('/<a\b[^>]*?\shref=["\']([^"\']+)["\']/i', $html, $matches);
        $out = [];
        $base = trim($resolutionBase) !== '' ? $resolutionBase : $seed;
        foreach ($matches[1] ?? [] as $href) {
            $resolved = $this->urls->resolve($base, html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5));
            if ($resolved !== null && $this->urls->sameSite($seed, $resolved) && SeoText::isCrawlablePage($resolved)) {
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
     * @param  list<string>  $candidates
     * @return array{pages: list<string>, lastmods: array<string, string>, documents: list<array{url: string, fetch: array<string, mixed>}>, bytes: int}
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
        $lastmods = [];
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
                    if ($normalized === null || ! $this->urls->sameSite($seed, $normalized) || isset($visited[$normalized]) || SeoText::isJunkSitemap($normalized)) {
                        continue;
                    }
                    $queue[] = ['url' => $normalized, 'depth' => $depth + 1];
                }

                continue;
            }

            if ($parsed['type'] !== 'urlset') {
                continue;
            }

            $entries = $this->sitemapEntries((string) $fetch['body']);
            if ($entries === []) {
                $entries = array_fill_keys($parsed['locs'], null);
            }
            foreach ($entries as $pageUrl => $lastmod) {
                $normalized = $this->urls->normalizeAbsolute($pageUrl);
                if ($normalized === null || ! $this->urls->sameSite($seed, $normalized) || ! SeoText::isCrawlablePage($normalized)) {
                    continue;
                }
                $pages[$normalized] = true;
                if ($lastmod !== null) {
                    $lastmods[$normalized] = $lastmod;
                }
                if (count($pages) >= DiscoveryConfig::MAX_SITEMAP_URLS) {
                    break;
                }
            }
        }

        return [
            'pages' => array_keys($pages),
            'lastmods' => $lastmods,
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

    /** @return array<string, mixed>|null */
    private function jsonArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
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
