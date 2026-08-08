<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\Log;

class Ga4Discoverer
{
    public function __construct(
        private readonly GoogleApiClient $client,
    ) {}

    public function discover(CoreIntegration $integration): CapabilityDiscoveryResult
    {
        $resources = [];
        $pageToken = null;

        do {
            $query = ['pageSize' => 200];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            try {
                $response = $this->client->get(
                    $integration,
                    'https://analyticsadmin.googleapis.com/v1beta/accountSummaries',
                    $query,
                );
            } catch (\Throwable) {
                return CapabilityDiscoveryResult::error('ga4', 'GA4 discovery failed to run.');
            }

            if ($response->status() === 403) {
                return CapabilityDiscoveryResult::setupRequired(
                    'ga4',
                    'Google Analytics Admin API access denied. Enable the API and ensure analytics.readonly was granted.',
                );
            }

            if (! $response->successful()) {
                Log::warning('GA4 accountSummaries.list failed', [
                    'integration_id' => $integration->id,
                    'status' => $response->status(),
                ]);

                return CapabilityDiscoveryResult::error('ga4', 'GA4 accountSummaries.list returned an error.');
            }

            $summaries = $response->json('accountSummaries') ?? [];
            if (! is_array($summaries)) {
                $summaries = [];
            }

            foreach ($summaries as $accountSummary) {
                if (! is_array($accountSummary)) {
                    continue;
                }

                $accountName = (string) ($accountSummary['account'] ?? '');
                $accountDisplay = (string) ($accountSummary['displayName'] ?? $accountName);
                $propertySummaries = $accountSummary['propertySummaries'] ?? [];
                if (! is_array($propertySummaries)) {
                    continue;
                }

                foreach ($propertySummaries as $propertySummary) {
                    if (! is_array($propertySummary)) {
                        continue;
                    }

                    $property = (string) ($propertySummary['property'] ?? '');
                    if ($property === '' || ! str_starts_with($property, 'properties/')) {
                        continue;
                    }

                    $displayName = (string) ($propertySummary['displayName'] ?? $property);
                    $resources[] = new DiscoveredExternalResource(
                        resourceType: 'ga4',
                        externalId: $property,
                        displayName: $displayName,
                        parentExternalId: $accountName !== '' ? $accountName : null,
                        metadata: array_filter([
                            'account' => $accountName !== '' ? $accountName : null,
                            'account_display_name' => $accountDisplay !== '' ? $accountDisplay : null,
                            'property_id' => str_replace('properties/', '', $property),
                            'property_type' => $propertySummary['propertyType'] ?? null,
                        ]),
                    );
                }
            }

            $pageToken = $response->json('nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '');

        return CapabilityDiscoveryResult::ok(
            'ga4',
            $resources,
            count($resources).' GA4 properties discovered.',
        );
    }
}
