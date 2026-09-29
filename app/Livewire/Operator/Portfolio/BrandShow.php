<?php

namespace App\Livewire\Operator\Portfolio;

use App\Jobs\DiscoverProviderResourcesJob;
use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\OperatorFile;
use App\Models\Recommendation;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\BrandSetup\BrandSetupStatus;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Findings\FindingReadService;
use App\Services\Integrations\BrandAccountCandidates;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Integrations\Meta\DiscoverMetaResourcesService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Opportunities\OpportunityReadService;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Recommendations\RecommendationReadService;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Services\Work\WorkReadService;
use App\Support\Demo\DemoState;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Options\IndustryOptions;
use App\Support\Roles;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Brand workspace: one tab per channel — Arama · Harita · Google Ads · Meta — rebuilt in Faz 4–7 on the v2 model
 * (reserved until then), and "Ayarlar" with the brand page (setup, business, assets, work, files). Everything shown
 * comes from the database.
 */
#[Layout('operator.layouts.app')]
#[Title('Marka')]
class BrandShow extends Component
{
    use InteractsWithDemoPeriod;

    /** Settings sub-tabs (the former brand page). */
    public const array TABS = ['settings', 'overview', 'business', 'assets', 'work', 'files'];

    /** Workspace tab => [label, Livewire component class (rendered only when it exists)]. "ayarlar" opens TABS. */
    public const array WORKSPACE_TABS = [
        'arama' => ['Arama', 'App\\Livewire\\Operator\\Workspace\\SearchTab'],
        'harita' => ['Harita', 'App\\Livewire\\Operator\\Workspace\\MapsTab'],
        'google_ads' => ['Google Ads', 'App\\Livewire\\Operator\\Workspace\\GoogleAdsTab'],
        'meta' => ['Meta', 'App\\Livewire\\Operator\\Workspace\\MetaTab'],
    ];

    /** Old deep links keep working. */
    private const array LEGACY_TABS = [
        'estate' => 'assets', 'cross_channel' => 'assets', 'operations' => 'work', 'growth' => 'work', 'ai' => 'work',
        'value' => 'overview', 'history' => 'overview', 'reports' => 'overview', 'research' => 'business', 'discovery' => 'business', 'context' => 'business',
    ];

    public const array WORK_SECTIONS = ['findings', 'opportunities', 'recommendations', 'tasks'];

    #[Locked]
    public string $brand = '';

    #[Url(as: 'tab', history: true)]
    public string $tab = 'arama';

    #[Url(as: 'ops', history: true)]
    public string $ops = 'findings';

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

    public string $taskCreateNonce = '';

    public function mount(string $brand): void
    {
        abort_unless(ctype_digit($brand), 404);
        abort_if(Brand::query()->find($brand) === null, 404);
        $this->brand = $brand;
        $this->tab = $this->normalizeTab($this->tab);
        if (! in_array($this->ops, self::WORK_SECTIONS, true)) {
            $this->ops = 'findings';
        }
        $this->taskCreateNonce = (string) Str::uuid();
        $this->mountPeriod();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $this->normalizeTab($tab);
    }

    private function normalizeTab(string $tab): string
    {
        if ($tab === 'ayarlar') {
            return 'settings';
        }
        $tab = self::LEGACY_TABS[$tab] ?? $tab;

        return isset(self::WORKSPACE_TABS[$tab]) || in_array($tab, self::TABS, true) ? $tab : 'arama';
    }

    public function setOps(string $section): void
    {
        $section = $section === 'work' ? 'tasks' : $section;
        $this->ops = in_array($section, self::WORK_SECTIONS, true) ? $section : 'findings';
        $this->tab = 'work';
    }

    /** Star / unstar a service: the SEO plan looks deeply only at starred services. */
    public function toggleOfferingPriority(int $offeringId): void
    {
        $offering = BrandOffering::query()->where('brand_id', (int) $this->brand)->whereKey($offeringId)->firstOrFail();
        $offering->forceFill(['is_priority' => ! $offering->is_priority])->save();
        DemoState::flash($offering->is_priority
            ? 'Hizmet öncelikli olarak işaretlendi; SEO planı bu hizmete derinlemesine bakar.'
            : 'Hizmetin önceliği kaldırıldı.');
    }

    public function startEditingContext(): void
    {
        $context = $this->brandModel()->intelligenceContext;
        $join = fn (mixed $rows, array $keys): string => implode("\n", $this->labels($rows, $keys));
        $this->context_business_summary = (string) ($context?->business_summary ?? '');
        $this->context_business_model = (string) ($context?->business_model ?? '');
        $this->context_priority_offerings = $join($context?->priority_offerings, ['name', 'label', 'goal']);
        $this->context_target_audiences = $join($context?->target_audiences, ['name', 'label']);
        $this->context_positioning = (string) ($context?->positioning ?? '');
        $this->context_differentiators = $join($context?->differentiators, ['name', 'label']);
        $this->context_business_goals = $join($context?->business_goals, ['goal', 'label', 'name']);
        $this->context_conversion_goals = $join($context?->conversion_goals, ['label', 'type', 'goal']);
        $this->context_constraints = is_string($context?->important_constraints) ? $context->important_constraints : $join($context?->important_constraints, ['name', 'label']);
        $this->editingContext = true;
        $this->tab = 'business';
    }

    public function cancelEditingContext(): void
    {
        $this->editingContext = false;
    }

    public function saveBusinessContext(): void
    {
        $brand = $this->brandModel();
        $context = $brand->intelligenceContext;
        $split = static fn (string $value): array => array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $value) ?: [])));
        // Column sizes (PostgreSQL refuses longer values): business model 64, each goal / offering / audience line 255.
        $this->validate(['context_business_model' => ['nullable', 'string', 'max:64']], [
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

        $this->editingContext = false;
        DemoState::flash('İş bağlamı kaydedildi.');
    }

    public function createTaskFromRecommendation(string $recommendationId): void
    {
        $recommendation = ctype_digit($recommendationId) ? Recommendation::query()->find((int) $recommendationId) : null;
        if ($recommendation === null) {
            DemoState::flash(__('operator.flash.recommendation_not_found'), 'info');

            return;
        }
        $service = app(CreateTaskFromRecommendation::class);
        if (! $service->userCanConvert(auth()->user())) {
            DemoState::flash(__('operator.flash.not_allowed_create_task'), 'info');

            return;
        }
        try {
            $task = $service->create($recommendation, [], auth()->user(), 'rec-task:'.$recommendation->id.':brand:'.$this->taskCreateNonce);
            $this->taskCreateNonce = (string) Str::uuid();
            DemoState::flash(__('operator.flash.task_created_from_recommendation', ['id' => $task->id]));
            $this->setOps('tasks');
        } catch (Throwable $exception) {
            DemoState::flash($exception->getMessage(), 'info');
        }
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

    public function render(): View
    {
        $brand = $this->brandModel();
        if (isset(self::WORKSPACE_TABS[$this->tab])) {
            return $this->renderWorkspace($brand);
        }
        $workspace = app(BrandWorkspaceReadService::class);
        $assets = $workspace->assets($brand);
        $services = $workspace->services($brand);
        $checklist = $workspace->checklist($brand, $assets, $services);

        $findings = collect(app(FindingReadService::class)->forBrand($brand))->map(fn ($dto): array => $dto->toArray())->values();
        $recommendations = collect(app(RecommendationReadService::class)->forListPresentation(['brand_id' => $brand->id]));
        $tasks = collect(app(WorkReadService::class)->workItems())->filter(fn (array $t): bool => (int) ($t['brand_id'] ?? 0) === $brand->id)->values();
        $opportunities = collect(app(OpportunityReadService::class)->forListPresentation(['brand_id' => $brand->id]));

        $openFindings = $findings->where('status', 'open');
        $openRecommendations = $recommendations->whereIn('status', ['pending', 'approved']);
        $openTasks = $tasks->whereIn('status', ['open', 'in_progress', 'blocked']);
        $work = [
            'findings' => ['label' => 'Bulgular', 'count' => $openFindings->count(), 'rows' => $findings],
            'opportunities' => ['label' => 'Fırsatlar', 'count' => $opportunities->whereIn('status', ['open', 'reviewing'])->count(), 'rows' => $opportunities],
            'recommendations' => ['label' => 'Öneriler', 'count' => $openRecommendations->count(), 'rows' => $recommendations],
            'tasks' => ['label' => 'Görevler', 'count' => $openTasks->count(), 'rows' => $tasks],
        ];

        $setup = in_array($this->tab, ['overview', 'assets'], true) ? $this->setupStatus($brand) : null;
        $seo = $workspace->seo($assets);
        $attention = array_values(array_filter([
            $seo['critical'] > 0 ? ['tone' => 'error', 'text' => $seo['critical'].' kritik SEO düzeltmesi', 'url' => route('operator.website', ['assetId' => $seo['website_id'], 'tab' => 'seo'])] : null,
            $seo['questions'] > 0 ? ['tone' => 'warning', 'text' => $seo['questions'].' SEO kararı seni bekliyor', 'url' => route('operator.website', ['assetId' => $seo['website_id'], 'tab' => 'seo'])] : null,
            $seo['content'] > 0 ? ['tone' => 'info', 'text' => $seo['content'].' içerik önerisi', 'url' => route('operator.website', ['assetId' => $seo['website_id'], 'tab' => 'seo'])] : null,
            ($critical = $openFindings->whereIn('severity', ['critical', 'high'])->count()) > 0 ? ['tone' => 'error', 'text' => $critical.' kritik/yüksek bulgu', 'ops' => 'findings'] : null,
            ($blocked = $tasks->where('status', 'blocked')->count()) > 0 ? ['tone' => 'warning', 'text' => $blocked.' görev engelli', 'ops' => 'tasks'] : null,
            $work['recommendations']['count'] > 0 ? ['tone' => 'info', 'text' => $work['recommendations']['count'].' karar bekleyen öneri', 'ops' => 'recommendations'] : null,
        ]));

        $context = $brand->intelligenceContext;
        $sectors = collect($brand->sectorCodes())->map(fn (string $code): string => IndustryOptions::label($code))->filter()->values()->all();

        return view('livewire.operator.portfolio.brand-show', [
            ...$this->frame(false),
            'brandModel' => $brand,
            'customer' => $brand->customer,
            'sectors' => $sectors,
            'areas' => $brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->get()->map->label()->values()->all(),
            'responsible' => $brand->responsibleUsers->pluck('name')->all(),
            'assets' => $assets,
            'services' => $services,
            'checklist' => $checklist,
            'setup' => $setup,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'attention' => $attention,
            'work' => $work,
            'context' => $context instanceof BrandIntelligenceContext ? $this->contextRows($context) : [],
            'serviceScope' => app(CustomerServiceScopeReadService::class)->forBrand($brand, includeEnded: false),
            'reportPreview' => null,
            'flash' => DemoState::pullFlash(),
            'brandFiles' => $this->tab === 'files' ? $this->brandFiles($brand) : collect(),
        ]);
    }

    /** Workspace tabs: only the header, the week's top cards and the channel component. */
    private function renderWorkspace(Brand $brand): View
    {
        $class = self::WORKSPACE_TABS[$this->tab][1];
        $operational = app(ServiceScope::class)->isBrandOperational($brand->id);
        // v2: the week's cards come from the suggestions table once Faz 4–7 fill it.
        $weekTop = [];
        $sectors = collect($brand->sectorCodes())->map(fn (string $code): string => IndustryOptions::label($code))->filter()->values()->all();

        return view('livewire.operator.portfolio.brand-show', [
            ...$this->frame(true),
            'brandModel' => $brand,
            'customer' => $brand->customer,
            'sectors' => $sectors,
            'areas' => $brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->get()->map->label()->values()->all(),
            'operational' => $operational,
            'weekTop' => $weekTop,
            'channelComponent' => class_exists($class) ? $class : null,
            'work' => [],
            'checklist' => ['complete' => true, 'items' => []],
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /** @return array{workspaceTab: bool, mainTab: string, workspaceTabs: array<string, string>} */
    private function frame(bool $workspace): array
    {
        return [
            'workspaceTab' => $workspace,
            'mainTab' => $workspace ? $this->tab : 'ayarlar',
            'workspaceTabs' => array_map(fn (array $t): string => $t[0], self::WORKSPACE_TABS) + ['ayarlar' => 'Ayarlar'],
        ];
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
    private function contextRows(BrandIntelligenceContext $context): array
    {
        $rows = [
            'İşletme özeti' => $context->business_summary,
            'İş modeli' => $context->business_model,
            'Hedef kitle' => implode(', ', $this->labels($context->target_audiences, ['name', 'label'])),
            'Konumlandırma' => $context->positioning,
            'Farklılaştırıcılar' => implode(', ', $this->labels($context->differentiators, ['name', 'label'])),
            'İş hedefleri' => implode(', ', $this->labels($context->business_goals, ['goal', 'label', 'name'])),
            'Dönüşüm hedefleri' => implode(', ', $this->labels($context->conversion_goals, ['label', 'type', 'goal'])),
            'Kısıtlar' => is_string($context->important_constraints) ? $context->important_constraints : implode(', ', $this->labels($context->important_constraints, ['name', 'label'])),
        ];

        return collect($rows)->map(fn ($value, string $label): array => ['label' => $label, 'value' => trim((string) $value)])->values()->all();
    }

    /** @return list<string> */
    private function labels(mixed $rows, array $keys): array
    {
        if (! is_array($rows)) {
            return [];
        }
        $labels = [];
        foreach ($rows as $row) {
            if (is_string($row) && trim($row) !== '') {
                $labels[] = trim($row);

                continue;
            }
            foreach ($keys as $key) {
                if (is_array($row) && is_string($row[$key] ?? null) && trim($row[$key]) !== '') {
                    $labels[] = trim($row[$key]);
                    break;
                }
            }
        }

        return array_values(array_unique($labels));
    }
}
