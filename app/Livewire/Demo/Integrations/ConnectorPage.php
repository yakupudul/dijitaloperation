<?php

namespace App\Livewire\Demo\Integrations;

use App\Support\Demo\ConnectorWorkspaceFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
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

    /**
     * @var list<string>
     */
    private const TABS = ['overview', 'resources', 'bindings', 'data', 'sync', 'activity'];

    public function mount(string $connector): void
    {
        $this->connector = $connector;

        if (ConnectorWorkspaceFixtures::connector($connector) === null) {
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
        $data = ConnectorWorkspaceFixtures::connector($this->connector);
        $resource = collect($data['resources'] ?? [])->firstWhere('id', $resourceId);
        if ($resource === null || ($resource['state'] ?? '') !== 'available') {
            DemoState::flash('Only Available resources can be bound. Conflict/Unavailable require review.', 'info');

            return;
        }

        $this->bindResourceId = $resourceId;
        $this->bindMode = 'existing';
        $this->confirmBind = false;
        $assets = $data['existing_assets_for_brand'] ?? [];
        $this->selectedAssetId = (string) (($assets[0]['id'] ?? '') ?: '');
        $this->newAssetName = (string) ($resource['name'] ?? 'New Digital Asset');
        $this->tab = 'resources';
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
        if ($this->bindResourceId === null) {
            return;
        }

        if ($this->bindMode === 'existing' && $this->selectedAssetId === '') {
            DemoState::flash('Select an existing Digital Asset or create one.', 'info');

            return;
        }

        if ($this->bindMode === 'create' && trim($this->newAssetName) === '') {
            DemoState::flash('Digital Asset name is required.', 'info');

            return;
        }

        $this->confirmBind = true;
    }

    public function confirmBinding(): void
    {
        if ($this->bindResourceId === null || ! $this->confirmBind) {
            return;
        }

        $data = ConnectorWorkspaceFixtures::connector($this->connector);
        $resource = collect($data['resources'] ?? [])->firstWhere('id', $this->bindResourceId);
        if ($resource === null) {
            $this->closeBind();

            return;
        }

        // Cross-Brand safety: session-bound assets must stay on Atlas Demo Brand scope.
        $targetBrandId = DemoCatalog::BRAND_ID;
        if ($this->bindMode === 'existing') {
            $asset = collect($data['existing_assets_for_brand'] ?? [])->firstWhere('id', $this->selectedAssetId);
            if ($asset === null) {
                DemoState::flash('Selected Digital Asset is outside Brand scope — binding rejected.', 'info');
                $this->closeBind();

                return;
            }
            if (($asset['brand_id'] ?? '') !== $targetBrandId) {
                DemoState::flash('Cross-Brand binding rejected. Resource must stay within Customer/Brand scope.', 'info');
                $this->closeBind();

                return;
            }
            $assetId = $this->selectedAssetId;
            $assetName = (string) $asset['name'];
        } else {
            // Prevent duplicate asset names for same type+brand in session.
            $existing = collect(DemoState::all()['demo_assets'] ?? [])
                ->first(function (array $a) use ($data): bool {
                    return ($a['brand_id'] ?? '') === DemoCatalog::BRAND_ID
                        && ($a['type'] ?? '') === ($data['type'] ?? '')
                        && mb_strtolower((string) ($a['name'] ?? '')) === mb_strtolower(trim($this->newAssetName));
                });
            if ($existing !== null) {
                DemoState::flash('Matching Digital Asset already exists — bind to existing instead of creating a duplicate.', 'info');
                $this->bindMode = 'existing';
                $this->selectedAssetId = (string) $existing['id'];
                $this->confirmBind = false;

                return;
            }

            $assetId = 'da-bind-'.substr(md5($this->bindResourceId.microtime(true)), 0, 8);
            $assetName = trim($this->newAssetName);
            DemoState::addDemoAsset([
                'id' => $assetId,
                'brand_id' => DemoCatalog::BRAND_ID,
                'name' => $assetName,
                'type' => $data['type'],
                'type_label' => $data['name'],
                'status' => 'active',
                'role' => 'channel',
                'role_label' => 'Channel',
                'health' => 'healthy',
                'health_label' => 'Healthy',
                'provenance' => 'Bound via Connector (Demo)',
                'open_findings' => 0,
                'last_update' => 'Just now',
                'route' => $resource['asset_route'] ?? 'demo.assets',
                'connection' => 'connected',
            ]);
        }

        DemoState::bindConnectorResource($this->connector, $this->bindResourceId, $assetId, $assetName, $targetBrandId);
        DemoState::flash('Binding confirmed (Demo Mode — human-approved; no provider write).');
        $this->closeBind();
        $this->tab = 'bindings';
    }

    public function unbindResource(string $resourceId): void
    {
        DemoState::unbindConnectorResource($this->connector, $resourceId);
        DemoState::flash('Resource unbound in Demo Mode (Evidence history retained conceptually).');
    }

    public function refreshCollection(): void
    {
        DemoState::flash('Manual refresh queued (Demo Mode — no live provider collector expansion).', 'info');
    }

    public function render(): View
    {
        $data = ConnectorWorkspaceFixtures::connector($this->connector);
        $sessionBindings = DemoState::connectorBindings($this->connector);

        $resources = collect($data['resources'] ?? [])->map(function (array $resource) use ($sessionBindings): array {
            $session = $sessionBindings[$resource['id']] ?? null;
            if (is_array($session)) {
                if (($session['action'] ?? '') === 'bound') {
                    $resource['state'] = 'bound';
                    $resource['state_label'] = 'Bound';
                    $resource['asset_id'] = $session['asset_id'] ?? $resource['asset_id'];
                    $resource['asset_name'] = $session['asset_name'] ?? $resource['asset_name'];
                    $resource['brand_id'] = $session['brand_id'] ?? DemoCatalog::BRAND_ID;
                    $resource['brand_name'] = 'Atlas Dental Ankara';
                }
                if (($session['action'] ?? '') === 'unbound') {
                    $resource['state'] = 'available';
                    $resource['state_label'] = 'Available';
                    $resource['asset_id'] = null;
                    $resource['asset_name'] = null;
                }
            }

            return $resource;
        });

        if ($this->q !== '') {
            $q = mb_strtolower($this->q);
            $resources = $resources->filter(function (array $r) use ($q): bool {
                $hay = mb_strtolower(($r['name'] ?? '').' '.($r['external_id'] ?? '').' '.($r['stream'] ?? '').' '.($r['address'] ?? ''));

                return str_contains($hay, $q);
            });
        }

        if ($this->state !== 'all') {
            $resources = $resources->where('state', $this->state);
        }

        if ($this->brand === 'atlas') {
            $resources = $resources->where('brand_id', DemoCatalog::BRAND_ID);
        } elseif ($this->brand === 'unmapped') {
            $resources = $resources->filter(fn (array $r): bool => empty($r['brand_id']));
        }

        $bindResource = $resources->firstWhere('id', $this->bindResourceId);

        $bindings = $resources->where('state', 'bound')->values()->all();

        return view('livewire.demo.integrations.connector-page', [
            'data' => $data,
            'resources' => $resources->values()->all(),
            'bindings' => $bindings,
            'bindResource' => $bindResource,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
