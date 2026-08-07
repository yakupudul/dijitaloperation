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
 * Read-only Meta Ads ad-account access probe (no Meta Marketing API writes).
 */
class MetaAdsConnectionProbeService
{
    public const MODULE_ID = 'meta-ads-connector';

    public const CONNECTION_TYPE = 'meta_ads_api';

    public const ASSET_TYPE = 'meta_ads';

    public const EVIDENCE_TYPE_META_ADS_ACCOUNT_ACCESS = 'meta_ads_account_access';

    private const GRAPH_API_VERSION = 'v21.0';

    private const AD_ACCOUNT_FIELDS = 'id,name,account_status,currency,timezone_name';

    /**
     * Verify a Meta Ads connection can access the configured ad account and persist Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('Meta Ads probe requires a CoreConnection with type meta_ads_api.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('Meta Ads probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== self::ASSET_TYPE) {
            throw new InvalidArgumentException('Meta Ads probe requires a meta_ads Digital Asset.');
        }

        $adAccountId = $this->resolveAdAccountId($connection);
        $accessToken = $this->accessToken($connection);

        if ($accessToken === null) {
            throw new InvalidArgumentException('Meta Ads probe requires an encrypted access_token credential.');
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
                'probe' => 'ad-account-get',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->getAdAccount($adAccountId, $accessToken);
            $payload = $this->normalizeAccountAccessEvidence($adAccountId, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_META_ADS_ACCOUNT_ACCESS,
                'title' => 'Meta Ads account access',
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
                    : 'meta_ads_probe_failed';

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

    private function resolveAdAccountId(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $raw = isset($config['ad_account_id']) && is_string($config['ad_account_id'])
            ? trim($config['ad_account_id'])
            : '';

        if ($raw === '') {
            throw new InvalidArgumentException('Meta Ads probe requires config.ad_account_id.');
        }

        if (preg_match('/^act_([0-9]+)$/', $raw, $matches) === 1) {
            return 'act_'.$matches[1];
        }

        if (preg_match('/^[0-9]+$/', $raw) === 1) {
            return 'act_'.$raw;
        }

        throw new InvalidArgumentException('Meta Ads probe config.ad_account_id must be numeric or act_{id}.');
    }

    private function accessToken(CoreConnection $connection): ?string
    {
        $payload = $connection->credential?->encrypted_payload;

        if (! is_array($payload)) {
            return null;
        }

        $accessToken = isset($payload['access_token']) && is_string($payload['access_token'])
            ? trim($payload['access_token'])
            : '';

        if ($accessToken === '') {
            return null;
        }

        return $accessToken;
    }

    /**
     * @return array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }
     */
    private function getAdAccount(string $adAccountId, string $accessToken): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s',
            self::GRAPH_API_VERSION,
            $adAccountId,
        );

        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($accessToken)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-MetaAdsConnector/1.0',
                ])
                ->get($url, [
                    'fields' => self::AD_ACCOUNT_FIELDS,
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
     *     requested_ad_account_id: string,
     *     ad_account_id: string|null,
     *     name: string|null,
     *     account_status: int|null,
     *     currency: string|null,
     *     timezone_name: string|null,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizeAccountAccessEvidence(string $requestedAdAccountId, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];

        $responseId = is_array($body) && isset($body['id']) && is_string($body['id'])
            ? $body['id']
            : null;
        $name = is_array($body) && isset($body['name']) && is_string($body['name'])
            ? $body['name']
            : null;
        $accountStatus = is_array($body) && isset($body['account_status']) && is_int($body['account_status'])
            ? $body['account_status']
            : null;
        $currency = is_array($body) && isset($body['currency']) && is_string($body['currency'])
            ? $body['currency']
            : null;
        $timezoneName = is_array($body) && isset($body['timezone_name']) && is_string($body['timezone_name'])
            ? $body['timezone_name']
            : null;

        $matched = $responseId !== null && (
            $responseId === $requestedAdAccountId
            || $responseId === str_replace('act_', '', $requestedAdAccountId)
            || ('act_'.$responseId) === $requestedAdAccountId
        );

        $ok = $errorClass === null && $statusCode === 200 && $matched;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($errorClass === null && ($statusCode === 404 || ($statusCode === 200 && ! $matched) || ($statusCode !== null && $statusCode >= 400))) {
            if ($statusCode === 404 || ($statusCode === 200 && ! $matched)) {
                $statusOrError = 'account_not_accessible';
            } elseif (is_array($body) && isset($body['error']) && is_array($body['error'])) {
                $apiMessage = $body['error']['message'] ?? null;
                $statusOrError = is_string($apiMessage) && $apiMessage !== ''
                    ? 'api_error: '.$apiMessage
                    : 'account_not_accessible';
            } else {
                $statusOrError = 'account_not_accessible';
            }
        }

        $normalizedId = null;
        if ($responseId !== null) {
            $normalizedId = str_starts_with($responseId, 'act_')
                ? $responseId
                : 'act_'.$responseId;
        }

        return [
            'requested_ad_account_id' => $requestedAdAccountId,
            'ad_account_id' => $normalizedId,
            'name' => $name,
            'account_status' => $accountStatus,
            'currency' => $currency,
            'timezone_name' => $timezoneName,
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }
}
