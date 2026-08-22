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

    /** @param array<string, mixed> $body */
    public function runReport(CoreIntegration $integration, string $propertyResourceName, array $body): Response
    {
        return $this->http->post(
            $integration,
            self::DATA_API_V1BETA.'/'.$propertyResourceName.':runReport',
            $body,
            GoogleScopeRegistry::CAPABILITY_GA4,
        );
    }

    public function getMetadata(CoreIntegration $integration, string $propertyResourceName): Response
    {
        return $this->http->get(
            $integration,
            self::DATA_API_V1BETA.'/'.$propertyResourceName.'/metadata',
            [],
            GoogleScopeRegistry::CAPABILITY_GA4,
        );
    }

    /** @param array<string, mixed> $body */
    public function checkCompatibility(CoreIntegration $integration, string $propertyResourceName, array $body): Response
    {
        return $this->http->post(
            $integration,
            self::DATA_API_V1BETA.'/'.$propertyResourceName.':checkCompatibility',
            $body,
            GoogleScopeRegistry::CAPABILITY_GA4,
        );
    }

    public function getProperty(CoreIntegration $integration, string $propertyResourceName): Response
    {
        return $this->adminGet($integration, $propertyResourceName);
    }

    /** @param array<string, mixed> $query */
    public function listDataStreams(CoreIntegration $integration, string $propertyResourceName, array $query = []): Response
    {
        return $this->adminGet($integration, $propertyResourceName.'/dataStreams', $query);
    }

    /** @param array<string, mixed> $query */
    public function listKeyEvents(CoreIntegration $integration, string $propertyResourceName, array $query = []): Response
    {
        return $this->adminGet($integration, $propertyResourceName.'/keyEvents', $query);
    }

    public function getDataRetentionSettings(CoreIntegration $integration, string $propertyResourceName): Response
    {
        return $this->adminGet($integration, $propertyResourceName.'/dataRetentionSettings');
    }

    public function getAttributionSettings(CoreIntegration $integration, string $propertyResourceName): Response
    {
        return $this->adminGet($integration, $propertyResourceName.'/attributionSettings');
    }

    /** @param array<string, mixed> $query */
    public function listCustomDimensions(CoreIntegration $integration, string $propertyResourceName, array $query = []): Response
    {
        return $this->adminGet($integration, $propertyResourceName.'/customDimensions', $query);
    }

    /** @param array<string, mixed> $query */
    public function listCustomMetrics(CoreIntegration $integration, string $propertyResourceName, array $query = []): Response
    {
        return $this->adminGet($integration, $propertyResourceName.'/customMetrics', $query);
    }

    /** @param array<string, mixed> $query */
    public function listGoogleAdsLinks(CoreIntegration $integration, string $propertyResourceName, array $query = []): Response
    {
        return $this->adminGet($integration, $propertyResourceName.'/googleAdsLinks', $query);
    }

    /** @param array<string, mixed> $query */
    private function adminGet(CoreIntegration $integration, string $resource, array $query = []): Response
    {
        return $this->http->get(
            $integration,
            self::ADMIN_API_V1BETA.'/'.$resource,
            $query,
            GoogleScopeRegistry::CAPABILITY_GA4,
        );
    }
}
