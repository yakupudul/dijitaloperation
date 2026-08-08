<?php

namespace App\Services\Integrations\Google\Discovery;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Support\Integrations\DiscoveredExternalResource;
use Illuminate\Support\Facades\Log;

/**
 * Discover Google Ads accounts via ListAccessibleCustomers + MCC customer_client hierarchy.
 * Read-only. No campaign mutations. No manual customer ID entry.
 */
class GoogleAdsDiscoverer
{
    private const string CUSTOMER_CLIENT_QUERY = <<<'GAQL'
SELECT
  customer_client.id,
  customer_client.descriptive_name,
  customer_client.manager,
  customer_client.level,
  customer_client.status,
  customer_client.currency_code,
  customer_client.time_zone,
  customer_client.test_account,
  customer_client.client_customer
FROM customer_client
GAQL;

    private const string CUSTOMER_QUERY = <<<'GAQL'
SELECT
  customer.id,
  customer.descriptive_name,
  customer.manager,
  customer.currency_code,
  customer.time_zone,
  customer.test_account
FROM customer
LIMIT 1
GAQL;

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

        /** @var array<string, DiscoveredExternalResource> $byId */
        $byId = [];

        foreach ($names as $resourceName) {
            if (! is_string($resourceName) || ! str_starts_with($resourceName, 'customers/')) {
                continue;
            }

            $seedId = str_replace('customers/', '', $resourceName);
            if ($seedId === '' || ! ctype_digit($seedId)) {
                continue;
            }

            $expanded = $this->expandSeedAccount($integration, $seedId);
            foreach ($expanded as $resource) {
                $existing = $byId[$resource->externalId] ?? null;
                if ($existing === null) {
                    $byId[$resource->externalId] = $resource;

                    continue;
                }

                // Prefer richer descriptive names / lower hierarchy level when duplicates appear.
                $byId[$resource->externalId] = $this->preferRicher($existing, $resource);
            }
        }

        $resources = array_values($byId);

        return CapabilityDiscoveryResult::ok(
            'google_ads',
            $resources,
            count($resources).' Google Ads accounts discovered.',
        );
    }

    /**
     * @return list<DiscoveredExternalResource>
     */
    private function expandSeedAccount(CoreIntegration $integration, string $seedId): array
    {
        try {
            $search = $this->client->searchAds(
                $integration,
                $seedId,
                self::CUSTOMER_CLIENT_QUERY,
                $seedId,
            );
        } catch (\Throwable $e) {
            Log::warning('Google Ads customer_client search failed', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);

            return [$this->resourceFromCustomerLookup($integration, $seedId, $seedId, seedAccessible: true)];
        }

        if ($search->successful()) {
            $fromHierarchy = $this->resourcesFromCustomerClientResults(
                $search->json('results') ?? [],
                $seedId,
            );

            if ($fromHierarchy !== []) {
                // Ensure the accessible seed/MCC itself is retained even when customer_client
                // only returns child accounts (manager relationship metadata stays on children).
                $hasSeed = false;
                foreach ($fromHierarchy as $resource) {
                    if ($resource->externalId === $seedId) {
                        $hasSeed = true;
                        break;
                    }
                }

                if (! $hasSeed) {
                    array_unshift(
                        $fromHierarchy,
                        $this->resourceFromCustomerLookup($integration, $seedId, $seedId, seedAccessible: true),
                    );
                }

                return $fromHierarchy;
            }
        } else {
            Log::warning('Google Ads customer_client search non-success', [
                'integration_id' => $integration->id,
                'status' => $search->status(),
            ]);
        }

        // Non-manager seed or hierarchy unavailable: still catalog the directly accessible account.
        return [$this->resourceFromCustomerLookup($integration, $seedId, $seedId, seedAccessible: true)];
    }

    /**
     * @return list<DiscoveredExternalResource>
     */
    private function resourcesFromCustomerClientResults(mixed $results, string $loginCustomerId): array
    {
        if (! is_array($results)) {
            return [];
        }

        $resources = [];

        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }

            $client = $row['customerClient'] ?? $row['customer_client'] ?? null;
            if (! is_array($client)) {
                continue;
            }

            $id = $this->stringId($client['id'] ?? null);
            if ($id === null) {
                $clientCustomer = $client['clientCustomer'] ?? $client['client_customer'] ?? null;
                if (is_string($clientCustomer) && str_starts_with($clientCustomer, 'customers/')) {
                    $id = str_replace('customers/', '', $clientCustomer);
                }
            }

            if ($id === null || ! ctype_digit($id)) {
                continue;
            }

            $descriptiveName = trim((string) ($client['descriptiveName'] ?? $client['descriptive_name'] ?? ''));
            $isManager = (bool) ($client['manager'] ?? false);
            $level = is_numeric($client['level'] ?? null) ? (int) $client['level'] : null;
            $status = isset($client['status']) && is_string($client['status']) ? $client['status'] : null;

            $resources[] = $this->makeResource(
                customerId: $id,
                descriptiveName: $descriptiveName !== '' ? $descriptiveName : null,
                loginCustomerId: $loginCustomerId,
                isManager: $isManager,
                level: $level,
                status: $status,
                currencyCode: $this->nullableString($client['currencyCode'] ?? $client['currency_code'] ?? null),
                timeZone: $this->nullableString($client['timeZone'] ?? $client['time_zone'] ?? null),
                testAccount: isset($client['testAccount']) ? (bool) $client['testAccount'] : (isset($client['test_account']) ? (bool) $client['test_account'] : null),
                seedAccessible: $id === $loginCustomerId,
                managerCustomerId: ($id !== $loginCustomerId) ? $loginCustomerId : ($isManager ? $id : null),
            );
        }

        return $resources;
    }

    private function resourceFromCustomerLookup(
        CoreIntegration $integration,
        string $customerId,
        string $loginCustomerId,
        bool $seedAccessible,
    ): DiscoveredExternalResource {
        $descriptiveName = null;
        $isManager = false;
        $currency = null;
        $timeZone = null;
        $testAccount = null;

        try {
            $response = $this->client->searchAds(
                $integration,
                $customerId,
                self::CUSTOMER_QUERY,
                $loginCustomerId,
            );

            if ($response->successful()) {
                $results = $response->json('results') ?? [];
                $first = is_array($results) ? ($results[0] ?? null) : null;
                $customer = is_array($first) ? ($first['customer'] ?? null) : null;
                if (is_array($customer)) {
                    $name = trim((string) ($customer['descriptiveName'] ?? $customer['descriptive_name'] ?? ''));
                    $descriptiveName = $name !== '' ? $name : null;
                    $isManager = (bool) ($customer['manager'] ?? false);
                    $currency = $this->nullableString($customer['currencyCode'] ?? $customer['currency_code'] ?? null);
                    $timeZone = $this->nullableString($customer['timeZone'] ?? $customer['time_zone'] ?? null);
                    $testAccount = isset($customer['testAccount']) ? (bool) $customer['testAccount'] : (isset($customer['test_account']) ? (bool) $customer['test_account'] : null);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Google Ads customer lookup failed', [
                'integration_id' => $integration->id,
                'exception' => $e::class,
            ]);
        }

        return $this->makeResource(
            customerId: $customerId,
            descriptiveName: $descriptiveName,
            loginCustomerId: $loginCustomerId,
            isManager: $isManager,
            level: 0,
            status: null,
            currencyCode: $currency,
            timeZone: $timeZone,
            testAccount: $testAccount,
            seedAccessible: $seedAccessible,
            managerCustomerId: $isManager ? $customerId : null,
        );
    }

    private function makeResource(
        string $customerId,
        ?string $descriptiveName,
        string $loginCustomerId,
        bool $isManager,
        ?int $level,
        ?string $status,
        ?string $currencyCode,
        ?string $timeZone,
        ?bool $testAccount,
        bool $seedAccessible,
        ?string $managerCustomerId,
    ): DiscoveredExternalResource {
        $formattedId = $this->formatCustomerId($customerId);
        $displayName = $descriptiveName !== null && $descriptiveName !== ''
            ? $descriptiveName
            : 'Google Ads '.$formattedId;

        return new DiscoveredExternalResource(
            resourceType: 'google_ads',
            externalId: $customerId,
            displayName: $displayName,
            parentExternalId: ($managerCustomerId !== null && $managerCustomerId !== $customerId) ? $managerCustomerId : null,
            metadata: array_filter([
                'resource_name' => 'customers/'.$customerId,
                'customer_id' => $customerId,
                'customer_id_formatted' => $formattedId,
                'descriptive_name' => $descriptiveName,
                'is_manager' => $isManager,
                'level' => $level,
                'status' => $status,
                'currency_code' => $currencyCode,
                'time_zone' => $timeZone,
                'test_account' => $testAccount,
                'seed_accessible' => $seedAccessible,
                'login_customer_id' => $loginCustomerId,
                'manager_customer_id' => $managerCustomerId,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    private function preferRicher(DiscoveredExternalResource $a, DiscoveredExternalResource $b): DiscoveredExternalResource
    {
        $aName = trim((string) ($a->metadata['descriptive_name'] ?? ''));
        $bName = trim((string) ($b->metadata['descriptive_name'] ?? ''));

        if ($aName === '' && $bName !== '') {
            return $b;
        }

        if ($bName === '' && $aName !== '') {
            return $a;
        }

        $aLevel = is_numeric($a->metadata['level'] ?? null) ? (int) $a->metadata['level'] : PHP_INT_MAX;
        $bLevel = is_numeric($b->metadata['level'] ?? null) ? (int) $b->metadata['level'] : PHP_INT_MAX;

        return $bLevel < $aLevel ? $b : $a;
    }

    private function formatCustomerId(string $customerId): string
    {
        if (strlen($customerId) === 10) {
            return substr($customerId, 0, 3).'-'.substr($customerId, 3, 3).'-'.substr($customerId, 6);
        }

        return $customerId;
    }

    private function stringId(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return $value;
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
