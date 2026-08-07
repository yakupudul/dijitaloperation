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
 * Read-only Google Ads accessible-customer probe (no Ads writes).
 */
class GoogleAdsConnectionProbeService
{
    public const MODULE_ID = 'google-ads-connector';

    public const CONNECTION_TYPE = 'google_ads_api';

    public const ASSET_TYPE = 'google_ads';

    public const EVIDENCE_TYPE_GOOGLE_ADS_ACCOUNT_ACCESS = 'google_ads_account_access';

    private const LIST_ACCESSIBLE_CUSTOMERS_URL = 'https://googleads.googleapis.com/v18/customers:listAccessibleCustomers';

    /**
     * Verify a Google Ads connection can access the configured customer and persist Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('Google Ads probe requires a CoreConnection with type google_ads_api.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('Google Ads probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== self::ASSET_TYPE) {
            throw new InvalidArgumentException('Google Ads probe requires a google_ads Digital Asset.');
        }

        $customerId = $this->resolveCustomerId($connection);
        $credentials = $this->apiCredentials($connection);

        if ($credentials === null) {
            throw new InvalidArgumentException('Google Ads probe requires encrypted access_token and developer_token credentials.');
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
                'probe' => 'list-accessible-customers',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->listAccessibleCustomers($credentials, $connection);
            $payload = $this->normalizeAccountAccessEvidence($customerId, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_GOOGLE_ADS_ACCOUNT_ACCESS,
                'title' => 'Google Ads account access',
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
                    : 'google_ads_probe_failed';

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

    private function resolveCustomerId(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $raw = isset($config['customer_id']) && is_string($config['customer_id'])
            ? trim($config['customer_id'])
            : '';

        if ($raw === '') {
            throw new InvalidArgumentException('Google Ads probe requires config.customer_id.');
        }

        if (preg_match('/^customers\/([0-9]+)$/', $raw, $matches) === 1) {
            return $matches[1];
        }

        $digits = str_replace('-', '', $raw);

        if (preg_match('/^[0-9]+$/', $digits) !== 1) {
            throw new InvalidArgumentException('Google Ads probe config.customer_id must be numeric or customers/{id}.');
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
    private function listAccessibleCustomers(array $credentials, CoreConnection $connection): array
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

        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($credentials['access_token'])
                ->withHeaders($headers)
                ->get(self::LIST_ACCESSIBLE_CUSTOMERS_URL);

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
     *     matched_customer_resource: string|null,
     *     accessible_customer_count: int,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizeAccountAccessEvidence(string $requestedCustomerId, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];
        $resourceNames = [];

        if (is_array($body) && isset($body['resourceNames']) && is_array($body['resourceNames'])) {
            foreach ($body['resourceNames'] as $name) {
                if (is_string($name) && $name !== '') {
                    $resourceNames[] = $name;
                }
            }
        }

        $matched = null;
        $expected = 'customers/'.$requestedCustomerId;

        foreach ($resourceNames as $name) {
            if ($name === $expected) {
                $matched = $name;
                break;
            }
        }

        $ok = $errorClass === null && $statusCode === 200 && $matched !== null;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($errorClass === null && $statusCode === 200 && $matched === null) {
            $statusOrError = 'customer_not_accessible';
        }

        return [
            'requested_customer_id' => $requestedCustomerId,
            'matched_customer_resource' => $matched,
            'accessible_customer_count' => count($resourceNames),
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }
}
