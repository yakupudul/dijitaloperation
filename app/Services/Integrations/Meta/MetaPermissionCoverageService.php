<?php

namespace App\Services\Integrations\Meta;

use App\Models\CoreIntegration;
use App\Support\Integrations\Meta\MetaPermissionRegistry;

/**
 * Answers capability questions from persisted authorization metadata (no Graph calls).
 */
final class MetaPermissionCoverageService
{
    /**
     * @return list<string>
     */
    public function requestedPermissions(CoreIntegration $integration): array
    {
        return MetaPermissionRegistry::normalize(
            data_get($integration->config, 'requested_permissions')
                ?? MetaPermissionRegistry::requiredForMetaAds(),
        );
    }

    /**
     * @return list<string>
     */
    public function grantedPermissions(CoreIntegration $integration): array
    {
        $fromConfig = data_get($integration->config, 'granted_permissions');
        if (is_array($fromConfig) && $fromConfig !== []) {
            return MetaPermissionRegistry::normalize($fromConfig);
        }

        $payload = app(MetaCredentialResolver::class)->providerPayload($integration);
        $fromPayload = $payload['granted_permissions'] ?? $payload['scopes'] ?? null;

        return MetaPermissionRegistry::normalize(is_array($fromPayload) ? $fromPayload : []);
    }

    /**
     * True when debug_token / OAuth has persisted an explicit grant set.
     */
    public function hasValidatedGrantSet(CoreIntegration $integration): bool
    {
        return data_get($integration->config, 'granted_permissions') !== null
            || data_get($integration->config, 'credential_status') === MetaCredentialValidator::STATUS_VALID;
    }

    /**
     * @return list<string>
     */
    public function missingForBusinessDiscovery(CoreIntegration $integration): array
    {
        // Unknown coverage ≠ missing — do not block discovery until grants are known.
        if (! $this->hasValidatedGrantSet($integration)) {
            return [];
        }

        return MetaPermissionRegistry::missing(
            $this->grantedPermissions($integration),
            MetaPermissionRegistry::forBusinessDiscovery(),
        );
    }

    /**
     * @return list<string>
     */
    public function missingForAdAccountDiscovery(CoreIntegration $integration): array
    {
        if (! $this->hasValidatedGrantSet($integration)) {
            return [];
        }

        return MetaPermissionRegistry::missing(
            $this->grantedPermissions($integration),
            MetaPermissionRegistry::forAdAccountDiscovery(),
        );
    }

    /**
     * @return list<string>
     */
    public function missingForCollection(CoreIntegration $integration): array
    {
        if (! $this->hasValidatedGrantSet($integration)) {
            return [];
        }

        return MetaPermissionRegistry::missing(
            $this->grantedPermissions($integration),
            MetaPermissionRegistry::forFutureCollection(),
        );
    }

    public function canDiscoverBusinesses(CoreIntegration $integration): bool
    {
        return $this->missingForBusinessDiscovery($integration) === [];
    }

    public function canDiscoverAdAccounts(CoreIntegration $integration): bool
    {
        return $this->missingForAdAccountDiscovery($integration) === [];
    }

    public function needsReauthorization(CoreIntegration $integration): bool
    {
        if (! app(MetaCredentialResolver::class)->hasTenantAuthorization($integration)) {
            return true;
        }

        $status = data_get($integration->config, 'credential_status');
        if (in_array($status, ['expired', 'revoked', 'invalid', 'wrong_app'], true)) {
            return true;
        }

        if (! $this->hasValidatedGrantSet($integration)) {
            return false;
        }

        return $this->missingForAdAccountDiscovery($integration) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(CoreIntegration $integration): array
    {
        $granted = $this->grantedPermissions($integration);
        $requested = $this->requestedPermissions($integration);

        return [
            'requested' => $requested,
            'granted' => $granted,
            'missing_business_discovery' => $this->missingForBusinessDiscovery($integration),
            'missing_ad_account_discovery' => $this->missingForAdAccountDiscovery($integration),
            'missing_collection' => $this->missingForCollection($integration),
            'can_discover_businesses' => $this->canDiscoverBusinesses($integration),
            'can_discover_ad_accounts' => $this->canDiscoverAdAccounts($integration),
            'needs_reauthorization' => $this->needsReauthorization($integration),
        ];
    }
}
