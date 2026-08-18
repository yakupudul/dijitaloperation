<?php

namespace App\Services\Collection\Providers\Ga4;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use Illuminate\Http\Client\Response;

/**
 * Read-only GA4 Data API + Admin API client. No Admin/Data writes.
 */
final class Ga4ApiClient
{
    public const string DATA_API_V1BETA = 'https://analyticsdata.googleapis.com/v1beta';

    public const string ADMIN_API_V1BETA = 'https://analyticsadmin.googleapis.com/v1beta';

    public function __construct(
        private readonly GoogleApiClient $http,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public function runReport(CoreIntegration $integration, string $propertyResourceName, array $body): Response
    {
        $url = self::DATA_API_V1BETA.'/'.$propertyResourceName.':runReport';

        return $this->http->post($integration, $url, $body, GoogleScopeRegistry::CAPABILITY_GA4);
    }

    public function getMetadata(CoreIntegration $integration, string $propertyResourceName): Response
    {
        $url = self::DATA_API_V1BETA.'/'.$propertyResourceName.'/metadata';

        return $this->http->get($integration, $url, [], GoogleScopeRegistry::CAPABILITY_GA4);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function checkCompatibility(CoreIntegration $integration, string $propertyResourceName, array $body): Response
    {
        $url = self::DATA_API_V1BETA.'/'.$propertyResourceName.':checkCompatibility';

        return $this->http->post($integration, $url, $body, GoogleScopeRegistry::CAPABILITY_GA4);
    }

    public function getProperty(CoreIntegration $integration, string $propertyResourceName): Response
    {
        $url = self::ADMIN_API_V1BETA.'/'.$propertyResourceName;

        return $this->http->get($integration, $url, [], GoogleScopeRegistry::CAPABILITY_GA4);
    }

    public function listDataStreams(CoreIntegration $integration, string $propertyResourceName): Response
    {
        $url = self::ADMIN_API_V1BETA.'/'.$propertyResourceName.'/dataStreams';

        return $this->http->get($integration, $url, [], GoogleScopeRegistry::CAPABILITY_GA4);
    }
}
