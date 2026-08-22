<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Exceptions\Integrations\GoogleAuthenticationException;
use App\Exceptions\Integrations\GoogleAuthorizationException;
use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleScopeCoverageService;
use App\Services\Integrations\Google\GoogleScopeRegistry;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GBP Location discovery via Account Management + Business Information APIs.
 *
 * Verified 2026-08-22:
 * - accounts.list: mybusinessaccountmanagement.googleapis.com/v1/accounts
 * - accounts.locations.list: mybusinessbusinessinformation.googleapis.com/v1/{parent}/locations
 *   requires readMask; supports accounts/- wildcard for indirect ownership
 * - OAuth scope: https://www.googleapis.com/auth/business.manage
 *
 * GBP is a first-class discovery connector. External project API access
 * is represented as EXTERNAL_ACCESS_REQUIRED — never as deferred architecture.
 */
class GoogleBusinessProfileDiscoverer
{
    private const string READ_MASK = 'name,title,storeCode,storefrontAddress,websiteUri,metadata';

    public function __construct(
        private readonly GoogleApiClient $client,
        private readonly GoogleScopeCoverageService $coverage,
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleScopeRegistry $scopeRegistry,
    ) {}

    public function discover(CoreIntegration $integration): CapabilityDiscoveryResult
    {
        if (! (bool) config('moxdop.google.include_gbp_scope', false)) {
            return CapabilityDiscoveryResult::scopeRequired(
                'google_business_profile',
                'Missing business.manage scope. Enable GOOGLE_INCLUDE_GBP_SCOPE and re-authorize Google.',
            );
        }

        // Local granted_scopes can be stale after incremental Google re-authorization.
        // Before rejecting GBP, reconcile the persisted scope list against Google's
        // tokeninfo response. Provider scope is authoritative; no token is logged.
        $this->syncGrantedScopesFromProvider($integration);
        $integration = $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration;

        $granted = $this->coverage->grantedScopes($integration);
        if ($granted !== [] && ! $this->coverage->hasCapability($integration, GoogleScopeRegistry::CAPABILITY_GBP)) {
            return CapabilityDiscoveryResult::scopeRequired(
                'google_business_profile',
                'Missing business.manage scope. Re-authorize Google to grant GBP access.',
            );
        }

        if (! (bool) config('moxdop.google.gbp_discovery_enabled', false)) {
            return CapabilityDiscoveryResult::externalAccessRequired(
                'google_business_profile',
                'Google Business Profile API access is not enabled for this deployment. Set GOOGLE_GBP_DISCOVERY_ENABLED=true after Google approves Business Profile API access / quota.',
            );
        }

        try {
            $accountsResponse = $this->client->get(
                $integration,
                'https://mybusinessaccountmanagement.googleapis.com/v1/accounts',
                ['pageSize' => 20],
                GoogleScopeRegistry::CAPABILITY_GBP,
            );
        } catch (GoogleAuthorizationException) {
            return CapabilityDiscoveryResult::scopeRequired(
                'google_business_profile',
                'Missing business.manage scope for GBP discovery.',
            );
        } catch (GoogleAuthenticationException $e) {
            return CapabilityDiscoveryResult::authenticationRequired('google_business_profile', $e->getMessage());
        } catch (\Throwable) {
            return CapabilityDiscoveryResult::error('google_business_profile', 'GBP account discovery failed to run.');
        }

        if (in_array($accountsResponse->status(), [403, 429], true)) {
            return CapabilityDiscoveryResult::externalAccessRequired(
                'google_business_profile',
                'Google Business Profile API access unavailable (enable Account Management and Business Information APIs and request GBP API access if quota is 0).',
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
        if (! is_array($accounts)) {
            $accounts = [];
        }

        // Prefer wildcard listing for direct + indirect ownership.
        // Official guide: GET .../accounts/-/locations?readMask=...
        $accountParents = ['accounts/-'];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $name = (string) ($account['name'] ?? '');
            if ($name !== '' && $name !== 'accounts/-') {
                $accountParents[] = $name;
            }
        }
        $accountParents = array_values(array_unique($accountParents));

        /** @var array<string, DiscoveredExternalResource> $byId */
        $byId = [];
        $partial = false;
        $accountContext = [];

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $name = (string) ($account['name'] ?? '');
            if ($name !== '') {
                $accountContext[$name] = (string) ($account['accountName'] ?? $account['name'] ?? $name);
            }
        }

        foreach ($accountParents as $parent) {
            $page = $this->listLocations($integration, $parent, $accountContext);
            if ($page['status'] === 'external_access_required' && $byId === []) {
                return CapabilityDiscoveryResult::externalAccessRequired(
                    'google_business_profile',
                    $page['message'] ?? 'GBP locations.list access unavailable.',
                );
            }
            if ($page['status'] === 'error' && $byId === [] && $parent === 'accounts/-') {
                // Fall through to per-account listing.
                continue;
            }
            if ($page['partial']) {
                $partial = true;
            }
            foreach ($page['resources'] as $resource) {
                $byId[$resource->externalId] = $resource;
            }

            // Wildcard success is sufficient for complete inventory.
            if ($parent === 'accounts/-' && $page['status'] === 'ok' && ! $page['partial']) {
                break;
            }
        }

        $resources = array_values($byId);

        if ($partial) {
            return CapabilityDiscoveryResult::partial(
                'google_business_profile',
                $resources,
                count($resources).' GBP locations discovered (partial pagination).',
            );
        }

        return CapabilityDiscoveryResult::ok(
            'google_business_profile',
            $resources,
            count($resources).' GBP locations discovered.',
        );
    }

    /**
     * @param  array<string, string>  $accountContext
     * @return array{status: string, message: ?string, resources: list<DiscoveredExternalResource>, partial: bool}
     */
    private function listLocations(CoreIntegration $integration, string $parent, array $accountContext): array
    {
        $resources = [];
        $pageToken = null;
        $pages = 0;
        $partial = false;

        do {
            $query = [
                'readMask' => self::READ_MASK,
                'pageSize' => 100,
            ];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            try {
                $locationsResponse = $this->client->get(
                    $integration,
                    'https://mybusinessbusinessinformation.googleapis.com/v1/'.$parent.'/locations',
                    $query,
                    GoogleScopeRegistry::CAPABILITY_GBP,
                );
            } catch (GoogleAuthorizationException) {
                return [
                    'status' => 'scope_required',
                    'message' => 'Missing business.manage scope.',
                    'resources' => $resources,
                    'partial' => $resources !== [],
                ];
            } catch (GoogleAuthenticationException $e) {
                return [
                    'status' => 'authentication_required',
                    'message' => $e->getMessage(),
                    'resources' => $resources,
                    'partial' => $resources !== [],
                ];
            } catch (\Throwable) {
                return [
                    'status' => 'error',
                    'message' => 'GBP locations.list failed to run.',
                    'resources' => $resources,
                    'partial' => $resources !== [],
                ];
            }

            $pages++;

            if (in_array($locationsResponse->status(), [403, 429], true)) {
                return [
                    'status' => 'external_access_required',
                    'message' => 'Google Business Profile locations API access unavailable.',
                    'resources' => $resources,
                    'partial' => $resources !== [],
                ];
            }

            if (! $locationsResponse->successful()) {
                Log::warning('GBP locations.list failed', [
                    'integration_id' => $integration->id,
                    'status' => $locationsResponse->status(),
                    'parent' => $parent,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'GBP locations.list returned an error.',
                    'resources' => $resources,
                    'partial' => $resources !== [],
                ];
            }

            $locations = $locationsResponse->json('locations') ?? [];
            if (! is_array($locations)) {
                $locations = [];
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
                $accountParent = $parent === 'accounts/-'
                    ? $this->inferAccountParent($locationName)
                    : $parent;

                $resources[] = new DiscoveredExternalResource(
                    resourceType: 'google_business_profile',
                    externalId: $locationName,
                    displayName: $title,
                    parentExternalId: $accountParent,
                    metadata: array_filter([
                        'account' => $accountParent,
                        'account_display_name' => $accountContext[$accountParent ?? ''] ?? null,
                        'title' => $title,
                        'store_code' => $location['storeCode'] ?? null,
                        'storefront_address' => $location['storefrontAddress'] ?? null,
                        'website_uri' => $location['websiteUri'] ?? null,
                        'place_id' => data_get($location, 'metadata.placeId'),
                        'selectable' => true,
                    ], fn (mixed $value): bool => $value !== null),
                );
            }

            $next = $locationsResponse->json('nextPageToken');
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $pages < 100);

        if ($pageToken !== null) {
            $partial = true;
        }

        return [
            'status' => 'ok',
            'message' => null,
            'resources' => $resources,
            'partial' => $partial,
        ];
    }

    private function syncGrantedScopesFromProvider(CoreIntegration $integration): void
    {
        $fresh = $integration->fresh(['authorizationCredential']) ?? $integration;
        $token = $this->oauth->validAccessToken($fresh);
        if ($token === null) {
            return;
        }

        try {
            $response = Http::timeout(15)->get('https://oauth2.googleapis.com/tokeninfo', [
                'access_token' => $token,
            ]);
        } catch (\Throwable $e) {
            Log::info('Google scope reconciliation skipped: tokeninfo unavailable', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            return;
        }

        if (! $response->successful()) {
            return;
        }

        $rawScope = $response->json('scope');
        if (! is_string($rawScope) || trim($rawScope) === '') {
            return;
        }

        $granted = $this->scopeRegistry->parseGranted($rawScope);
        if ($granted === []) {
            return;
        }

        $config = $fresh->config ?? [];
        $config['granted_scopes'] = $granted;
        $config['granted_scopes_verified_at'] = now()->toIso8601String();
        $fresh->forceFill(['config' => $config])->save();

        $credential = $fresh->authorizationCredential;
        if ($credential !== null && is_array($credential->encrypted_payload)) {
            $payload = $credential->encrypted_payload;
            $payload['scope'] = implode(' ', $granted);
            $credential->forceFill(['encrypted_payload' => $payload])->save();
        }
    }

    private function inferAccountParent(string $locationName): ?string
    {
        // locations/{id} — account may be unknown when listed via accounts/-
        if (str_starts_with($locationName, 'locations/')) {
            return null;
        }

        if (preg_match('#^(accounts/[^/]+)/locations/#', $locationName, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
