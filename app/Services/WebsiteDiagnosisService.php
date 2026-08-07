<?php

namespace App\Services;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Support\SslCertificateProbe;
use App\Support\SslCertParser;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class WebsiteDiagnosisService
{
    public const MODULE_ID = 'website-diagnosis';

    public const CATALOG_REACHABILITY_HTTP = 'reachability-http';

    public const CATALOG_HTTPS_TLS_VALIDITY = 'https-tls-validity';

    public const EVIDENCE_TYPE_TLS_INFO = 'tls_info';

    private const TLS_EXPIRING_SOON_DAYS = 7;

    private const CONFIDENCE_HIGH = 0.9000;

    public function __construct(
        private readonly SslCertificateProbe $sslCertificateProbe = new SslCertificateProbe,
    ) {}

    /**
     * Deterministic Website Diagnosis slice: reachability + HTTPS/TLS validity → Evidence → Finding upsert.
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
                'checks' => [self::CATALOG_REACHABILITY_HTTP, self::CATALOG_HTTPS_TLS_VALIDITY],
            ],
        ]);

        try {
            $observedAt = now();
            $normalized = $this->fetchHttp($primaryUrl);

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

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
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
     *     error_or_status: string
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

            return [
                'url' => $url,
                'status_code' => $statusCode,
                'effective_url' => $effectiveUrl,
                'is_https' => $this->isHttps($effectiveUrl ?? $url),
                'response_is_ok' => $responseIsOk,
                'error_class' => null,
                'error_or_status' => (string) $statusCode,
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
            ];
        }
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

        return $finding;
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
