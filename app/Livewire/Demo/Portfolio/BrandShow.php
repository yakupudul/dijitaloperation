<?php

namespace App\Livewire\Demo\Portfolio;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Brand')]
class BrandShow extends Component
{
    public string $brand = DemoCatalog::BRAND_ID;

    #[Url]
    public string $tab = 'overview';

    /**
     * @var list<string>
     */
    private const AI_PRIORITY_RECOMMENDATION_IDS = [
        'r-replace-creative',
        'r-fix-lcp',
        'r-negatives',
        'r-map-relevance',
    ];

    public function mount(string $brand): void
    {
        $this->brand = $brand;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    public function runPublicResearch(): void
    {
        DemoState::startPublicResearch();
        $this->tab = 'research';
    }

    public function runAiBrief(): void
    {
        DemoState::showAiBrief();
        $this->tab = 'ai';
    }

    public function createRecommendationFromPriority(int $index): void
    {
        $analysis = DemoCatalog::brandAiAnalysis();
        $priority = $analysis['priorities'][$index] ?? null;

        if (! is_string($priority) || $priority === '') {
            return;
        }

        $recommendationId = self::AI_PRIORITY_RECOMMENDATION_IDS[$index] ?? null;
        DemoState::createRecommendationFromAiPriority($priority, $recommendationId);
        $this->tab = 'recommendations';
    }

    public function createTaskFromRecommendation(string $recommendationId): void
    {
        DemoState::createTaskFromRecommendation($recommendationId);
        $this->tab = 'tasks';
    }

    public function render(): View
    {
        $state = DemoState::all();
        $brandRow = collect($state['brands'])->firstWhere('id', $this->brand) ?? DemoCatalog::brand();
        $assets = collect(DemoCatalog::assets())
            ->filter(fn (array $asset): bool => ($asset['brand_id'] ?? null) === $this->brand || $this->brand === DemoCatalog::BRAND_ID)
            ->values()
            ->all();

        $findings = collect(DemoCatalog::findings())
            ->filter(function (array $finding) use ($brandRow): bool {
                return ($finding['brand'] ?? '') === ($brandRow['name'] ?? '');
            })
            ->values()
            ->all();

        $lifecycleAssets = collect($assets)
            ->filter(fn (array $asset): bool => in_array($asset['type'] ?? '', ['domain', 'hosting'], true))
            ->values()
            ->all();

        $openTasks = collect($state['tasks'])
            ->where('status', '!=', 'completed')
            ->values()
            ->all();

        $summaryKpis = [
            [
                'label' => 'Media spend',
                'value' => $brandRow['summary']['media_spend'] ?? 0,
                'format' => 'try',
                'tone' => 'neutral',
                'family' => 'spend',
            ],
            [
                'label' => 'Platform leads',
                'value' => $brandRow['summary']['platform_leads'] ?? 0,
                'format' => 'int',
                'tone' => 'neutral',
                'family' => 'result',
            ],
            [
                'label' => 'Website leads',
                'value' => $brandRow['summary']['website_leads'] ?? 0,
                'format' => 'int',
                'tone' => 'neutral',
                'family' => 'result',
            ],
            [
                'label' => 'Calls / messages',
                'value' => $brandRow['summary']['calls_messages'] ?? 0,
                'format' => 'int',
                'tone' => 'neutral',
                'family' => 'delivery',
            ],
        ];

        $analysis = DemoCatalog::brandAiAnalysis();

        return view('livewire.demo.portfolio.brand-show', [
            'brandRow' => $brandRow,
            'assets' => $assets,
            'findings' => $findings,
            'recommendations' => $state['recommendations'],
            'tasks' => $state['tasks'],
            'openTasks' => $openTasks,
            'attention' => DemoCatalog::brandAttention(),
            'timeline' => DemoCatalog::decisionTimeline(),
            'lifecycleAssets' => $lifecycleAssets,
            'summaryKpis' => $summaryKpis,
            'priorities' => $analysis['priorities'],
            'research' => $state['public_research'],
            'aiBrief' => $state['ai_brief_visible'] ? $analysis : null,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
