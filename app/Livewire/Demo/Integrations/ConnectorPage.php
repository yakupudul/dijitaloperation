<?php

namespace App\Livewire\Demo\Integrations;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\Ga4\Ga4CentralCollectionService;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Support\Demo\DemoState;
use App\Support\Integrations\Google\GoogleConnectorRegistry;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

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

    /** @var list<string> */
    public array $selectedResourceIds = [];

    public ?string $bindResourceId = null;
    public string $bindMode = 'existing';
    public string $selectedAssetId = '';
    public string $newAssetName = '';
    public bool $confirmBind = false;

    /** @var list<string> */
    private const TABS = ['overview', 'resources', 'bindings', 'data', 'sync', 'activity'];

    /** @var array<string, array{id: string, name: string, type: string, integration: string, integration_label: string, integration_route: string}> */
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

    public function collectSelectedGa4(Ga4CentralCollectionService $collector): void
    {
        if ($this->connector !== 'ga4') {
            return;
        }

        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->orderBy('id')
            ->first();
        if (! $integration instanceof CoreIntegration) {
            DemoState::flash('Google entegrasyonu bulunamadı.', 'info');

            return;
        }

        try {
            $run = $collector->startInitial($integration, $this->selectedResourceIds, $user);
        } catch (Throwable $e) {
            DemoState::flash('GA4 veri toplama başlatılamadı: '.$e->getMessage(), 'info');

            return;
        }

        $count = count(array_unique(array_map('intval', $this->selectedResourceIds)));
        $this->selectedResourceIds = [];
        $this->tab = 'activity';
        DemoState::flash("{$count} GA4 property için 486 günlük merkezi veri toplama başlatıldı. Run #{$run->id}.", 'info');
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
            $this->tab = 'activity';

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
            ->orderByRaw("COALESCE(metadata->>'account_display_name', '')")
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
            $meta = is_array($resource->metadata) ? $resource->metadata : [];

            return [
                'id' => (string) $resource->id,
                'name' => (string) $resource->display_name,
                'external_id' => (string) $resource->external_id,
                'account_name' => $meta['account_display_name'] ?? $meta['account'] ?? null,
                'property_id' => $meta['property_id'] ?? preg_replace('/^properties\//', '', (string) $resource->external_id),
                'state' => $state,
                'state_label' => match ($state) {
                    'bound' => 'Bound',
                    'available' => 'Available',
                    default => 'Unavailable',
                },
                'stream' => $meta['stream_id'] ?? null,
                'stream_label' => 'Stream',
                'property_type' => $meta['property_type'] ?? null,
                'address' => $meta['address'] ?? null,
                'currency' => $meta['currency'] ?? null,
                'timezone' => $meta['timezone'] ?? null,
                'data_state' => $status !== null ? ucfirst(str_replace('_', ' ', $status)) : 'Not collected',
                'last_collection' => $run?->last_activity_at?->diffForHumans() ?? '—',
                'asset_name' => $asset?->name,
                'asset_url' => $asset instanceof DigitalAsset ? $this->assetUrl($asset) : null,
                'selectable_for_collection' => $resource->status === CoreExternalResource::STATUS_AVAILABLE,
            ];
        });

        if (trim($this->q) !== '') {
            $needle = mb_strtolower(trim($this->q));
            $resources = $resources->filter(function (array $resource) use ($needle): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $resource['name'] ?? null,
                    $resource['external_id'] ?? null,
                    $resource['property_id'] ?? null,
                    $resource['account_name'] ?? null,
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

        $activity = $runs->take(20)->map(function (CollectionResourceRun $run): array {
            $status = $run->status->value;
            $scope = $run->digital_asset_id === null ? 'Central Data Pool' : 'Digital Asset';

            return [
                'when' => $run->last_activity_at?->diffForHumans() ?? '—',
                'actor' => 'System',
                'kind' => $scope,
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
            'ontology_note' => $this->connector === 'ga4'
                ? 'GA4 properties can be collected into the central Data Pool before Customer, Brand or Digital Asset binding.'
                : 'Reads the shared Google Integration, discovered resources, bindings and collection history.',
            'existing_assets_for_brand' => [],
            'resources' => $resources->values()->all(),
            'data' => [
                'latest_through' => $latestThrough,
                'metrics' => [
                    ['label' => 'Discovered resources', 'value' => (string) $resourceModels->count(), 'state' => 'Persisted External Resources'],
                    ['label' => 'Central/bound collection runs', 'value' => (string) $runs->count(), 'state' => 'Collection Engine history'],
                    ['label' => 'History target', 'value' => $this->connector === 'ga4' ? '486 days' : 'Provider policy', 'state' => 'Initial central collection'],
                ],
                'note' => $latestSuccess instanceof CollectionResourceRun
                    ? 'Central provider data exists and can later be attached to a Digital Asset without recollecting the same history.'
                    : 'No completed collection has been recorded for this connector yet.',
                'asset_cta' => $firstBoundAsset instanceof DigitalAsset ? $this->assetCta($firstBoundAsset) : null,
            ],
            'sync' => [
                'last_success' => $latestSuccess?->last_activity_at?->diffForHumans() ?? '—',
                'last_attempt' => $latestAttempt?->last_activity_at?->diffForHumans() ?? '—',
                'status' => $latestAttempt instanceof CollectionResourceRun
                    ? ucfirst(str_replace('_', ' ', $latestAttempt->status->value))
                    : 'Not collected',
                'timezone' => (string) ($resourceModels->first()?->metadata['timezone'] ?? 'Per GA4 property timezone'),
                'scope' => $this->connector === 'ga4'
                    ? 'Provider resource → Central Data Pool → later Digital Asset binding'
                    : (string) $connector['label'].' resources discovered through the shared Google Integration',
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
            'ontology_note' => 'Configure the '.$meta['integration_label'].' integration before discovering resources.',
            'existing_assets_for_brand' => [],
            'resources' => [],
            'data' => ['latest_through' => '—', 'metrics' => [], 'note' => 'No collection data.', 'asset_cta' => null],
            'sync' => ['last_success' => '—', 'last_attempt' => '—', 'status' => 'Not configured', 'timezone' => '—', 'scope' => 'Not configured', 'failure' => null],
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

        return $route === 'operator.assets' ? route($route) : route($route, ['assetId' => $asset->id]);
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
