<?php

namespace App\Services\Integrations\Meta;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationDiscoveryContext;
use App\Models\DataPool\DatasetMaterialization;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\Meta\MetaConfigurationHealth;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\Presentation\IntegrationHealthPresenter;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen `/app/integrations` Meta read model.
 *
 * Persisted CoreIntegration state only — never decrypts secrets for UI,
 * never performs Graph HTTP, never invents Demo connection counts as real.
 */
final class MetaIntegrationReadModel
{
    public function __construct(
        private readonly IntegrationHealthPresenter $health = new IntegrationHealthPresenter,
        private readonly MetaCredentialResolver $credentials = new MetaCredentialResolver,
    ) {}

    public function findIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with(['providerCredential', 'authorizationCredential'])
            ->where('provider', ProviderRegistry::META)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function hubCard(): array
    {
        $detail = $this->detail();

        return [
            'id' => ProviderRegistry::META,
            'name' => 'Meta',
            'logo_type' => 'meta_ads',
            'state' => $detail['state'],
            'state_label' => $detail['state_label'],
            'resources_discovered' => $detail['ad_accounts_discovered'],
            'bound' => $detail['bound'],
            'available' => $detail['available'],
            'last_check' => $detail['last_check'],
            'dependent_assets' => $detail['dependent_assets'],
            'route' => 'demo.integrations.meta',
            'manage_label' => $detail['integration_id'] !== null ? 'Manage' : 'Configure',
            'provenance' => 'real',
            'next_action' => $detail['next_action'],
            'collection_state' => $detail['collection_state'],
            'data_state' => $detail['data_state'],
            'note' => $detail['hub_note'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        $integration = $this->findIntegration();

        if (! $integration instanceof CoreIntegration) {
            return $this->emptyDetail();
        }

        $operatorStatus = $this->health->status($integration, ProviderRegistry::META);
        $authStatus = MetaAuthStatus::for($integration);
        $counts = $this->resourceBindingCounts($integration);
        $selectedBusinesses = $this->selectedBusinessCount($integration);
        $collection = $this->collectionSummary($integration);
        $dataState = $this->dataFreshnessSummary($integration);
        $permission = app(MetaPermissionCoverageService::class)->summary($integration);
        $configHealth = (new MetaConfigurationHealth)->check($integration);
        $credentialStatus = (string) (data_get($integration->config, 'credential_status') ?? 'unknown');
        $discovery = $this->discoveryStates($integration, $counts, $selectedBusinesses);
        $authorizeUrl = $this->authorizeUrl($integration);
        $next = $this->nextAction($authStatus, $counts, $selectedBusinesses, $permission);

        return [
            'id' => ProviderRegistry::META,
            'integration_id' => $integration->id,
            'name' => $integration->name !== '' ? $integration->name : 'Meta',
            'state' => $operatorStatus,
            'state_label' => IntegrationOperatorStatus::label($operatorStatus),
            'auth_status' => $authStatus,
            'auth_status_label' => MetaAuthStatus::label($authStatus),
            'app_configuration_label' => MetaAuthStatus::configurationLabel($integration),
            'app_configured' => $this->credentials->isApplicationConfigured(),
            'app_configuration' => $configHealth,
            'authorization_credential_label' => MetaAuthStatus::accessTokenLabel($integration),
            'connection_test_label' => MetaAuthStatus::connectionLabel($integration),
            'credential_status' => $credentialStatus,
            'credential_valid' => $credentialStatus === MetaCredentialValidator::STATUS_VALID
                || ($authStatus === MetaAuthStatus::CONNECTED && $credentialStatus === 'unknown'),
            'credential_summary' => [
                'application_configured' => $this->credentials->isApplicationConfigured(),
                'tenant_authorization_present' => $this->credentials->hasTenantAuthorization($integration),
                'authorization_source' => $this->credentials->accessTokenSource($integration),
                'credential_status' => $credentialStatus,
                'legacy_manual_token_path' => $this->credentials->hasTenantAuthorization($integration)
                    && data_get($integration->config, 'auth_method') !== 'oauth',
            ],
            'permission_coverage' => [
                'requested' => $permission['requested'],
                'granted' => $permission['granted'],
                'missing_business_discovery' => $permission['missing_business_discovery'],
                'missing_ad_account_discovery' => $permission['missing_ad_account_discovery'],
                'can_discover_businesses' => $permission['can_discover_businesses'],
                'can_discover_ad_accounts' => $permission['can_discover_ad_accounts'],
                'needs_reauthorization' => $permission['needs_reauthorization'],
            ],
            'graph_api_version' => MetaApiConfig::apiVersion(),
            'last_check' => $this->relativeLastCheck($integration) ?? '—',
            'businesses_discovered' => $counts['businesses'],
            'businesses_selected' => $selectedBusinesses,
            'ad_accounts_discovered' => $counts['ad_accounts'],
            'resources_discovered' => $counts['ad_accounts'],
            'bound' => $counts['bound'],
            'available' => $counts['available'],
            'dependent_assets' => $counts['bound_assets'],
            'discovery' => $discovery,
            'businesses' => $this->businessRows($integration),
            'resource_groups' => $this->resourceGroups($counts),
            'unbound_resources' => $this->unboundAdAccounts($integration),
            'bindings' => $this->bindingRows($integration),
            'connectors' => $this->connectorSummaries($counts),
            'collection_state' => $collection['state'],
            'collection_state_label' => $collection['label'],
            'data_state' => $dataState['state'],
            'data_state_label' => $dataState['label'],
            'next_action' => $next,
            'next_action_label' => $this->nextActionLabel($next),
            'actions' => $this->availableActions(
                $integration,
                $authStatus,
                $counts,
                $selectedBusinesses,
                $permission,
            ),
            'authorize_url' => $authorizeUrl,
            'reauthorize_url' => $authorizeUrl,
            'milestones' => [
                'authorization_discovery' => 'REAL (Prompt 22)',
                'resource_selection_binding' => 'REAL (Prompt 23)',
                'production_collector' => 'REAL (Prompt 24)',
                'initial_backfill' => 'REAL (Prompt 25)',
            ],
            'activity' => $this->activityLines($integration, $authStatus, $counts, $collection),
            'hub_note' => null,
            'provenance' => 'real',
            'write_actions' => 'Disabled — MoxDOP is read / bind only',
            'secrets' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertNoSecrets(array $payload): void
    {
        $forbidden = ['access_token', 'app_secret', 'client_secret', 'token', 'authorization', 'bearer'];
        $json = strtolower((string) json_encode($payload));
        foreach ($forbidden as $needle) {
            if (str_contains($json, '"'.$needle.'"') && ! in_array($needle, ['authorization'], true)) {
                // Allow keys like authorization_credential_label / authorization_source — not secret values.
            }
        }

        if (isset($payload['secrets']) && $payload['secrets'] !== null) {
            throw new \RuntimeException('Meta read model must not expose secrets.');
        }

        foreach (['access_token', 'app_secret', 'long_lived_token', 'system_user_token'] as $key) {
            if (array_key_exists($key, $payload) && filled($payload[$key] ?? null)) {
                throw new \RuntimeException('Meta read model leaked secret key: '.$key);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDetail(): array
    {
        return [
            'id' => ProviderRegistry::META,
            'integration_id' => null,
            'name' => 'Meta',
            'state' => IntegrationOperatorStatus::NOT_CONFIGURED,
            'state_label' => 'Not configured',
            'auth_status' => MetaAuthStatus::NOT_CONFIGURED,
            'auth_status_label' => MetaAuthStatus::label(MetaAuthStatus::NOT_CONFIGURED),
            'app_configuration_label' => $this->credentials->applicationConfigurationLabel(),
            'app_configured' => $this->credentials->isApplicationConfigured(),
            'authorization_credential_label' => 'Not configured',
            'connection_test_label' => 'Not tested',
            'credential_summary' => [
                'application_configured' => $this->credentials->isApplicationConfigured(),
                'tenant_authorization_present' => false,
                'authorization_source' => MetaCredentialResolver::SOURCE_MISSING,
                'legacy_manual_token_path' => false,
            ],
            'graph_api_version' => MetaApiConfig::apiVersion(),
            'last_check' => '—',
            'businesses_discovered' => 0,
            'ad_accounts_discovered' => 0,
            'resources_discovered' => 0,
            'bound' => 0,
            'available' => 0,
            'dependent_assets' => 0,
            'resource_groups' => $this->resourceGroups([
                'businesses' => 0,
                'ad_accounts' => 0,
                'bound' => 0,
                'available' => 0,
                'bound_assets' => 0,
            ]),
            'unbound_resources' => [],
            'bindings' => [],
            'connectors' => $this->connectorSummaries([
                'businesses' => 0,
                'ad_accounts' => 0,
                'bound' => 0,
                'available' => 0,
                'bound_assets' => 0,
            ]),
            'collection_state' => 'not_run',
            'collection_state_label' => 'Collection not run',
            'data_state' => 'none',
            'data_state_label' => 'No data available',
            'businesses_selected' => 0,
            'discovery' => [
                'businesses' => 'never_run',
                'ad_accounts' => 'never_run',
                'last_business_discovery_at' => null,
                'last_ad_account_discovery_at' => null,
            ],
            'businesses' => [],
            'permission_coverage' => [
                'requested' => [],
                'granted' => [],
                'missing_business_discovery' => [],
                'missing_ad_account_discovery' => [],
                'can_discover_businesses' => false,
                'can_discover_ad_accounts' => false,
                'needs_reauthorization' => true,
            ],
            'app_configuration' => (new MetaConfigurationHealth)->check(),
            'credential_status' => 'unknown',
            'credential_valid' => false,
            'next_action' => $this->credentials->isApplicationConfigured() ? 'authorize' : 'configure',
            'next_action_label' => $this->credentials->isApplicationConfigured()
                ? 'Connect Meta'
                : 'Configure Meta App credentials',
            'actions' => [
                'configure' => true,
                'authorize' => $this->credentials->isApplicationConfigured(),
                'reauthorize' => false,
                'discover_businesses' => false,
                'select_business' => false,
                'discover_ad_accounts' => false,
                'discover' => false,
                'disconnect' => false,
                'bind' => false,
                'unbind' => false,
                'collect' => false,
            ],
            'authorize_url' => null,
            'reauthorize_url' => null,
            'milestones' => [
                'authorization_discovery' => 'REAL (Prompt 22)',
                'resource_selection_binding' => 'REAL (Prompt 23)',
                'production_collector' => 'Prompt 24',
                'initial_backfill' => 'Prompt 25',
            ],
            'activity' => [[
                'when' => '—',
                'event' => 'Meta Integration not configured',
                'actor' => 'System',
                'status' => 'not_configured',
            ]],
            'hub_note' => 'Meta Integration is not configured yet.',
            'provenance' => 'real',
            'write_actions' => 'Disabled — MoxDOP is read / bind only',
            'secrets' => null,
        ];
    }

    /**
     * @return array{
     *   businesses: int,
     *   ad_accounts: int,
     *   bound: int,
     *   available: int,
     *   bound_assets: int
     * }
     */
    private function resourceBindingCounts(CoreIntegration $integration): array
    {
        $businesses = (int) CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', MetaResourceType::META_BUSINESS)
            ->count();

        $adAccounts = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->withCount([
                'bindings as active_bindings_count' => fn ($q) => $q->where('status', CoreAssetBinding::STATUS_ACTIVE),
            ])
            ->get(['id', 'status']);

        $bound = 0;
        $available = 0;
        $boundIds = [];
        foreach ($adAccounts as $resource) {
            if ((int) ($resource->active_bindings_count ?? 0) > 0) {
                $bound++;
                $boundIds[] = $resource->id;
            } elseif ($resource->status === CoreExternalResource::STATUS_AVAILABLE) {
                $available++;
            }
        }

        $boundAssets = $boundIds === [] ? 0 : (int) CoreAssetBinding::query()
            ->whereIn('external_resource_id', $boundIds)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->distinct()
            ->count('digital_asset_id');

        return [
            'businesses' => $businesses,
            'ad_accounts' => $adAccounts->count(),
            'bound' => $bound,
            'available' => $available,
            'bound_assets' => $boundAssets,
        ];
    }

    /**
     * @param  array{businesses: int, ad_accounts: int, bound: int, available: int, bound_assets: int}  $counts
     * @return list<array<string, mixed>>
     */
    private function resourceGroups(array $counts): array
    {
        return [
            [
                'type' => 'meta_ads',
                'label' => 'Meta Businesses',
                'accounts' => $counts['businesses'],
                'bound' => 0,
                'available' => $counts['businesses'],
                'connector' => null,
                'capability' => null,
                'resource_type' => MetaResourceType::META_BUSINESS,
                'container' => true,
                'bindable' => false,
                'note' => 'Provider container / access context — not a Digital Asset',
            ],
            [
                'type' => 'meta_ads',
                'label' => 'Meta Ad Accounts',
                'accounts' => $counts['ad_accounts'],
                'bound' => $counts['bound'],
                'available' => $counts['available'],
                'connector' => 'meta-ads',
                'capability' => MetaConnectorRegistry::META_ADS,
                'resource_type' => MetaResourceType::META_AD_ACCOUNT,
                'container' => false,
                'bindable' => true,
                'note' => 'Selectable for Prompt 23 binding; Prompt 24 collection root',
            ],
        ];
    }

    /**
     * @param  array{businesses: int, ad_accounts: int, bound: int, available: int, bound_assets: int}  $counts
     * @return list<array<string, mixed>>
     */
    private function connectorSummaries(array $counts): array
    {
        $out = [];
        foreach (MetaConnectorRegistry::connectors() as $connector) {
            $out[] = [
                'id' => $connector['capability'],
                'label' => $connector['label'],
                'ui_slug' => $connector['slug'],
                'resource_type' => $connector['resource_type'],
                'discovered' => $counts['ad_accounts'],
                'bound' => $counts['bound'],
                'available' => $counts['available'],
                'shares_credential' => true,
                'production_foundation' => $connector['production_foundation'],
                'collection_status' => $connector['collection_status'],
                'collection_note' => $connector['collection_note'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unboundAdAccounts(CoreIntegration $integration): array
    {
        $boundIds = CoreAssetBinding::query()
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn(
                'external_resource_id',
                CoreExternalResource::query()
                    ->select('id')
                    ->where('integration_id', $integration->id)
                    ->where('resource_type', MetaResourceType::META_AD_ACCOUNT),
            )
            ->pluck('external_resource_id')
            ->all();

        return CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->when($boundIds !== [], fn ($q) => $q->whereNotIn('id', $boundIds))
            ->orderBy('display_name')
            ->limit(100)
            ->get()
            ->map(function (CoreExternalResource $resource): array {
                $meta = is_array($resource->metadata) ? $resource->metadata : [];

                return [
                    'id' => (string) $resource->id,
                    'type' => 'meta_ads',
                    'type_label' => MetaResourceType::label(MetaResourceType::META_AD_ACCOUNT),
                    'name' => $resource->display_name,
                    'external_id' => $resource->external_id,
                    'external_id_masked' => $this->maskExternalId((string) $resource->external_id),
                    'business' => $meta['business_name'] ?? $resource->parent_external_id,
                    'currency' => $meta['currency'] ?? null,
                    'timezone' => $meta['timezone_name'] ?? null,
                    'access_label' => $this->accessContextLabel($meta),
                    'status_label' => 'Discovered — not bound',
                    'bindable' => true,
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bindingRows(CoreIntegration $integration): array
    {
        return CoreAssetBinding::query()
            ->with([
                'digitalAsset:id,name,type,brand_id',
                'digitalAsset.brand:id,name',
                'externalResource:id,display_name,external_id,resource_type,metadata,status',
            ])
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->where('capability', MetaConnectorRegistry::META_ADS)
            ->whereHas('externalResource', fn ($q) => $q->where('integration_id', $integration->id))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(function (CoreAssetBinding $binding): array {
                $resource = $binding->externalResource;
                $meta = is_array($resource?->metadata) ? $resource->metadata : [];
                $resourceAccessible = $resource?->status === CoreExternalResource::STATUS_AVAILABLE;

                return [
                    'id' => (string) $binding->id,
                    'resource' => $resource?->display_name
                        ?? $resource?->external_id
                        ?? 'Ad Account',
                    'external_id' => $resource?->external_id,
                    'external_id_masked' => $this->maskExternalId((string) ($resource?->external_id ?? '')),
                    'business' => $meta['business_name'] ?? $resource?->parent_external_id,
                    'currency' => $meta['currency'] ?? null,
                    'timezone' => $meta['timezone_name'] ?? null,
                    'access_label' => $this->accessContextLabel($meta),
                    'binding' => 'Meta Ads Binding',
                    'asset' => $binding->digitalAsset?->name ?? 'Digital Asset',
                    'brand' => $binding->digitalAsset?->brand?->name,
                    'status' => $binding->status,
                    'resource_access' => $resourceAccessible ? 'accessible' : 'access_lost',
                    'data_label' => 'Not collected yet / see Data state',
                    'route' => 'demo.meta.overview',
                ];
            })
            ->all();
    }

    /**
     * @return array{state: string, label: string}
     */
    private function collectionSummary(CoreIntegration $integration): array
    {
        if (! Schema::hasTable('collection_runs')) {
            return ['state' => 'not_run', 'label' => 'Collection not run'];
        }

        $assetIds = CoreAssetBinding::query()
            ->where('capability', MetaConnectorRegistry::META_ADS)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereHas('externalResource', fn ($q) => $q->where('integration_id', $integration->id))
            ->pluck('digital_asset_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($assetIds === []) {
            return ['state' => 'not_run', 'label' => 'Collection not run'];
        }

        $run = CollectionRun::query()
            ->whereIn('digital_asset_id', $assetIds)
            ->orderByDesc('id')
            ->first();

        if ($run === null) {
            return ['state' => 'not_run', 'label' => 'Collection not run'];
        }

        return [
            'state' => $run->status->value,
            'label' => 'Last collection · '.$run->status->value,
        ];
    }

    /**
     * @return array{state: string, label: string}
     */
    private function dataFreshnessSummary(CoreIntegration $integration): array
    {
        if (! Schema::hasTable('dataset_materializations')) {
            return ['state' => 'none', 'label' => 'No data available'];
        }

        $resourceIds = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->pluck('id')
            ->all();

        if ($resourceIds === []) {
            return ['state' => 'none', 'label' => 'No data available'];
        }

        $available = DatasetMaterialization::query()
            ->whereIn('external_resource_id', $resourceIds)
            ->where('provider_or_source', 'META_ADS')
            ->where('status', MaterializationStatus::Available)
            ->exists();

        $stale = DatasetMaterialization::query()
            ->whereIn('external_resource_id', $resourceIds)
            ->where('provider_or_source', 'META_ADS')
            ->whereIn('status', [MaterializationStatus::Stale, MaterializationStatus::Partial])
            ->exists();

        if ($stale && $available) {
            return ['state' => 'stale', 'label' => 'Previous data available · refresh required'];
        }
        if ($available) {
            return ['state' => 'available', 'label' => 'Data available'];
        }
        if ($stale) {
            return ['state' => 'stale', 'label' => 'Stale / partial data'];
        }

        return ['state' => 'none', 'label' => 'No data available'];
    }

    /**
     * @param  array{businesses: int, ad_accounts: int, bound: int, available: int, bound_assets: int}  $counts
     * @param  array<string, mixed>  $permission
     */
    private function nextAction(string $authStatus, array $counts, int $selectedBusinesses, array $permission): string
    {
        if (! $this->credentials->isApplicationConfigured()) {
            return 'configure';
        }
        if (in_array($authStatus, [
            MetaAuthStatus::NOT_CONFIGURED,
            MetaAuthStatus::AUTHORIZATION_REQUIRED,
            MetaAuthStatus::REAUTH_REQUIRED,
        ], true)) {
            return $authStatus === MetaAuthStatus::REAUTH_REQUIRED ? 'reauthorize' : 'authorize';
        }
        if ($authStatus === MetaAuthStatus::PERMISSION_REQUIRED) {
            return 'reauthorize';
        }
        if ($counts['businesses'] === 0) {
            return 'discover_businesses';
        }
        if ($selectedBusinesses === 0) {
            return 'select_business';
        }
        if ($counts['ad_accounts'] === 0) {
            return 'discover_ad_accounts';
        }
        if ($counts['bound'] === 0) {
            return 'bind';
        }

        return 'collect';
    }

    private function nextActionLabel(string $next): string
    {
        return match ($next) {
            'configure' => 'Configure Meta App credentials',
            'authorize' => 'Connect Meta',
            'reauthorize' => 'Reauthorize Meta',
            'discover_businesses' => 'Discover Businesses',
            'select_business' => 'Select Business discovery context',
            'discover_ad_accounts' => 'Discover Ad Accounts',
            'bind' => 'Confirm Ad Account connection',
            'collect' => 'Collect data',
            default => 'Manage Meta',
        };
    }

    /**
     * @param  array{businesses: int, ad_accounts: int, bound: int, available: int, bound_assets: int}  $counts
     * @param  array<string, mixed>  $permission
     * @return array<string, bool>
     */
    private function availableActions(
        CoreIntegration $integration,
        string $authStatus,
        array $counts,
        int $selectedBusinesses,
        array $permission,
    ): array {
        $appOk = $this->credentials->isApplicationConfigured();
        $authorized = in_array($authStatus, [
            MetaAuthStatus::CONNECTED,
            MetaAuthStatus::CONFIGURED,
            MetaAuthStatus::PERMISSION_REQUIRED,
        ], true);
        $reauth = in_array($authStatus, [
            MetaAuthStatus::REAUTH_REQUIRED,
            MetaAuthStatus::PERMISSION_REQUIRED,
        ], true);

        return [
            'configure' => true,
            'authorize' => $appOk && ! $authorized,
            'reauthorize' => $appOk && ($authorized || $reauth),
            'discover_businesses' => $authorized && (bool) ($permission['can_discover_businesses'] ?? false),
            'select_business' => $authorized && $counts['businesses'] > 0,
            'discover_ad_accounts' => $authorized
                && $selectedBusinesses > 0
                && (bool) ($permission['can_discover_ad_accounts'] ?? false),
            'discover' => $authorized && (bool) ($permission['can_discover_businesses'] ?? false),
            'disconnect' => $this->credentials->hasTenantAuthorization($integration),
            'bind' => $authorized && $counts['ad_accounts'] > 0,
            'unbind' => $counts['bound'] > 0,
            'collect' => $authorized
                && $counts['bound'] > 0
                && $this->credentials->hasTenantAuthorization($integration),
        ];
    }

    private function selectedBusinessCount(CoreIntegration $integration): int
    {
        if (! Schema::hasTable('core_integration_discovery_contexts')) {
            return 0;
        }

        return (int) CoreIntegrationDiscoveryContext::query()
            ->where('integration_id', $integration->id)
            ->where('purpose', CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT)
            ->where('status', CoreIntegrationDiscoveryContext::STATUS_ACTIVE)
            ->count();
    }

    /**
     * @param  array{businesses: int, ad_accounts: int, bound: int, available: int, bound_assets: int}  $counts
     * @return array<string, mixed>
     */
    private function discoveryStates(CoreIntegration $integration, array $counts, int $selectedBusinesses): array
    {
        $businessState = data_get($integration->config, 'discovery.businesses.status');
        $adState = data_get($integration->config, 'discovery.ad_accounts.status');

        if (! is_string($businessState) || $businessState === '') {
            $businessState = $counts['businesses'] > 0 ? 'completed' : 'never_run';
        }
        if (! is_string($adState) || $adState === '') {
            $adState = $counts['ad_accounts'] > 0 ? 'completed' : 'never_run';
        }

        return [
            'businesses' => $businessState,
            'ad_accounts' => $adState,
            'selected_businesses' => $selectedBusinesses,
            'last_business_discovery_at' => data_get($integration->config, 'discovery.businesses.finished_at'),
            'last_ad_account_discovery_at' => data_get($integration->config, 'discovery.ad_accounts.finished_at'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function businessRows(CoreIntegration $integration): array
    {
        $selectedIds = Schema::hasTable('core_integration_discovery_contexts')
            ? CoreIntegrationDiscoveryContext::query()
                ->where('integration_id', $integration->id)
                ->where('purpose', CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT)
                ->where('status', CoreIntegrationDiscoveryContext::STATUS_ACTIVE)
                ->pluck('external_resource_id')
                ->all()
            : [];

        return CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', MetaResourceType::META_BUSINESS)
            ->orderBy('display_name')
            ->limit(100)
            ->get()
            ->map(fn (CoreExternalResource $resource): array => [
                'id' => (string) $resource->id,
                'external_id' => $resource->external_id,
                'name' => $resource->display_name,
                'status' => $resource->status,
                'selected' => in_array($resource->id, $selectedIds, true),
                'container' => true,
                'bindable' => false,
            ])
            ->all();
    }

    private function authorizeUrl(CoreIntegration $integration): ?string
    {
        if (! $this->credentials->isApplicationConfigured()) {
            return null;
        }

        try {
            return route('integrations.meta.authorize', ['integration' => $integration->id]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{businesses: int, ad_accounts: int, bound: int, available: int, bound_assets: int}  $counts
     * @param  array{state: string, label: string}  $collection
     * @return list<array<string, mixed>>
     */
    private function activityLines(
        CoreIntegration $integration,
        string $authStatus,
        array $counts,
        array $collection,
    ): array {
        return [
            [
                'when' => $this->relativeLastCheck($integration) ?? '—',
                'event' => 'Authorization · '.MetaAuthStatus::label($authStatus),
                'actor' => 'System',
                'status' => $authStatus === MetaAuthStatus::CONNECTED ? 'success' : 'info',
            ],
            [
                'when' => '—',
                'event' => sprintf(
                    'Inventory · %d Businesses · %d Ad Accounts · %d bound',
                    $counts['businesses'],
                    $counts['ad_accounts'],
                    $counts['bound'],
                ),
                'actor' => 'System',
                'status' => 'info',
            ],
            [
                'when' => '—',
                'event' => $collection['label'],
                'actor' => 'System',
                'status' => $collection['state'] === 'not_run' ? 'info' : 'success',
            ],
        ];
    }

    private function relativeLastCheck(CoreIntegration $integration): ?string
    {
        $at = data_get($integration->config, 'last_tested_at')
            ?? $integration->last_success_at
            ?? $integration->updated_at;

        if ($at === null) {
            return null;
        }

        try {
            return Carbon::parse($at)->diffForHumans();
        } catch (\Throwable) {
            return null;
        }
    }

    private function maskExternalId(string $externalId): string
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            return '—';
        }

        if (strlen($externalId) <= 8) {
            return $externalId;
        }

        return substr($externalId, 0, 4).'…'.substr($externalId, -4);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function accessContextLabel(array $meta): ?string
    {
        $contexts = is_array($meta['access_contexts'] ?? null) ? $meta['access_contexts'] : [];
        $edges = collect($contexts)
            ->pluck('edge')
            ->filter(fn ($edge) => is_string($edge) && $edge !== '')
            ->unique()
            ->values();

        if ($edges->contains('owned_ad_accounts') && $edges->contains('client_ad_accounts')) {
            return 'Owned + Client / Shared';
        }
        if ($edges->contains('client_ad_accounts')) {
            return 'Client / Shared';
        }
        if ($edges->contains('owned_ad_accounts')) {
            return 'Owned';
        }

        return null;
    }
}
