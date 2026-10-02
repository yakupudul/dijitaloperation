<?php

namespace App\Services\Collection\Providers\SearchConsole;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use Illuminate\Http\Client\Response;

/**
 * Read-only Search Console API client. No sitemap submit/delete, no indexing writes.
 */
final class SearchConsoleApiClient
{
    public const string WEBMASTERS_V3 = 'https://www.googleapis.com/webmasters/v3';

    public const string URL_INSPECTION_V1 = 'https://searchconsole.googleapis.com/v1';

    public function __construct(
        private readonly GoogleApiClient $http,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public function searchAnalyticsQuery(CoreIntegration $integration, string $siteUrl, array $body): Response
    {
        $url = self::WEBMASTERS_V3.'/sites/'.$this->encodeSiteUrl($siteUrl).'/searchAnalytics/query';

        return $this->http->post($integration, $url, $body, GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE);
    }

    public function getSite(CoreIntegration $integration, string $siteUrl): Response
    {
        $url = self::WEBMASTERS_V3.'/sites/'.$this->encodeSiteUrl($siteUrl);

        return $this->http->get($integration, $url, [], GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE);
    }

    public function listSitemaps(CoreIntegration $integration, string $siteUrl): Response
    {
        $url = self::WEBMASTERS_V3.'/sites/'.$this->encodeSiteUrl($siteUrl).'/sitemaps';

        return $this->http->get($integration, $url, [], GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE);
    }

    /**
     * @return array{inspectionUrl: string, siteUrl: string, languageCode?: string}
     */
    public function inspectUrl(CoreIntegration $integration, string $siteUrl, string $inspectionUrl): Response
    {
        $url = self::URL_INSPECTION_V1.'/urlInspection/index:inspect';

        return $this->http->post($integration, $url, [
            'inspectionUrl' => $inspectionUrl,
            'siteUrl' => $siteUrl,
        ], GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE);
    }

    public function encodeSiteUrl(string $siteUrl): string
    {
        return rawurlencode($siteUrl);
    }
}
