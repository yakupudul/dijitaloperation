<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\Log;

class GoogleAdsDiscoverer
{
    public function __construct(
        private readonly GoogleApiClient $client,
        private readonly GoogleCredentialResolver $credentials,
    ) {}

    public function discover(CoreIntegration $integration): CapabilityDiscoveryResult
    {
        if ($this->credentials->developerToken($integration) === null) {
            return CapabilityDiscoveryResult::setupRequired(
                'google_ads',
                'Google Ads developer token is missing. Configure it under Settings → Integrations → Google, or set GOOGLE_ADS_DEVELOPER_TOKEN as a deployment fallback.',
            );
        }

        try {
            $response = $this->client->getAds($integration, 'customers:listAccessibleCustomers');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'developer token')) {
                return CapabilityDiscoveryResult::setupRequired('google_ads', $message);
            }

            return CapabilityDiscoveryResult::error('google_ads', 'Google Ads discovery failed to run.');
        } catch (\Throwable) {
            return CapabilityDiscoveryResult::error('google_ads', 'Google Ads discovery failed to run.');
        }

        if ($response->status() === 403) {
            return CapabilityDiscoveryResult::setupRequired(
                'google_ads',
                'Google Ads API access denied. Check developer token approval status and adwords scope.',
            );
        }

        if (! $response->successful()) {
            Log::warning('Google Ads listAccessibleCustomers failed', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
            ]);

            return CapabilityDiscoveryResult::error('google_ads', 'Google Ads listAccessibleCustomers returned an error.');
        }

        $names = $response->json('resourceNames') ?? [];
        if (! is_array($names)) {
            $names = [];
        }

        $resources = [];
        foreach ($names as $resourceName) {
            if (! is_string($resourceName) || ! str_starts_with($resourceName, 'customers/')) {
                continue;
            }

            $customerId = str_replace('customers/', '', $resourceName);
            $resources[] = new DiscoveredExternalResource(
                resourceType: 'google_ads',
                externalId: $customerId,
                displayName: 'Google Ads '.$customerId,
                metadata: [
                    'resource_name' => $resourceName,
                    'customer_id' => $customerId,
                ],
            );
        }

        return CapabilityDiscoveryResult::ok(
            'google_ads',
            $resources,
            count($resources).' Google Ads accounts discovered.',
        );
    }
}
