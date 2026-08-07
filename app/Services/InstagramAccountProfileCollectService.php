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
 * Read-only Instagram Graph API account profile collector (no Instagram writes).
 */
class InstagramAccountProfileCollectService
{
    public const MODULE_ID = 'instagram-connector';

    public const CONNECTION_TYPE = 'instagram_graph_api';

    public const ASSET_TYPE = 'instagram';

    public const EVIDENCE_TYPE_ACCOUNT_PROFILE = 'instagram_account_profile';

    private const GRAPH_API_VERSION = 'v21.0';

    private const PROFILE_FIELDS = 'id,username,name,account_type,website,biography';

    /**
     * Collect normalized Instagram account profile fields (including website) and persist Evidence.
     */
    public function collect(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('Instagram profile collect requires a CoreConnection with type instagram_graph_api.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('Instagram profile collect requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== self::ASSET_TYPE) {
            throw new InvalidArgumentException('Instagram profile collect requires an instagram Digital Asset.');
        }

        $igUserId = $this->resolveIgUserId($connection);
        $accessToken = $this->accessToken($connection);

        if ($accessToken === null) {
            throw new InvalidArgumentException('Instagram profile collect requires an encrypted access_token credential.');
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
                'collect' => 'ig-user-profile-get',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->getIgUserProfile($igUserId, $accessToken);
            $payload = $this->normalizeAccountProfileEvidence($igUserId, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_ACCOUNT_PROFILE,
                'title' => 'Instagram account profile',
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
                        'has_website' => is_string($payload['website'] ?? null) && trim((string) $payload['website']) !== '',
                    ]),
                ]);
            } else {
                $error = is_string($payload['status_or_error'] ?? null)
                    ? $payload['status_or_error']
                    : 'instagram_profile_collect_failed';

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

    private function resolveIgUserId(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $raw = isset($config['ig_user_id']) && is_string($config['ig_user_id'])
            ? trim($config['ig_user_id'])
            : '';

        if ($raw === '') {
            throw new InvalidArgumentException('Instagram profile collect requires config.ig_user_id.');
        }

        if (preg_match('/^[0-9]+$/', $raw) !== 1) {
            throw new InvalidArgumentException('Instagram profile collect config.ig_user_id must be numeric.');
        }

        return $raw;
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

        return $accessToken !== '' ? $accessToken : null;
    }

    /**
     * @return array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }
     */
    private function getIgUserProfile(string $igUserId, string $accessToken): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s',
            self::GRAPH_API_VERSION,
            $igUserId,
        );

        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($accessToken)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-InstagramConnector/1.0',
                ])
                ->get($url, [
                    'fields' => self::PROFILE_FIELDS,
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
     *     requested_ig_user_id: string,
     *     ig_user_id: string|null,
     *     username: string|null,
     *     name: string|null,
     *     account_type: string|null,
     *     website: string|null,
     *     website_host: string|null,
     *     biography: string|null,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null,
     *     fetch_method: string
     * }
     */
    private function normalizeAccountProfileEvidence(string $requestedIgUserId, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];

        $responseId = is_array($body) && isset($body['id']) && is_string($body['id'])
            ? $body['id']
            : null;
        $username = is_array($body) && isset($body['username']) && is_string($body['username'])
            ? $body['username']
            : null;
        $name = is_array($body) && isset($body['name']) && is_string($body['name'])
            ? $body['name']
            : null;
        $accountType = is_array($body) && isset($body['account_type']) && is_string($body['account_type'])
            ? $body['account_type']
            : null;
        $website = is_array($body) && isset($body['website']) && is_string($body['website'])
            ? trim($body['website'])
            : null;
        $biography = is_array($body) && isset($body['biography']) && is_string($body['biography'])
            ? $body['biography']
            : null;

        if ($website === '') {
            $website = null;
        }

        $websiteHost = is_string($website) ? $this->hostFromUrl($website) : null;

        $matched = $responseId !== null && $responseId === $requestedIgUserId;
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

        return [
            'requested_ig_user_id' => $requestedIgUserId,
            'ig_user_id' => $responseId,
            'username' => $username,
            'name' => $name,
            'account_type' => $accountType,
            'website' => $website,
            'website_host' => $websiteHost,
            'biography' => $biography,
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
            'fetch_method' => 'instagram_graph_ig_user_get',
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
