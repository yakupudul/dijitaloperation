<?php

namespace App\Livewire\Operator;

use App\Jobs\DiscoverProviderResourcesJob;
use App\Livewire\Concerns\ConfirmsOwnershipTransfer;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Models\ResourceAutomation;
use App\Models\SeoPlan;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Ownership\OwnershipTransferService;
use App\Services\PageSpeedConnectionProbeService;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Support\Integrations\AssetBindingCompatibility;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Veri kaynakları')]
final class AssetDataSourcesPage extends Component
{
    use ConfirmsOwnershipTransfer;

    public int $assetId;

    /** @var array<string, string> */
    public array $selectedResource = [];

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(string $assetId): void
    {
        abort_unless(ctype_digit($assetId), 404);
        $asset = DigitalAsset::query()->findOrFail((int) $assetId);
        $capabilities = AssetBindingCompatibility::capabilitiesForAssetType((string) $asset->type);
        abort_if((string) $asset->type !== 'website' && $capabilities === [], 404);
        $this->assetId = $asset->id;
    }

    public function discover(string $provider): void
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, [ProviderRegistry::GOOGLE, ProviderRegistry::META], true), 404);

        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(Roles::ADMIN), 403);

        $integration = CoreIntegration::query()->where('provider', $provider)->first();
        if (! $integration instanceof CoreIntegration || ! $integration->isActive()) {
            $this->messageTone = 'error';
            $this->message = __('operator_runtime.sources.integration_not_ready');

            return;
        }

        try {
            // Faz 13: discovery runs in the background; with a sync queue the result is already here.
            Cache::put(DiscoverProviderResourcesJob::cacheKey($provider), ['state' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());
            DiscoverProviderResourcesJob::dispatch($provider, (int) $actor->id);
            $state = Cache::get(DiscoverProviderResourcesJob::cacheKey($provider));
            if (($state['state'] ?? '') !== 'done') {
                $this->messageTone = 'info';
                $this->message = 'Hesap keşfi arka planda başladı; birkaç dakika sonra bu sayfayı yenileyin.';

                return;
            }
            $result = (array) $state['result'];

            if (! ($result['ok'] ?? false)) {
                $this->messageTone = 'error';
                $this->message = __('operator_runtime.sources.discovery_failed', [
                    'message' => (string) ($result['message'] ?? ''),
                ]);

                return;
            }

            $count = match ($provider) {
                ProviderRegistry::GOOGLE => (int) collect($result['results'] ?? [])->sum(
                    fn (array $row): int => (int) ($row['count'] ?? 0),
                ),
                ProviderRegistry::META => (int) ($result['businesses']['count'] ?? 0)
                    + (int) ($result['ad_accounts']['count'] ?? 0),
            };

            $this->messageTone = 'success';
            $this->message = __('operator_runtime.sources.discovery_completed', ['count' => $count]);
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

        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(Roles::ADMIN), 403);

        $resourceId = (string) ($this->selectedResource[$capability] ?? '');
        if (! ctype_digit($resourceId)) {
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => __('operator_runtime.sources.select_resource'),
            ]);
        }

        $resource = CoreExternalResource::query()->with('integration')->find((int) $resourceId);
        if (! $resource instanceof CoreExternalResource) {
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => __('operator_runtime.sources.resource_incompatible'),
            ]);
        }

        // Yetki devri: an account already bound to another asset (another customer, or another asset of this customer)
        // is never moved silently; the operator sees who owns it and confirms the transfer explicitly.
        $conflict = app(OwnershipGuard::class)->forResource($resource, $asset);
        if ($conflict !== null) {
            $this->presentOwnershipConflict($conflict, ['capability' => $capability, 'resource_id' => (int) $resource->id]);

            return;
        }

        try {
            match ($this->providerForCapability($capability)) {
                ProviderRegistry::META => app(ConfirmMetaResourceBindingService::class)
                    ->bindExisting($asset, $resource, $actor, allowReplace: true),
                ProviderRegistry::GOOGLE => app(ConfirmGoogleResourceBindingService::class)
                    ->bindExisting($asset, $resource, $actor, allowReplace: true),
                default => throw ValidationException::withMessages([
                    'selectedResource.'.$capability => __('operator_runtime.sources.resource_incompatible'),
                ]),
            };
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => collect($e->errors())->flatten()->first()
                    ?: __('operator_runtime.sources.resource_incompatible'),
            ]);
        }

        $this->selectedResource[$capability] = '';
        $this->messageTone = 'success';
        $this->message = __('operator_runtime.sources.bound', ['capability' => ProviderRegistry::capabilityLabel($capability)])
            .$this->queueFirstSeoPlan($asset, $capability, $actor);
    }

    /**
     * Search Console just bound to a website that has never had an SEO plan: queue the first one now instead of
     * leaving the site to the weekly schedule (same as "Otomatik kur").
     */
    private function queueFirstSeoPlan(DigitalAsset $asset, string $capability, User $actor): string
    {
        if ($capability !== 'search_console' || (string) $asset->type !== 'website' || SeoPlan::query()->where('digital_asset_id', $asset->id)->exists()) {
            return '';
        }
        try {
            app(SeoPlanRunner::class)->queue($asset->fresh() ?? $asset, $actor, 'first_bind');

            return ' İlk SEO planı kuyruğa alındı.';
        } catch (Throwable $error) {
            report($error);

            return '';
        }
    }

    /** "Devret": binds the pending account here after the Admin ticked "Yetki devrini onaylıyorum". */
    public function transferResource(OwnershipTransferService $transfers): void
    {
        $actor = $this->ownershipTransferActor();
        if ($actor === null) {
            return;
        }
        $asset = $this->asset();
        $capability = (string) ($this->pendingTransfer['capability'] ?? '');
        $this->assertCapability($asset, $capability);
        $resource = CoreExternalResource::query()->with('integration')->find((int) ($this->pendingTransfer['resource_id'] ?? 0));
        if (! $resource instanceof CoreExternalResource || $resource->resource_type !== $capability) {
            $this->cancelOwnershipTransfer();
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => __('operator_runtime.sources.resource_incompatible'),
            ]);
        }

        try {
            $transfers->transferResource($resource, $asset, $actor, confirmed: true, note: $this->transferNote);
        } catch (ValidationException $e) {
            $this->cancelOwnershipTransfer();
            throw ValidationException::withMessages([
                'selectedResource.'.$capability => collect($e->errors())->flatten()->first()
                    ?: __('operator_runtime.sources.resource_incompatible'),
            ]);
        }

        $label = (string) ($resource->display_name ?: $resource->external_id);
        $this->cancelOwnershipTransfer();
        $this->selectedResource[$capability] = '';
        $this->messageTone = 'success';
        $this->message = $label.' bu varlığa devredildi. Eski varlıktaki bağlantı kapatıldı (geçmişi korunuyor); devir kaydedildi.';
    }

    public function disable(string $capability): void
    {
        $asset = $this->asset();
        $this->assertCapability($asset, $capability);

        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(Roles::ADMIN), 403);

        $binding = CoreAssetBinding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('capability', $capability)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->first();

        if ($binding instanceof CoreAssetBinding) {
            try {
                match ($this->providerForCapability($capability)) {
                    ProviderRegistry::META => app(ConfirmMetaResourceBindingService::class)->unbind($binding, $actor),
                    ProviderRegistry::GOOGLE => app(ConfirmGoogleResourceBindingService::class)->unbind($binding, $actor),
                    default => throw ValidationException::withMessages([
                        'selectedResource.'.$capability => __('operator_runtime.sources.resource_incompatible'),
                    ]),
                };
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'selectedResource.'.$capability => collect($e->errors())->flatten()->first()
                        ?: __('operator_runtime.sources.resource_incompatible'),
                ]);
            }
        }

        $this->messageTone = 'success';
        $this->message = __('operator_runtime.sources.disabled', ['capability' => ProviderRegistry::capabilityLabel($capability)]);
    }

    public function collectNow(AsyncOperationService $async, WebsiteCollectionOrchestrator $website): void
    {
        $asset = $this->asset();

        if ((string) $asset->type === 'website') {
            $actor = auth()->user();
            abort_unless($actor instanceof User, 403);

            try {
                $run = $website->start(
                    asset: $asset,
                    requestedBy: $actor,
                    context: [
                        'trigger' => 'operator.asset.sources.collect',
                        'force_refresh' => true,
                    ],
                );

                $this->messageTone = 'success';
                $this->message = app()->getLocale() === 'tr'
                    ? "Site taraması kuyruğa alındı (çalışma #{$run->id}). Birkaç dakika içinde sayfalar ve teknik bulgular site ekranında görünür."
                    : "Website collection queued. Collection #{$run->id}.";
            } catch (Throwable $e) {
                report($e);
                $this->messageTone = 'error';
                $this->message = app()->getLocale() === 'tr'
                    ? 'Site taraması başlatılamadı: '.$e->getMessage()
                    : 'Website collection could not be started: '.$e->getMessage();
            }

            return;
        }

        $result = $async->queueBoundCollect($asset, auth()->user(), [
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
            ->orderBy('status')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (CoreAssetBinding $binding): string => (string) $binding->capability)
            ->keyBy('capability');

        $elsewhereBindings = CoreAssetBinding::query()
            ->with('digitalAsset.brand.customer')
            ->where('digital_asset_id', '!=', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->get(['id', 'digital_asset_id', 'external_resource_id']);
        $boundElsewhere = $elsewhereBindings->pluck('external_resource_id');
        // Owner of each account bound elsewhere ("Müşteri › Varlık"), so the operator can pick it and transfer it.
        $owners = $elsewhereBindings->mapWithKeys(fn (CoreAssetBinding $binding): array => [
            (int) $binding->external_resource_id => collect([
                $binding->digitalAsset?->brand?->customer?->name,
                $binding->digitalAsset?->name,
            ])->filter()->implode(' › '),
        ]);
        $ownedElsewhere = [];

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
                        $q->orWhere('id', $currentResourceId);
                    }
                })
                ->orderBy('display_name')
                ->get()
                // Google Ads managers (MCC) are hierarchy context, never a bind target: do not offer them.
                ->reject(fn (CoreExternalResource $resource): bool => ($resource->metadata['is_manager'] ?? false) === true || ($resource->metadata['selectable'] ?? true) === false)
                ->values();
            $ownedElsewhere[$capability] = CoreExternalResource::query()
                ->where('resource_type', $capability)
                ->where('status', CoreExternalResource::STATUS_AVAILABLE)
                ->whereHas('integration', fn ($q) => $q->where('status', CoreIntegration::STATUS_ACTIVE))
                ->whereIn('id', $boundElsewhere)
                ->when($currentResourceId !== null, fn ($q) => $q->whereKeyNot($currentResourceId))
                ->orderBy('display_name')
                ->get()
                ->map(fn (CoreExternalResource $resource): array => [
                    'id' => (int) $resource->id,
                    'label' => ($resource->display_name ?: $resource->external_id).' · '.$resource->external_id,
                    'owner' => (string) ($owners[(int) $resource->id] ?? ''),
                ])
                ->all();
        }

        $providers = collect($resources)
            ->flatten(1)
            ->map(fn (CoreExternalResource $resource) => $resource->integration?->provider)
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Keep provider discovery available even before external resources exist.
        foreach ($capabilities as $capability) {
            $providers[] = $this->providerForCapability($capability);
        }
        $providers = array_values(array_unique(array_filter($providers)));

        $hasActiveBinding = $bindings->contains(
            fn (CoreAssetBinding $binding): bool => $binding->status === CoreAssetBinding::STATUS_ACTIVE,
        );

        $isWebsite = (string) $asset->type === 'website';
        $websiteCollection = null;
        $websiteSources = [];
        $websiteCollectable = filled($asset->primary_url) || filled($asset->domain);

        if ($isWebsite) {
            $websiteCollection = CollectionRun::query()
                ->where('digital_asset_id', $asset->id)
                ->latest('id')
                ->limit(25)
                ->get()
                ->first(fn (CollectionRun $run): bool => in_array(
                    'WEBSITE_DIRECT',
                    (array) data_get($run->request_context, 'provider_sources', []),
                    true,
                ));

            $pageSpeed = CoreConnection::query()
                ->with('credential')
                ->where('digital_asset_id', $asset->id)
                ->where('type', PageSpeedConnectionProbeService::CONNECTION_TYPE)
                ->latest('id')
                ->first();
            $pageSpeedPayload = $pageSpeed?->credential?->encrypted_payload;
            $pageSpeedReady = $pageSpeed instanceof CoreConnection
                && $pageSpeed->enabled
                && is_array($pageSpeedPayload)
                && filled($pageSpeedPayload['api_key'] ?? null);

            $websiteSources = [
                [
                    'key' => 'public_crawl',
                    'name' => app()->getLocale() === 'tr' ? 'Açık site taraması' : 'Public Website Crawl',
                    'ready' => $websiteCollectable,
                    'status' => $websiteCollectable ? 'ready' : 'url_required',
                ],
                [
                    'key' => 'http_html',
                    'name' => app()->getLocale() === 'tr' ? 'HTTP / HTML analizi' : 'HTTP / HTML Intelligence',
                    'ready' => $websiteCollectable,
                    'status' => $websiteCollectable ? 'ready' : 'url_required',
                ],
                [
                    'key' => 'dns_tls',
                    'name' => app()->getLocale() === 'tr' ? 'SSL / TLS altyapısı' : 'SSL / TLS Infrastructure',
                    'ready' => $websiteCollectable,
                    'status' => $websiteCollectable ? 'ready' : 'domain_required',
                ],
                [
                    'key' => 'pagespeed',
                    'name' => 'PageSpeed Insights',
                    'ready' => $pageSpeedReady,
                    'status' => $pageSpeedReady ? 'ready' : 'connection_required',
                ],
                [
                    // Faz 12: reflects the paired MoxDOP WordPress connector instead of a fixed "later" label.
                    'key' => 'wordpress_connector',
                    'name' => 'WordPress bağlayıcısı',
                    'ready' => $wordpressPaired = $this->wordpressConnectorPaired((int) $asset->id),
                    'status' => $wordpressPaired ? 'ready'
                        : (str_contains(strtolower((string) $asset->cms), 'wordpress') ? 'plugin_required' : 'not_wordpress'),
                ],
            ];
        }

        return view('livewire.operator.asset-data-sources', [
            'asset' => $asset,
            'brand' => $asset->brand,
            'customer' => $asset->brand?->customer,
            'capabilities' => $capabilities,
            'bindings' => $bindings,
            // Faz 13: automatic collection state per bound account (last success, stopped / reconnect) on the asset.
            'automations' => ResourceAutomation::query()->whereIn('external_resource_id', $bindings->pluck('external_resource_id')->filter()->all())
                ->get()->keyBy('external_resource_id'),
            'resources' => $resources,
            'ownedElsewhere' => $ownedElsewhere,
            'transfers' => OwnershipTransfer::query()->with('transferredBy:id,name')->touchingAsset((int) $asset->id)->latest('id')->limit(10)->get(),
            'canTransfer' => $this->canTransferOwnership(),
            'providers' => $providers,
            'canDiscover' => auth()->user() instanceof User && auth()->user()->hasRole(Roles::ADMIN),
            'hasActiveBinding' => $hasActiveBinding,
            'hasCollectableSource' => $isWebsite ? $websiteCollectable : $hasActiveBinding,
            'isWebsite' => $isWebsite,
            'websiteCollection' => $websiteCollection,
            'websiteSources' => $websiteSources,
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

    private function wordpressConnectorPaired(int $assetId): bool
    {
        return DB::table('core_connections')->where('digital_asset_id', $assetId)->where('type', 'wordpress_connector')->where('enabled', true)
            ->get(['config'])->contains(fn (object $row): bool => data_get(json_decode((string) $row->config, true), 'pairing_state') === 'paired');
    }
}
