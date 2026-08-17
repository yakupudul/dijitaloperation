<?php

namespace App\Livewire\Demo\Integrations;

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

    /**
     * @var array<string, array{id: string, name: string, type: string, integration: string, integration_label: string, integration_route: string}>
     */
    private const CONNECTORS = [
        'google-ads' => [
            'id' => 'google-ads',
            'name' => 'Google Ads',
            'type' => 'google_ads',
            'integration' => 'google',
            'integration_label' => 'Google',
            'integration_route' => 'demo.integrations.google',
        ],
        'ga4' => [
            'id' => 'ga4',
            'name' => 'Google Analytics',
            'type' => 'ga4',
            'integration' => 'google',
            'integration_label' => 'Google',
            'integration_route' => 'demo.integrations.google',
        ],
        'gsc' => [
            'id' => 'gsc',
            'name' => 'Search Console',
            'type' => 'gsc',
            'integration' => 'google',
            'integration_label' => 'Google',
            'integration_route' => 'demo.integrations.google',
        ],
        'gbp' => [
            'id' => 'gbp',
            'name' => 'Google Business Profile',
            'type' => 'google_business_profile',
            'integration' => 'google',
            'integration_label' => 'Google',
            'integration_route' => 'demo.integrations.google',
        ],
        'meta-ads' => [
            'id' => 'meta-ads',
            'name' => 'Meta Ads',
            'type' => 'meta_ads',
            'integration' => 'meta',
            'integration_label' => 'Meta',
            'integration_route' => 'demo.integrations.meta',
        ],
    ];

    public function mount(string $connector): void
    {
        $this->connector = $connector;

        if (! isset(self::CONNECTORS[$connector])) {
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
        DemoState::flash('Configure integration first. No provider resources are available until credentials are configured.', 'info');
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
        DemoState::flash('Configure integration first.', 'info');
    }

    public function confirmBinding(): void
    {
        DemoState::flash('Configure integration first. Binding is unavailable until the provider is configured.', 'info');
        $this->closeBind();
    }

    public function unbindResource(string $resourceId): void
    {
        DemoState::flash('No bindings exist — the provider is not configured.', 'info');
    }

    public function refreshCollection(): void
    {
        DemoState::flash('Configure integration first. Collection cannot run without credentials.', 'info');
    }

    public function render(): View
    {
        $meta = self::CONNECTORS[$this->connector];

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
}
