<?php

namespace App\Services;

use App\Models\CoreConnection;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Integrations\Google\GoogleOAuthConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only Google Ads final URL collector (GAQL search; no Ads mutations).
 */
class GoogleAdsLandingFinalUrlsCollectService
{
    public const MODULE_ID = 'google-ads-connector';

    public const CONNECTION_TYPE = 'google_ads_api';

    public const ASSET_TYPE = 'google_ads';

    public const EVIDENCE_TYPE_LANDING_FINAL_URLS = 'google_ads_landing_final_urls';

    private const FINAL_URLS_QUERY = <<<'GAQL'
SELECT ad_group_ad.ad.final_urls
FROM ad_group_ad
WHERE ad_group_ad.status != 'REMOVED'
LIMIT 100
GAQL;

    /**
     * Collect normalized Google Ads ad final URLs for a connection and persist Evidence.
     */
    public function collect(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('Google Ads landing URL collect requires a CoreConnection with type google_ads_api.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('Google Ads landing URL collect requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== self::ASSET_TYPE) {
            throw new InvalidArgumentException('Google Ads landing URL collect requires a google_ads Digital Asset.');
        }

        $customerId = $this->resolveCustomerId($connection);
        $credentials = $this->apiCredentials($connection);

        if ($credentials === null) {
            throw new InvalidArgumentException('Google Ads landing URL collect requires encrypted access_token and developer_token credentials.');
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
                'collect' => 'ad-final-urls-search',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->searchFinalUrls($customerId, $credentials, $connection);
            $payload = $this->normalizeLandingFinalUrlsEvidence($customerId, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_LANDING_FINAL_URLS,
                'title' => 'Google Ads landing final URLs',
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
                    'metadata' => array_merge($run->metadata ?? [], [
                        'collect_ok' => true,
                        'final_url_count' => $payload['final_url_count'] ?? 0,
                    ]),
                ]);
            } else {
                $error = is_string($payload['status_or_error'] ?? null)
                    ? $payload['status_or_error']
                    : 'google_ads_landing_urls_collect_failed';

                $connection->forceFill([
                    'last_error' => $error,
                ])->save();

                $run->update([
                    'status' => 'completed',
                    'finished_at' => now(),
                    'metadata' => array_merge($run->metadata ?? [], [
                        'collect_ok' => false,
                        'status_or_error' => $error,
                    ]),
                ]);
            }
        } catch (Throwable $exception) {
            $connection->forceFill([
                'last_error' => 'collect_exception: '.$exception->getMessage(),
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

    private function resolveCustomerId(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $raw = isset($config['customer_id']) && is_string($config['customer_id'])
            ? trim($config['customer_id'])
            : '';

        if ($raw === '') {
            throw new InvalidArgumentException('Google Ads landing URL collect requires config.customer_id.');
        }

        if (preg_match('/^customers\/([0-9]+)$/', $raw, $matches) === 1) {
            return $matches[1];
        }

        $digits = str_replace('-', '', $raw);

        if (preg_match('/^[0-9]+$/', $digits) !== 1) {
            throw new InvalidArgumentException('Google Ads landing URL collect config.customer_id must be numeric or customers/{id}.');
        }

        return $digits;
    }

    /**
     * @return array{access_token: string, developer_token: string}|null
     */
    private function apiCredentials(CoreConnection $connection): ?array
    {
        $payload = $connection->credential?->encrypted_payload;

        if (! is_array($payload)) {
            return null;
        }

        $accessToken = isset($payload['access_token']) && is_string($payload['access_token'])
            ? trim($payload['access_token'])
            : '';
        $developerToken = isset($payload['developer_token']) && is_string($payload['developer_token'])
            ? trim($payload['developer_token'])
            : '';

        if ($accessToken === '' || $developerToken === '') {
            return null;
        }

        return [
            'access_token' => $accessToken,
            'developer_token' => $developerToken,
        ];
    }

    /**
     * @param  array{access_token: string, developer_token: string}  $credentials
     * @return array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }
     */
    private function searchFinalUrls(string $customerId, array $credentials, CoreConnection $connection): array
    {
        $headers = [
            'User-Agent' => 'MoxDOP-GoogleAdsConnector/1.0',
            'developer-token' => $credentials['developer_token'],
        ];

        $config = is_array($connection->config) ? $connection->config : [];
        $loginCustomerId = isset($config['login_customer_id']) && is_string($config['login_customer_id'])
            ? trim(str_replace('-', '', $config['login_customer_id']))
            : '';

        if ($loginCustomerId !== '' && preg_match('/^[0-9]+$/', $loginCustomerId) === 1) {
            $headers['login-customer-id'] = $loginCustomerId;
        }

        $url = GoogleOAuthConfig::adsApiUrl('customers/'.$customerId.'/googleAds:search');

        try {
            /** @var Response $response */
            $response = Http::timeout(20)
                ->acceptJson()
                ->asJson()
                ->withToken($credentials['access_token'])
                ->withHeaders($headers)
                ->post($url, [
                    'query' => self::FINAL_URLS_QUERY,
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
     *     requested_customer_id: string,
     *     final_urls: list<string>,
     *     final_url_hosts: list<string>,
     *     final_url_count: int,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null,
     *     fetch_method: string
     * }
     */
    private function normalizeLandingFinalUrlsEvidence(string $requestedCustomerId, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];

        $finalUrls = [];
        $hosts = [];

        if (is_array($body) && isset($body['results']) && is_array($body['results'])) {
            foreach ($body['results'] as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $adGroupAd = $result['adGroupAd'] ?? null;
                if (! is_array($adGroupAd)) {
                    continue;
                }

                $ad = $adGroupAd['ad'] ?? null;
                if (! is_array($ad)) {
                    continue;
                }

                $urls = $ad['finalUrls'] ?? null;
                if (! is_array($urls)) {
                    continue;
                }

                foreach ($urls as $url) {
                    if (! is_string($url)) {
                        continue;
                    }

                    $trimmed = trim($url);
                    if ($trimmed === '') {
                        continue;
                    }

                    $finalUrls[] = $trimmed;
                    $host = $this->hostFromUrl($trimmed);
                    if ($host !== null) {
                        $hosts[] = $host;
                    }
                }
            }
        }

        $finalUrls = array_values(array_unique($finalUrls));
        $hosts = array_values(array_unique($hosts));

        $ok = $errorClass === null && $statusCode === 200;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        return [
            'requested_customer_id' => $requestedCustomerId,
            'final_urls' => $finalUrls,
            'final_url_hosts' => $hosts,
            'final_url_count' => count($finalUrls),
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
            'fetch_method' => 'google_ads_search_gaql',
        ];
    }

    private function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return strtolower($host);
    }
}
