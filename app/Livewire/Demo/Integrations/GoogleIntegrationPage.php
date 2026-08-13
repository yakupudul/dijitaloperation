<?php

namespace App\Livewire\Demo\Integrations;

use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\Google\DiscoverGoogleResourcesService;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use App\Support\Integrations\Presentation\IntegrationWorkspaceCatalog;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Integration')]
class GoogleIntegrationPage extends Component
{
    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

    public bool $confirmDisconnect = false;

    public bool $showBindModal = false;

    public ?string $bindingResourceId = null;

    public string $bindMode = ResourceBindingPlan::MODE_CREATE_ASSET;

    public ?int $brandId = null;

    public ?int $digitalAssetId = null;

    public string $assetName = '';

    /** @var list<array{id: int, name: string, type: string, customer: string}> */
    public array $compatibleAssets = [];

    public function mount(): void
    {
        if (! in_array($this->tab, ['overview', 'connectors', 'configuration', 'resources', 'activity'], true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'connectors', 'configuration', 'resources', 'activity'], true)) {
            $this->tab = $tab;
        }
    }

    public function bindResource(string $resourceId): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $resource = CoreExternalResource::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->whereKey($resourceId)
            ->first();

        if (! $resource instanceof CoreExternalResource) {
            DemoState::flash('Select a discovered Google resource to bind.', 'info');

            return;
        }

        $this->bindingResourceId = (string) $resource->id;
        $this->bindMode = ResourceBindingPlan::MODE_CREATE_ASSET;
        $this->assetName = (string) $resource->display_name;
        $this->digitalAssetId = null;
        $this->compatibleAssets = [];

        $firstBrand = Brand::query()->orderBy('name')->first();
        $this->brandId = $firstBrand?->id;
        if ($this->brandId !== null) {
            $this->refreshCompatibleAssets();
        }

        $this->showBindModal = true;
        $this->tab = 'resources';
    }

    public function updatedBrandId(): void
    {
        $this->digitalAssetId = null;
        $this->refreshCompatibleAssets();
    }

    public function updatedBindMode(): void
    {
        if ($this->bindMode === ResourceBindingPlan::MODE_CREATE_ASSET) {
            $this->digitalAssetId = null;
        }
        $this->refreshCompatibleAssets();
    }

    public function cancelBind(): void
    {
        $this->showBindModal = false;
        $this->bindingResourceId = null;
        $this->compatibleAssets = [];
    }

    public function confirmBind(ConfirmGoogleResourceBindingService $binder): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $resource = CoreExternalResource::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->whereKey($this->bindingResourceId)
            ->first();

        $brand = Brand::query()->find($this->brandId);

        if (! $resource instanceof CoreExternalResource || ! $brand instanceof Brand) {
            DemoState::flash('Select a Brand and a discovered resource before confirming.', 'info');

            return;
        }

        $existing = null;
        if ($this->bindMode === ResourceBindingPlan::MODE_EXISTING_ASSET) {
            $existing = DigitalAsset::query()->find($this->digitalAssetId);
            if (! $existing instanceof DigitalAsset) {
                DemoState::flash('Select an existing compatible Digital Asset.', 'info');

                return;
            }
        }

        try {
            $result = $binder->confirm(new ResourceBindingPlan(
                resource: $resource,
                brand: $brand,
                mode: $this->bindMode,
                existingAsset: $existing,
                assetName: $this->assetName,
                confirmedBy: $user,
            ));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Binding could not be confirmed.';
            DemoState::flash((string) $message, 'info');

            return;
        }

        $this->showBindModal = false;
        $this->bindingResourceId = null;
        DemoState::flash($result['message'], 'info');
    }

    public function discoverResources(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        if (! $integration instanceof CoreIntegration) {
            DemoState::flash('No Google Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverGoogleResourcesService::class)->discover(
            $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration,
            $user,
        );

        DemoState::flash($result['message'], 'info');
    }

    public function bootstrapAndConnect(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::GOOGLE);
        $result = app(GoogleOAuthService::class)->beginAuthorization($integration, $user);

        if (isset($result['error'])) {
            DemoState::flash($result['error'], 'info');

            return;
        }

        $this->redirect($result['url']);
    }

    public function openDisconnect(): void
    {
        $this->confirmDisconnect = true;
    }

    public function cancelDisconnect(): void
    {
        $this->confirmDisconnect = false;
    }

    public function confirmDisconnectAction(): void
    {
        $this->confirmDisconnect = false;

        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        if (! $integration instanceof CoreIntegration) {
            DemoState::flash('No Google Integration is configured.', 'info');

            return;
        }

        $result = app(GoogleOAuthService::class)->revokeAuthorization(
            $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration,
        );

        DemoState::flash($result['message'], 'info');
    }

    public function render(GoogleIntegrationReadModel $readModel): View
    {
        $integration = $readModel->detail();
        $readModel->assertNoSecrets($integration);

        $brands = Brand::query()
            ->with('customer:id,name')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (Brand $brand): array => [
                'id' => $brand->id,
                'label' => trim(($brand->customer?->name ? $brand->customer->name.' · ' : '').$brand->name),
            ])
            ->all();

        $preferredType = null;
        if ($this->bindingResourceId !== null) {
            $resource = CoreExternalResource::query()->find($this->bindingResourceId);
            if ($resource instanceof CoreExternalResource) {
                $preferredType = ExternalResourceAssetCompatibility::preferredAssetType((string) $resource->resource_type);
            }
        }

        return view('livewire.demo.integrations.google-integration', [
            'integration' => $integration,
            'flash' => DemoState::pullFlash(),
            'brands' => $brands,
            'preferred_asset_type' => $preferredType,
        ]);
    }

    private function refreshCompatibleAssets(): void
    {
        $this->compatibleAssets = [];

        if ($this->bindingResourceId === null || $this->brandId === null) {
            return;
        }

        $resource = CoreExternalResource::query()->find($this->bindingResourceId);
        $brand = Brand::query()->find($this->brandId);
        if (! $resource instanceof CoreExternalResource || ! $brand instanceof Brand) {
            return;
        }

        $this->compatibleAssets = app(ConfirmGoogleResourceBindingService::class)
            ->compatibleExistingAssets($resource, $brand);
    }
}
