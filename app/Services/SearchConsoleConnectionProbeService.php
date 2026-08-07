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
 * Read-only Google Search Console property probe (no Search Console writes).
 */
class SearchConsoleConnectionProbeService
{
    public const MODULE_ID = 'search-console-connector';

    public const CONNECTION_TYPE = 'search_console';

    public const EVIDENCE_TYPE_GSC_PROPERTY = 'gsc_property';

    private const SITES_LIST_URL = 'https://www.googleapis.com/webmasters/v3/sites';

    /**
     * Verify a Search Console connection can read the configured property and persist Evidence.
     */
    public function probe(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('Search Console probe requires a CoreConnection with type search_console.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('Search Console probe requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== 'website') {
            throw new InvalidArgumentException('Search Console probe requires a website Digital Asset.');
        }

        $siteUrl = $this->resolveSiteUrl($connection);
        $accessToken = $this->accessToken($connection);

        if ($accessToken === null) {
            throw new InvalidArgumentException('Search Console probe requires an encrypted access_token credential.');
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
                'probe' => 'sites-list',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->getSitesList($accessToken);
            $payload = $this->normalizePropertyEvidence($siteUrl, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_GSC_PROPERTY,
                'title' => 'Search Console property access',
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
                    : 'search_console_probe_failed';

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

    private function resolveSiteUrl(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $configured = isset($config['site_url']) && is_string($config['site_url'])
            ? trim($config['site_url'])
            : '';

        if ($configured !== '') {
            return $configured;
        }

        $primary = is_string($connection->digitalAsset?->primary_url)
            ? trim($connection->digitalAsset->primary_url)
            : '';

        if ($primary === '') {
            throw new InvalidArgumentException('Search Console probe requires config.site_url or website primary_url.');
        }

        return rtrim($primary, '/').'/';
    }

    private function accessToken(CoreConnection $connection): ?string
    {
        $payload = $connection->credential?->encrypted_payload;

        if (! is_array($payload)) {
            return null;
        }

        $token = isset($payload['access_token']) && is_string($payload['access_token'])
            ? trim($payload['access_token'])
            : '';

        return $token !== '' ? $token : null;
    }

    /**
     * @return array{
     *     status_code: int|null,
     *     error_class: string|null,
     *     body: array<string, mixed>|null,
     *     error_message?: string
     * }
     */
    private function getSitesList(string $accessToken): array
    {
        try {
            /** @var Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->withToken($accessToken)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-SearchConsoleConnector/1.0',
                ])
                ->get(self::SITES_LIST_URL);

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
     *     requested_site_url: string,
     *     matched_site_url: string|null,
     *     permission_level: string|null,
     *     site_count: int,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null
     * }
     */
    private function normalizePropertyEvidence(string $requestedSiteUrl, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];
        $entries = [];

        if (is_array($body) && isset($body['siteEntry']) && is_array($body['siteEntry'])) {
            $entries = $body['siteEntry'];
        }

        $matchedUrl = null;
        $permission = null;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $candidate = isset($entry['siteUrl']) && is_string($entry['siteUrl'])
                ? $entry['siteUrl']
                : null;

            if ($candidate === null) {
                continue;
            }

            if ($this->siteUrlsMatch($requestedSiteUrl, $candidate)) {
                $matchedUrl = $candidate;
                $permission = isset($entry['permissionLevel']) && is_string($entry['permissionLevel'])
                    ? $entry['permissionLevel']
                    : null;
                break;
            }
        }

        $ok = $errorClass === null && $statusCode === 200 && $matchedUrl !== null;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($errorClass === null && $statusCode === 200 && $matchedUrl === null) {
            $statusOrError = 'property_not_found';
        }

        return [
            'requested_site_url' => $requestedSiteUrl,
            'matched_site_url' => $matchedUrl,
            'permission_level' => $permission,
            'site_count' => count($entries),
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
        ];
    }

    private function siteUrlsMatch(string $requested, string $candidate): bool
    {
        $normalize = static function (string $url): string {
            $trimmed = rtrim(trim($url), '/');

            return strtolower($trimmed);
        };

        return $normalize($requested) === $normalize($candidate);
    }
}
