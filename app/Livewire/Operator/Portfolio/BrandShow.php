<?php

namespace App\Livewire\Operator\Portfolio;

use App\Jobs\DiscoverProviderResourcesJob;
use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Operator\Workspace\BrandDossierTab;
use App\Livewire\Operator\Workspace\BrandScorecardTab;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\OperatorFile;
use App\Models\ResourceAutomation;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\BrandSetup\BrandSetupStatus;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Integrations\BrandAccountCandidates;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Integrations\Meta\DiscoverMetaResourcesService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Operator\BrandOverviewReader;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Ownership\OwnershipGuard;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Options\IndustryOptions;
use App\Support\Roles;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Brand page — one row of tabs, like the website screen: Özet (period numbers, digital assets with their data status,
 * open work with a channel filter, services) · Dijital varlıklar (asset cards, "Hesap ekle", setup status, bindings) ·
 * Bilgi dosyası (the file every AI agent reads first) · Ayarlar (Marka bilgileri, Dosyalar). A channel tab (Arama,
 * Harita, Google Ads, Meta) appears only once its own component exists; until then its old link opens Özet filtered to
 * that channel. Old ?tab= values resolve to their new place (LEGACY_TABS). Everything shown comes from the database.
 */
#[Layout('operator.layouts.app')]
#[Title('Marka')]
class BrandShow extends Component
{
    use InteractsWithDemoPeriod;

    /** The tab row. */
    public const array TABS = ['ozet' => 'Özet', 'varliklar' => 'Dijital varlıklar', 'karne' => 'Hizmet karnesi', 'dosya' => 'Bilgi dosyası', 'ayarlar' => 'Ayarlar'];

    /** Ayarlar views. */
    public const array SETTINGS = ['marka' => 'Marka bilgileri', 'dosyalar' => 'Dosyalar'];

    /** Tab => its own Livewire component. */
    public const array COMPONENTS = ['dosya' => BrandDossierTab::class, 'karne' => BrandScorecardTab::class];

    /** Channel tab => [label, component class]: shown as a tab only when the class exists. */
    public const array CHANNEL_TABS = [
        'arama' => ['Arama', 'App\\Livewire\\Operator\\Workspace\\SearchTab'],
        'harita' => ['Harita', 'App\\Livewire\\Operator\\Workspace\\MapsTab'],
        'google_ads' => ['Google Ads', 'App\\Livewire\\Operator\\Workspace\\GoogleAdsTab'],
        'meta' => ['Meta', 'App\\Livewire\\Operator\\Workspace\\MetaTab'],
    ];

    /** The default tab. */
    public const string OVERVIEW_TAB = 'ozet';

    /** Old tab ids => [tab, Ayarlar view]. Old deep links keep working. */
    public const array LEGACY_TABS = [
        'assets' => ['varliklar', ''], 'estate' => ['varliklar', ''], 'cross_channel' => ['varliklar', ''], 'overview' => ['varliklar', ''],
        'settings' => ['ayarlar', 'marka'], 'business' => ['ayarlar', 'marka'], 'research' => ['ayarlar', 'marka'], 'discovery' => ['ayarlar', 'marka'],
        'context' => ['ayarlar', 'marka'], 'files' => ['ayarlar', 'dosyalar'],
        'operations' => ['ozet', ''], 'growth' => ['ozet', ''], 'ai' => ['ozet', ''], 'work' => ['ozet', ''], 'value' => ['ozet', ''],
        'history' => ['ozet', ''], 'reports' => ['ozet', ''],
    ];

    /** Open work shown on Özet before "+N iş daha". */
    public const int WORK_LIMIT = 5;

    #[Locked]
    public string $brand = '';

    #[Url(as: 'tab', history: true)]
    public string $tab = self::OVERVIEW_TAB;

    /** Ayarlar view (marka | dosyalar). */
    #[Url(as: 'sub', history: true)]
    public string $sub = '';

    /** Özet channel filter (arama | harita | google_ads | meta), empty = all channels. */
    #[Url(as: 'kanal', history: true)]
    public string $kanal = '';

    /** Özet: every open suggestion instead of the first few. */
    public bool $allWork = false;

    public bool $editingContext = false;

    public string $context_business_summary = '';

    public string $context_business_model = '';

    public string $context_priority_offerings = '';

    public string $context_target_audiences = '';

    public string $context_positioning = '';

    public string $context_differentiators = '';

    public string $context_business_goals = '';

    public string $context_conversion_goals = '';

    public string $context_constraints = '';

    public function mount(string $brand): void
    {
        abort_unless(ctype_digit($brand), 404);
        abort_if(Brand::query()->find($brand) === null, 404);
        $this->brand = $brand;
        $this->applyTab($this->tab, $this->sub);
        $this->mountPeriod();
    }

    public function setTab(string $tab): void
    {
        $this->allWork = false;
        $this->applyTab($tab, '');
    }

    public function setSub(string $sub): void
    {
        $this->applyTab('ayarlar', $sub);
    }

    /** Özet channel filter; the same channel again (or an unknown one) clears it. */
    public function setChannel(string $channel): void
    {
        $this->kanal = isset(BrandOverviewReader::CHANNELS[$channel]) && $this->kanal !== $channel ? $channel : '';
        $this->allWork = false;
    }

    public function showAllWork(bool $all = true): void
    {
        $this->allWork = $all;
    }

    /**
     * Current or old tab (and Ayarlar view) → [tab, Ayarlar view, channel filter].
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    public static function resolve(string $tab, string $sub = ''): array
    {
        if (isset(self::LEGACY_TABS[$tab])) {
            [$tab, $legacySub] = self::LEGACY_TABS[$tab];
            $sub = $legacySub !== '' ? $legacySub : $sub;
        }
        if (isset(self::CHANNEL_TABS[$tab])) {
            return class_exists(self::CHANNEL_TABS[$tab][1]) ? [$tab, '', null] : [self::OVERVIEW_TAB, '', $tab];
        }
        if ($tab === 'ayarlar') {
            return ['ayarlar', isset(self::SETTINGS[$sub]) ? $sub : 'marka', null];
        }

        return [isset(self::TABS[$tab]) ? $tab : self::OVERVIEW_TAB, '', null];
    }

    private function applyTab(string $tab, string $sub): void
    {
        [$this->tab, $this->sub, $channel] = self::resolve($tab, $sub);
        if ($channel !== null) {
            $this->kanal = $channel;
        } elseif ($this->tab !== self::OVERVIEW_TAB || ! isset(BrandOverviewReader::CHANNELS[$this->kanal])) {
            $this->kanal = '';
        }
    }

    /**
     * Marka eksikleri › "Kontrol ettim": what Claude or the automatic rules filled is confirmed as the operator's own
     * (the automatic run's services / places / sector, the İş bağlamı taken from the site, the automatically counted
     * conversions). Nothing changes but the "Kontrol et" mark.
     */
    public function confirmChecked(string $key): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $brandId = (int) $this->brand;
        match (true) {
            in_array($key, BrandWorkspaceReadService::AUTOFILLED_KEYS, true) => BrandSetupProposal::query()->where('brand_id', $brandId)
                ->where('status', BrandSetupProposal::STATUS_APPLIED)->where('auto_apply', true)->get()
                ->each(fn (BrandSetupProposal $p) => $p->forceFill(['summary' => array_merge((array) $p->summary, ['checked_at' => now()->toIso8601String()])])->save()),
            $key === 'context' => BrandIntelligenceContext::query()->where('brand_id', $brandId)
                ->where('source', BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY)->update(['source' => BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY_EDITED, 'updated_by' => auth()->id()]),
            $key === 'conversions' => BrandConversionSource::query()->where('brand_id', $brandId)->where('counts', true)
                ->where('origin', BrandConversionSource::ORIGIN_AUTO)->update(['origin' => BrandConversionSource::ORIGIN_OPERATOR]),
            default => null,
        };
        DemoState::flash('Kontrol edildi olarak işaretlendi.');
    }

    /** ★ on / off: ★ = main service (`priority = main`); the SEO plan, Harita and Ads look at main services first. */
    public function toggleOfferingPriority(int $offeringId): void
    {
        $offering = BrandOffering::query()->where('brand_id', (int) $this->brand)->whereKey($offeringId)->firstOrFail();
        $offering->forceFill(['priority' => $offering->isMain() ? 'secondary' : 'main'])->save();
        DemoState::flash($offering->isMain()
            ? 'Hizmet ana hizmet (★) olarak işaretlendi; SEO planı bu hizmete derinlemesine bakar.'
            : 'Hizmet ikincil yapıldı.');
    }

    public function startEditingContext(): void
    {
        $brand = $this->brandModel();
        $context = $brand->intelligenceContext;
        $join = fn (mixed $rows, array $keys): string => implode("\n", BrandDossier::labels($rows, $keys));
        $notes = BrandDossier::notes($brand);
        $this->context_business_summary = (string) ($context?->business_summary ?? '');
        $this->context_business_model = (string) ($context?->business_model ?? '');
        $this->context_priority_offerings = $join($context?->priority_offerings, ['name', 'label', 'goal']);
        $this->context_target_audiences = $join($context?->target_audiences, ['name', 'label']);
        $this->context_positioning = (string) ($context?->positioning ?? '');
        $this->context_differentiators = $join($context?->differentiators, ['name', 'label']);
        $this->context_business_goals = $notes['goals'];
        $this->context_conversion_goals = $join($context?->conversion_goals, ['label', 'type', 'goal']);
        $this->context_constraints = $notes['constraints'];
        $this->editingContext = true;
        $this->tab = 'ayarlar';
        $this->sub = 'marka';
    }

    public function cancelEditingContext(): void
    {
        $this->editingContext = false;
    }

    /**
     * İş bağlamı is one form: summary, model, audiences… go to the brand's intelligence context; goals and constraints
     * are the brand file's notes (one source, read by every AI agent and MCP get-brand) and are mirrored into the context.
     */
    public function saveBusinessContext(): void
    {
        $brand = $this->brandModel();
        $context = $brand->intelligenceContext;
        $split = static fn (string $value): array => array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $value) ?: [])));
        // Column sizes (PostgreSQL refuses longer values): business model 64, each goal / offering / audience line 255.
        $this->validate([
            'context_business_model' => ['nullable', 'string', 'max:64'],
            'context_business_goals' => ['nullable', 'string', 'max:4000'],
            'context_constraints' => ['nullable', 'string', 'max:4000'],
        ], [
            'context_business_model.max' => 'İş modeli en fazla 64 karakter olabilir (ör. "Klinik — randevulu hizmet").',
        ]);
        foreach (['context_priority_offerings' => 'Öncelikli teklifler', 'context_target_audiences' => 'Hedef kitle', 'context_business_goals' => 'İş hedefleri',
            'context_conversion_goals' => 'Dönüşüm hedefleri', 'context_differentiators' => 'Farklılaştırıcılar'] as $field => $label) {
            if (collect($split((string) $this->{$field}))->contains(fn (string $line): bool => mb_strlen($line) > 255)) {
                throw ValidationException::withMessages([$field => $label.': her satır en fazla 255 karakter olabilir.']);
            }
        }

        app(BrandIntelligenceContextWriteService::class)->saveFromForm($brand, [
            'business_summary' => $this->context_business_summary,
            'business_model' => $this->context_business_model,
            'products_services' => is_array($context?->products_services) ? $context->products_services : [],
            'priority_offerings' => $split($this->context_priority_offerings),
            'target_audiences' => array_map(fn (string $v): array => ['name' => $v, 'note' => null], $split($this->context_target_audiences)),
            'target_markets' => is_array($context?->target_markets) ? $context->target_markets : [],
            'business_goals' => array_map(fn (string $v): array => ['goal' => $v, 'note' => null], $split($this->context_business_goals)),
            'conversion_goals' => array_map(fn (string $v): array => ['type' => 'custom', 'label' => $v, 'note' => null], $split($this->context_conversion_goals)),
            'positioning' => $this->context_positioning,
            'differentiators' => $split($this->context_differentiators),
            'known_competitors' => is_array($context?->known_competitors) ? $context->known_competitors : [],
            'important_constraints' => trim($this->context_constraints),
        ], auth()->user());
        BrandDossier::saveNotes($brand, $this->context_business_goals, $this->context_constraints);
        try {
            app(BrandDossier::class)->build($brand->fresh() ?? $brand);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->editingContext = false;
        DemoState::flash('İş bağlamı kaydedildi; bilgi dosyası yenilendi.');
    }

    /**
     * "Hesap ekle": bind one unbound account of the brand's business (MCC / Meta Business) or one matching the brand's
     * name. One account = one new asset of the brand. Only accounts listed for this brand are accepted, and the
     * ownership guard is re-checked: an account bound elsewhere in the meantime is never moved from here.
     */
    public function addAccount(int $resourceId): void
    {
        $actor = $this->adminActor();
        $brand = $this->brandModel();
        if (! collect(app(BrandAccountCandidates::class)->forBrand($brand))->contains('resource_id', $resourceId)) {
            DemoState::flash('Bu hesap artık bu marka için bağlanabilir listede değil; listeyi yenileyin.', 'info');

            return;
        }
        DemoState::flash($this->bindCandidate($brand, CoreExternalResource::query()->findOrFail($resourceId), $actor), 'info');
    }

    /** Bind every suggested (strong) candidate of the brand at once. */
    public function addSuggestedAccounts(): void
    {
        $actor = $this->adminActor();
        $brand = $this->brandModel();
        $messages = [];
        foreach (array_filter(app(BrandAccountCandidates::class)->forBrand($brand), fn (array $c): bool => $c['strong']) as $candidate) {
            $messages[] = $candidate['name'].': '.$this->bindCandidate($brand, CoreExternalResource::query()->findOrFail($candidate['resource_id']), $actor);
        }
        DemoState::flash($messages === [] ? 'Önerilen hesap yok.' : implode(' ', $messages), 'info');
    }

    private function bindCandidate(Brand $brand, CoreExternalResource $resource, User $actor): string
    {
        $conflict = app(OwnershipGuard::class)->forResourceInBrand($resource, $brand);
        if ($conflict !== null) {
            return $conflict->plainMessage().' Devretmek için varlığın Veri kaynakları sayfasını kullanın.';
        }
        $label = BrandAccountCandidates::TYPE_LABELS[$resource->resource_type] ?? (string) $resource->resource_type;
        $plan = new ResourceBindingPlan($resource, $brand, ResourceBindingPlan::MODE_CREATE_ASSET, null, $label.' · '.$resource->display_name, $actor);
        try {
            $result = $resource->provider === ProviderRegistry::META
                ? app(ConfirmMetaResourceBindingService::class)->confirm($plan)
                : app(ConfirmGoogleResourceBindingService::class)->confirm($plan);
        } catch (ValidationException $exception) {
            return (string) (collect($exception->errors())->flatten()->first() ?? 'Hesap bağlanamadı.');
        }

        return (string) ($result['message'] ?? 'Hesap bağlandı.');
    }

    /** Kurulum durumu: list the accounts of the provider connection (Google in the background, Meta right away). */
    public function setupDiscover(string $provider): void
    {
        $actor = $this->adminActor();
        $integration = CoreIntegration::query()->where('provider', $provider)->first();
        if (! in_array($provider, [ProviderRegistry::GOOGLE, ProviderRegistry::META], true) || $integration === null || ! $integration->isActive()) {
            DemoState::flash('Önce Entegrasyonlar sayfasından '.($provider === ProviderRegistry::META ? 'Meta' : 'Google').' bağlantısını tamamlayın.', 'info');

            return;
        }
        if ($provider === ProviderRegistry::META) {
            $result = app(DiscoverMetaResourcesService::class)->discoverAdAccounts($integration, $actor);
            DemoState::flash((string) ($result['message'] ?? ''), 'info');

            return;
        }
        Cache::put(DiscoverProviderResourcesJob::cacheKey($provider), ['state' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());
        DiscoverProviderResourcesJob::dispatch($provider, (int) $actor->id);
        $state = Cache::get(DiscoverProviderResourcesJob::cacheKey($provider));
        DemoState::flash(($state['state'] ?? '') === 'done'
            ? (string) ($state['result']['message'] ?? 'Hesaplar listelendi.')
            : 'Hesap listesi arka planda yenileniyor; birkaç dakika sonra sayfayı yenileyin.', 'info');
    }

    /** Kurulum durumu: first (or repeated) crawl of the brand's website. */
    public function setupStartCrawl(): void
    {
        $actor = $this->operatorActor();
        $site = $this->brandWebsite();
        if ($site === null || (blank($site->primary_url) && blank($site->domain))) {
            DemoState::flash('Önce markaya adresi olan bir web sitesi ekleyin.', 'info');

            return;
        }
        try {
            app(WebsiteCollectionOrchestrator::class)->start(asset: $site, requestedBy: $actor, context: ['trigger' => 'operator.brand.setup_status', 'force_refresh' => true]);
            DemoState::flash('Site taraması kuyruğa alındı; birkaç dakika içinde sayfalar ve teknik bulgular site ekranında görünür.', 'info');
        } catch (Throwable $exception) {
            report($exception);
            DemoState::flash('Site taraması başlatılamadı: '.$exception->getMessage(), 'info');
        }
    }

    /** Kurulum durumu: collect the brand's accounts of one channel on the next automation tick. */
    public function setupCollectNow(string $type): void
    {
        $actor = $this->operatorActor();
        $resourceIds = $this->boundResourceIds($type);
        if ($resourceIds === []) {
            DemoState::flash('Bu kanalda markaya bağlı hesap yok; önce "Hesap ekle" ile bağlayın.', 'info');

            return;
        }
        $automation = app(ResourceAutomationService::class);
        $automation->discover();
        $count = 0;
        foreach (ResourceAutomation::query()->whereIn('external_resource_id', $resourceIds)->pluck('id') as $id) {
            $automation->runNow((int) $id, $actor);
            $count++;
        }
        DemoState::flash($count.' hesabın veri çekimi öne alındı; birkaç dakika içinde başlar.', 'info');
    }

    /** @return list<int> */
    private function boundResourceIds(string $type): array
    {
        return CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->where('capability', $type)
            ->whereIn('digital_asset_id', DigitalAsset::query()->where('brand_id', (int) $this->brand)->select('id'))
            ->pluck('external_resource_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function brandWebsite(): ?DigitalAsset
    {
        return DigitalAsset::query()->where('brand_id', (int) $this->brand)->where('type', 'website')->where('status', 'active')->orderBy('id')->first();
    }

    private function adminActor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(Roles::ADMIN), 403);

        return $actor;
    }

    private function operatorActor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /**
     * Faz 11c: files attached to the brand (uploaded from this tab's link or Dosyalar with the brand scope).
     * Non-admins see only their own files, as on Dosyalar.
     *
     * @return Collection<int, OperatorFile>
     */
    private function brandFiles(Brand $brand): Collection
    {
        return OperatorFile::query()->where('brand_id', (string) $brand->id)
            ->when(! auth()->user()?->hasRole(Roles::ADMIN), fn ($q) => $q->where('user_id', auth()->id()))
            ->latest()->limit(100)->get();
    }

    /** Selected Özet period in days (28 / 90), from the shared period preset. */
    public function overviewDays(): int
    {
        return $this->period === 'last_90' ? 90 : 28;
    }

    public function render(): View
    {
        $brand = $this->brandModel();
        $workspace = app(BrandWorkspaceReadService::class);
        $overview = app(BrandOverviewReader::class);
        $assets = $workspace->assets($brand);
        $services = $workspace->services($brand);
        $checklist = $workspace->checklist($brand, $assets, $services);

        $data = match ($this->tab) {
            self::OVERVIEW_TAB => $this->overviewData($brand, $overview, $services),
            'varliklar' => $this->assetsData($brand, $overview, $assets),
            'ayarlar' => $this->settingsData($brand, $services),
            default => [],
        };

        return view('livewire.operator.portfolio.brand-show', [
            ...$this->header($brand),
            'tabs' => $this->tabs(),
            'channelComponent' => isset(self::CHANNEL_TABS[$this->tab]) ? self::CHANNEL_TABS[$this->tab][1] : null,
            'checklist' => $checklist,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'flash' => DemoState::pullFlash(),
            ...$data,
        ]);
    }

    /**
     * Özet: period KPIs, asset cards and open work (both filtered by the channel chip), services.
     *
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    private function overviewData(Brand $brand, BrandOverviewReader $overview, array $services): array
    {
        $models = $overview->assetModels($brand);
        $cards = $overview->assetCards($models);
        $channel = BrandOverviewReader::CHANNELS[$this->kanal] ?? null;
        $limit = $this->allWork ? 100 : self::WORK_LIMIT;
        $all = $overview->openWork($brand, null, $channel === null ? $limit : 0);
        $work = $channel === null ? $all : $overview->openWork($brand, $channel['channel'], $limit);
        $channelCounts = [];
        foreach (BrandOverviewReader::CHANNELS as $key => $definition) {
            $channelCounts[$key] = ['label' => Suggestion::CHANNEL_LABELS[$definition['channel']] ?? $key, 'count' => (int) ($all['by_key'][$definition['channel']] ?? 0)];
        }

        return [
            'days' => $this->overviewDays(),
            'kpis' => $overview->kpis($brand, $models, $cards, $this->overviewDays()),
            'assetCards' => $channel === null ? $cards : array_values(array_filter($cards, fn (array $c): bool => in_array($c['type'], $channel['types'], true))),
            'channelFilter' => $channel === null ? null : ['key' => $this->kanal, 'label' => $channelCounts[$this->kanal]['label'], 'asset_label' => $channel['asset_label']],
            'channelCounts' => $channelCounts,
            'workTotal' => $all['total'],
            'work' => $work,
            'serviceSummary' => $overview->services($services),
        ];
    }

    /**
     * Dijital varlıklar: every asset card, "Hesap ekle" and the per-channel setup status.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return array<string, mixed>
     */
    private function assetsData(Brand $brand, BrandOverviewReader $overview, array $assets): array
    {
        return [
            'assetCards' => $overview->assetCards($overview->assetModels($brand)),
            'assets' => $assets,
            'setup' => $this->setupStatus($brand),
        ];
    }

    /**
     * Ayarlar: Marka bilgileri (brand settings, İş bağlamı, scope, conversions) or Dosyalar.
     *
     * @param  list<array<string, mixed>>  $services
     * @return array<string, mixed>
     */
    private function settingsData(Brand $brand, array $services): array
    {
        if ($this->sub === 'dosyalar') {
            return ['brandFiles' => $this->brandFiles($brand)];
        }
        $context = $brand->intelligenceContext;

        return [
            'services' => $services,
            'responsible' => $brand->responsibleUsers->pluck('name')->all(),
            'context' => $this->contextRows($brand, $context instanceof BrandIntelligenceContext ? $context : null),
            'serviceScope' => app(CustomerServiceScopeReadService::class)->forBrand($brand, includeEnded: false),
        ];
    }

    /** @return array<string, string> the visible tab row (channel tabs only once their component exists) */
    private function tabs(): array
    {
        $channels = array_map(fn (array $t): string => $t[0], array_filter(self::CHANNEL_TABS, fn (array $t): bool => class_exists($t[1])));

        return ['ozet' => self::TABS['ozet']] + $channels + array_diff_key(self::TABS, ['ozet' => true]);
    }

    /** @return array<string, mixed> header data shared by every tab */
    private function header(Brand $brand): array
    {
        return [
            'websites' => $this->websites($brand),
            'brandModel' => $brand,
            'customer' => $brand->customer,
            'sectors' => collect($brand->sectorCodes())->map(fn (string $code): string => IndustryOptions::label($code))->filter()->values()->all(),
            'areas' => $brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->get()->map->label()->values()->all(),
            'operational' => app(ServiceScope::class)->isBrandOperational($brand->id),
        ];
    }

    /**
     * The brand's websites for the header links.
     *
     * @return Collection<int, DigitalAsset>
     */
    private function websites(Brand $brand): Collection
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->orderBy('id')->get(['id', 'name', 'domain', 'primary_url']);
    }

    /** @return array<string, mixed>|null */
    private function setupStatus(Brand $brand): ?array
    {
        try {
            return app(BrandSetupStatus::class)->for($brand);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function brandModel(): Brand
    {
        return Brand::query()->with(['customer', 'responsibleUsers', 'intelligenceContext'])->findOrFail((int) $this->brand);
    }

    /** @return list<array{label: string, value: string}> */
    private function contextRows(Brand $brand, ?BrandIntelligenceContext $context): array
    {
        $notes = BrandDossier::notes($brand);
        if ($context === null && $notes['goals'] === '' && $notes['constraints'] === '') {
            return [];
        }
        $rows = [
            'İşletme özeti' => $context?->business_summary,
            'İş modeli' => $context?->business_model,
            'Hedef kitle' => implode(', ', BrandDossier::labels($context?->target_audiences, ['name', 'label'])),
            'Konumlandırma' => $context?->positioning,
            'Farklılaştırıcılar' => implode(', ', BrandDossier::labels($context?->differentiators, ['name', 'label'])),
            'İş hedefleri' => $notes['goals'],
            'Dönüşüm hedefleri' => implode(', ', BrandDossier::labels($context?->conversion_goals, ['label', 'type', 'goal'])),
            'Kısıtlar' => $notes['constraints'],
        ];

        return collect($rows)->map(fn ($value, string $label): array => ['label' => $label, 'value' => trim((string) $value)])->values()->all();
    }
}
