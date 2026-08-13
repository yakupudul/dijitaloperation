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
 * Search Console property discovery via sites.list.
 *
 * Verified 2026-08-13:
 * https://developers.google.com/webmaster-tools/v1/sites/list
 * Response is a single list (siteEntry); not page-token paginated.
 */
class SearchConsoleDiscoverer
{
    public function __construct(
        private readonly GoogleApiClient $client,
        private readonly GoogleScopeCoverageService $coverage,
    ) {}

    public function discover(CoreIntegration $integration): CapabilityDiscoveryResult
    {
        $granted = $this->coverage->grantedScopes($integration);
        if ($granted !== [] && ! $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE)) {
            return CapabilityDiscoveryResult::scopeRequired(
                'search_console',
                'Missing webmasters.readonly scope. Grant Search Console access via incremental authorization.',
            );
        }

        try {
            $response = $this->client->get(
                $integration,
                'https://www.googleapis.com/webmasters/v3/sites',
                [],
                GoogleScopeRegistry::CAPABILITY_SEARCH_CONSOLE,
            );
        } catch (GoogleAuthorizationException) {
            return CapabilityDiscoveryResult::scopeRequired(
                'search_console',
                'Missing webmasters.readonly scope for Search Console discovery.',
            );
        } catch (GoogleAuthenticationException $e) {
            return CapabilityDiscoveryResult::authenticationRequired('search_console', $e->getMessage());
        } catch (\Throwable) {
            return CapabilityDiscoveryResult::error('search_console', 'Search Console discovery failed to run.');
        }

        if ($response->status() === 403) {
            return CapabilityDiscoveryResult::externalAccessRequired(
                'search_console',
                'Search Console API access denied. Enable Search Console API for this Google Cloud project.',
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
            $isDomain = str_starts_with($siteUrl, 'sc-domain:');
            $display = $isDomain
                ? substr($siteUrl, strlen('sc-domain:'))
                : $siteUrl;

            $resources[] = new DiscoveredExternalResource(
                resourceType: 'search_console',
                externalId: $siteUrl,
                displayName: $display,
                metadata: array_filter([
                    'permission_level' => $permission !== '' ? $permission : null,
                    'site_url' => $siteUrl,
                    'property_form' => $isDomain ? 'domain' : 'url_prefix',
                    'selectable' => true,
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
