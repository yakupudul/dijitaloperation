<?php

namespace App\Livewire\Operator\Portfolio;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Operator\Portfolio\Concerns\InteractsWithBrandReports;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\Recommendation;
use App\Services\Approvals\ApprovalReadService;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Findings\FindingReadService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Opportunities\OpportunityReadService;
use App\Services\Recommendations\RecommendationReadService;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Services\Work\WorkReadService;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Brand page: who the brand is, what is connected, what needs doing, and its reports.
 * Everything shown comes from the database; missing data is shown as missing.
 */
#[Layout('operator.layouts.app')]
#[Title('Marka')]
class BrandShow extends Component
{
    use InteractsWithBrandReports;
    use InteractsWithDemoPeriod;

    public const array TABS = ['overview', 'business', 'assets', 'work', 'reports'];

    /** Old deep links keep working. */
    private const array LEGACY_TABS = [
        'estate' => 'assets', 'cross_channel' => 'assets', 'operations' => 'work', 'growth' => 'work', 'ai' => 'work',
        'value' => 'reports', 'history' => 'reports', 'research' => 'business', 'discovery' => 'business', 'context' => 'business', 'files' => 'overview',
    ];

    public const array WORK_SECTIONS = ['findings', 'opportunities', 'recommendations', 'tasks', 'requests', 'approvals'];

    public string $brand = '';

    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

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
        $this->tab = self::LEGACY_TABS[$this->tab] ?? $this->tab;
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
        if (! in_array($this->ops, self::WORK_SECTIONS, true)) {
            $this->ops = 'findings';
        }
        $this->taskCreateNonce = (string) Str::uuid();
        $this->mountPeriod();
        $this->mountBrandReports();
    }

    public function setTab(string $tab): void
    {
        $tab = self::LEGACY_TABS[$tab] ?? $tab;
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
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
        } catch (\Throwable $exception) {
            DemoState::flash($exception->getMessage(), 'info');
        }
    }

    public function render(): View
    {
        $brand = $this->brandModel();
        $workspace = app(BrandWorkspaceReadService::class);
        $assets = $workspace->assets($brand);
        $services = $workspace->services($brand);
        $checklist = $workspace->checklist($brand, $assets, $services);

        $findings = collect(app(FindingReadService::class)->forBrand($brand))->map(fn ($dto): array => $dto->toArray())->values();
        $recommendations = collect(app(RecommendationReadService::class)->forListPresentation(['brand_id' => $brand->id]));
        $tasks = collect(app(WorkReadService::class)->workItems())->filter(fn (array $t): bool => (int) ($t['brand_id'] ?? 0) === $brand->id)->values();
        $requests = collect(app(ClientRequestReadService::class)->forBrandPresentation($brand->id));
        $approvals = collect(app(ApprovalReadService::class)->forBrandPresentation($brand->id));
        $opportunities = collect(app(OpportunityReadService::class)->forListPresentation(['brand_id' => $brand->id]));

        $openFindings = $findings->where('status', 'open');
        $openRecommendations = $recommendations->whereIn('status', ['pending', 'approved']);
        $openTasks = $tasks->whereIn('status', ['open', 'in_progress', 'blocked']);
        $work = [
            'findings' => ['label' => 'Bulgular', 'count' => $openFindings->count(), 'rows' => $findings],
            'opportunities' => ['label' => 'Fırsatlar', 'count' => $opportunities->whereIn('status', ['open', 'reviewing'])->count(), 'rows' => $opportunities],
            'recommendations' => ['label' => 'Öneriler', 'count' => $openRecommendations->count(), 'rows' => $recommendations],
            'tasks' => ['label' => 'Görevler', 'count' => $openTasks->count(), 'rows' => $tasks],
            'requests' => ['label' => 'Müşteri talepleri', 'count' => $requests->whereNotIn('status', ['done', 'declined', 'closed'])->count(), 'rows' => $requests],
            'approvals' => ['label' => 'Onaylar', 'count' => $approvals->whereIn('status', ['pending', 'requested'])->count(), 'rows' => $approvals],
        ];

        $seo = $workspace->seo($assets);
        $attention = array_values(array_filter([
            $seo['critical'] > 0 ? ['tone' => 'error', 'text' => $seo['critical'].' kritik SEO düzeltmesi', 'url' => route('operator.website', ['assetId' => $seo['website_id'], 'tab' => 'seo'])] : null,
            $seo['questions'] > 0 ? ['tone' => 'warning', 'text' => $seo['questions'].' SEO kararı seni bekliyor', 'url' => route('operator.website', ['assetId' => $seo['website_id'], 'tab' => 'seo'])] : null,
            $seo['content'] > 0 ? ['tone' => 'info', 'text' => $seo['content'].' içerik önerisi', 'url' => route('operator.website', ['assetId' => $seo['website_id'], 'tab' => 'seo'])] : null,
            ($critical = $openFindings->whereIn('severity', ['critical', 'high'])->count()) > 0 ? ['tone' => 'error', 'text' => $critical.' kritik/yüksek bulgu', 'ops' => 'findings'] : null,
            ($blocked = $tasks->where('status', 'blocked')->count()) > 0 ? ['tone' => 'warning', 'text' => $blocked.' görev engelli', 'ops' => 'tasks'] : null,
            $work['requests']['count'] > 0 ? ['tone' => 'info', 'text' => $work['requests']['count'].' açık müşteri talebi', 'ops' => 'requests'] : null,
            $work['recommendations']['count'] > 0 ? ['tone' => 'info', 'text' => $work['recommendations']['count'].' karar bekleyen öneri', 'ops' => 'recommendations'] : null,
        ]));

        $context = $brand->intelligenceContext;
        $sectors = collect($brand->sectorCodes())->map(fn (string $code): string => IndustryOptions::label($code))->filter()->values()->all();

        return view('livewire.operator.portfolio.brand-show', [
            'brandModel' => $brand,
            'customer' => $brand->customer,
            'sectors' => $sectors,
            'areas' => $brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->get()->map->label()->values()->all(),
            'responsible' => $brand->responsibleUsers->pluck('name')->all(),
            'assets' => $assets,
            'services' => $services,
            'checklist' => $checklist,
            'attention' => $attention,
            'work' => $work,
            'context' => $context instanceof BrandIntelligenceContext ? $this->contextRows($context) : [],
            'serviceScope' => app(CustomerServiceScopeReadService::class)->forBrand($brand, includeEnded: false),
            'reportPreview' => null,
            'flash' => DemoState::pullFlash(),
            'valueStory' => $this->tab === 'reports' ? $this->valueStory($brand) : null,
            ...($this->tab === 'reports' ? $this->brandReportData($brand) : ['reportSnapshots' => ['items' => [], 'empty' => true, 'demo' => false], 'reportSnapshotDetail' => null]),
        ]);
    }

    /** @return array<string, mixed>|null "What we observed / what we did" for the selected period. */
    private function valueStory(Brand $brand): ?array
    {
        $bounds = DemoPeriod::bounds((string) ($this->period ?: 'last_28'), $this->periodStart, $this->periodEnd);
        $start = ($this->periodStart && $this->periodEnd) ? $this->periodStart : $bounds['start']->toDateString();
        $end = ($this->periodStart && $this->periodEnd) ? $this->periodEnd : $bounds['end']->toDateString();

        return app(ClientValueStoryReadService::class)->forBrand($brand, $start, $end)?->toPresentationArray();
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
