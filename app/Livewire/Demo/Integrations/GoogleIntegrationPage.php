<?php

namespace App\Livewire\Demo\Integrations;

use App\Livewire\Demo\Integrations\Concerns\ManagesOperatorCredentials;
use App\Models\Brand;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\Collection\Google\GoogleIncrementalCollectionOrchestrator;
use App\Services\Collection\Google\GoogleInitialBackfillOrchestrator;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\Google\DiscoverGoogleResourcesService;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Google\GoogleIntegrationReadModel;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleProviderCredentialService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use App\Support\Integrations\Google\GoogleAuthStatus;
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
    use ManagesOperatorCredentials;

    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

    public bool $confirmDisconnect = false;

    public bool $showBindModal = false;

    public ?string $bindingResourceId = null;

    public string $bindMode = ResourceBindingPlan::MODE_CREATE_ASSET;

    public ?int $brandId = null;

    public ?int $digitalAssetId = null;

    public string $assetName = '';

    public string $googleClientId = '';

    public string $googleClientSecret = '';

    public string $googleDeveloperToken = '';

    public bool $clearGoogleClientSecret = false;

    public bool $clearGoogleDeveloperToken = false;

    public bool $confirmRemoveGoogleCredentials = false;

    /** @var list<array{id: int, name: string, type: string, customer: string}> */
    public array $compatibleAssets = [];

    public function mount(): void
    {
        if (! in_array($this->tab, ['overview', 'connectors', 'configuration', 'resources', 'activity'], true)) {
            $this->tab = 'overview';
        }

        $this->hydrateGoogleForm();
    }

    public function dehydrate(): void
    {
        $this->googleClientSecret = '';
        $this->googleDeveloperToken = '';
        $this->clearGoogleClientSecret = false;
        $this->clearGoogleDeveloperToken = false;
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
        $fresh = $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration;

        if (! app(GoogleCredentialResolver::class)->isAppConfigured($fresh)) {
            $this->tab = 'configuration';
            DemoState::flash('Configure Google application first.', 'info');

            return;
        }

        $result = app(GoogleOAuthService::class)->beginAuthorization($fresh, $user);

        if (isset($result['error'])) {
            DemoState::flash($result['error'], 'info');

            return;
        }

        $this->redirect($result['url']);
    }

    public function collectData(
        GoogleInitialBackfillOrchestrator $orchestrator,
        GoogleIncrementalCollectionOrchestrator $incremental,
    ): void {
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

        $fresh = $integration->fresh(['authorizationCredential', 'providerCredential']) ?? $integration;
        $preflight = $orchestrator->preflight($fresh);

        if ($preflight->outcome === 'already_satisfied') {
            $incr = $incremental->start($fresh, $user);
            DemoState::flash($incr->message, 'info');
            if ($incr->collectionRun !== null) {
                $this->tab = 'activity';
                $this->dispatch('collection-run-selected', uuid: $incr->collectionRun->uuid);
            }

            return;
        }

        $result = $orchestrator->start($fresh, $user);

        DemoState::flash($result->message, 'info');

        if ($result->collectionRun !== null) {
            $this->tab = 'activity';
            $this->dispatch('collection-run-selected', uuid: $result->collectionRun->uuid);
        }
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

    public function saveGoogleConfiguration(GoogleProviderCredentialService $service): void
    {
        $user = $this->credentialManager();
        $integration = app(IntegrationWorkspaceCatalog::class)->bootstrap(ProviderRegistry::GOOGLE);

        try {
            $service->save($integration, [
                'client_id' => $this->googleClientId,
                'client_secret' => $this->googleClientSecret,
                'developer_token' => $this->googleDeveloperToken,
                'clear_client_secret' => $this->clearGoogleClientSecret,
                'clear_developer_token' => $this->clearGoogleDeveloperToken,
            ], $user);
        } catch (ValidationException $exception) {
            $this->mapCredentialValidationErrors($exception, [
                'client_id' => 'googleClientId',
                'client_secret' => 'googleClientSecret',
                'developer_token' => 'googleDeveloperToken',
            ]);

            return;
        }

        $this->googleClientSecret = '';
        $this->googleDeveloperToken = '';
        $this->clearGoogleClientSecret = false;
        $this->clearGoogleDeveloperToken = false;
        $this->hydrateGoogleForm($integration->fresh(['providerCredential']));
        $this->tab = 'configuration';
        DemoState::flash('Google application credentials saved.', 'info');
    }

    public function testGoogleConfiguration(): void
    {
        $this->credentialManager();
        $integration = CoreIntegration::query()
            ->with(['providerCredential', 'authorizationCredential'])
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        if (! $integration instanceof CoreIntegration
            || ! app(GoogleCredentialResolver::class)->isAppConfigured($integration)) {
            DemoState::flash('Configure Google application first.', 'info');

            return;
        }

        $authStatus = GoogleAuthStatus::for($integration);
        if ($authStatus !== GoogleAuthStatus::CONNECTED) {
            DemoState::flash('Application credentials are configured. Authorization is still required.', 'info');

            return;
        }

        $result = app(GoogleOAuthService::class)->testConnection($integration);
        DemoState::flash($result['message'], 'info');
    }

    public function askRemoveGoogleCredentials(): void
    {
        $this->credentialManager();
        $this->confirmRemoveGoogleCredentials = true;
    }

    public function cancelRemoveGoogleCredentials(): void
    {
        $this->confirmRemoveGoogleCredentials = false;
    }

    public function removeGoogleConfiguration(GoogleProviderCredentialService $service): void
    {
        $user = $this->credentialManager();
        $this->confirmRemoveGoogleCredentials = false;

        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        if (! $integration instanceof CoreIntegration) {
            DemoState::flash('No Google application credentials are stored.', 'info');

            return;
        }

        $service->remove($integration, $user);
        $this->googleClientId = '';
        $this->hydrateGoogleForm();
        DemoState::flash('Google application credentials removed. Authorization and historical data were not deleted.', 'info');
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

        $preflight = null;
        if (($integration['actions']['collect'] ?? false) === true && ($integration['integration_id'] ?? null) !== null) {
            $core = CoreIntegration::query()->find($integration['integration_id']);
            if ($core instanceof CoreIntegration) {
                $preflight = app(GoogleInitialBackfillOrchestrator::class)
                    ->preflight($core)
                    ->toArray();
            }
        }

        return view('livewire.demo.integrations.google-integration', [
            'integration' => $integration,
            'flash' => DemoState::pullFlash(),
            'brands' => $brands,
            'preferred_asset_type' => $preferredType,
            'preflight' => $preflight,
            'canManageCredentials' => $this->canManageCredentials(),
            'googleClientSecretConfigured' => $this->googleSecretConfigured(),
            'googleDeveloperTokenConfigured' => $this->googleDeveloperTokenConfigured(),
        ]);
    }

    private function hydrateGoogleForm(?CoreIntegration $integration = null): void
    {
        $integration ??= CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        $this->googleClientSecret = '';
        $this->googleDeveloperToken = '';

        if (! $integration instanceof CoreIntegration) {
            $this->googleClientId = '';

            return;
        }

        $this->googleClientId = app(GoogleCredentialResolver::class)->databaseClientId($integration) ?? '';
    }

    /**
     * @param  array<string, string>  $map
     */
    private function mapCredentialValidationErrors(ValidationException $exception, array $map): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $target = $map[$field] ?? $field;
            $this->addError($target, (string) ($messages[0] ?? 'Invalid value.'));
        }
    }

    private function googleSecretConfigured(): bool
    {
        $integration = CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        return $integration instanceof CoreIntegration
            && app(GoogleCredentialResolver::class)->hasDatabaseClientSecret($integration);
    }

    private function googleDeveloperTokenConfigured(): bool
    {
        $integration = CoreIntegration::query()
            ->with('providerCredential')
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        return $integration instanceof CoreIntegration
            && app(GoogleCredentialResolver::class)->hasDatabaseDeveloperToken($integration);
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
