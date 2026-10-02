<?php

namespace App\Services;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Services\Findings\FindingLifecycleService;
use App\Support\CanonicalLinkParser;
use App\Support\RobotsTxtParser;
use App\Support\SitemapXmlParser;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use App\Support\WebsiteDiagnosisCatalog;
use App\Support\WebsitePostalAddressExtractor;
use App\Support\WebsiteTelephoneExtractor;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use MoxDop\Website\Diagnosis\DocumentHeadCatalog;
use MoxDop\Website\Diagnosis\DocumentHeadEvaluator;
use MoxDop\Website\Diagnosis\DocumentHeadParser;

class WebsiteDiagnosisService
{
    public const MODULE_ID = 'website-diagnosis';

    public const CATALOG_REACHABILITY_HTTP = 'reachability-http';

    public const CATALOG_HTTPS_TLS_VALIDITY = 'https-tls-validity';

    public const CATALOG_REDIRECT_HTTP_TO_HTTPS = 'redirect-http-to-https';

    public const CATALOG_ROBOTS_TXT_AVAILABILITY = 'robots-txt-availability';

    public const CATALOG_SITEMAP_XML_AVAILABILITY = 'sitemap-xml-availability';

    public const CATALOG_CANONICAL_LINK_CONSISTENCY = 'canonical-link-consistency';

    public const EVIDENCE_TYPE_TLS_INFO = 'tls_info';

    public const EVIDENCE_TYPE_REDIRECTS = 'redirects';

    public const EVIDENCE_TYPE_ROBOTS = 'robots';

    public const EVIDENCE_TYPE_SITEMAP = 'sitemap';

    public const EVIDENCE_TYPE_PAGE_HTML = 'page_html';

    private const TLS_EXPIRING_SOON_DAYS = 7;

    private const MAX_REDIRECT_HOPS = 10;

    private const CONFIDENCE_HIGH = 0.9000;

    private const CONFIDENCE_MEDIUM = 0.7000;

    public function __construct(
        private readonly SslCertificateProbe $sslCertificateProbe = new SslCertificateProbe,
        private readonly RobotsTxtParser $robotsTxtParser = new RobotsTxtParser,
        private readonly SitemapXmlParser $sitemapXmlParser = new SitemapXmlParser,
        private readonly CanonicalLinkParser $canonicalLinkParser = new CanonicalLinkParser,
        private readonly WebsiteDiagnosisCatalog $websiteDiagnosisCatalog = new WebsiteDiagnosisCatalog,
        private readonly WebsiteTelephoneExtractor $websiteTelephoneExtractor = new WebsiteTelephoneExtractor,
        private readonly WebsitePostalAddressExtractor $websitePostalAddressExtractor = new WebsitePostalAddressExtractor,
        private readonly DocumentHeadParser $documentHeadParser = new DocumentHeadParser,
        private readonly DocumentHeadEvaluator $documentHeadEvaluator = new DocumentHeadEvaluator,
        private readonly ?FindingLifecycleService $findingLifecycleService = null,
    ) {}

    /**
     * Deterministic Website Diagnosis slice: reachability + TLS + HTTP→HTTPS redirect + robots.txt + sitemap + canonical → Evidence → Finding upsert.
     */
    public function diagnose(DigitalAsset $asset): Run
    {
        $primaryUrl = $this->resolvePrimaryUrl($asset);

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'programmatic',
                'catalog_id' => 'website-diagnosis',
                'checks' => [
                    self::CATALOG_REACHABILITY_HTTP,
                    self::CATALOG_HTTPS_TLS_VALIDITY,
                    self::CATALOG_REDIRECT_HTTP_TO_HTTPS,
                    self::CATALOG_ROBOTS_TXT_AVAILABILITY,
                    self::CATALOG_SITEMAP_XML_AVAILABILITY,
                    self::CATALOG_CANONICAL_LINK_CONSISTENCY,
                    ...DocumentHeadCatalog::ruleIds(),
                ],
            ],
        ]);

        try {
            $observedAt = now();
            $primaryFetch = $this->fetchHttp($primaryUrl);
            $primaryBody = $primaryFetch['body'] ?? null;
            $primaryContentType = $primaryFetch['content_type'] ?? null;
            $normalized = $this->httpFetchEvidencePayload($primaryFetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => 'http_fetch',
                'title' => 'Primary URL HTTP fetch',
                'payload' => $normalized,
                'observed_at' => $observedAt,
            ]);

            $this->evaluateReachabilityHttp($asset, $run, $normalized, $observedAt);

            $tlsPayload = $this->collectTlsInfo($primaryUrl, $observedAt);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_TLS_INFO,
                'title' => 'TLS certificate info',
                'payload' => $tlsPayload,
                'observed_at' => $observedAt,
            ]);

            $this->evaluateHttpsTlsValidity($asset, $run, $tlsPayload, $observedAt);

            $httpStartUrl = $this->httpFormOf($primaryUrl);
            $redirectCollection = $this->collectHttpRedirectChain($httpStartUrl);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => 'http_fetch',
                'title' => 'HTTP entrypoint fetch',
                'payload' => $redirectCollection['http_fetch'],
                'observed_at' => $observedAt,
            ]);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_REDIRECTS,
                'title' => 'HTTP redirect chain',
                'payload' => $redirectCollection['redirects'],
                'observed_at' => $observedAt,
            ]);

            $this->evaluateRedirectHttpToHttps(
                $asset,
                $run,
                $redirectCollection['redirects'],
                $observedAt,
            );

            $robotsCollection = $this->collectRobotsTxt($primaryUrl);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => 'http_fetch',
                'title' => 'robots.txt HTTP fetch',
                'payload' => $robotsCollection['http_fetch'],
                'observed_at' => $observedAt,
            ]);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_ROBOTS,
                'title' => 'robots.txt',
                'payload' => $robotsCollection['robots'],
                'observed_at' => $observedAt,
            ]);

            $this->evaluateRobotsTxtAvailability(
                $asset,
                $run,
                $robotsCollection['robots'],
                $observedAt,
            );

            $sitemapCollection = $this->collectSitemap(
                $primaryUrl,
                $robotsCollection['robots']['sitemap_urls'] ?? [],
            );

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => 'http_fetch',
                'title' => 'sitemap HTTP fetch',
                'payload' => $sitemapCollection['http_fetch'],
                'observed_at' => $observedAt,
            ]);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_SITEMAP,
                'title' => 'Sitemap XML',
                'payload' => $sitemapCollection['sitemap'],
                'observed_at' => $observedAt,
            ]);

            $this->evaluateSitemapXmlAvailability(
                $asset,
                $run,
                $sitemapCollection['sitemap'],
                $observedAt,
            );

            $pageHtmlCollection = $this->collectPageHtml(
                $normalized,
                is_string($primaryBody) ? $primaryBody : null,
                is_string($primaryContentType) ? $primaryContentType : null,
            );

            if ($pageHtmlCollection !== null) {
                Evidence::query()->create([
                    'run_id' => $run->id,
                    'digital_asset_id' => $asset->id,
                    'source_module' => self::MODULE_ID,
                    'type' => self::EVIDENCE_TYPE_PAGE_HTML,
                    'title' => 'Primary page HTML',
                    'payload' => $pageHtmlCollection,
                    'observed_at' => $observedAt,
                ]);

                $this->evaluateCanonicalLinkConsistency(
                    $asset,
                    $run,
                    $pageHtmlCollection,
                    $redirectCollection['redirects'],
                    $observedAt,
                );
            }

            $lifecycle = $this->findingLifecycleService ?? app(FindingLifecycleService::class);
            $lifecycle->apply(
                $this->documentHeadEvaluator->evaluate(
                    $asset,
                    $run,
                    $pageHtmlCollection,
                    $observedAt,
                ),
            );

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'document_head_rules' => DocumentHeadCatalog::ruleIds(),
                ]),
            ]);
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'error' => $exception->getMessage(),
                ]),
            ]);

            throw $exception;
        }

        return $run->fresh(['evidence', 'digitalAsset']) ?? $run;
    }

    /**
     * @return array{
     *     url: string,
     *     status_code: int|null,
     *     effective_url: string|null,
     *     is_https: bool,
     *     response_is_ok: bool,
     *     error_class: string|null,
     *     error_or_status: string,
     *     body: string|null,
     *     content_type: string|null
     * }
     */
    private function fetchHttp(string $url): array
    {
        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->accept('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-WebsiteDiagnosis/1.0',
                ])
                ->get($url);

            $effectiveUrl = $this->effectiveUrl($response, $url);
            $statusCode = $response->status();
            $responseIsOk = $response->successful();
            $contentType = $response->header('Content-Type');

            return [
                'url' => $url,
                'status_code' => $statusCode,
                'effective_url' => $effectiveUrl,
                'is_https' => $this->isHttps($effectiveUrl ?? $url),
                'response_is_ok' => $responseIsOk,
                'error_class' => null,
                'error_or_status' => (string) $statusCode,
                'body' => $response->body(),
                'content_type' => is_string($contentType) && $contentType !== '' ? $contentType : null,
            ];
        } catch (ConnectionException $exception) {
            return [
                'url' => $url,
                'status_code' => null,
                'effective_url' => null,
                'is_https' => $this->isHttps($url),
                'response_is_ok' => false,
                'error_class' => 'connection',
                'error_or_status' => 'connection_error: '.$exception->getMessage(),
                'body' => null,
                'content_type' => null,
            ];
        }
    }

    /**
     * @param  array{
     *     url: string,
     *     status_code: int|null,
     *     effective_url: string|null,
     *     is_https: bool,
     *     response_is_ok: bool,
     *     error_class: string|null,
     *     error_or_status: string,
     *     body?: string|null,
     *     content_type?: string|null
     * }  $fetch
     * @return array{
     *     url: string,
     *     status_code: int|null,
     *     effective_url: string|null,
     *     is_https: bool,
     *     response_is_ok: bool,
     *     error_class: string|null,
     *     error_or_status: string
     * }
     */
    private function httpFetchEvidencePayload(array $fetch): array
    {
        return [
            'url' => $fetch['url'],
            'status_code' => $fetch['status_code'],
            'effective_url' => $fetch['effective_url'],
            'is_https' => $fetch['is_https'],
            'response_is_ok' => $fetch['response_is_ok'],
            'error_class' => $fetch['error_class'],
            'error_or_status' => $fetch['error_or_status'],
        ];
    }

    /**
     * @return array{
     *     subject_common_name: string|null,
     *     issuer_common_name: string|null,
     *     valid_from: string|null,
     *     valid_to: string|null,
     *     observed_at: string,
     *     fetch_method: string,
     *     host: string,
     *     present: bool,
     *     error_class?: string
     * }
     */
    private function collectTlsInfo(string $primaryUrl, DateTimeInterface $observedAt): array
    {
        $host = strtolower((string) parse_url($primaryUrl, PHP_URL_HOST));
        $port = parse_url($primaryUrl, PHP_URL_PORT);

        if ($host === '') {
            return (new SslCertParser)->missing('', $observedAt, SslCertParser::FETCH_METHOD_PHP_STREAM, 'invalid_host');
        }

        $port = is_int($port) ? $port : 443;

        return $this->sslCertificateProbe->probe($host, $observedAt, $port);
    }

    /**
     * Follow the plaintext HTTP entrypoint without auto-redirect to capture hop evidence.
     *
     * @return array{
     *     http_fetch: array{
     *         url: string,
     *         status_code: int|null,
     *         effective_url: string|null,
     *         is_https: bool,
     *         response_is_ok: bool,
     *         error_class: string|null,
     *         error_or_status: string
     *     },
     *     redirects: array{
     *         start_url: string,
     *         final_url: string|null,
     *         hop_count: int,
     *         hops: list<array{url: string, status: int|null, location: string|null}>,
     *         upgraded_to_https_same_host: bool,
     *         error_class: string|null
     *     }
     * }
     */
    private function collectHttpRedirectChain(string $httpStartUrl): array
    {
        $startHost = strtolower((string) parse_url($httpStartUrl, PHP_URL_HOST));
        $hops = [];
        $currentUrl = $httpStartUrl;
        $finalUrl = $httpStartUrl;
        $finalStatus = null;
        $errorClass = null;
        $upgradedToHttpsSameHost = false;

        for ($i = 0; $i < self::MAX_REDIRECT_HOPS; $i++) {
            try {
                /** @var Response $response */
                $response = Http::timeout(15)
                    ->withoutRedirecting()
                    ->accept('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8')
                    ->withHeaders([
                        'User-Agent' => 'MoxDOP-WebsiteDiagnosis/1.0',
                    ])
                    ->get($currentUrl);
            } catch (ConnectionException $exception) {
                $errorClass = 'connection';
                $hops[] = [
                    'url' => $currentUrl,
                    'status' => null,
                    'location' => null,
                ];
                $finalUrl = $currentUrl;
                $finalStatus = null;

                break;
            }

            $status = $response->status();
            $locationHeader = $response->header('Location');
            $location = is_string($locationHeader) && trim($locationHeader) !== ''
                ? trim($locationHeader)
                : null;

            $hops[] = [
                'url' => $currentUrl,
                'status' => $status,
                'location' => $location,
            ];

            $finalStatus = $status;
            $finalUrl = $currentUrl;

            if ($status >= 300 && $status < 400 && $location !== null) {
                $nextUrl = $this->resolveRedirectTarget($currentUrl, $location);
                $nextScheme = strtolower((string) parse_url($nextUrl, PHP_URL_SCHEME));
                $nextHost = strtolower((string) parse_url($nextUrl, PHP_URL_HOST));

                if ($nextScheme === 'https' && $nextHost !== '' && $nextHost === $startHost) {
                    $upgradedToHttpsSameHost = true;
                    $finalUrl = $nextUrl;
                    break;
                }

                $currentUrl = $nextUrl;

                continue;
            }

            break;
        }

        if (! $upgradedToHttpsSameHost && is_string($finalUrl)) {
            $finalScheme = strtolower((string) parse_url($finalUrl, PHP_URL_SCHEME));
            $finalHost = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
            $upgradedToHttpsSameHost = $finalScheme === 'https' && $finalHost === $startHost;
        }

        $httpFetch = [
            'url' => $httpStartUrl,
            'status_code' => $finalStatus,
            'effective_url' => $finalUrl,
            'is_https' => false,
            'response_is_ok' => is_int($finalStatus) && $finalStatus >= 200 && $finalStatus < 300,
            'error_class' => $errorClass,
            'error_or_status' => $errorClass !== null
                ? 'connection_error'
                : (string) ($finalStatus ?? 'no_response'),
        ];

        return [
            'http_fetch' => $httpFetch,
            'redirects' => [
                'start_url' => $httpStartUrl,
                'final_url' => $finalUrl,
                'hop_count' => count(array_filter(
                    $hops,
                    static fn (array $hop): bool => is_int($hop['status'] ?? null)
                        && $hop['status'] >= 300
                        && $hop['status'] < 400
                        && is_string($hop['location'] ?? null),
                )),
                'hops' => $hops,
                'upgraded_to_https_same_host' => $upgradedToHttpsSameHost,
                'error_class' => $errorClass,
            ],
        ];
    }

    /**
     * Catalog item `redirect-http-to-https`: plaintext HTTP entry must upgrade to HTTPS on the same host.
     *
     * @param  array{
     *     start_url: string,
     *     final_url: string|null,
     *     hop_count: int,
     *     hops: list<array{url: string, status: int|null, location: string|null}>,
     *     upgraded_to_https_same_host: bool,
     *     error_class: string|null
     * }  $evidence
     */
    private function evaluateRedirectHttpToHttps(
        DigitalAsset $asset,
        Run $run,
        array $evidence,
        DateTimeInterface $observedAt,
    ): void {
        if ($evidence['error_class'] !== null) {
            return;
        }

        if ($evidence['upgraded_to_https_same_host'] === true) {
            return;
        }

        $host = strtolower((string) parse_url($evidence['start_url'], PHP_URL_HOST));
        $fingerprint = $this->fingerprint(self::CATALOG_REDIRECT_HTTP_TO_HTTPS, [
            'host' => $host,
        ]);

        $finalUrl = $evidence['final_url'] ?? $evidence['start_url'];

        $this->upsertFinding(
            asset: $asset,
            run: $run,
            fingerprint: $fingerprint,
            category: 'transport',
            severity: 'medium',
            title: 'HTTP does not upgrade to HTTPS',
            summary: sprintf(
                'Request to %s ended at %s without a stable HTTPS upgrade (%d redirect hop(s)).',
                $evidence['start_url'],
                $finalUrl,
                $evidence['hop_count'],
            ),
            confidence: self::CONFIDENCE_HIGH,
            observedAt: $observedAt,
            catalogItemId: self::CATALOG_REDIRECT_HTTP_TO_HTTPS,
        );
    }

    /**
     * Collect robots.txt evidence for the primary host (HTTPS preferred).
     *
     * @return array{
     *     http_fetch: array{
     *         url: string,
     *         status_code: int|null,
     *         effective_url: string|null,
     *         is_https: bool,
     *         response_is_ok: bool,
     *         error_class: string|null,
     *         error_or_status: string
     *     },
     *     robots: array{
     *         robots_url: string,
     *         effective_url: string|null,
     *         status_code: int|null,
     *         present: bool,
     *         body: string|null,
     *         body_truncated: bool,
     *         parse_ok: bool,
     *         has_user_agent_group: bool,
     *         sitemap_urls: list<string>,
     *         status_or_error: string,
     *         error_class: string|null,
     *         reason_code: string|null
     *     }
     * }
     */
    private function collectRobotsTxt(string $primaryUrl): array
    {
        $robotsUrl = $this->robotsTxtUrlFor($primaryUrl);

        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->accept('text/plain,*/*;q=0.8')
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-WebsiteDiagnosis/1.0',
                ])
                ->get($robotsUrl);

            $statusCode = $response->status();
            $effectiveUrl = $this->effectiveUrl($response, $robotsUrl);
            $rawBody = $response->body();
            $parsed = $this->robotsTxtParser->parse($rawBody, $statusCode);

            $errorClass = null;
            $statusOrError = (string) $statusCode;
            $reasonCode = null;

            if ($statusCode >= 500 && $statusCode <= 599) {
                $reasonCode = 'fetch_5xx';
            } elseif ($parsed['malformed']) {
                $reasonCode = 'malformed';
            }

            $httpFetch = [
                'url' => $robotsUrl,
                'status_code' => $statusCode,
                'effective_url' => $effectiveUrl,
                'is_https' => $this->isHttps($effectiveUrl ?? $robotsUrl),
                'response_is_ok' => $response->successful(),
                'error_class' => $errorClass,
                'error_or_status' => $statusOrError,
            ];

            return [
                'http_fetch' => $httpFetch,
                'robots' => [
                    'robots_url' => $robotsUrl,
                    'effective_url' => $effectiveUrl,
                    'status_code' => $statusCode,
                    'present' => $statusCode === 200 && is_string($parsed['body']),
                    'body' => $parsed['body'],
                    'body_truncated' => $parsed['body_truncated'],
                    'parse_ok' => $parsed['parse_ok'],
                    'has_user_agent_group' => $parsed['has_user_agent_group'],
                    'sitemap_urls' => $parsed['sitemap_urls'],
                    'status_or_error' => $statusOrError,
                    'error_class' => $errorClass,
                    'reason_code' => $reasonCode,
                ],
            ];
        } catch (ConnectionException $exception) {
            $statusOrError = 'connection_error: '.$exception->getMessage();

            $httpFetch = [
                'url' => $robotsUrl,
                'status_code' => null,
                'effective_url' => null,
                'is_https' => $this->isHttps($robotsUrl),
                'response_is_ok' => false,
                'error_class' => 'connection',
                'error_or_status' => $statusOrError,
            ];

            return [
                'http_fetch' => $httpFetch,
                'robots' => [
                    'robots_url' => $robotsUrl,
                    'effective_url' => null,
                    'status_code' => null,
                    'present' => false,
                    'body' => null,
                    'body_truncated' => false,
                    'parse_ok' => false,
                    'has_user_agent_group' => false,
                    'sitemap_urls' => [],
                    'status_or_error' => $statusOrError,
                    'error_class' => 'connection',
                    'reason_code' => 'connection',
                ],
            ];
        }
    }

    /**
     * Catalog item `robots-txt-availability`: 5xx/transport failure or malformed robots body.
     *
     * @param  array{
     *     robots_url: string,
     *     effective_url: string|null,
     *     status_code: int|null,
     *     present: bool,
     *     body: string|null,
     *     body_truncated: bool,
     *     parse_ok: bool,
     *     has_user_agent_group: bool,
     *     sitemap_urls: list<string>,
     *     status_or_error: string,
     *     error_class: string|null,
     *     reason_code: string|null
     * }  $evidence
     */
    private function evaluateRobotsTxtAvailability(
        DigitalAsset $asset,
        Run $run,
        array $evidence,
        DateTimeInterface $observedAt,
    ): void {
        $reasonCode = $evidence['reason_code'];

        if ($reasonCode === null) {
            return;
        }

        $host = strtolower((string) parse_url($evidence['robots_url'], PHP_URL_HOST));
        $fingerprint = $this->fingerprint(self::CATALOG_ROBOTS_TXT_AVAILABILITY, [
            'host' => $host,
        ]);

        $severity = $reasonCode === 'malformed' ? 'low' : 'medium';
        $confidence = $reasonCode === 'malformed' ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_HIGH;

        $this->upsertFinding(
            asset: $asset,
            run: $run,
            fingerprint: $fingerprint,
            category: 'indexability',
            severity: $severity,
            title: 'robots.txt problem',
            summary: sprintf(
                'Fetching %s yielded %s; parse_ok=%s.',
                $evidence['robots_url'],
                $evidence['status_or_error'],
                $evidence['parse_ok'] ? 'true' : 'false',
            ),
            confidence: $confidence,
            observedAt: $observedAt,
            catalogItemId: self::CATALOG_ROBOTS_TXT_AVAILABILITY,
        );
    }

    private function robotsTxtUrlFor(string $primaryUrl): string
    {
        $parts = parse_url($primaryUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new InvalidArgumentException('Unable to derive robots.txt URL from primary_url.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.'/robots.txt';
    }

    /**
     * Collect sitemap evidence using robots-declared Sitemap URLs when present, else default /sitemap.xml.
     *
     * @param  list<string>  $robotsSitemapUrls
     * @return array{
     *     http_fetch: array{
     *         url: string,
     *         status_code: int|null,
     *         effective_url: string|null,
     *         is_https: bool,
     *         response_is_ok: bool,
     *         error_class: string|null,
     *         error_or_status: string
     *     },
     *     sitemap: array{
     *         host: string,
     *         tried_urls: list<string>,
     *         candidates_from_robots: bool,
     *         sitemap_url: string,
     *         effective_url: string|null,
     *         status_code: int|null,
     *         present: bool,
     *         parse_ok: bool,
     *         root_element: string|null,
     *         url_count: int|null,
     *         body: string|null,
     *         body_truncated: bool,
     *         last_outcome: string,
     *         error_class: string|null,
     *         reason_code: string|null
     *     }
     * }
     */
    private function collectSitemap(string $primaryUrl, array $robotsSitemapUrls): array
    {
        $host = strtolower((string) parse_url($primaryUrl, PHP_URL_HOST));
        $candidatesFromRobots = $robotsSitemapUrls !== [];
        $triedUrls = $candidatesFromRobots
            ? array_values(array_unique(array_filter(
                $robotsSitemapUrls,
                static fn (mixed $url): bool => is_string($url) && trim($url) !== '',
            )))
            : [$this->defaultSitemapUrlFor($primaryUrl)];

        if ($triedUrls === []) {
            $triedUrls = [$this->defaultSitemapUrlFor($primaryUrl)];
            $candidatesFromRobots = false;
        }

        $decisiveHttpFetch = null;
        $decisiveSitemap = null;

        foreach ($triedUrls as $candidateUrl) {
            $attempt = $this->fetchSitemapCandidate($candidateUrl, $host, $triedUrls, $candidatesFromRobots);

            $decisiveHttpFetch = $attempt['http_fetch'];
            $decisiveSitemap = $attempt['sitemap'];

            if ($attempt['sitemap']['parse_ok'] === true) {
                break;
            }
        }

        /** @var array{http_fetch: array<string, mixed>, sitemap: array<string, mixed>} $decisive */
        $decisive = [
            'http_fetch' => $decisiveHttpFetch,
            'sitemap' => $decisiveSitemap,
        ];

        return $decisive;
    }

    /**
     * @param  list<string>  $triedUrls
     * @return array{
     *     http_fetch: array{
     *         url: string,
     *         status_code: int|null,
     *         effective_url: string|null,
     *         is_https: bool,
     *         response_is_ok: bool,
     *         error_class: string|null,
     *         error_or_status: string
     *     },
     *     sitemap: array{
     *         host: string,
     *         tried_urls: list<string>,
     *         candidates_from_robots: bool,
     *         sitemap_url: string,
     *         effective_url: string|null,
     *         status_code: int|null,
     *         present: bool,
     *         parse_ok: bool,
     *         root_element: string|null,
     *         url_count: int|null,
     *         body: string|null,
     *         body_truncated: bool,
     *         last_outcome: string,
     *         error_class: string|null,
     *         reason_code: string|null
     *     }
     * }
     */
    private function fetchSitemapCandidate(
        string $candidateUrl,
        string $host,
        array $triedUrls,
        bool $candidatesFromRobots,
    ): array {
        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->accept('application/xml,text/xml,*/*;q=0.8')
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-WebsiteDiagnosis/1.0',
                ])
                ->get($candidateUrl);

            $statusCode = $response->status();
            $effectiveUrl = $this->effectiveUrl($response, $candidateUrl);
            $rawBody = $response->body();
            $parsed = $this->sitemapXmlParser->parse($rawBody, $statusCode);

            $errorClass = null;
            $statusOrError = (string) $statusCode;
            $lastOutcome = $this->sitemapOutcomeFromStatus($statusCode, $parsed['malformed'], $parsed['parse_ok']);
            $reasonCode = $this->sitemapReasonCode($lastOutcome);

            $httpFetch = [
                'url' => $candidateUrl,
                'status_code' => $statusCode,
                'effective_url' => $effectiveUrl,
                'is_https' => $this->isHttps($effectiveUrl ?? $candidateUrl),
                'response_is_ok' => $response->successful(),
                'error_class' => $errorClass,
                'error_or_status' => $statusOrError,
            ];

            return [
                'http_fetch' => $httpFetch,
                'sitemap' => [
                    'host' => $host,
                    'tried_urls' => $triedUrls,
                    'candidates_from_robots' => $candidatesFromRobots,
                    'sitemap_url' => $candidateUrl,
                    'effective_url' => $effectiveUrl,
                    'status_code' => $statusCode,
                    'present' => $statusCode === 200 && is_string($parsed['body']),
                    'parse_ok' => $parsed['parse_ok'],
                    'root_element' => $parsed['root_element'],
                    'url_count' => $parsed['url_count'],
                    'body' => $parsed['body'],
                    'body_truncated' => $parsed['body_truncated'],
                    'last_outcome' => $lastOutcome,
                    'error_class' => $errorClass,
                    'reason_code' => $reasonCode,
                ],
            ];
        } catch (ConnectionException $exception) {
            $statusOrError = 'connection_error: '.$exception->getMessage();

            $httpFetch = [
                'url' => $candidateUrl,
                'status_code' => null,
                'effective_url' => null,
                'is_https' => $this->isHttps($candidateUrl),
                'response_is_ok' => false,
                'error_class' => 'connection',
                'error_or_status' => $statusOrError,
            ];

            return [
                'http_fetch' => $httpFetch,
                'sitemap' => [
                    'host' => $host,
                    'tried_urls' => $triedUrls,
                    'candidates_from_robots' => $candidatesFromRobots,
                    'sitemap_url' => $candidateUrl,
                    'effective_url' => null,
                    'status_code' => null,
                    'present' => false,
                    'parse_ok' => false,
                    'root_element' => null,
                    'url_count' => null,
                    'body' => null,
                    'body_truncated' => false,
                    'last_outcome' => 'connection',
                    'error_class' => 'connection',
                    'reason_code' => 'fetch_failure',
                ],
            ];
        }
    }

    private function sitemapOutcomeFromStatus(?int $statusCode, bool $malformed, bool $parseOk): string
    {
        if ($parseOk) {
            return 'ok';
        }

        if ($statusCode === null) {
            return 'connection';
        }

        if ($statusCode >= 500 && $statusCode <= 599) {
            return 'status_5xx';
        }

        if ($statusCode === 404) {
            return 'status_404';
        }

        if ($statusCode === 410) {
            return 'status_410';
        }

        if ($malformed || $statusCode === 200) {
            return 'malformed_xml';
        }

        return 'status_'.$statusCode;
    }

    private function sitemapReasonCode(string $lastOutcome): ?string
    {
        return match ($lastOutcome) {
            'ok' => null,
            'malformed_xml' => 'malformed',
            'status_404', 'status_410' => 'not_found',
            'connection', 'status_5xx' => 'fetch_failure',
            default => 'fetch_failure',
        };
    }

    /**
     * Catalog item `sitemap-xml-availability`: every candidate missing/unreadable, or decisive body malformed.
     *
     * @param  array{
     *     host: string,
     *     tried_urls: list<string>,
     *     candidates_from_robots: bool,
     *     sitemap_url: string,
     *     effective_url: string|null,
     *     status_code: int|null,
     *     present: bool,
     *     parse_ok: bool,
     *     root_element: string|null,
     *     url_count: int|null,
     *     body: string|null,
     *     body_truncated: bool,
     *     last_outcome: string,
     *     error_class: string|null,
     *     reason_code: string|null
     * }  $evidence
     */
    private function evaluateSitemapXmlAvailability(
        DigitalAsset $asset,
        Run $run,
        array $evidence,
        DateTimeInterface $observedAt,
    ): void {
        if ($evidence['parse_ok'] === true) {
            return;
        }

        $fingerprint = $this->fingerprint(self::CATALOG_SITEMAP_XML_AVAILABILITY, [
            'host' => $evidence['host'],
        ]);

        $confidence = $evidence['candidates_from_robots']
            ? self::CONFIDENCE_HIGH
            : self::CONFIDENCE_MEDIUM;

        $triedUrls = implode(', ', $evidence['tried_urls']);

        $this->upsertFinding(
            asset: $asset,
            run: $run,
            fingerprint: $fingerprint,
            category: 'indexability',
            severity: 'medium',
            title: 'Sitemap missing or unreadable',
            summary: sprintf(
                'No usable sitemap at tried URL(s): %s; last_outcome=%s.',
                $triedUrls,
                $evidence['last_outcome'],
            ),
            confidence: $confidence,
            observedAt: $observedAt,
            catalogItemId: self::CATALOG_SITEMAP_XML_AVAILABILITY,
        );
    }

    private function defaultSitemapUrlFor(string $primaryUrl): string
    {
        $parts = parse_url($primaryUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new InvalidArgumentException('Unable to derive sitemap URL from primary_url.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = 'https';
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.'/sitemap.xml';
    }

    /**
     * Build page_html evidence from a successful primary HTML response body.
     *
     * @param  array{
     *     url: string,
     *     status_code: int|null,
     *     effective_url: string|null,
     *     is_https: bool,
     *     response_is_ok: bool,
     *     error_class: string|null,
     *     error_or_status: string
     * }  $httpFetch
     * @return array{
     *     final_url: string,
     *     status_code: int,
     *     content_type: string|null,
     *     head_html: string|null,
     *     head_truncated: bool,
     *     head_complete: bool,
     *     canonical_hrefs: list<string>,
     *     absolute_canonical_hrefs: list<string>,
     *     relative_canonical_hrefs: list<string>,
     *     canonical_state: string,
     *     telephone_candidates: list<string>,
     *     postal_address_candidates: list<array{
     *         street_address: string|null,
     *         locality: string|null,
     *         region: string|null,
     *         postal_code: string|null,
     *         country: string|null,
     *         formatted: string
     *     }>
     * }|null
     */
    private function collectPageHtml(array $httpFetch, ?string $body, ?string $contentType): ?array
    {
        if ($httpFetch['status_code'] !== 200 || $httpFetch['error_class'] !== null) {
            return null;
        }

        if (! $this->looksLikeHtml($body)) {
            return null;
        }

        $finalUrl = $httpFetch['effective_url'] ?? $httpFetch['url'];
        $parsed = $this->canonicalLinkParser->parse($body);
        $canonicalState = $this->canonicalStateFromParsed($parsed);
        $headSource = is_string($parsed['head_html'] ?? null) && $parsed['head_html'] !== ''
            ? $parsed['head_html']
            : $body;
        $documentHead = $this->documentHeadParser->parse($headSource);

        return [
            'final_url' => $finalUrl,
            'status_code' => 200,
            'content_type' => $contentType,
            'head_html' => $parsed['head_html'],
            'head_truncated' => $parsed['head_truncated'],
            'head_complete' => $parsed['head_complete'],
            'canonical_hrefs' => $parsed['canonical_hrefs'],
            'absolute_canonical_hrefs' => $parsed['absolute_canonical_hrefs'],
            'relative_canonical_hrefs' => $parsed['relative_canonical_hrefs'],
            'canonical_state' => $canonicalState,
            'telephone_candidates' => $this->websiteTelephoneExtractor->extract($body),
            'postal_address_candidates' => $this->websitePostalAddressExtractor->extract($body),
            'document' => [
                'title' => $documentHead['title'],
                'title_present' => $documentHead['title_present'],
                'title_empty' => $documentHead['title_empty'],
                'title_length' => $documentHead['title_length'],
                'charset' => $documentHead['charset'],
                'charset_present' => $documentHead['charset_present'],
                'viewport' => $documentHead['viewport'],
                'viewport_present' => $documentHead['viewport_present'],
            ],
            'meta' => [
                'description' => $documentHead['meta_description'],
                'description_present' => $documentHead['meta_description_present'],
                'description_empty' => $documentHead['meta_description_empty'],
                'description_length' => $documentHead['meta_description_length'],
                'robots' => $documentHead['meta_robots'],
                'googlebot' => $documentHead['meta_googlebot'],
                'robots_directives' => $documentHead['robots_directives'],
                'googlebot_directives' => $documentHead['googlebot_directives'],
            ],
            'links' => [
                'hreflang' => $documentHead['hreflang'],
            ],
            'open_graph' => $documentHead['open_graph'],
            'open_graph_present_count' => $documentHead['open_graph_present_count'],
            'structured_data' => $documentHead['json_ld'],
        ];
    }

    private function looksLikeHtml(?string $body): bool
    {
        if (! is_string($body) || trim($body) === '') {
            return false;
        }

        // Require HTML markers. Content-Type alone is insufficient — stubs/plain bodies must not invent page_html.
        return preg_match('/<!doctype\s+html|<html\b|<head\b/i', $body) === 1;
    }

    /**
     * @param  array{
     *     canonical_hrefs: list<string>,
     *     absolute_canonical_hrefs: list<string>,
     *     relative_canonical_hrefs: list<string>
     * }  $parsed
     */
    private function canonicalStateFromParsed(array $parsed): string
    {
        $hrefs = $parsed['canonical_hrefs'];
        $absolute = $parsed['absolute_canonical_hrefs'];
        $relative = $parsed['relative_canonical_hrefs'];

        if ($hrefs === []) {
            return 'missing';
        }

        if (count($absolute) > 1) {
            return 'conflict_multiple';
        }

        if (count($absolute) === 1 && $relative === []) {
            return 'absolute_single';
        }

        if (count($absolute) === 0 && $relative !== []) {
            return 'relative_only';
        }

        return 'conflict_mixed';
    }

    /**
     * Catalog item `canonical-link-consistency`: missing / relative / conflicting canonical signals.
     *
     * @param  array{
     *     final_url: string,
     *     status_code: int,
     *     content_type: string|null,
     *     head_html: string|null,
     *     head_truncated: bool,
     *     head_complete: bool,
     *     canonical_hrefs: list<string>,
     *     absolute_canonical_hrefs: list<string>,
     *     relative_canonical_hrefs: list<string>,
     *     canonical_state: string
     * }  $evidence
     * @param  array{
     *     start_url: string,
     *     final_url: string|null,
     *     hop_count: int,
     *     hops: list<array{url: string, status: int|null, location: string|null}>,
     *     upgraded_to_https_same_host: bool,
     *     error_class: string|null
     * }  $redirects
     */
    private function evaluateCanonicalLinkConsistency(
        DigitalAsset $asset,
        Run $run,
        array $evidence,
        array $redirects,
        DateTimeInterface $observedAt,
    ): void {
        $issue = $this->canonicalIssue($evidence, $redirects);

        if ($issue === null) {
            return;
        }

        $finalUrl = $evidence['final_url'];
        $fingerprint = $this->fingerprint(self::CATALOG_CANONICAL_LINK_CONSISTENCY, [
            'url' => $this->normalizeUrl($finalUrl),
        ]);
        $confidence = $evidence['head_complete'] ? self::CONFIDENCE_HIGH : self::CONFIDENCE_MEDIUM;
        $hrefList = $evidence['canonical_hrefs'] === []
            ? '(none)'
            : implode(', ', $evidence['canonical_hrefs']);

        $this->upsertFinding(
            asset: $asset,
            run: $run,
            fingerprint: $fingerprint,
            category: 'on-page',
            severity: $issue['severity'],
            title: 'Canonical link issue',
            summary: sprintf(
                'Primary page %s canonical signal: %s (values: %s).',
                $finalUrl,
                $issue['state'],
                $hrefList,
            ),
            confidence: $confidence,
            observedAt: $observedAt,
            catalogItemId: self::CATALOG_CANONICAL_LINK_CONSISTENCY,
        );
    }

    /**
     * @param  array{
     *     final_url: string,
     *     status_code: int,
     *     content_type: string|null,
     *     head_html: string|null,
     *     head_truncated: bool,
     *     head_complete: bool,
     *     canonical_hrefs: list<string>,
     *     absolute_canonical_hrefs: list<string>,
     *     relative_canonical_hrefs: list<string>,
     *     canonical_state: string
     * }  $evidence
     * @param  array{
     *     start_url: string,
     *     final_url: string|null,
     *     hop_count: int,
     *     hops: list<array{url: string, status: int|null, location: string|null}>,
     *     upgraded_to_https_same_host: bool,
     *     error_class: string|null
     * }  $redirects
     * @return array{state: string, severity: string}|null
     */
    private function canonicalIssue(array $evidence, array $redirects): ?array
    {
        $state = $evidence['canonical_state'];

        if ($state === 'missing') {
            return ['state' => 'missing', 'severity' => 'medium'];
        }

        if ($state === 'conflict_multiple' || $state === 'conflict_mixed') {
            return ['state' => $state, 'severity' => 'medium'];
        }

        if ($state === 'relative_only') {
            return ['state' => 'relative_only', 'severity' => 'low'];
        }

        if ($state === 'absolute_single') {
            $canonical = $evidence['absolute_canonical_hrefs'][0] ?? null;

            if (! is_string($canonical)) {
                return null;
            }

            if ($this->redirectsIndicateStableLanding($redirects, $evidence['final_url'])
                && $this->normalizeUrl($canonical) !== $this->normalizeUrl($evidence['final_url'])) {
                return ['state' => 'conflict_mismatch', 'severity' => 'medium'];
            }
        }

        return null;
    }

    /**
     * @param  array{
     *     start_url: string,
     *     final_url: string|null,
     *     hop_count: int,
     *     hops: list<array{url: string, status: int|null, location: string|null}>,
     *     upgraded_to_https_same_host: bool,
     *     error_class: string|null
     * }  $redirects
     */
    private function redirectsIndicateStableLanding(array $redirects, string $pageFinalUrl): bool
    {
        if ($redirects['error_class'] !== null) {
            return false;
        }

        $redirectFinal = $redirects['final_url'];

        if (! is_string($redirectFinal) || $redirectFinal === '') {
            return false;
        }

        return $this->normalizeUrl($redirectFinal) === $this->normalizeUrl($pageFinalUrl);
    }

    private function httpFormOf(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new InvalidArgumentException('Unable to derive http:// form of primary_url.');
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return 'http://'.$host.$port.$path.$query;
    }

    private function resolveRedirectTarget(string $currentUrl, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }

        $parts = parse_url($currentUrl);
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$host.$port.$location;
        }

        $basePath = $parts['path'] ?? '/';
        $directory = str_contains($basePath, '/')
            ? substr($basePath, 0, (int) strrpos($basePath, '/') + 1)
            : '/';

        return $scheme.'://'.$host.$port.$directory.$location;
    }

    /**
     * Catalog item `https-tls-validity`: missing, expired, or expiring within 7 days.
     *
     * @param  array{
     *     subject_common_name: string|null,
     *     issuer_common_name: string|null,
     *     valid_from: string|null,
     *     valid_to: string|null,
     *     observed_at: string,
     *     fetch_method: string,
     *     host: string,
     *     present: bool,
     *     error_class?: string
     * }  $evidence
     */
    private function evaluateHttpsTlsValidity(
        DigitalAsset $asset,
        Run $run,
        array $evidence,
        DateTimeInterface $observedAt,
    ): void {
        $host = $evidence['host'];
        $validTo = $evidence['valid_to'];
        $fingerprint = $this->fingerprint(self::CATALOG_HTTPS_TLS_VALIDITY, [
            'host' => $host,
        ]);

        if (! ($evidence['present'] ?? false)) {
            $reason = $evidence['error_class'] ?? 'certificate_missing';

            $this->upsertFinding(
                asset: $asset,
                run: $run,
                fingerprint: $fingerprint,
                category: 'transport',
                severity: 'high',
                title: 'HTTPS/TLS certificate problem',
                summary: sprintf(
                    'TLS for host %s failed validation (%s); certificate notAfter=%s.',
                    $host,
                    $reason,
                    $validTo ?? 'unknown',
                ),
                confidence: self::CONFIDENCE_HIGH,
                observedAt: $observedAt,
                catalogItemId: self::CATALOG_HTTPS_TLS_VALIDITY,
            );

            return;
        }

        if ($validTo === null) {
            $this->upsertFinding(
                asset: $asset,
                run: $run,
                fingerprint: $fingerprint,
                category: 'transport',
                severity: 'high',
                title: 'HTTPS/TLS certificate problem',
                summary: sprintf(
                    'TLS for host %s failed validation (missing_not_after); certificate notAfter=unknown.',
                    $host,
                ),
                confidence: self::CONFIDENCE_HIGH,
                observedAt: $observedAt,
                catalogItemId: self::CATALOG_HTTPS_TLS_VALIDITY,
            );

            return;
        }

        $expiresAt = new DateTimeImmutable($validTo);
        $now = DateTimeImmutable::createFromInterface($observedAt);

        if ($expiresAt < $now) {
            $this->upsertFinding(
                asset: $asset,
                run: $run,
                fingerprint: $fingerprint,
                category: 'transport',
                severity: 'high',
                title: 'HTTPS/TLS certificate problem',
                summary: sprintf(
                    'TLS for host %s failed validation (expired); certificate notAfter=%s.',
                    $host,
                    $validTo,
                ),
                confidence: self::CONFIDENCE_HIGH,
                observedAt: $observedAt,
                catalogItemId: self::CATALOG_HTTPS_TLS_VALIDITY,
            );

            return;
        }

        $expiringSoonThreshold = $now->modify('+'.self::TLS_EXPIRING_SOON_DAYS.' days');

        if ($expiresAt <= $expiringSoonThreshold) {
            $this->upsertFinding(
                asset: $asset,
                run: $run,
                fingerprint: $fingerprint,
                category: 'transport',
                severity: 'medium',
                title: 'HTTPS/TLS certificate problem',
                summary: sprintf(
                    'TLS for host %s failed validation (expiring_within_%d_days); certificate notAfter=%s.',
                    $host,
                    self::TLS_EXPIRING_SOON_DAYS,
                    $validTo,
                ),
                confidence: self::CONFIDENCE_HIGH,
                observedAt: $observedAt,
                catalogItemId: self::CATALOG_HTTPS_TLS_VALIDITY,
            );
        }
    }

    /**
     * Catalog item `reachability-http`: transport failure, missing response, or final 5xx.
     *
     * @param  array{
     *     url: string,
     *     status_code: int|null,
     *     effective_url: string|null,
     *     is_https: bool,
     *     response_is_ok: bool,
     *     error_class: string|null,
     *     error_or_status: string
     * }  $evidence
     */
    private function evaluateReachabilityHttp(
        DigitalAsset $asset,
        Run $run,
        array $evidence,
        DateTimeInterface $observedAt,
    ): void {
        if (! $this->reachabilityHttpMatches($evidence)) {
            return;
        }

        $fingerprint = $this->fingerprint(self::CATALOG_REACHABILITY_HTTP, [
            'url' => $this->normalizeUrl($evidence['url']),
        ]);

        $startUrl = $evidence['url'];
        $finalUrl = $evidence['effective_url'];
        $summary = sprintf(
            'Primary URL %s did not return a successful HTTP response (outcome: %s).',
            $startUrl,
            $evidence['error_or_status'],
        );

        if (is_string($finalUrl) && $finalUrl !== '' && $finalUrl !== $startUrl) {
            $summary .= sprintf(' Final URL: %s.', $finalUrl);
        }

        $this->upsertFinding(
            asset: $asset,
            run: $run,
            fingerprint: $fingerprint,
            category: 'availability',
            severity: 'critical',
            title: 'Website not reachable',
            summary: $summary,
            confidence: self::CONFIDENCE_HIGH,
            observedAt: $observedAt,
            catalogItemId: self::CATALOG_REACHABILITY_HTTP,
        );
    }

    /**
     * @param  array{status_code: int|null, error_class: string|null}  $evidence
     */
    private function reachabilityHttpMatches(array $evidence): bool
    {
        if ($evidence['error_class'] !== null) {
            return true;
        }

        $statusCode = $evidence['status_code'];

        if ($statusCode === null) {
            return true;
        }

        return $statusCode >= 500 && $statusCode <= 599;
    }

    /**
     * @param  array<string, string>  $normalizedEvidenceParts
     */
    private function fingerprint(string $catalogItemId, array $normalizedEvidenceParts): string
    {
        ksort($normalizedEvidenceParts);

        $material = $catalogItemId.'|'.implode('|', array_map(
            static fn (string $key, string $value): string => $key.'='.$value,
            array_keys($normalizedEvidenceParts),
            array_values($normalizedEvidenceParts),
        ));

        return hash('sha256', $material);
    }

    private function upsertFinding(
        DigitalAsset $asset,
        Run $run,
        string $fingerprint,
        string $category,
        string $severity,
        string $title,
        string $summary,
        float $confidence,
        DateTimeInterface $observedAt,
        string $catalogItemId,
    ): Finding {
        $finding = Finding::query()->firstOrNew([
            'digital_asset_id' => $asset->id,
            'fingerprint' => $fingerprint,
        ]);

        if (! $finding->exists) {
            $finding->first_seen_at = $observedAt;
            $finding->source_module = self::MODULE_ID;
        }

        $finding->fill([
            'source_module' => self::MODULE_ID,
            'category' => $category,
            'severity' => $severity,
            'title' => $title,
            'summary' => $summary,
            'confidence' => $confidence,
            'status' => 'open',
            'last_seen_at' => $observedAt,
            'last_run_id' => $run->id,
        ]);

        $finding->save();

        $this->upsertRecommendationForFinding($finding, $catalogItemId);

        return $finding;
    }

    /**
     * Deterministic Recommendation upsert using catalog recommendation_logic (ADR-031).
     */
    private function upsertRecommendationForFinding(Finding $finding, string $catalogItemId): void
    {
        $action = $this->websiteDiagnosisCatalog->recommendationLogic($catalogItemId);

        if ($action === null) {
            return;
        }

        $recommendation = Recommendation::query()->firstOrNew([
            'finding_id' => $finding->id,
            'source_module' => self::MODULE_ID,
        ]);

        $priority = $this->recommendationPriorityForSeverity((string) $finding->severity);

        if ($recommendation->exists && ! in_array($recommendation->status, ['open', 'accepted'], true)) {
            // Preserve terminal operator decisions (dismissed/converted); still refresh guidance text.
            $recommendation->fill([
                'source_kind' => RecommendationSourceKind::Finding->value,
                'opportunity_id' => null,
                'origin' => RecommendationOrigin::DeterministicTemplate->value,
                'digital_asset_id' => $finding->digital_asset_id,
                'title' => 'Fix: '.$finding->title,
                'action' => $action,
                'rationale' => $finding->summary,
                'priority' => $priority,
            ]);
            $recommendation->save();

            return;
        }

        $recommendation->fill([
            'source_kind' => RecommendationSourceKind::Finding->value,
            'opportunity_id' => null,
            'origin' => RecommendationOrigin::DeterministicTemplate->value,
            'digital_asset_id' => $finding->digital_asset_id,
            'source_module' => self::MODULE_ID,
            'title' => 'Fix: '.$finding->title,
            'action' => $action,
            'rationale' => $finding->summary,
            'priority' => $priority,
            'effort' => null,
            'status' => $recommendation->exists ? $recommendation->status : 'open',
        ]);

        $recommendation->save();
    }

    private function recommendationPriorityForSeverity(string $severity): string
    {
        return match ($severity) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'medium',
        };
    }

    private function resolvePrimaryUrl(DigitalAsset $asset): string
    {
        $url = is_string($asset->primary_url) ? trim($asset->primary_url) : '';

        if ($url === '') {
            throw new InvalidArgumentException('Website diagnosis requires a DigitalAsset with primary_url.');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Website diagnosis primary_url must be an http(s) URL.');
        }

        return $url;
    }

    private function effectiveUrl(Response $response, string $fallback): ?string
    {
        $effective = $response->effectiveUri();

        if ($effective !== null) {
            return (string) $effective;
        }

        return $fallback;
    }

    private function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $path = $path === '/' ? '' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }
}
