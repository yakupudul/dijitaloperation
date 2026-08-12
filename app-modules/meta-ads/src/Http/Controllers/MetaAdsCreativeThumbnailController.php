<?php

namespace MoxDop\MetaAds\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CoreAssetBinding;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;

/**
 * Bounded, authenticated proxy for Meta creative thumbnail URLs.
 *
 * Prevents broken browser previews (CDN referrer limits) without exposing
 * integration tokens or archiving full media binaries.
 */
final class MetaAdsCreativeThumbnailController extends Controller
{
    private const int MAX_BYTES = 512_000;

    private const int CACHE_SECONDS = 86_400;

    public function __construct(
        private readonly MetaCredentialResolver $credentialResolver,
        private readonly MetaApiClient $metaApiClient,
    ) {}

    public function __invoke(Request $request, DigitalAsset $digitalAsset, string $creativeId): Response
    {
        abort_unless($digitalAsset->type === 'meta_ads', 404);
        abort_unless(preg_match('/^\d+$/', $creativeId) === 1, 404);

        $cacheKey = 'meta-creative-thumb:'.$digitalAsset->id.':'.$creativeId;

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['body'], $cached['content_type'])) {
            return response($cached['body'], 200, $this->responseHeaders((string) $cached['content_type']));
        }

        $integration = $this->integrationForAsset($digitalAsset);
        if ($integration === null || ! $this->credentialResolver->isConfigured($integration)) {
            return response('', 404);
        }

        $thumbnailUrl = $this->resolveThumbnailUrl($digitalAsset, $creativeId, $integration);
        if ($thumbnailUrl === null || ! $this->isAllowedThumbnailHost($thumbnailUrl)) {
            return response('', 404);
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'User-Agent' => 'MoxDOP/1.0 (creative-thumbnail-proxy)',
                    'Referer' => 'https://www.facebook.com/',
                ])
                ->get($thumbnailUrl);
        } catch (\Throwable) {
            return response('', 502);
        }

        if (! $response->successful()) {
            return response('', $response->status() >= 400 ? $response->status() : 502);
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            return response('', 413);
        }

        $contentType = $response->header('Content-Type') ?: 'image/jpeg';
        if (! str_starts_with(strtolower($contentType), 'image/')) {
            return response('', 415);
        }

        Cache::put($cacheKey, [
            'body' => $body,
            'content_type' => $contentType,
        ], self::CACHE_SECONDS);

        return response($body, 200, $this->responseHeaders($contentType));
    }

    private function integrationForAsset(DigitalAsset $asset): ?CoreIntegration
    {
        $binding = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', MetaAdsBoundCollector::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->with('externalResource.integration')
            ->first();

        $integration = $binding?->externalResource?->integration;
        if ($integration === null || $integration->provider !== ProviderRegistry::META) {
            return null;
        }

        return $integration;
    }

    private function resolveThumbnailUrl(DigitalAsset $asset, string $creativeId, CoreIntegration $integration): ?string
    {
        try {
            $fresh = $this->metaApiClient->get($integration, $creativeId, [
                'fields' => 'thumbnail_url',
            ]);
            $url = $fresh['thumbnail_url'] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable) {
            // Fall back to bounded Evidence reference.
        }

        return $this->thumbnailUrlFromEvidence($asset, $creativeId);
    }

    private function thumbnailUrlFromEvidence(DigitalAsset $asset, string $creativeId): ?string
    {
        $evidence = Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', MetaAdsBoundCollector::EVIDENCE_CREATIVE_METADATA)
            ->where('source_module', MetaAdsBoundCollector::MODULE_ID)
            ->orderByDesc('id')
            ->first();

        $rows = data_get($evidence?->payload, 'rows');
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['creative_id'] ?? '') === $creativeId) {
                $url = $row['thumbnail_url'] ?? null;

                return is_string($url) && $url !== '' ? $url : null;
            }
        }

        return null;
    }

    private function isAllowedThumbnailHost(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower($host);

        return str_ends_with($host, '.fbcdn.net')
            || str_ends_with($host, '.facebook.com')
            || str_ends_with($host, '.fbsbx.com')
            || $host === parse_url(MetaApiConfig::graphBaseUrl(), PHP_URL_HOST);
    }

    /**
     * @return array<string, string>
     */
    private function responseHeaders(string $contentType): array
    {
        return [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
