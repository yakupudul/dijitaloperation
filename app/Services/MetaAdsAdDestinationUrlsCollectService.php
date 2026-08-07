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
 * Read-only Meta Ads ad destination URL collector (Marketing API GET; no writes).
 */
class MetaAdsAdDestinationUrlsCollectService
{
    public const MODULE_ID = 'meta-ads-connector';

    public const CONNECTION_TYPE = 'meta_ads_api';

    public const ASSET_TYPE = 'meta_ads';

    public const EVIDENCE_TYPE_AD_DESTINATION_URLS = 'meta_ads_ad_destination_urls';

    private const GRAPH_API_VERSION = 'v21.0';

    private const ADS_FIELDS = 'id,status,creative{id,name,link_url,object_url,object_story_spec,asset_feed_spec}';

    /**
     * Collect normalized Meta Ads ad destination URLs for a connection and persist Evidence.
     */
    public function collect(CoreConnection $connection): Run
    {
        $connection->loadMissing(['digitalAsset', 'credential']);

        if ($connection->type !== self::CONNECTION_TYPE) {
            throw new InvalidArgumentException('Meta Ads destination URL collect requires a CoreConnection with type meta_ads_api.');
        }

        if (! $connection->enabled) {
            throw new InvalidArgumentException('Meta Ads destination URL collect requires an enabled connection.');
        }

        $asset = $connection->digitalAsset;

        if ($asset === null || $asset->type !== self::ASSET_TYPE) {
            throw new InvalidArgumentException('Meta Ads destination URL collect requires a meta_ads Digital Asset.');
        }

        $adAccountId = $this->resolveAdAccountId($connection);
        $accessToken = $this->accessToken($connection);

        if ($accessToken === null) {
            throw new InvalidArgumentException('Meta Ads destination URL collect requires an encrypted access_token credential.');
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
                'collect' => 'ads-destination-urls-list',
            ],
        ]);

        try {
            $observedAt = now();
            $fetch = $this->listAds($adAccountId, $accessToken);
            $payload = $this->normalizeDestinationUrlsEvidence($adAccountId, $fetch);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_AD_DESTINATION_URLS,
                'title' => 'Meta Ads ad destination URLs',
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
                        'destination_url_count' => $payload['destination_url_count'] ?? 0,
                    ]),
                ]);
            } else {
                $error = is_string($payload['status_or_error'] ?? null)
                    ? $payload['status_or_error']
                    : 'meta_ads_destination_urls_collect_failed';

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

    private function resolveAdAccountId(CoreConnection $connection): string
    {
        $config = is_array($connection->config) ? $connection->config : [];
        $raw = isset($config['ad_account_id']) && is_string($config['ad_account_id'])
            ? trim($config['ad_account_id'])
            : '';

        if ($raw === '') {
            throw new InvalidArgumentException('Meta Ads destination URL collect requires config.ad_account_id.');
        }

        if (preg_match('/^act_([0-9]+)$/', $raw, $matches) === 1) {
            return 'act_'.$matches[1];
        }

        if (preg_match('/^[0-9]+$/', $raw) === 1) {
            return 'act_'.$raw;
        }

        throw new InvalidArgumentException('Meta Ads destination URL collect config.ad_account_id must be numeric or act_{id}.');
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
    private function listAds(string $adAccountId, string $accessToken): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/ads',
            self::GRAPH_API_VERSION,
            $adAccountId,
        );

        try {
            /** @var Response $response */
            $response = Http::timeout(20)
                ->acceptJson()
                ->withToken($accessToken)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP-MetaAdsConnector/1.0',
                ])
                ->get($url, [
                    'fields' => self::ADS_FIELDS,
                    'limit' => 100,
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
     *     destination_urls: list<string>,
     *     destination_url_hosts: list<string>,
     *     destination_url_count: int,
     *     ok: bool,
     *     status_code: int|null,
     *     status_or_error: string,
     *     error_class: string|null,
     *     fetch_method: string
     * }
     */
    private function normalizeDestinationUrlsEvidence(string $requestedAdAccountId, array $fetch): array
    {
        $statusCode = $fetch['status_code'];
        $errorClass = $fetch['error_class'];
        $body = $fetch['body'];

        $destinationUrls = [];

        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $ad) {
                if (! is_array($ad)) {
                    continue;
                }

                $creative = $ad['creative'] ?? null;
                if (! is_array($creative)) {
                    continue;
                }

                foreach ($this->extractUrlsFromCreative($creative) as $url) {
                    $destinationUrls[] = $url;
                }
            }
        }

        $destinationUrls = array_values(array_unique($destinationUrls));
        $hosts = [];
        foreach ($destinationUrls as $url) {
            $host = $this->hostFromUrl($url);
            if ($host !== null) {
                $hosts[] = $host;
            }
        }
        $hosts = array_values(array_unique($hosts));

        $ok = $errorClass === null && $statusCode === 200;

        $statusOrError = $errorClass !== null
            ? $errorClass.(isset($fetch['error_message']) ? ': '.$fetch['error_message'] : '')
            : (string) ($statusCode ?? 'unknown');

        if ($errorClass === null && $statusCode !== null && $statusCode >= 400) {
            if (is_array($body) && isset($body['error']) && is_array($body['error'])) {
                $apiMessage = $body['error']['message'] ?? null;
                $statusOrError = is_string($apiMessage) && $apiMessage !== ''
                    ? 'api_error: '.$apiMessage
                    : (string) $statusCode;
            }
        }

        return [
            'requested_ad_account_id' => $requestedAdAccountId,
            'destination_urls' => $destinationUrls,
            'destination_url_hosts' => $hosts,
            'destination_url_count' => count($destinationUrls),
            'ok' => $ok,
            'status_code' => $statusCode,
            'status_or_error' => $statusOrError,
            'error_class' => $errorClass,
            'fetch_method' => 'meta_ads_ads_list_get',
        ];
    }

    /**
     * @param  array<string, mixed>  $creative
     * @return list<string>
     */
    private function extractUrlsFromCreative(array $creative): array
    {
        $urls = [];

        foreach (['link_url', 'object_url'] as $key) {
            $value = $creative[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $urls[] = trim($value);
            }
        }

        $storySpec = $creative['object_story_spec'] ?? null;
        if (is_array($storySpec)) {
            $linkData = $storySpec['link_data'] ?? null;
            if (is_array($linkData) && isset($linkData['link']) && is_string($linkData['link']) && trim($linkData['link']) !== '') {
                $urls[] = trim($linkData['link']);
            }

            $templateData = $storySpec['template_data'] ?? null;
            if (is_array($templateData) && isset($templateData['link']) && is_string($templateData['link']) && trim($templateData['link']) !== '') {
                $urls[] = trim($templateData['link']);
            }

            $videoData = $storySpec['video_data'] ?? null;
            if (is_array($videoData)) {
                $cta = $videoData['call_to_action'] ?? null;
                if (is_array($cta)) {
                    $value = $cta['value'] ?? null;
                    if (is_array($value) && isset($value['link']) && is_string($value['link']) && trim($value['link']) !== '') {
                        $urls[] = trim($value['link']);
                    }
                }
            }
        }

        $assetFeed = $creative['asset_feed_spec'] ?? null;
        if (is_array($assetFeed) && isset($assetFeed['link_urls']) && is_array($assetFeed['link_urls'])) {
            foreach ($assetFeed['link_urls'] as $linkUrl) {
                if (! is_array($linkUrl)) {
                    continue;
                }

                $websiteUrl = $linkUrl['website_url'] ?? null;
                if (is_string($websiteUrl) && trim($websiteUrl) !== '') {
                    $urls[] = trim($websiteUrl);
                }
            }
        }

        return $urls;
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
