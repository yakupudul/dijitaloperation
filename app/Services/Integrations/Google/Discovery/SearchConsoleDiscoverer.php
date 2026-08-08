<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\Log;

class SearchConsoleDiscoverer
{
    public function __construct(
        private readonly GoogleApiClient $client,
    ) {}

    public function discover(CoreIntegration $integration): CapabilityDiscoveryResult
    {
        try {
            $response = $this->client->get($integration, 'https://www.googleapis.com/webmasters/v3/sites');
        } catch (\Throwable $e) {
            return CapabilityDiscoveryResult::error('search_console', 'Search Console discovery failed to run.');
        }

        if ($response->status() === 403) {
            return CapabilityDiscoveryResult::setupRequired(
                'search_console',
                'Search Console API access denied. Enable Search Console API and ensure webmasters.readonly scope was granted.',
            );
        }

        if (! $response->successful()) {
            Log::warning('Search Console sites.list failed', [
                'integration_id' => $integration->id,
                'status' => $response->status(),
            ]);

            return CapabilityDiscoveryResult::error('search_console', 'Search Console sites.list returned an error.');
        }

        $entries = $response->json('siteEntry') ?? [];
        if (! is_array($entries)) {
            $entries = [];
        }

        $resources = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $siteUrl = (string) ($entry['siteUrl'] ?? '');
            if ($siteUrl === '') {
                continue;
            }

            $permission = (string) ($entry['permissionLevel'] ?? '');
            $display = str_starts_with($siteUrl, 'sc-domain:')
                ? substr($siteUrl, strlen('sc-domain:'))
                : $siteUrl;

            $resources[] = new DiscoveredExternalResource(
                resourceType: 'search_console',
                externalId: $siteUrl,
                displayName: $display,
                metadata: array_filter([
                    'permission_level' => $permission !== '' ? $permission : null,
                    'site_url' => $siteUrl,
                ]),
            );
        }

        return CapabilityDiscoveryResult::ok(
            'search_console',
            $resources,
            count($resources).' Search Console properties discovered.',
        );
    }
}
