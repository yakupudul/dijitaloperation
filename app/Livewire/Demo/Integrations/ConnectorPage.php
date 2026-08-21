<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Support\Demo\DemoState;
use App\Support\Integrations\Google\GoogleConnectorRegistry;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Connector')]
class ConnectorPage extends Component
{
    public string $connector = 'ga4';

    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

    #[Url]
    public string $q = '';

    #[Url]
    public string $state = 'all';

    #[Url]
    public string $brand = 'all';

    public ?string $bindResourceId = null;

    public string $bindMode = 'existing';

    public string $selectedAssetId = '';

    public string $newAssetName = '';

    public bool $confirmBind = false;

    /** @var list<string> */
    private const TABS = ['overview', 'resources', 'bindings', 'data', 'sync', 'activity'];

    /**
     * Non-Google connector metadata. Google connector metadata is canonicalized in GoogleConnectorRegistry.
     *
     * @var array<string, array{id: string, name: string, type: string, integration: string, integration_label: string, integration_route: string}>
     */
    private const CONNECTORS = [
        'meta-ads' => [
            'id' => 'meta-ads',
            'name' => 'Meta Ads',
            'type' => 'meta_ads',
            'integration' => 'meta',
            'integration_label' => 'Meta',
            'integration_route' => 'operator.integrations.meta',
        ],
    ];

    public function mount(string $connector): void
    {
        $this->connector = $connector;

        if (GoogleConnectorRegistry::byUiSlug($connector) === null && ! isset(self::CONNECTORS[$connector])) {
            abort(404);
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
            $this->closeBind();
        }
    }

    public function openBind(string $resourceId): void
    {
        if (GoogleConnectorRegistry::byUiSlug($this->connector) !== null) {
            $this->redirect(route('operator.integrations.google', ['tab' => 'resources']), navigate: true);

            return;
        }

        DemoState::flash(__('operator.flash.configure_integration_resources'), 'info');
    }

    public function closeBind(): void
    {
        $this->bindResourceId = null;
        $this->confirmBind = false;
        $this->selectedAssetId = '';
        $this->newAssetName = '';
    }

    public function prepareConfirm(): void
    {
        DemoState::flash(__('operator.flash.configure_integration_first'), 'info');
    }

    public function confirmBinding(): void
    {
        DemoState::flash(__('operator.flash.configure_integration_binding'), 'info');
        $this->closeBind();
    }

    public function unbindResource(string $resourceId): void
    {
        if (GoogleConnectorRegistry::byUiSlug($this->connector) !== null) {
            $this->redirect(route('operator.integrations.google', ['tab' => 'resources']), navigate: true);

            return;
        }

        DemoState::flash(__('operator.flash.no_bindings'), 'info');
    }

    public function refreshCollection(): void
    {
        if (GoogleConnectorRegistry::byUiSlug($this->connector) !== null) {
            $this->redirect(route('operator.integrations.google', ['tab' => 'activity']), navigate: true);

            return;
        }

        DemoState::flash(__('operator.flash.configure_integration_collection'), 'info');
    }

    public function render(GoogleIntegrationReadModel $googleReadModel): View
    {
        $googleConnector = GoogleConnectorRegistry::byUiSlug($this->connector);

        if ($googleConnector !== null) {
            return $this->renderGoogleConnector($googleReadModel, $googleConnector);
        }

        return $this->renderUnavailableConnector(self::CONNECTORS[$this->connector]);
    }

    /** @param array<string, mixed> $connector */
    private function renderGoogleConnector(GoogleIntegrationReadModel $readModel, array $connector): View
    {
        $detail = $readModel->detail();
        $integration = $readModel->findIntegration();
        $summary = collect($detail['connectors'] ?? [])->first(
            fn (array $row): bool => ($row['ui_slug'] ?? null) === $this->connector,
        ) ?? [];

        if ($integration === null) {
            return $this->renderUnavailableConnector([
                'id' => $this->connector,
                'name' => (string) $connector['label'],
                'type' => (string) $connector['visual_type'],
                'integration' => 'google',
                'integration_label' => 'Google',
                'integration_route' => 'operator.integrations.google',
            ]);
        }

        /** @var Collection<int, CoreExternalResource> $resourceModels */
        $resourceModels = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('provider', ProviderRegistry::GOOGLE)
            ->where('resource_type', $connector['resource_type'])
            ->with([
                'bindings' => fn ($query) => $query->where('status', CoreAssetBinding::STATUS_ACTIVE),
                'bindings.digitalAsset',
            ])
            ->orderBy('display_name')
            ->get();

        $resourceIds = $resourceModels->pluck('id')->all();
        $runs = $resourceIds === []
            ? collect()
            : CollectionResourceRun::query()
                ->whereIn('external_resource_id', $resourceIds)
                ->with('externalResource:id,display_name')
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id')
                ->get();

        $latestByResource = $runs->unique('external_resource_id')->keyBy('external_resource_id');

        $resources = $resourceModels->map(function (CoreExternalResource $resource) use ($latestByResource): array {
            $binding = $resource->bindings->first();
            $asset = $binding?->digitalAsset;
            $run = $latestByResource->get($resource->id);
            $status = $run instanceof CollectionResourceRun ? $run->status->value : null;
            $state = $binding instanceof CoreAssetBinding
                ? 'bound'
                : ($resource->status === CoreExternalResource::STATUS_AVAILABLE ? 'available' : 'unavailable');

            return [
                'id' => (string) $resource->id,
                'name' => (string) $resource->display_name,
                'external_id' => (string) $resource->external_id,
                'state' => $state,
                'state_label' => match ($state) {
                    'bound' => 'Bound',
                    'available' => 'Available',
                    default => 'Unavailable',
                },
                'recommended' => false,
                'stream' => $resource->metadata['stream_id'] ?? null,
                'stream_label' => 'Stream',
                'property_type' => $resource->metadata['property_type'] ?? null,
                'address' => $resource->metadata['address'] ?? null,
                'currency' => $resource->metadata['currency'] ?? null,
                'timezone' => $resource->metadata['timezone'] ?? null,
                'data_state' => $status !== null ? ucfirst(str_replace('_', ' ', $status)) : 'Not collected',
                'last_collection' => $run?->last_activity_at?->diffForHumans() ?? '—',
                'match_signal' => null,
                'asset_name' => $asset?->name,
                'asset_url' => $asset instanceof DigitalAsset ? $this->assetUrl($asset) : null,
            ];
        });

        if (trim($this->q) !== '') {
            $needle = mb_strtolower(trim($this->q));
            $resources = $resources->filter(function (array $resource) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $resource['name'] ?? null,
                    $resource['external_id'] ?? null,
                    $resource['address'] ?? null,
                ])));

                return str_contains($haystack, $needle);
            });
        }

        if ($this->state !== 'all') {
            $resources = $resources->where('state', $this->state);
        }

        if ($this->brand === 'unmapped') {
            $resources = $resources->where('state', '!=', 'bound');
        }

        $bindings = $resourceModels
            ->filter(fn (CoreExternalResource $resource): bool => $resource->bindings->isNotEmpty())
            ->map(function (CoreExternalResource $resource): array {
                $binding = $resource->bindings->first();
                $asset = $binding?->digitalAsset;

                return [
                    'name' => (string) $resource->display_name,
                    'external_id' => (string) $resource->external_id,
                    'asset_name' => $asset?->name ?? 'Unknown asset',
                    'asset_url' => $asset instanceof DigitalAsset ? $this->assetUrl($asset) : null,
                    'related_website' => null,
                    'related_website_note' => null,
                ];
            })
            ->values()
            ->all();

        $latestAttempt = $runs->first();
        $latestSuccess = $runs->first(
            fn (CollectionResourceRun $run): bool => $run->status === CollectionRunStatus::Completed,
        );
        $firstBoundAsset = $resourceModels
            ->flatMap(fn (CoreExternalResource $resource) => $resource->bindings)
            ->map(fn (CoreAssetBinding $binding) => $binding->digitalAsset)
            ->first(fn ($asset) => $asset instanceof DigitalAsset);

        $connection = (string) ($summary['auth_status_label'] ?? $detail['auth_status_label'] ?? 'Not authorized');
        $freshness = $latestSuccess instanceof CollectionResourceRun ? 'Collected' : 'Not collected';
        $latestCollection = $latestAttempt?->last_activity_at?->diffForHumans() ?? '—';
        $latestThrough = $latestSuccess?->last_activity_at?->toDateString() ?? '—';

        $activity = $runs->take(10)->map(function (CollectionResourceRun $run): array {
            $status = $run->status->value;

            return [
                'when' => $run->last_activity_at?->diffForHumans() ?? '—',
                'actor' => 'System',
                'kind' => 'Collection',
                'event' => 'Collection '.ucfirst(str_replace('_', ' ', $status)),
                'detail' => ($run->externalResource?->display_name ?? 'Resource')
                    .' · '.(int) $run->datasets_completed.'/'.(int) $run->datasets_total.' datasets',
            ];
        })->all();

        $data = [
            'id' => $this->connector,
            'name' => (string) $connector['label'],
            'type' => (string) $connector['visual_type'],
            'integration_label' => 'Google',
            'integration_route' => 'operator.integrations.google',
            'connection' => $connection,
            'freshness' => $freshness,
            'latest_collection' => $latestCollection,
            'resources_count' => (int) ($summary['discovered'] ?? $resourceModels->count()),
            'bound' => (int) ($summary['bound'] ?? $resourceModels->filter(fn (CoreExternalResource $resource) => $resource->bindings->isNotEmpty())->count()),
            'available' => (int) ($summary['available'] ?? $resourceModels->filter(fn (CoreExternalResource $resource) => $resource->bindings->isEmpty() && $resource->status === CoreExternalResource::STATUS_AVAILABLE)->count()),
            'ontology_note' => 'Reads the shared Google Integration, discovered External Resources, active Digital Asset bindings and Collection Engine history.',
            'existing_assets_for_brand' => [],
            'resources' => $resources->values()->all(),
            'data' => [
                'latest_through' => $latestThrough,
                'metrics' => [
                    ['label' => 'Discovered resources', 'value' => (string) $resourceModels->count(), 'state' => 'Persisted External Resources'],
                    ['label' => 'Bound resources', 'value' => (string) count($bindings), 'state' => 'Active Digital Asset bindings'],
                    ['label' => 'Collection runs', 'value' => (string) $runs->count(), 'state' => 'Persisted Collection Engine history'],
                ],
                'note' => $latestSuccess instanceof CollectionResourceRun
                    ? 'Central collection data exists. Open the bound Digital Asset for specialist metrics from the Data Pool.'
                    : 'No completed collection has been recorded for this connector yet.',
                'asset_cta' => $firstBoundAsset instanceof DigitalAsset ? $this->assetCta($firstBoundAsset) : null,
            ],
            'sync' => [
                'last_success' => $latestSuccess?->last_activity_at?->diffForHumans() ?? '—',
                'last_attempt' => $latestAttempt?->last_activity_at?->diffForHumans() ?? '—',
                'status' => $latestAttempt instanceof CollectionResourceRun
                    ? ucfirst(str_replace('_', ' ', $latestAttempt->status->value))
                    : 'Not collected',
                'timezone' => (string) ($resourceModels->first()?->metadata['timezone'] ?? 'Provider / property timezone'),
                'scope' => (string) $connector['label'].' resources discovered through the shared Google Integration',
                'failure' => $latestAttempt?->error_summary,
            ],
            'activity' => $activity,
        ];

        return view('livewire.demo.integrations.connector-page', [
            'data' => $data,
            'resources' => $resources->values()->all(),
            'bindings' => $bindings,
            'bindResource' => null,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /** @param array{id: string, name: string, type: string, integration: string, integration_label: string, integration_route: string} $meta */
    private function renderUnavailableConnector(array $meta): View
    {
        $data = [
            'id' => $meta['id'],
            'name' => $meta['name'],
            'type' => $meta['type'],
            'integration_label' => $meta['integration_label'],
            'integration_route' => $meta['integration_route'],
            'connection' => 'Not configured',
            'freshness' => 'Not collected',
            'latest_collection' => '—',
            'resources_count' => 0,
            'bound' => 0,
            'available' => 0,
            'ontology_note' => 'Configure the '.$meta['integration_label'].' integration before discovering resources. Asset existence is not a connection.',
            'existing_assets_for_brand' => [],
            'resources' => [],
            'data' => [
                'latest_through' => '—',
                'metrics' => [],
                'note' => 'No collection data — provider is not configured.',
                'asset_cta' => null,
            ],
            'sync' => [
                'last_success' => '—',
                'last_attempt' => '—',
                'status' => 'Not configured',
                'timezone' => '—',
                'scope' => 'Not configured',
                'failure' => null,
            ],
            'activity' => [],
        ];

        return view('livewire.demo.integrations.connector-page', [
            'data' => $data,
            'resources' => [],
            'bindings' => [],
            'bindResource' => null,
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /** @return array{route: string, params: array<string, int>, label: string} */
    private function assetCta(DigitalAsset $asset): array
    {
        $route = $this->assetRouteName($asset);

        return [
            'route' => $route,
            'params' => $route === 'operator.assets' ? [] : ['assetId' => $asset->id],
            'label' => 'Open '.$asset->name.' →',
        ];
    }

    private function assetUrl(DigitalAsset $asset): string
    {
        $route = $this->assetRouteName($asset);

        return $route === 'operator.assets'
            ? route($route)
            : route($route, ['assetId' => $asset->id]);
    }

    private function assetRouteName(DigitalAsset $asset): string
    {
        return match ((string) $asset->type) {
            'ga4', 'google_analytics', 'analytics' => 'operator.analytics',
            'gsc', 'search_console', 'google_search_console' => 'operator.search-console',
            'google_ads' => 'operator.google-ads.overview',
            'google_business_profile', 'gbp' => 'operator.gbp',
            default => 'operator.assets',
        };
    }
}
