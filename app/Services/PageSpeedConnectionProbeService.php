<?php

namespace App\Services;

use App\Models\CoreConnection;
use App\Models\Evidence;
use App\Models\Run;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only PageSpeed Insights (Lighthouse lab) probe (no external writes).
 */
class PageSpeedConnectionProbeService
{
    public const MODULE_ID = 'pagespeed-connector';

    public const CONNECTION_TYPE = 'pagespeed';

    public const EVIDENCE_TYPE_PAGESPEED_LAB = 'pagespeed_lab';

    private const RUN_PAGESPEED_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Verify a PageSpeed connection can read lab metrics for the website URL and persist Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('PageSpeed probe requires a CoreConnection with type pagespeed.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('PageSpeed probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== 'website') {
            throw new InvalidArgumentException('PageSpeed probe requires a website Digital Asset.');
        }

        $url = $this->resolveUrl($connection);
        $strategy = $this->resolveStrategy($connection);
        $apiKey = $this->apiKey($connection);

        if ($apiKey === null) {
            throw new InvalidArgumentException('PageSpeed probe requires an encrypted api_key credential.');
        }

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => $connection->id,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'programmatic',
                'connector' => self::CONNECTION_TYPE,
                'probe' => 'runPagespeed',
                'strategy' => $strategy,
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->getRunPagespeed($url, $strategy, $apiKey);
            $payload = $this->normalizeLabEvidence($url, $strategy, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_PAGESPEED_LAB,
                'title' => 'PageSpeed lab metrics',
                'payload' => $payload,
                'observed_at' => $observedAt,
            ]);

            if (($payload['ok'] ?? false) === true) {
                $connection->forceFill([
                    'last_success_at' => $observedAt,
                    'last_error' => null,
                ])->save();

                $run->update([
                    'status' => 'completed',
                    'finished_at' => now(),
                ]);
            } else {
                $error = is_string($payload['status_or_error'] ?? null)
                    ? $payload['status_or_error']
                    : 'pagespeed_probe_failed';

                $connection->forceFill([
                    'last_error' => $error,
                ])->save();

                $run->update([
                    'status' => 'completed',
                    'finished_at' => now(),
                    'metadata' => array_merge($run->metadata ?? [], [
                        'probe_ok' => false,
                        'status_or_error' => $error,
                    ]),
                ]);
            }
        } catch (Throwable $exception) {
            $connection->forceFill([
                'last_error' => 'probe_exception: '.$exception->getMessage(),
            ])->save();

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'error' => $exception->getMessage(),
                ]),
            ]);

            throw $exception;
        }

        return $run->fresh(['evidence', 'coreConnection', 'digitalAsset']) ?? $run;
    }

    private function resolveUrl(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $configured = isset($config['url']) && is_string($config['url'])
            ? trim($config['url'])
            : '';

        if ($configured !== '') {
            return $configured;
        }

        $primary = is_string($connection->digitalAsset?->primary_url)
            ? trim($connection->digitalAsset->primary_url)
            : '';

        if ($primary === '') {
            throw new InvalidArgumentException('PageSpeed probe requires config.url or website primary_url.');
        }

        return $primary;
    }

    private function resolveStrategy(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $strategy = isset($config['strategy']) && is_string($config['strategy'])
            ? strtolower(trim($config['strategy']))
            : 'mobile';

        if (! in_array($strategy, ['mobile', 'desktop'], true)) {
            throw new InvalidArgumentException('PageSpeed probe config.strategy must be mobile or desktop.');
        }

        return $strategy;
    }

    private function apiKey(CoreConnection $connection): ?string
    {
        $payload = $connection->credential?->encrypted_payload;

        if (! is_array($payload)) {
            return null;
        }

        $key = isset($payload['api_key']) && is_string($payload['api_key'])
            ? trim($payload['api_key'])
            : '';

        return $key !== '' ? $key : null;
    }

    /**
     * @return array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }
     */
    private function getRunPagespeed(string $url, string $strategy, string $apiKey): array
    {
        try {
            /** @var Response $response */
            $response = Http::timeout(60)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-PageSpeedConnector/1.0',
                ])
                ->get(self::RUN_PAGESPEED_URL, [
                    'url' => $url,
                    'strategy' => $strategy,
                    'category' => 'performance',
                    'key' => $apiKey,
                ]);

            $json = $response->json();

            return [
                'status_code' => $response->status(),
                'error_class' => null,
                'body' => is_array($json) ? $json : null,
            ];
        } catch (ConnectionException $exception) {
            return [
                'status_code' => null,
                'error_class' => 'connection',
                'body' => null,
                'error_message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }  $fetch
     * @return array{
     *     requested_url: string,
     *     final_url: string|null,
     *     strategy: string,
     *     fetch_time: string|null,
     *     performance_score: int|null,
     *     lcp_ms: float|null,
     *     cls: float|null,
     *     fcp_ms: float|null,
     *     tbt_ms: float|null,
     *     speed_index_ms: float|null,
     *     lab_data: bool,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizeLabEvidence(string $requestedUrl, string $strategy, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];

        $lighthouse = is_array($body) && isset($body['lighthouseResult']) && is_array($body['lighthouseResult'])
            ? $body['lighthouseResult']
            : null;

        $finalUrl = null;
        $fetchTime = null;
        $performanceScore = null;
        $lcpMs = null;
        $cls = null;
        $fcpMs = null;
        $tbtMs = null;
        $speedIndexMs = null;

        if (is_array($lighthouse)) {
            if (isset($lighthouse['finalUrl']) && is_string($lighthouse['finalUrl'])) {
                $finalUrl = $lighthouse['finalUrl'];
            } elseif (isset($lighthouse['requestedUrl']) && is_string($lighthouse['requestedUrl'])) {
                $finalUrl = $lighthouse['requestedUrl'];
            }

            if (isset($lighthouse['fetchTime']) && is_string($lighthouse['fetchTime'])) {
                $fetchTime = $lighthouse['fetchTime'];
            }

            $categories = isset($lighthouse['categories']) && is_array($lighthouse['categories'])
                ? $lighthouse['categories']
                : [];
            $performance = isset($categories['performance']) && is_array($categories['performance'])
                ? $categories['performance']
                : [];

            if (isset($performance['score']) && is_numeric($performance['score'])) {
                $performanceScore = (int) round(((float) $performance['score']) * 100);
            }

            $audits = isset($lighthouse['audits']) && is_array($lighthouse['audits'])
                ? $lighthouse['audits']
                : [];

            $lcpMs = $this->auditNumericValue($audits, 'largest-contentful-paint');
            $cls = $this->auditNumericValue($audits, 'cumulative-layout-shift');
            $fcpMs = $this->auditNumericValue($audits, 'first-contentful-paint');
            $tbtMs = $this->auditNumericValue($audits, 'total-blocking-time');
            $speedIndexMs = $this->auditNumericValue($audits, 'speed-index');
        }

        $ok = $errorClass === null
            && $statusCode === 200
            && is_array($lighthouse)
            && $performanceScore !== null;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($errorClass === null && $statusCode === 200 && ! $ok) {
            $statusOrError = 'performance_metrics_missing';
        }

        return [
            'requested_url' => $requestedUrl,
            'final_url' => $finalUrl,
            'strategy' => $strategy,
            'fetch_time' => $fetchTime,
            'performance_score' => $performanceScore,
            'lcp_ms' => $lcpMs,
            'cls' => $cls,
            'fcp_ms' => $fcpMs,
            'tbt_ms' => $tbtMs,
            'speed_index_ms' => $speedIndexMs,
            'lab_data' => true,
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }

    /**
     * @param  array<string, mixed>  $audits
     */
    private function auditNumericValue(array $audits, string $auditId): ?float
    {
        $audit = isset($audits[$auditId]) && is_array($audits[$auditId])
            ? $audits[$auditId]
            : null;

        if ($audit === null || ! isset($audit['numericValue']) || ! is_numeric($audit['numericValue'])) {
            return null;
        }

        return (float) $audit['numericValue'];
    }
}
