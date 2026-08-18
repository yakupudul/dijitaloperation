<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleScopeCoverageService;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\Log;

/**
 * GA4 Property discovery via Analytics Admin API accountSummaries.list (v1beta).
 *
 * Verified 2026-08-13:
 * https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/accountSummaries/list
 */
class Ga4Discoverer
{
    public function __construct(
        private readonly GoogleApiClient $client,
        private readonly GoogleScopeCoverageService $coverage,
    ) {}

    public function discover(CoreIntegration $integration): CapabilityDiscoveryResult
    {
        $granted = $this->coverage->grantedScopes($integration);
        if ($granted !== [] && ! $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_GA4)) {
            return CapabilityDiscoveryResult::scopeRequired(
                'ga4',
                'Missing analytics.readonly scope. Grant GA4 access via incremental authorization.',
            );
        }

        $resources = [];
        $pageToken = null;
        $pages = 0;

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
                    GoogleScopeRegistry::CAPABILITY_GA4,
                );
            } catch (GoogleAuthorizationException) {
                return CapabilityDiscoveryResult::scopeRequired(
                    'ga4',
                    'Missing analytics.readonly scope for GA4 discovery.',
                );
            } catch (GoogleAuthenticationException $e) {
                return CapabilityDiscoveryResult::authenticationRequired('ga4', $e->getMessage());
            } catch (\Throwable) {
                return $resources === []
                    ? CapabilityDiscoveryResult::error('ga4', 'GA4 discovery failed to run.')
                    : CapabilityDiscoveryResult::partial('ga4', $resources, 'GA4 discovery failed after partial pages.');
            }

            $pages++;

            if ($response->status() === 403) {
                return $resources === []
                    ? CapabilityDiscoveryResult::externalAccessRequired(
                        'ga4',
                        'Google Analytics Admin API access denied. Enable the API for this Google Cloud project.',
                    )
                    : CapabilityDiscoveryResult::partial('ga4', $resources, 'GA4 discovery denied after partial pages.');
            }

            if (! $response->successful()) {
                Log::warning('GA4 accountSummaries.list failed', [
                    'integration_id' => $integration->id,
                    'status' => $response->status(),
                ]);

                return $resources === []
                    ? CapabilityDiscoveryResult::error('ga4', 'GA4 accountSummaries.list returned an error.')
                    : CapabilityDiscoveryResult::partial('ga4', $resources, 'GA4 discovery failed after page '.$pages.'.');
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
                            'selectable' => true,
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
