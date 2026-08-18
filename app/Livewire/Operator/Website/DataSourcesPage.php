<?php

namespace App\Livewire\Operator\Website;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\DataForSeo\DataForSeoCredentialResolver;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\Meta\MetaAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Website Data Sources')]
class DataSourcesPage extends Component
{
    public int $assetId;

    public string $ga4ResourceId = '';

    public string $searchConsoleResourceId = '';

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

    public function bindGa4(): void
    {
        $this->persistBinding('ga4', $this->ga4ResourceId);
        $this->ga4ResourceId = '';
    }

    public function bindSearchConsole(): void
    {
        $this->persistBinding('search_console', $this->searchConsoleResourceId);
        $this->searchConsoleResourceId = '';
    }

    public function disableBinding(string $capability): void
    {
        abort_unless(in_array($capability, ['ga4', 'search_console'], true), 404);

        $binding = $this->binding($capability);
        if ($binding !== null) {
            $binding->update(['status' => CoreAssetBinding::STATUS_DISABLED]);
        }

        $this->message = ProviderRegistry::capabilityLabel($capability).' Website bağlantısı devre dışı bırakıldı.';
        $this->messageTone = 'success';
    }

    public function render(WebsiteOperatorWorkspace $workspace): View
    {
        $asset = $this->asset()->loadMissing('brand');
        $connections = $workspace->connectionCards($asset);

        $ga4Binding = $this->binding('ga4');
        $gscBinding = $this->binding('search_console');

        $ga4Resources = $workspace
            ->availableResourcesForCapability($asset, 'ga4', $ga4Binding?->id)
            ->mapWithKeys(fn (CoreExternalResource $resource): array => [(string) $resource->id => $this->resourceLabel($resource)])
            ->all();
        $gscResources = $workspace
            ->availableResourcesForCapability($asset, 'search_console', $gscBinding?->id)
            ->mapWithKeys(fn (CoreExternalResource $resource): array => [(string) $resource->id => $this->resourceLabel($resource)])
            ->all();

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

        $dataForSeoConfigured = $dataForSeo instanceof CoreIntegration
            && app(DataForSeoCredentialResolver::class)->isConfigured($dataForSeo);

        return view('livewire.operator.website.data-sources', [
            'asset' => $asset,
            'connections' => $connections,
            'ga4Resources' => $ga4Resources,
            'gscResources' => $gscResources,
            'ga4Binding' => $ga4Binding,
            'gscBinding' => $gscBinding,
            'googleStatus' => $google instanceof CoreIntegration ? GoogleAuthStatus::for($google) : GoogleAuthStatus::NOT_CONFIGURED,
            'googleStatusLabel' => $google instanceof CoreIntegration ? GoogleAuthStatus::label(GoogleAuthStatus::for($google)) : GoogleAuthStatus::label(GoogleAuthStatus::NOT_CONFIGURED),
            'metaStatus' => $meta instanceof CoreIntegration ? MetaAuthStatus::for($meta) : MetaAuthStatus::NOT_CONFIGURED,
            'metaStatusLabel' => $meta instanceof CoreIntegration ? MetaAuthStatus::label(MetaAuthStatus::for($meta)) : MetaAuthStatus::label(MetaAuthStatus::NOT_CONFIGURED),
            'dataForSeoConfigured' => $dataForSeoConfigured,
            'dataForSeoConnectionStatus' => is_array($dataForSeo?->config) ? ($dataForSeo->config['connection_status'] ?? null) : null,
            'relatedAssets' => $relatedAssets,
        ]);
    }

    private function persistBinding(string $capability, string $resourceId): void
    {
        if (! ctype_digit($resourceId)) {
            throw ValidationException::withMessages([
                $capability === 'ga4' ? 'ga4ResourceId' : 'searchConsoleResourceId' => 'Keşfedilmiş bir kaynak seçin.',
            ]);
        }

        $asset = $this->asset();
        $resource = CoreExternalResource::query()->with('integration')->find((int) $resourceId);

        if (! $resource instanceof CoreExternalResource
            || $resource->resource_type !== $capability
            || $resource->status !== CoreExternalResource::STATUS_AVAILABLE
            || ! AssetBindingCompatibility::isCompatible($asset, $resource)) {
            throw ValidationException::withMessages([
                $capability === 'ga4' ? 'ga4ResourceId' : 'searchConsoleResourceId' => 'Bu kaynak Website ile uyumlu değil.',
            ]);
        }

        $duplicate = CoreAssetBinding::query()
            ->where('external_resource_id', $resource->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->where('digital_asset_id', '!=', $asset->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                $capability === 'ga4' ? 'ga4ResourceId' : 'searchConsoleResourceId' => 'Bu kaynak başka bir Digital Asset’e aktif olarak bağlı.',
            ]);
        }

        $binding = $this->binding($capability);
        if ($binding === null) {
            $asset->assetBindings()->create([
                'external_resource_id' => $resource->id,
                'capability' => $capability,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
                'configuration' => [],
            ]);
        } else {
            $binding->update([
                'external_resource_id' => $resource->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ]);
        }

        $this->message = ProviderRegistry::capabilityLabel($capability).' bu Website’e bağlandı.';
        $this->messageTone = 'success';
    }

    private function binding(string $capability): ?CoreAssetBinding
    {
        return CoreAssetBinding::query()
            ->with('externalResource')
            ->where('digital_asset_id', $this->assetId)
            ->where('capability', $capability)
            ->first();
    }

    private function resourceLabel(CoreExternalResource $resource): string
    {
        $name = $resource->display_name ?: $resource->external_id;

        return $name.' · '.$resource->external_id;
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()
            ->whereKey($this->assetId)
            ->where('type', 'website')
            ->firstOrFail();
    }
}
