<?php

namespace App\Livewire\Demo\Integrations;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Integrations\Meta\DiscoverMetaResourcesService;
use App\Services\Integrations\Meta\MetaIntegrationReadModel;
use App\Services\Integrations\Meta\MetaOAuthService;
use App\Services\Integrations\Meta\SelectMetaDiscoveryContextService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\Meta\MetaResourceType;
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
#[Title('Meta Integration')]
class MetaIntegrationPage extends Component
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

    public bool $allowReplace = false;

    /** @var list<array{id: int, name: string, type: string, customer: string, has_active_binding?: bool}> */
    public array $compatibleAssets = [];

    public function mount(): void
    {
        if (! in_array($this->tab, ['overview', 'connectors', 'resources', 'activity'], true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['overview', 'connectors', 'resources', 'activity'], true)) {
            $this->tab = $tab;
        }
    }

    public function bootstrapAndConnect(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::META);
        $result = app(MetaOAuthService::class)->beginAuthorization($integration, $user);

        if (isset($result['error'])) {
            DemoState::flash($result['error'], 'info');

            return;
        }

        $this->redirect($result['url']);
    }

    public function discoverBusinesses(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverMetaResourcesService::class)->discoverBusinesses($integration, $user);
        DemoState::flash($result['message'], 'info');
        $this->tab = 'resources';
    }

    public function discoverAdAccounts(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverMetaResourcesService::class)->discoverAdAccounts($integration, $user);
        DemoState::flash($result['message'], 'info');
        $this->tab = 'resources';
    }

    public function refreshResources(): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(DiscoverMetaResourcesService::class)->refreshInventory($integration, $user);
        DemoState::flash($result['message'], 'info');
        $this->tab = 'resources';
    }

    public function toggleBusinessSelection(string $resourceId): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $selection = app(SelectMetaDiscoveryContextService::class);
        $activeIds = $selection->activeBusinessResourceIds($integration);

        if (in_array((int) $resourceId, $activeIds, true)) {
            $selection->deselect($integration, $resourceId, $user);
            DemoState::flash('Business removed from discovery context. Existing Ad Account Binding and inventory are preserved.', 'info');
        } else {
            $selection->select($integration, $resourceId, $user);
            DemoState::flash('Business selected as Ad Account discovery context — not a Digital Asset binding.', 'info');
        }

        $this->tab = 'resources';
    }

    public function bindResource(string $resourceId): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $resource = CoreExternalResource::query()
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->where('integration_id', $integration->id)
            ->whereKey($resourceId)
            ->first();

        if (! $resource instanceof CoreExternalResource) {
            DemoState::flash('Select a discovered Meta Ad Account to connect.', 'info');

            return;
        }

        $this->bindingResourceId = (string) $resource->id;
        $this->bindMode = ResourceBindingPlan::MODE_CREATE_ASSET;
        $this->assetName = (string) $resource->display_name;
        $this->digitalAssetId = null;
        $this->allowReplace = false;
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
        $this->allowReplace = false;
    }

    public function confirmBind(ConfirmMetaResourceBindingService $binder): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        $resource = CoreExternalResource::query()
            ->where('provider', ProviderRegistry::META)
            ->where('resource_type', MetaResourceType::META_AD_ACCOUNT)
            ->when($integration, fn ($q) => $q->where('integration_id', $integration->id))
            ->whereKey($this->bindingResourceId)
            ->first();

        $brand = Brand::query()->find($this->brandId);

        if (! $resource instanceof CoreExternalResource || ! $brand instanceof Brand || $integration === null) {
            DemoState::flash('Select a Brand and a discovered Ad Account before confirming.', 'info');

            return;
        }

        $existing = null;
        if ($this->bindMode === ResourceBindingPlan::MODE_EXISTING_ASSET) {
            $existing = DigitalAsset::query()->find($this->digitalAssetId);
            if (! $existing instanceof DigitalAsset) {
                DemoState::flash('Select an existing Meta Ads Digital Asset.', 'info');

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
                allowReplace: $this->allowReplace,
                expectedIntegrationId: (int) $integration->id,
            ));
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Binding could not be confirmed.';
            DemoState::flash((string) $message, 'info');

            return;
        }

        $this->showBindModal = false;
        $this->bindingResourceId = null;
        $this->allowReplace = false;
        DemoState::flash($result['message'], 'info');
    }

    public function unbindBinding(string $bindingId): void
    {
        $user = auth()->user();
        if ($user === null || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $binding = CoreAssetBinding::query()
            ->whereKey($bindingId)
            ->where('capability', 'meta_ads')
            ->whereHas('externalResource', fn ($q) => $q->where('integration_id', $integration->id))
            ->first();

        if (! $binding instanceof CoreAssetBinding) {
            DemoState::flash('Binding not found.', 'info');

            return;
        }

        try {
            $result = app(ConfirmMetaResourceBindingService::class)->unbind($binding, $user);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? 'Disconnect failed.';
            DemoState::flash((string) $message, 'info');

            return;
        }

        DemoState::flash($result['message'], 'info');
        $this->tab = 'resources';
    }

    public function askDisconnect(): void
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

        $integration = $this->metaIntegration();
        if ($integration === null) {
            DemoState::flash('No Meta Integration is configured.', 'info');

            return;
        }

        $result = app(MetaOAuthService::class)->disconnect($integration);
        DemoState::flash($result['message'], 'info');
    }

    public function render(MetaIntegrationReadModel $readModel): View
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

        $bindingPreview = null;
        if ($this->bindingResourceId !== null) {
            $resource = CoreExternalResource::query()->find($this->bindingResourceId);
            if ($resource instanceof CoreExternalResource) {
                $meta = is_array($resource->metadata) ? $resource->metadata : [];
                $bindingPreview = [
                    'name' => $resource->display_name,
                    'external_id' => $resource->external_id,
                    'business' => $meta['business_name'] ?? $resource->parent_external_id,
                    'currency' => $meta['currency'] ?? null,
                    'timezone' => $meta['timezone_name'] ?? null,
                    'access' => $this->accessLabel($meta),
                ];
            }
        }

        return view('livewire.demo.integrations.meta-integration', [
            'integration' => $integration,
            'flash' => DemoState::pullFlash(),
            'confirmDisconnect' => $this->confirmDisconnect,
            'showBindModal' => $this->showBindModal,
            'bindMode' => $this->bindMode,
            'brandId' => $this->brandId,
            'digitalAssetId' => $this->digitalAssetId,
            'assetName' => $this->assetName,
            'allowReplace' => $this->allowReplace,
            'compatibleAssets' => $this->compatibleAssets,
            'brands' => $brands,
            'bindingPreview' => $bindingPreview,
        ]);
    }

    private function metaIntegration(): ?CoreIntegration
    {
        return CoreIntegration::query()
            ->with(['providerCredential'])
            ->where('provider', ProviderRegistry::META)
            ->first();
    }

    private function refreshCompatibleAssets(): void
    {
        $resource = CoreExternalResource::query()->find($this->bindingResourceId);
        $brand = Brand::query()->find($this->brandId);
        if (! $resource instanceof CoreExternalResource || ! $brand instanceof Brand) {
            $this->compatibleAssets = [];

            return;
        }

        $this->compatibleAssets = app(ConfirmMetaResourceBindingService::class)
            ->compatibleExistingAssets($resource, $brand);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function accessLabel(array $meta): ?string
    {
        $contexts = is_array($meta['access_contexts'] ?? null) ? $meta['access_contexts'] : [];
        $edges = collect($contexts)
            ->pluck('edge')
            ->filter(fn ($e) => is_string($e) && $e !== '')
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
