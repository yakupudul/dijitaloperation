<?php

namespace App\Services;

use App\Models\CoreConnection;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Security\ConnectionCredentialAccessService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Read-only WordPress REST probe for Website connections (no external writes).
 */
class WordPressConnectionProbeService
{
    public const MODULE_ID = 'wordpress-connector';

    public const CONNECTION_TYPE = 'wordpress';

    public const EVIDENCE_TYPE_WORDPRESS_SITE = 'wordpress_site';

    /**
     * Probe a WordPress connection via GET-only REST calls and persist Run/Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('WordPress probe requires a CoreConnection with type wordpress.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('WordPress probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== 'website') {
            throw new InvalidArgumentException('WordPress probe requires a website Digital Asset.');
        }

        $baseUrl = $this->resolveBaseUrl($connection);
        $indexUrl = rtrim($baseUrl, '/').'/wp-json/';

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
                'probe' => 'wp-json-index',
            ],
        ]);

        try {
            $observedAt = now();
            $auth = $this->authorizationHeader($connection);
            $fetch = $this->getJson($indexUrl, $auth);
            $payload = $this->normalizeWordpressSiteEvidence($indexUrl, $fetch, $auth !== []);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_WORDPRESS_SITE,
                'title' => 'WordPress REST site index',
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
                    : 'wordpress_probe_failed';

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

    private function resolveBaseUrl(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $configured = isset($config['base_url']) && is_string($config['base_url'])
            ? trim($config['base_url'])
            : '';

        if ($configured !== '') {
            if (! filter_var($configured, FILTER_VALIDATE_URL)
                || ! in_array(parse_url($configured, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new InvalidArgumentException('WordPress connection config.base_url must be an http(s) URL.');
            }

            return rtrim($configured, '/');
        }

        $primary = is_string($connection->digitalAsset?->primary_url)
            ? trim($connection->digitalAsset->primary_url)
            : '';

        if ($primary === ''
            || ! filter_var($primary, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($primary, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('WordPress probe requires config.base_url or website primary_url.');
        }

        return rtrim($primary, '/');
    }

    /**
     * @return array{Authorization?: string}
     */
    private function authorizationHeader(CoreConnection $connection): array
    {
        $access = app(ConnectionCredentialAccessService::class);
        $username = $access->wordpressUsername($connection);
        $password = $access->wordpressApplicationPassword($connection)?->reveal();

        if ($username === null || $username === '' || $password === null || $password === '') {
            return [];
        }

        return [
            'Authorization' => 'Basic '.base64_encode($username.':'.$password),
        ];
    }

    /**
     * @param  array{Authorization?: string}  $authHeaders
     * @return array{
     *     status_code: int|null,
     *     effective_url: string|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     wp_version_header: string|null
     * }
     */
    private function getJson(string $url, array $authHeaders): array
    {
        try {
            $pending = Http::timeout(15)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-WordPressConnector/1.0',
                ]);

            if ($authHeaders !== []) {
                $pending = $pending->withHeaders($authHeaders);
            }

            /** @var Response $response */
            $response = $pending->get($url);

            $json = $response->json();
            $body = is_array($json) ? $json : null;
            $versionHeader = $response->header('X-WP-Version');

            return [
                'status_code' => $response->status(),
                'effective_url' => $response->effectiveUri() !== null
                    ? (string) $response->effectiveUri()
                    : $url,
                'error_class' => null,
                'body' => $body,
                'wp_version_header' => is_string($versionHeader) && $versionHeader !== ''
                    ? $versionHeader
                    : null,
            ];
        } catch (ConnectionException $exception) {
            return [
                'status_code' => null,
                'effective_url' => null,
                'error_class' => 'connection',
                'body' => null,
                'wp_version_header' => null,
                'error_message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array{
     *     status_code: int|null,
     *     effective_url: string|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     wp_version_header: string|null,
     *     error_message?: string
     * }  $fetch
     * @return array{
     *     index_url: string,
     *     effective_url: string|null,
     *     status_code: int|null,
     *     ok: bool,
     *     site_name: string|null,
     *     description: string|null,
     *     site_url: string|null,
     *     home_url: string|null,
     *     namespaces: list<string>,
     *     has_wp_v2: bool,
     *     wordpress_version: string|null,
     *     auth_used: bool,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizeWordpressSiteEvidence(string $indexUrl, array $fetch, bool $authUsed): array
    {
        $body = $fetch['body'];
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $ok = $errorClass === null && $statusCode === 200 && is_array($body);

        $namespaces = [];
        if (is_array($body) && isset($body['namespaces']) && is_array($body['namespaces'])) {
            foreach ($body['namespaces'] as $namespace) {
                if (is_string($namespace) && $namespace !== '') {
                    $namespaces[] = $namespace;
                }
            }
        }

        $namespaces = array_values(array_slice(array_unique($namespaces), 0, 50));

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        return [
            'index_url' => $indexUrl,
            'effective_url' => $fetch['effective_url'],
            'status_code' => $statusCode,
            'ok' => $ok,
            'site_name' => is_array($body) && isset($body['name']) && is_string($body['name']) ? $body['name'] : null,
            'description' => is_array($body) && isset($body['description']) && is_string($body['description'])
                ? $body['description']
                : null,
            'site_url' => is_array($body) && isset($body['url']) && is_string($body['url']) ? $body['url'] : null,
            'home_url' => is_array($body) && isset($body['home']) && is_string($body['home']) ? $body['home'] : null,
            'namespaces' => $namespaces,
            'has_wp_v2' => in_array('wp/v2', $namespaces, true),
            'wordpress_version' => $fetch['wp_version_header'],
            'auth_used' => $authUsed,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }
}
