<?php

namespace App\Livewire\Operator\Website;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Website Data Sources')]
class DataSourcesPage extends Component
{
    public int $assetId;

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(string $assetId): void
    {
        $asset = DigitalAsset::query()->findOrFail((int) $assetId);
        abort_unless($asset->type === 'website', 404);
        $this->assetId = $asset->id;
    }

    public function collectNow(AsyncOperationService $async): void
    {
        $result = $async->queueBoundCollect($this->asset(), auth()->user());
        $this->message = $result['message'];
        $this->messageTone = $result['ok'] ? 'success' : 'info';
    }

    public function refreshSeoIntelligence(AsyncOperationService $async): void
    {
        $result = $async->queueSeoIntelligenceRefresh($this->asset(), auth()->user());
        $this->message = $result['message'];
        $this->messageTone = $result['ok'] ? 'success' : 'info';
    }

    public function render(WebsiteOperatorWorkspace $workspace): View
    {
        $asset = $this->asset()->loadMissing('brand');
        $connections = $workspace->connectionCards($asset);

        $google = CoreIntegration::query()
            ->with(['authorizationCredential', 'providerCredential'])
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();
        $meta = CoreIntegration::query()
            ->with(['authorizationCredential', 'providerCredential'])
            ->where('provider', ProviderRegistry::META)
            ->first();
        $dataForSeo = CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::DATAFORSEO)
            ->first();

        $relatedAssets = DigitalAsset::query()
            ->where('brand_id', $asset->brand_id)
            ->whereKeyNot($asset->id)
            ->whereIn('type', [
                'google_ads',
                'meta_ads',
                'google_business_profile',
                'gbp',
                'ga4',
                'google_analytics',
                'analytics',
                'gsc',
                'search_console',
                'google_search_console',
            ])
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $availableResources = [
            'ga4' => $workspace->availableResourcesForCapability($asset, 'ga4')->count(),
            'search_console' => $workspace->availableResourcesForCapability($asset, 'search_console')->count(),
        ];

        $dataForSeoConfigured = $dataForSeo instanceof CoreIntegration
            && app(DataForSeoCredentialResolver::class)->isConfigured($dataForSeo);

        return view('livewire.operator.website.data-sources', [
            'asset' => $asset,
            'connections' => $connections,
            'availableResources' => $availableResources,
            'googleStatus' => $google instanceof CoreIntegration ? GoogleAuthStatus::for($google) : GoogleAuthStatus::NOT_CONFIGURED,
            'googleStatusLabel' => $google instanceof CoreIntegration ? GoogleAuthStatus::label(GoogleAuthStatus::for($google)) : GoogleAuthStatus::label(GoogleAuthStatus::NOT_CONFIGURED),
            'metaStatus' => $meta instanceof CoreIntegration ? MetaAuthStatus::for($meta) : MetaAuthStatus::NOT_CONFIGURED,
            'metaStatusLabel' => $meta instanceof CoreIntegration ? MetaAuthStatus::label(MetaAuthStatus::for($meta)) : MetaAuthStatus::label(MetaAuthStatus::NOT_CONFIGURED),
            'dataForSeoConfigured' => $dataForSeoConfigured,
            'dataForSeoConnectionStatus' => is_array($dataForSeo?->config) ? ($dataForSeo->config['connection_status'] ?? null) : null,
            'relatedAssets' => $relatedAssets,
        ]);
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->findOrFail($this->assetId);
    }
}
