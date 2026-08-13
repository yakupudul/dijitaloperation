<?php

namespace App\Services\Integrations\Google;

use App\Enums\DataPool\MaterializationStatus;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DataPool\DatasetMaterialization;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Google\GoogleConnectorRegistry;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Presentation\IntegrationHealthPresenter;
use App\Support\Integrations\Presentation\IntegrationOperatorStatus;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen `/app/integrations` Google read model.
 *
 * Persisted CoreIntegration state only — never decrypts secrets for UI,
 * never performs provider HTTP, never invents Demo connection counts as real.
 */
final class GoogleIntegrationReadModel
{
    public function __construct(
        private readonly IntegrationHealthPresenter $health = new IntegrationHealthPresenter,
        private readonly GoogleCredentialResolver $credentials = new GoogleCredentialResolver,
        private readonly GoogleScopeCoverageService $coverage = new GoogleScopeCoverageService,
        private readonly GoogleOAuthConfigurationHealth $configHealth = new GoogleOAuthConfigurationHealth,
    ) {}

    public function findIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with(['providerCredential', 'authorizationCredential'])
            ->where('provider', ProviderRegistry::GOOGLE)
            ->orderBy('id')
            ->first();
    }

    /**
     * Hub card shape for frozen IntegrationsIndex (replaces fixture Google card).
     *
     * @return array<string, mixed>
     */
    public function hubCard(): array
    {
        $detail = $this->detail();

        return [
            'id' => ProviderRegistry::GOOGLE,
            'name' => 'Google',
            'logo_type' => 'google_ads',
            'state' => $detail['state'],
            'state_label' => $detail['state_label'],
            'resources_discovered' => $detail['resources_discovered'],
            'bound' => $detail['bound'],
            'available' => $detail['available'],
            'last_check' => $detail['last_check'],
            'dependent_assets' => $detail['dependent_assets'],
            'route' => 'demo.integrations.google',
            'manage_label' => $detail['integration_id'] !== null ? 'Manage' : 'Configure',
            'provenance' => 'real',
            'next_action' => $detail['next_action'],
            'collection_state' => $detail['collection_state'],
            'data_state' => $detail['data_state'],
            'note' => $detail['hub_note'],
        ];
    }

    /**
     * Detail payload for GoogleIntegrationPage (same keys as former fixtures, honest values).
     *
     * @return array<string, mixed>
     */
    public function detail(): array
    {
        $integration = $this->findIntegration();

        if (! $integration instanceof CoreIntegration) {
            return $this->emptyDetail(
                state: IntegrationOperatorStatus::NOT_CONFIGURED,
                stateLabel: 'Not configured',
                hubNote: 'Google Integration is not configured yet.',
                nextAction: 'configure',
            );
        }

        $operatorStatus = $this->health->status($integration, ProviderRegistry::GOOGLE);
        $authStatus = GoogleAuthStatus::for($integration);
        $counts = $this->resourceBindingCounts($integration);
        $resourceGroups = $this->resourceGroups($counts['by_type']);
        $unbound = $this->unboundResources($integration);
        $bindings = $this->bindingRows($integration);
        $collection = $this->collectionSummary($integration);
        $dataState = $this->dataFreshnessSummary($integration);
        $lastCheck = $this->relativeLastCheck($integration);

        $dependent = $counts['bound_assets'];

        return [
            'id' => ProviderRegistry::GOOGLE,
            'integration_id' => $integration->id,
            'name' => $integration->name !== '' ? $integration->name : 'Google',
            'state' => $operatorStatus,
            'state_label' => IntegrationOperatorStatus::label($operatorStatus),
            'auth_status' => $authStatus,
            'auth_status_label' => GoogleAuthStatus::label($authStatus),
            'app_configuration_label' => GoogleAuthStatus::applicationConfigurationLabel($integration),
            'ads_developer_token_label' => GoogleAuthStatus::adsDeveloperTokenLabel($integration),
            'credential_summary' => $this->credentialSummary($integration),
            'account_email' => $this->safeAccountEmail($integration),
            'granted_scopes_label' => $this->grantedScopesLabel($integration),
            'last_check' => $lastCheck ?? '—',
            'resources_discovered' => $counts['discovered'],
            'bound' => $counts['bound'],
            'available' => $counts['available'],
            'dependent_assets' => $dependent,
            'resource_groups' => $resourceGroups,
            'unbound_resources' => $unbound,
            'bindings' => $bindings,
            'disconnect_impact' => $this->disconnectImpact($resourceGroups, $dependent),
            'activity' => $this->activityLines($integration, $authStatus, $counts, $collection),
            'collection_state' => $collection['state'],
            'collection_state_label' => $collection['label'],
            'data_state' => $dataState['state'],
            'data_state_label' => $dataState['label'],
            'next_action' => $this->nextAction($operatorStatus, $authStatus, $counts),
            'next_action_label' => $this->nextActionLabel($operatorStatus, $authStatus, $counts),
            'actions' => $this->availableActions($integration, $operatorStatus, $authStatus),
            'connectors' => $this->connectorSummaries($counts['by_type'], $integration),
            'connector_auth' => $this->coverage->connectorStatuses($integration),
            'config_health_ok' => $this->configHealth->check($integration)['ok'],
            'authorize_url' => $this->authorizeUrl($integration),
            'reauthorize_url' => $this->authorizeUrl($integration, forceConsent: true),
            'hub_note' => null,
            'provenance' => 'real',
            'write_actions' => 'Disabled — MoxDOP is read / bind only',
            // Never expose secrets:
            'secrets' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDetail(string $state, string $stateLabel, string $hubNote, string $nextAction): array
    {
        $groups = $this->resourceGroups([]);

        return [
            'id' => ProviderRegistry::GOOGLE,
            'integration_id' => null,
            'name' => 'Google',
            'state' => $state,
            'state_label' => $stateLabel,
            'auth_status' => GoogleAuthStatus::NOT_CONFIGURED,
            'auth_status_label' => GoogleAuthStatus::label(GoogleAuthStatus::NOT_CONFIGURED),
            'app_configuration_label' => 'Incomplete',
            'ads_developer_token_label' => 'Developer token missing',
            'credential_summary' => [
                'provider_configured' => false,
                'authorization_present' => false,
                'has_refresh_token' => false,
            ],
            'account_email' => null,
            'granted_scopes_label' => 'Not granted',
            'last_check' => '—',
            'resources_discovered' => 0,
            'bound' => 0,
            'available' => 0,
            'dependent_assets' => 0,
            'resource_groups' => $groups,
            'unbound_resources' => [],
            'bindings' => [],
            'disconnect_impact' => [
                'Total dependent Digital Assets' => 0,
            ],
            'activity' => [
                [
                    'when' => '—',
                    'event' => 'Google Integration not configured',
                    'actor' => 'System',
                    'status' => 'not_configured',
                ],
            ],
            'collection_state' => 'not_run',
            'collection_state_label' => 'Collection not run',
            'data_state' => 'none',
            'data_state_label' => 'No data available',
            'next_action' => $nextAction,
            'next_action_label' => 'Configure Google',
            'actions' => [
                'configure' => true,
                'authorize' => false,
                'discover' => false,
                'bind' => false,
                'collect' => false,
                'disconnect' => false,
            ],
            'connectors' => $this->connectorSummaries([]),
            'connector_auth' => [],
            'config_health_ok' => false,
            'authorize_url' => null,
            'reauthorize_url' => null,
            'hub_note' => $hubNote,
            'provenance' => 'real',
            'write_actions' => 'Disabled — MoxDOP is read / bind only',
            'secrets' => null,
        ];
    }

    /**
     * @return array{
     *     discovered: int,
     *     bound: int,
     *     available: int,
     *     bound_assets: int,
     *     by_type: array<string, array{discovered: int, bound: int, available: int}>
     * }
     */
    private function resourceBindingCounts(CoreIntegration $integration): array
    {
        /** @var Collection<int, CoreExternalResource> $resources */
        $resources = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::GOOGLE)
            ->withCount([
                'bindings as active_bindings_count' => fn ($q) => $q->where('status', CoreAssetBinding::STATUS_ACTIVE),
            ])
            ->get(['id', 'resource_type', 'status']);

        $byType = [];
        foreach (GoogleResourceType::all() as $type) {
            $byType[$type] = ['discovered' => 0, 'bound' => 0, 'available' => 0];
        }

        $discovered = 0;
        $bound = 0;
        $available = 0;
        $boundResourceIds = [];

        foreach ($resources as $resource) {
            $type = (string) $resource->resource_type;
            if (! isset($byType[$type])) {
                $byType[$type] = ['discovered' => 0, 'bound' => 0, 'available' => 0];
            }

            $discovered++;
            $byType[$type]['discovered']++;

            $activeBindings = (int) ($resource->active_bindings_count ?? 0);
            if ($activeBindings > 0) {
                $bound++;
                $byType[$type]['bound']++;
                $boundResourceIds[] = $resource->id;
            } elseif ($resource->status === CoreExternalResource::STATUS_AVAILABLE) {
                $available++;
                $byType[$type]['available']++;
            }
        }

        $boundAssets = 0;
        if ($boundResourceIds !== []) {
            $boundAssets = (int) CoreAssetBinding::query()
                ->whereIn('external_resource_id', $boundResourceIds)
                ->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->distinct()
                ->count('digital_asset_id');
        }

        return [
            'discovered' => $discovered,
            'bound' => $bound,
            'available' => $available,
            'bound_assets' => $boundAssets,
            'by_type' => $byType,
        ];
    }

    /**
     * @param  array<string, array{discovered: int, bound: int, available: int}>  $byType
     * @return list<array<string, mixed>>
     */
    private function resourceGroups(array $byType): array
    {
        $groups = [];

        foreach (GoogleConnectorRegistry::all() as $connector) {
            $type = $connector['resource_type'];
            $counts = $byType[$type] ?? ['discovered' => 0, 'bound' => 0, 'available' => 0];

            $groups[] = [
                'type' => $connector['visual_type'],
                'label' => $connector['label'],
                'accounts' => $counts['discovered'],
                'bound' => $counts['bound'],
                'available' => $counts['available'],
                'connector' => $connector['ui_slug'],
                'capability' => $connector['capability'],
                'resource_type' => $type,
                'discovery_status' => $connector['discovery'],
                'collection_status' => $connector['collection'],
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, array{discovered: int, bound: int, available: int}>  $byType
     * @return list<array<string, mixed>>
     */
    private function connectorSummaries(array $byType, ?CoreIntegration $integration = null): array
    {
        $authStatuses = $integration instanceof CoreIntegration
            ? collect($this->coverage->connectorStatuses($integration))->keyBy('capability')
            : collect();

        $out = [];

        foreach (GoogleConnectorRegistry::all() as $id => $connector) {
            $counts = $byType[$connector['resource_type']] ?? ['discovered' => 0, 'bound' => 0, 'available' => 0];
            $auth = $authStatuses->get($connector['capability']);
            $out[] = [
                'id' => $id,
                'label' => $connector['label'],
                'ui_slug' => $connector['ui_slug'],
                'resource_type' => $connector['resource_type'],
                'discovered' => $counts['discovered'],
                'bound' => $counts['bound'],
                'available' => $counts['available'],
                'shares_credential' => GoogleConnectorRegistry::sharesAuthorizationCredential(),
                'auth_status' => $auth['status'] ?? 'not_authorized',
                'auth_status_label' => $auth['status_label'] ?? 'Not authorized',
            ];
        }

        return $out;
    }

    private function authorizeUrl(?CoreIntegration $integration, bool $forceConsent = false): ?string
    {
        if (! $integration instanceof CoreIntegration) {
            return null;
        }

        return route('integrations.google.authorize', [
            'integration' => $integration,
            'force_consent' => $forceConsent ? 1 : null,
        ], absolute: false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unboundResources(CoreIntegration $integration): array
    {
        $boundIds = CoreAssetBinding::query()
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn(
                'external_resource_id',
                CoreExternalResource::query()
                    ->select('id')
                    ->where('integration_id', $integration->id),
            )
            ->pluck('external_resource_id')
            ->all();

        return CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::GOOGLE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->when($boundIds !== [], fn ($q) => $q->whereNotIn('id', $boundIds))
            ->orderBy('resource_type')
            ->orderBy('display_name')
            ->limit(50)
            ->get()
            ->map(function (CoreExternalResource $resource): array {
                $type = (string) $resource->resource_type;

                return [
                    'id' => (string) $resource->id,
                    'type' => GoogleResourceType::visualType($type),
                    'type_label' => GoogleResourceType::label($type),
                    'name' => $resource->display_name,
                    'external_id' => $resource->external_id,
                    'status' => 'available',
                    'status_label' => 'Available · Not bound to a Digital Asset',
                    'resource_type' => $type,
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
            ->with(['digitalAsset:id,name,type', 'externalResource:id,display_name,external_id,resource_type,integration_id'])
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereHas('externalResource', function ($q) use ($integration): void {
                $q->where('integration_id', $integration->id)
                    ->where('provider', ProviderRegistry::GOOGLE);
            })
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(function (CoreAssetBinding $binding): array {
                $resource = $binding->externalResource;
                $asset = $binding->digitalAsset;
                $resourceLabel = $resource
                    ? trim($resource->display_name.' · '.$resource->external_id)
                    : 'Unknown resource';
                $route = $this->assetRouteForType((string) ($asset?->type ?? ''));

                return [
                    'resource' => $resourceLabel,
                    'binding' => 'Google binding · '.$binding->capability,
                    'asset' => $asset?->name ?? 'Unknown asset',
                    'asset_id' => $asset?->id,
                    'route' => $route,
                    'capability' => $binding->capability,
                ];
            })
            ->all();
    }

    private function assetRouteForType(string $type): string
    {
        return match ($type) {
            'ga4', 'google_analytics', 'analytics' => 'demo.analytics',
            'gsc', 'search_console', 'google_search_console' => 'demo.search-console',
            'google_ads' => 'demo.google-ads.overview',
            'google_business_profile', 'gbp' => 'demo.gbp',
            default => 'demo.assets',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $resourceGroups
     * @return array<string, int>
     */
    private function disconnectImpact(array $resourceGroups, int $dependent): array
    {
        $impact = [];
        foreach ($resourceGroups as $group) {
            $impact[$group['label'].' bindings'] = (int) $group['bound'];
        }
        $impact['Total dependent Digital Assets'] = $dependent;

        return $impact;
    }

    /**
     * @param  array{discovered: int, bound: int, available: int, bound_assets: int, by_type: array<string, array{discovered: int, bound: int, available: int}>}  $counts
     * @param  array{state: string, label: string}  $collection
     * @return list<array<string, mixed>>
     */
    private function activityLines(
        CoreIntegration $integration,
        string $authStatus,
        array $counts,
        array $collection,
    ): array {
        $lines = [];

        if (filled($integration->last_error)) {
            $lines[] = [
                'when' => $integration->updated_at?->diffForHumans(short: true) ?? '—',
                'event' => 'Integration reported an error (details withheld from UI)',
                'actor' => 'System',
                'status' => 'needs_attention',
            ];
        }

        if ($authStatus === GoogleAuthStatus::REFRESH_REQUIRED) {
            $lines[] = [
                'when' => '—',
                'event' => 'Authorization refresh required',
                'actor' => 'System',
                'status' => 'needs_attention',
            ];
        }

        $refreshAt = data_get($integration->config, 'last_resource_refresh_at');
        if (is_string($refreshAt) && $refreshAt !== '') {
            try {
                $when = Carbon::parse($refreshAt)->diffForHumans(short: true);
            } catch (\Throwable) {
                $when = '—';
            }
            $lines[] = [
                'when' => $when,
                'event' => 'Resource discovery last ran ('.$counts['discovered'].' resources known)',
                'actor' => 'System',
                'status' => 'success',
            ];
        } elseif ($counts['discovered'] === 0) {
            $lines[] = [
                'when' => '—',
                'event' => 'Discovery not run',
                'actor' => 'System',
                'status' => 'info',
            ];
        }

        $lines[] = [
            'when' => '—',
            'event' => $collection['label'],
            'actor' => 'System',
            'status' => $collection['state'] === 'not_run' ? 'info' : 'success',
        ];

        return array_slice($lines, 0, 8);
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
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereHas('externalResource', fn ($q) => $q->where('integration_id', $integration->id))
            ->pluck('digital_asset_id');

        if ($assetIds->isEmpty()) {
            return ['state' => 'not_run', 'label' => 'Collection not run'];
        }

        $run = CollectionRun::query()
            ->whereIn('digital_asset_id', $assetIds)
            ->orderByDesc('id')
            ->first(['id', 'status', 'finished_at', 'created_at']);

        if ($run === null) {
            return ['state' => 'not_run', 'label' => 'Collection not run'];
        }

        $status = (string) ($run->status?->value ?? $run->status ?? 'unknown');

        return [
            'state' => $status,
            'label' => 'Last collection · '.$status,
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
            ->pluck('id');

        if ($resourceIds->isEmpty()) {
            return ['state' => 'none', 'label' => 'No data available'];
        }

        $available = DatasetMaterialization::query()
            ->whereIn('external_resource_id', $resourceIds)
            ->where('status', MaterializationStatus::Available)
            ->exists();

        if ($available) {
            return ['state' => 'fresh', 'label' => 'Data available'];
        }

        $staleOrPartial = DatasetMaterialization::query()
            ->whereIn('external_resource_id', $resourceIds)
            ->whereIn('status', [MaterializationStatus::Stale, MaterializationStatus::Partial])
            ->exists();

        if ($staleOrPartial) {
            return ['state' => 'stale', 'label' => 'Stale or partial materialization'];
        }

        $any = DatasetMaterialization::query()
            ->whereIn('external_resource_id', $resourceIds)
            ->exists();

        if ($any) {
            return ['state' => 'unavailable', 'label' => 'Materialization present but not available'];
        }

        return ['state' => 'none', 'label' => 'No data available'];
    }

    private function relativeLastCheck(CoreIntegration $integration): ?string
    {
        $label = $this->health->lastCheckedLabel($integration, ProviderRegistry::GOOGLE);
        if ($label === null) {
            return null;
        }

        return str_replace('Last checked ', '', $label);
    }

    /**
     * @return array{provider_configured: bool, authorization_present: bool, has_refresh_token: bool}
     */
    private function credentialSummary(CoreIntegration $integration): array
    {
        $auth = $integration->authorizationCredential;
        $payload = is_array($auth?->encrypted_payload) ? $auth->encrypted_payload : [];

        return [
            'provider_configured' => $this->credentials->isAppConfigured($integration),
            'authorization_present' => $auth !== null,
            'has_refresh_token' => filled($payload['refresh_token'] ?? null),
        ];
    }

    private function safeAccountEmail(CoreIntegration $integration): ?string
    {
        $email = data_get($integration->config, 'account_email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    private function grantedScopesLabel(CoreIntegration $integration): string
    {
        $scopes = data_get($integration->config, 'granted_scopes');
        if (! is_array($scopes) || $scopes === []) {
            return 'Not granted';
        }

        // Human labels only — never dump raw scope URLs as primary content.
        $labels = [];
        foreach ($scopes as $scope) {
            if (! is_string($scope)) {
                continue;
            }
            if (str_contains($scope, 'adwords')) {
                $labels[] = 'Ads';
            } elseif (str_contains($scope, 'analytics')) {
                $labels[] = 'Analytics';
            } elseif (str_contains($scope, 'webmasters')) {
                $labels[] = 'Search Console';
            } elseif (str_contains($scope, 'business')) {
                $labels[] = 'Business Profile';
            }
        }

        $labels = array_values(array_unique($labels));

        return $labels === [] ? 'Granted (details withheld)' : implode(' · ', $labels);
    }

    /**
     * @param  array{discovered: int, bound: int, available: int, bound_assets: int, by_type: array<string, array{discovered: int, bound: int, available: int}>}  $counts
     */
    private function nextAction(string $operatorStatus, string $authStatus, array $counts): string
    {
        if ($operatorStatus === IntegrationOperatorStatus::NOT_CONFIGURED
            || $authStatus === GoogleAuthStatus::NOT_CONFIGURED) {
            return 'configure';
        }

        if (in_array($authStatus, [GoogleAuthStatus::AUTHORIZATION_REQUIRED, GoogleAuthStatus::REFRESH_REQUIRED], true)) {
            return 'authorize';
        }

        if ($counts['discovered'] === 0) {
            return 'discover';
        }

        if ($counts['bound'] === 0) {
            return 'bind';
        }

        if ($counts['bound'] > 0) {
            return 'collect';
        }

        return 'manage';
    }

    /**
     * @param  array{discovered: int, bound: int, available: int, bound_assets: int, by_type: array<string, array{discovered: int, bound: int, available: int}>}  $counts
     */
    private function nextActionLabel(string $operatorStatus, string $authStatus, array $counts): string
    {
        return match ($this->nextAction($operatorStatus, $authStatus, $counts)) {
            'configure' => 'Configure Google',
            'authorize' => 'Connect Google',
            'discover' => 'Discover resources (Prompt 15)',
            'bind' => 'Select resources (Prompt 16)',
            'collect' => 'Collect data via Collection Engine',
            default => 'Manage Google',
        };
    }

    /**
     * @return array{configure: bool, authorize: bool, discover: bool, bind: bool, collect: bool, disconnect: bool}
     */
    private function availableActions(
        CoreIntegration $integration,
        string $operatorStatus,
        string $authStatus,
    ): array {
        $configured = $this->credentials->isAppConfigured($integration);
        $canAuthorize = $configured
            && $operatorStatus !== IntegrationOperatorStatus::DISABLED
            && $authStatus !== GoogleAuthStatus::DISABLED;

        $authUsable = in_array($authStatus, [
            GoogleAuthStatus::CONNECTED,
            GoogleAuthStatus::REFRESH_REQUIRED,
            GoogleAuthStatus::REVOKED,
            GoogleAuthStatus::AUTHORIZATION_REQUIRED,
        ], true);

        return [
            'configure' => true,
            'authorize' => $canAuthorize,
            'reauthorize' => $canAuthorize && $authUsable,
            // Discovery / bind / collect production UX owned by later prompts.
            'discover' => false,
            'bind' => false,
            'collect' => false,
            // Explicit Google grant revocation (not per-Connector disable).
            'disconnect' => $canAuthorize && $integration->authorizationCredential()->exists(),
        ];
    }

    /**
     * Assert read model array never carries secret-shaped keys.
     *
     * @param  array<string, mixed>  $payload
     */
    public function assertNoSecrets(array $payload): void
    {
        $forbidden = [
            'access_token',
            'refresh_token',
            'client_secret',
            'developer_token',
            'encrypted_payload',
            'Authorization',
            'authorization',
        ];

        $json = json_encode($payload) ?: '';
        foreach ($forbidden as $key) {
            if (str_contains($json, '"'.$key.'"')) {
                throw new \RuntimeException("Google integration read model leaked secret key: {$key}");
            }
        }
    }
}
