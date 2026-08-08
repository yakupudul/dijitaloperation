<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\Log;

/**
 * GBP discovery via Account Management + Business Information APIs.
 *
 * Official docs note that enabling the API may still leave quota at 0 until
 * Google Business Profile API access is approved. Scope business.manage is
 * manage-level and only requested when GOOGLE_INCLUDE_GBP_SCOPE=true.
 */
class GoogleBusinessProfileDiscoverer
{
    public function __construct(
        private readonly GoogleApiClient $client,
    ) {}

    public function discover(CoreIntegration $integration): CapabilityDiscoveryResult
    {
        if (! (bool) config('moxdop.google.gbp_discovery_enabled', false)) {
            return CapabilityDiscoveryResult::setupRequired(
                'google_business_profile',
                'GBP discovery is disabled. Set GOOGLE_GBP_DISCOVERY_ENABLED=true and GOOGLE_INCLUDE_GBP_SCOPE=true after Google approves Business Profile API access.',
            );
        }

        if (! (bool) config('moxdop.google.include_gbp_scope', false)) {
            return CapabilityDiscoveryResult::setupRequired(
                'google_business_profile',
                'GBP requires the business.manage scope. Enable GOOGLE_INCLUDE_GBP_SCOPE and re-authorize Google.',
            );
        }

        try {
            $accountsResponse = $this->client->get(
                $integration,
                'https://mybusinessaccountmanagement.googleapis.com/v1/accounts',
            );
        } catch (\Throwable) {
            return CapabilityDiscoveryResult::error('google_business_profile', 'GBP account discovery failed to run.');
        }

        if (in_array($accountsResponse->status(), [403, 429], true)) {
            return CapabilityDiscoveryResult::setupRequired(
                'google_business_profile',
                'Google Business Profile API access unavailable (enable APIs and request GBP API access if quota is 0).',
            );
        }

        if (! $accountsResponse->successful()) {
            Log::warning('GBP accounts.list failed', [
                'integration_id' => $integration->id,
                'status' => $accountsResponse->status(),
            ]);

            return CapabilityDiscoveryResult::error('google_business_profile', 'GBP accounts.list returned an error.');
        }

        $accounts = $accountsResponse->json('accounts') ?? [];
        if (! is_array($accounts) || $accounts === []) {
            return CapabilityDiscoveryResult::ok('google_business_profile', [], 'No GBP accounts accessible.');
        }

        $resources = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $accountName = (string) ($account['name'] ?? '');
            if ($accountName === '') {
                continue;
            }

            try {
                $locationsResponse = $this->client->get(
                    $integration,
                    'https://mybusinessbusinessinformation.googleapis.com/v1/'.$accountName.'/locations',
                    ['readMask' => 'name,title,storefrontAddress,metadata'],
                );
            } catch (\Throwable) {
                continue;
            }

            if (! $locationsResponse->successful()) {
                continue;
            }

            $locations = $locationsResponse->json('locations') ?? [];
            if (! is_array($locations)) {
                continue;
            }

            foreach ($locations as $location) {
                if (! is_array($location)) {
                    continue;
                }

                $locationName = (string) ($location['name'] ?? '');
                if ($locationName === '') {
                    continue;
                }

                $title = (string) ($location['title'] ?? $locationName);
                $resources[] = new DiscoveredExternalResource(
                    resourceType: 'google_business_profile',
                    externalId: $locationName,
                    displayName: $title,
                    parentExternalId: $accountName,
                    metadata: array_filter([
                        'account' => $accountName,
                        'title' => $title,
                        'storefront_address' => $location['storefrontAddress'] ?? null,
                    ]),
                );
            }
        }

        return CapabilityDiscoveryResult::ok(
            'google_business_profile',
            $resources,
            count($resources).' GBP locations discovered.',
        );
    }
}
