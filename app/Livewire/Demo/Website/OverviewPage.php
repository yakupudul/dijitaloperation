<?php

namespace App\Livewire\Demo\Website;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;
use App\Support\Reality\UnavailableWorkspaceShells;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Website')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $health_group = 'all';

    #[Url]
    public string $severity = 'all';

    #[Url]
    public string $vis_lens = 'organic';

    #[Url]
    public string $perf_sub = 'search';

    #[Url]
    public string $content_q = '';

    #[Url]
    public string $content_role = '';

    #[Url]
    public string $content_cms = '';

    #[Url]
    public string $activity_filter = 'all';

    #[Url]
    public string $ops = 'findings';

    #[Url]
    public string $setup_section = 'connection';

    #[Url]
    public ?string $finding = null;

    #[Url]
    public ?string $page = null;

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
        'health',
        'visibility',
        'content',
        'performance',
        'infrastructure',
        'operations',
        'setup',
    ];

    /** @var array<string, string> */
    private const LEGACY_TAB_MAP = [
        'technical' => 'health',
        'search' => 'visibility',
        'pages' => 'performance',
        'conversions' => 'performance',
        'lifecycle' => 'setup',
        'insights' => 'overview',
        'domain' => 'infrastructure',
        'hosting' => 'infrastructure',
        'connections' => 'setup',
        'settings' => 'setup',
        'activity' => 'operations',
    ];

    /** @var list<string> */
    public array $timeBasedTabs = [
        'overview',
        'visibility',
        'content',
        'performance',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['website']);
        $this->mountPeriod();
        $this->normalizeTab();

        $stored = DemoState::getFilter('website_issue_severity');
        if (is_string($stored) && $stored !== '') {
            $this->severity = $stored;
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
        $this->finding = null;
        $this->page = null;
    }

    public function setHealthGroup(string $group): void
    {
        $this->health_group = $group;
        $this->tab = 'health';
    }

    public function setSeverity(string $severity): void
    {
        $allowed = ['all', 'high', 'medium', 'low', 'info'];
        if (! in_array($severity, $allowed, true)) {
            return;
        }

        $this->severity = $severity;
        DemoState::setFilter('website_issue_severity', $severity === 'all' ? null : $severity);
        $this->tab = 'health';
    }

    public function setVisLens(string $lens): void
    {
        if (in_array($lens, ['organic', 'local', 'ai'], true)) {
            $this->vis_lens = $lens;
            $this->tab = 'visibility';
        }
    }

    public function setPerfSub(string $sub): void
    {
        if (in_array($sub, ['search', 'acquisition', 'landing', 'conversions', 'outcome'], true)) {
            $this->perf_sub = $sub;
            $this->tab = 'performance';
        }
    }

    public function setActivityFilter(string $filter): void
    {
        $allowed = ['all', 'collection', 'diagnosis', 'seo', 'discovery', 'ai', 'operator', 'failure'];
        if (in_array($filter, $allowed, true)) {
            $this->activity_filter = $filter;
        }
    }

    public function setOps(string $ops): void
    {
        if (in_array($ops, ['findings', 'recommendations', 'tasks', 'outcomes'], true)) {
            $this->ops = $ops;
            $this->tab = 'operations';
        }
    }

    public function setSetupSection(string $section): void
    {
        if (in_array($section, ['connection', 'configuration'], true)) {
            $this->setup_section = $section;
            $this->tab = 'setup';
        }
    }

    public function openFinding(string $id): void
    {
        $this->finding = $id;
        $this->tab = 'health';
    }

    public function closeFinding(): void
    {
        $this->finding = null;
    }

    public function openContentPage(string $id): void
    {
        $this->page = $id;
        $this->tab = 'content';
    }

    public function closeContentPage(): void
    {
        $this->page = null;
    }

    public function refreshData(AsyncOperationService $async): void
    {
        $result = $async->queueBoundCollect($this->websiteAsset(), auth()->user());
        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
    }

    public function runDiagnosis(AsyncOperationService $async): void
    {
        $result = $async->queueWebsiteDiagnosis($this->websiteAsset(), auth()->user());
        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
        $this->tab = 'health';
    }

    public function refreshSeoIntelligence(AsyncOperationService $async): void
    {
        $result = $async->queueSeoIntelligenceRefresh($this->websiteAsset(), auth()->user());
        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
        $this->tab = 'visibility';
    }

    public function generateAiGuidance(AsyncOperationService $async): void
    {
        $result = $async->queueWebsiteAiGuidance($this->websiteAsset(), auth()->user());
        DemoState::flash($result['message'], $result['ok'] ? 'success' : 'info');
        $this->tab = 'overview';
    }

    public function clearContentFilters(): void
    {
        $this->content_q = '';
        $this->content_role = '';
        $this->content_cms = '';
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];
            if ($legacy === 'conversions') {
                $this->perf_sub = 'conversions';
            }
            if ($legacy === 'pages') {
                $this->perf_sub = 'landing';
            }
            if ($legacy === 'search') {
                $this->vis_lens = 'organic';
            }
            if ($legacy === 'settings') {
                $this->setup_section = 'configuration';
            }
            if ($legacy === 'connections') {
                $this->setup_section = 'connection';
            }
            if ($legacy === 'activity') {
                $this->ops = 'findings';
            }
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }

        if (! in_array($this->ops, ['findings', 'recommendations', 'tasks', 'outcomes'], true)) {
            $this->ops = 'findings';
        }

        if (! in_array($this->setup_section, ['connection', 'configuration'], true)) {
            $this->setup_section = 'connection';
        }
    }

    public function render(): View
    {
        $this->normalizeTab();

        // The legacy specialist shell still backs the old presentation tabs while
        // real engine surfaces are migrated incrementally. Operator actions above
        // now execute the canonical async services rather than Demo-only flashes.
        $data = UnavailableWorkspaceShells::website($this->assetId);

        $healthFindings = collect($data['health']['findings'] ?? []);
        if ($this->health_group !== 'all') {
            $healthFindings = $healthFindings->where('group', $this->health_group);
        }
        if ($this->severity !== 'all') {
            $healthFindings = $healthFindings->where('severity', $this->severity);
        }

        $selectedFinding = null;
        if ($this->finding) {
            $selectedFinding = collect($data['health']['findings'] ?? [])->firstWhere('id', $this->finding);
        }

        $directory = collect($data['content_workspace']['directory'] ?? []);
        if ($this->content_q !== '') {
            $q = mb_strtolower($this->content_q);
            $directory = $directory->filter(function (array $row) use ($q): bool {
                return str_contains(mb_strtolower(($row['title'] ?? '').' '.($row['url'] ?? '').' '.($row['topic'] ?? '')), $q);
            });
        }
        if ($this->content_role !== '') {
            $directory = $directory->filter(fn (array $row): bool => ($row['role'] ?? '') === $this->content_role);
        }
        if ($this->content_cms !== '') {
            $directory = $directory->filter(fn (array $row): bool => ($row['cms_type'] ?? '') === $this->content_cms);
        }

        $selectedPage = null;
        if ($this->page) {
            $selectedPage = collect($data['content_workspace']['directory'] ?? [])->firstWhere('id', $this->page);
        }

        $activity = collect($data['activity'] ?? []);
        if ($this->activity_filter !== 'all') {
            $activity = $activity->where('category', $this->activity_filter);
        }

        $asset = $this->presentCanonicalAsset();
        $opsFindings = collect($data['health']['findings'] ?? [])->values()->all();
        $opsRecommendations = [];
        $opsTasks = [];
        $opsOutcomes = collect($data['recent_outcomes'] ?? [])->values()->all();

        return view('livewire.demo.website.overview', [
            'asset' => $asset,
            'data' => $data,
            'identity' => $data['identity'],
            'healthFindings' => $healthFindings->values()->all(),
            'selectedFinding' => $selectedFinding,
            'contentDirectory' => $directory->values()->all(),
            'selectedPage' => $selectedPage,
            'activityRows' => $activity->values()->all(),
            'opsFindings' => $opsFindings,
            'opsRecommendations' => $opsRecommendations,
            'opsTasks' => $opsTasks,
            'opsOutcomes' => $opsOutcomes,
            'infrastructure' => $data['infrastructure'] ?? [],
            'showPeriodBar' => in_array($this->tab, $this->timeBasedTabs, true),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    private function websiteAsset(): DigitalAsset
    {
        return DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'website')
            ->firstOrFail();
    }
}
