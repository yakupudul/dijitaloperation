<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Models\User;

/**
 * Facade used by Livewire/Filament to run Meta discovery phases.
 *
 * Canonical two-phase discovery:
 * 1) discoverBusinesses — GET me/businesses (no selection required)
 * 2) discoverAdAccounts — owned_ad_accounts + client_ad_accounts for
 *    operator-selected Business discovery contexts (requires a selection)
 */
class DiscoverMetaResourcesService
{
    public function __construct(
        private readonly MetaBusinessDiscoverer $businesses,
        private readonly MetaAdAccountDiscoverer $adAccounts,
        private readonly SelectMetaDiscoveryContextService $selection,
    ) {}

    /**
     * @return array{ok: bool, status: string, message: string, count: int, resources: array}
     */
    public function discoverBusinesses(CoreIntegration $integration, ?User $triggeredBy = null): array
    {
        return $this->businesses->discover($integration, $triggeredBy);
    }

    /**
     * @return array{ok: bool, status: string, message: string, count: int, resources: array}
     */
    public function discoverAdAccounts(CoreIntegration $integration, ?User $triggeredBy = null): array
    {
        return $this->adAccounts->discover($integration, $triggeredBy);
    }

    /**
     * Filament "Discover resources" combined refresh:
     * 1) discover Businesses
     * 2) if a Business selection already exists, also discover Ad Accounts —
     *    otherwise stop after Businesses so the operator can select one first.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     businesses: array{ok: bool, status: string, message: string, count: int, resources: array},
     *     ad_accounts: array{ok: bool, status: string, message: string, count: int, resources: array}|null
     * }
     */
    public function refreshInventory(CoreIntegration $integration, ?User $triggeredBy = null): array
    {
        $businessResult = $this->discoverBusinesses($integration, $triggeredBy);

        if (! $this->selection->hasSelection($integration)) {
            $message = $businessResult['ok']
                ? $businessResult['message'].' Select a Meta Business to discover Ad Accounts.'
                : $businessResult['message'];

            return [
                'ok' => $businessResult['ok'],
                'message' => $message,
                'businesses' => $businessResult,
                'ad_accounts' => null,
            ];
        }

        $adAccountResult = $this->discoverAdAccounts($integration, $triggeredBy);

        return [
            'ok' => $businessResult['ok'] && $adAccountResult['ok'],
            'message' => trim($businessResult['message'].' '.$adAccountResult['message']),
            'businesses' => $businessResult,
            'ad_accounts' => $adAccountResult,
        ];
    }
}
