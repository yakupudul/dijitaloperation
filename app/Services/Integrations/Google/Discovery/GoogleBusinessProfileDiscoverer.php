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
 * - accounts.list is paginated (max pageSize 20)
 * - accounts.locations.list: mybusinessbusinessinformation.googleapis.com/v1/{parent}/locations
 * - Google recommends listing the specific accessible accounts and then listing
 *   locations for each account; accounts/- remains useful for indirectly owned listings.
 * - OAuth scope: https://www.googleapis.com/auth/business.manage
 */
class GoogleBusinessProfileDiscoverer
{
    private const string READ_MASK = 'name,title,storeCode,storefrontAddress,websiteUri,metadata';

    private const int MAX_ACCOUNT_PAGES = 100;

    private const int MAX_LOCATION_PAGES = 100;

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
        // Reconcile against Google's live token metadata before rejecting GBP locally.
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

        $accountResult = $this->listAccounts($integration);
        if ($accountResult['status'] !== 'ok') {
            return match ($accountResult['status']) {
                'scope_required' => CapabilityDiscoveryResult::scopeRequired(
                    'google_business_profile',
                    $accountResult['message'] ?? 'Missing business.manage scope for GBP discovery.',
                ),
                'authentication_required' => CapabilityDiscoveryResult::authenticationRequired(
                    'google_business_profile',
                    $accountResult['message'] ?? 'Google authorization is not usable for GBP discovery.',
                ),
                'external_access_required' => CapabilityDiscoveryResult::externalAccessRequired(
                    'google_business_profile',
                    $accountResult['message'] ?? 'Google Business Profile account API access is unavailable.',
                ),
                default => CapabilityDiscoveryResult::error(
                    'google_business_profile',
                    $accountResult['message'] ?? 'GBP account discovery failed.',
                ),
            };
        }

        $accounts = $accountResult['accounts'];
        $accountContext = [];
        $accountParents = [];

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $name = trim((string) ($account['name'] ?? ''));
            if ($name === '' || $name === 'accounts/-') {
                continue;
            }

            $accountParents[] = $name;
            $accountContext[$name] = (string) ($account['accountName'] ?? $account['name'] ?? $name);
        }

        $accountParents = array_values(array_unique($accountParents));

        /** @var array<string, DiscoveredExternalResource> $byId */
        $byId = [];
        $partial = $accountResult['partial'];
        $failures = [];
        $successfulParents = 0;

        // Google recommends listing locations for each concrete accessible account.
        foreach ($accountParents as $parent) {
            $page = $this->listLocations($integration, $parent, $accountContext);

            if ($page['status'] === 'ok') {
                $successfulParents++;
            } else {
                $failures[] = [
                    'parent' => $parent,
                    'status' => $page['status'],
                    'message' => $page['message'],
                ];
            }

            if ($page['partial']) {
                $partial = true;
            }

            foreach ($page['resources'] as $resource) {
                $byId[$resource->externalId] = $resource;
            }
        }

        // Also query wildcard for indirectly owned/managed listings. Crucially, a
        // successful wildcard response with zero locations must NOT suppress the
        // concrete-account results or prevent concrete-account discovery.
        $wildcard = $this->listLocations($integration, 'accounts/-', $accountContext);
        if ($wildcard['status'] === 'ok') {
            $successfulParents++;
        } else {
            $failures[] = [
                'parent' => 'accounts/-',
                'status' => $wildcard['status'],
                'message' => $wildcard['message'],
            ];
        }

        if ($wildcard['partial']) {
            $partial = true;
        }

        foreach ($wildcard['resources'] as $resource) {
            $byId[$resource->externalId] = $resource;
        }

        $resources = array_values($byId);

        if ($resources !== []) {
            if ($partial || $failures !== []) {
                return CapabilityDiscoveryResult::partial(
                    'google_business_profile',
                    $resources,
                    count($resources).' GBP locations discovered across '.count($accountParents).' accessible account(s); some account/location queries were partial or unavailable.',
                );
            }

            return CapabilityDiscoveryResult::ok(
                'google_business_profile',
                $resources,
                count($resources).' GBP locations discovered across '.count($accountParents).' accessible account(s).',
            );
        }

        // No locations were found. Prefer an actionable provider error if every
        // usable listing route failed; otherwise report the real provider result:
        // the authorized Google user currently exposes no locations to these APIs.
        if ($successfulParents === 0 && $failures !== []) {
            $first = $failures[0];

            return match ($first['status']) {
                'scope_required' => CapabilityDiscoveryResult::scopeRequired(
                    'google_business_profile',
                    $first['message'] ?? 'Missing business.manage scope.',
                ),
                'authentication_required' => CapabilityDiscoveryResult::authenticationRequired(
                    'google_business_profile',
                    $first['message'] ?? 'Google authorization is not usable.',
                ),
                'external_access_required' => CapabilityDiscoveryResult::externalAccessRequired(
                    'google_business_profile',
                    $first['message'] ?? 'Google Business Profile locations API access is unavailable.',
                ),
                default => CapabilityDiscoveryResult::error(
                    'google_business_profile',
                    $first['message'] ?? 'GBP locations.list returned an error.',
                ),
            };
        }

        return CapabilityDiscoveryResult::ok(
            'google_business_profile',
            [],
            'Google returned 0 Business Profile locations across '.count($accountParents).' accessible account(s). The authorized Google user currently exposes no locations to the Business Profile APIs.',
        );
    }

    /**
     * @return array{status: string, message: ?string, accounts: list<array<string, mixed>>, partial: bool}
     */
    private function listAccounts(CoreIntegration $integration): array
    {
        $accounts = [];
        $pageToken = null;
        $pages = 0;

        do {
            $query = ['pageSize' => 20];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            try {
                $response = $this->client->get(
                    $integration,
                    'https://mybusinessaccountmanagement.googleapis.com/v1/accounts',
                    $query,
                    GoogleScopeRegistry::CAPABILITY_GBP,
                );
            } catch (GoogleAuthorizationException) {
                return [
                    'status' => 'scope_required',
                    'message' => 'Missing business.manage scope for GBP account discovery.',
                    'accounts' => $accounts,
                    'partial' => $accounts !== [],
                ];
            } catch (GoogleAuthenticationException $e) {
                return [
                    'status' => 'authentication_required',
                    'message' => $e->getMessage(),
                    'accounts' => $accounts,
                    'partial' => $accounts !== [],
                ];
            } catch (\Throwable $e) {
                Log::warning('GBP accounts.list failed to run', [
                    'integration_id' => $integration->id,
                    'exception' => $e::class,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'GBP accounts.list failed to run.',
                    'accounts' => $accounts,
                    'partial' => $accounts !== [],
                ];
            }

            $pages++;

            if (in_array($response->status(), [403, 429], true)) {
                return [
                    'status' => 'external_access_required',
                    'message' => 'Google Business Profile Account Management API access unavailable. Enable the Account Management API and verify GBP API quota/access for the OAuth project.',
                    'accounts' => $accounts,
                    'partial' => $accounts !== [],
                ];
            }

            if (! $response->successful()) {
                Log::warning('GBP accounts.list returned an error', [
                    'integration_id' => $integration->id,
                    'status' => $response->status(),
                ]);

                return [
                    'status' => 'error',
                    'message' => 'GBP accounts.list returned HTTP '.$response->status().'.',
                    'accounts' => $accounts,
                    'partial' => $accounts !== [],
                ];
            }

            $rows = $response->json('accounts') ?? [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $accounts[] = $row;
                    }
                }
            }

            $next = $response->json('nextPageToken');
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && $pages < self::MAX_ACCOUNT_PAGES);

        return [
            'status' => 'ok',
            'message' => null,
            'accounts' => $accounts,
            'partial' => $pageToken !== null,
        ];
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
            } catch (\Throwable $e) {
                Log::warning('GBP locations.list failed to run', [
                    'integration_id' => $integration->id,
                    'parent' => $parent,
                    'exception' => $e::class,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'GBP locations.list failed to run for '.$parent.'.',
                    'resources' => $resources,
                    'partial' => $resources !== [],
                ];
            }

            $pages++;

            if (in_array($locationsResponse->status(), [403, 429], true)) {
                return [
                    'status' => 'external_access_required',
                    'message' => 'Google Business Profile Business Information API access unavailable for '.$parent.'. Enable the Business Information API and verify GBP API quota/access for the OAuth project.',
                    'resources' => $resources,
                    'partial' => $resources !== [],
                ];
            }

            if (! $locationsResponse->successful()) {
                Log::warning('GBP locations.list returned an error', [
                    'integration_id' => $integration->id,
                    'status' => $locationsResponse->status(),
                    'parent' => $parent,
                ]);

                return [
                    'status' => 'error',
                    'message' => 'GBP locations.list returned HTTP '.$locationsResponse->status().' for '.$parent.'.',
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

                $locationName = trim((string) ($location['name'] ?? ''));
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
        } while ($pageToken !== null && $pages < self::MAX_LOCATION_PAGES);

        return [
            'status' => 'ok',
            'message' => null,
            'resources' => $resources,
            'partial' => $pageToken !== null,
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
        // Business Information v1 normally returns locations/{id}, so the account
        // may remain unknown when the wildcard route produced the location.
        if (str_starts_with($locationName, 'locations/')) {
            return null;
        }

        if (preg_match('#^(accounts/[^/]+)/locations/#', $locationName, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
