<?php

namespace App\Livewire\Operator;

use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\Google\GoogleProviderResourceDiscovery;
use App\Services\Integrations\Meta\MetaProviderResourceDiscovery;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Data Sources')]
final class AssetDataSourcesPage extends Component
{
    public int $assetId;

    /** @var array<string, string> */
    public array $selectedResource = [];

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(string $assetId): void
    {
        abort_unless(ctype_digit($assetId), 404);
        $asset = DigitalAsset::query()->findOrFail((int) $assetId);
        abort_if(AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type) === [], 404);
        $this->assetId = $asset->id;
    }

    public function discover(string $provider): void
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, [ProviderRegistry::GOOGLE, ProviderRegistry::META], true), 404);

        $integration = CoreIntegration::query()->where('provider', $provider)->first();
        if (! $integration instanceof CoreIntegration || ! $integration->isActive()) {
            $this->messageTone = 'error';
            $this->message = __('operator_runtime.sources.integration_not_ready');

            return;
        }

        try {
            $resources = match ($provider) {
                ProviderRegistry::GOOGLE => app(GoogleProviderResourceDiscovery::class)->discover($integration),
                ProviderRegistry::META => app(MetaProviderResourceDiscovery::class)->discover($integration),
            };
            $this->messageTone = 'success';
            $this->message = __('operator_runtime.sources.discovery_completed', ['count' => count($resources)]);
        } catch (Throwable $e) {
            report($e);
            $this->messageTone = 'error';
            $this->message = __('operator_runtime.sources.discovery_failed', ['message' => $e->getMessage()]);
        }
    }

    public function bind(string $capability): void
    {
        $asset = $this->asset();
        $this->assertCapability($asset, $capability);

        $resourceId = (string) ($this->selectedResource[$capability] ?? '');
        if (! ctype_digit($resourceId)) {
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => __('operator_runtime.sources.select_resource'),
            ]);
        }

        $resource = CoreExternalResource::query()->with('integration')->find((int) $resourceId);
        if (! $resource instanceof CoreExternalResource
            || $resource->resource_type !== $capability
            || $resource->status !== CoreExternalResource::STATUS_AVAILABLE
            || ! $resource->integration instanceof CoreIntegration
            || ! $resource->integration->isActive()
            || ! AssetBindingCompatibility::isCompatible($asset, $resource)) {
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => __('operator_runtime.sources.resource_incompatible'),
            ]);
        }

        $duplicate = CoreAssetBinding::query()
            ->where('external_resource_id', $resource->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->where('digital_asset_id', '!=', $asset->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => __('operator_runtime.sources.resource_already_bound'),
            ]);
        }

        $binding = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', $capability)
            ->first();

        if ($binding instanceof CoreAssetBinding) {
            $binding->update([
                'external_resource_id' => $resource->id,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ]);
        } else {
            $asset->assetBindings()->create([
                'external_resource_id' => $resource->id,
                'capability' => $capability,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
                'configuration' => [],
            ]);
        }

        $this->selectedResource[$capability] = '';
        $this->messageTone = 'success';
        $this->message = __('operator_runtime.sources.bound', ['capability' => ProviderRegistry::capabilityLabel($capability)]);
    }

    public function disable(string $capability): void
    {
        $asset = $this->asset();
        $this->assertCapability($asset, $capability);

        CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', $capability)
            ->update(['status' => CoreAssetBinding::STATUS_DISABLED]);

        $this->messageTone = 'success';
        $this->message = __('operator_runtime.sources.disabled', ['capability' => ProviderRegistry::capabilityLabel($capability)]);
    }

    public function collectNow(AsyncOperationService $async): void
    {
        $result = $async->queueBoundCollect($this->asset(), auth()->user(), [
            'trigger' => 'operator.asset.sources.collect',
        ]);

        $this->messageTone = ($result['ok'] ?? false) ? 'success' : 'error';
        $this->message = (string) ($result['message'] ?? __('operator_runtime.sources.collect_failed'));
    }

    public function render(): View
    {
        $asset = $this->asset()->loadMissing('brand.customer');
        $capabilities = AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type);
        $bindings = CoreAssetBinding::query()
            ->with('externalResource.integration')
            ->where('digital_asset_id', $asset->id)
            ->whereIn('capability', $capabilities)
            ->get()
            ->keyBy('capability');

        $boundElsewhere = CoreAssetBinding::query()
            ->where('digital_asset_id', '!=', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->pluck('external_resource_id');

        $resources = [];
        foreach ($capabilities as $capability) {
            $currentResourceId = $bindings->get($capability)?->external_resource_id;
            $resources[$capability] = CoreExternalResource::query()
                ->with('integration')
                ->where('resource_type', $capability)
                ->where('status', CoreExternalResource::STATUS_AVAILABLE)
                ->whereHas('integration', fn ($q) => $q->where('status', CoreIntegration::STATUS_ACTIVE))
                ->where(function ($q) use ($boundElsewhere, $currentResourceId): void {
                    $q->whereNotIn('id', $boundElsewhere);
                    if ($currentResourceId !== null) {
                        $q->orWhereKey($currentResourceId);
                    }
                })
                ->orderBy('display_name')
                ->get();
        }

        $providers = collect($resources)
            ->flatten(1)
            ->map(fn (CoreExternalResource $resource) => $resource->integration?->provider)
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Keep discovery available even before resources exist.
        foreach ($capabilities as $capability) {
            $providers[] = $this->providerForCapability($capability);
        }
        $providers = array_values(array_unique(array_filter($providers)));

        return view('livewire.operator.asset-data-sources', [
            'asset' => $asset,
            'brand' => $asset->brand,
            'customer' => $asset->brand?->customer,
            'capabilities' => $capabilities,
            'bindings' => $bindings,
            'resources' => $resources,
            'providers' => $providers,
            'hasActiveBinding' => $bindings->contains(fn (CoreAssetBinding $binding) => $binding->status === CoreAssetBinding::STATUS_ACTIVE),
        ]);
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->findOrFail($this->assetId);
    }

    private function assertCapability(DigitalAsset $asset, string $capability): void
    {
        abort_unless(in_array($capability, AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type), true), 404);
    }

    private function providerForCapability(string $capability): ?string
    {
        return match ($capability) {
            'ga4', 'search_console', 'google_ads', 'google_business_profile' => ProviderRegistry::GOOGLE,
            'meta_ads' => ProviderRegistry::META,
            default => null,
        };
    }
}
