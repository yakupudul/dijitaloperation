<?php

namespace App\Livewire\Demo\Website;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\ConnectorWorkspaceFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\WebsiteWorkspaceFixtures;
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

    public string $assetId = DemoCatalog::WEBSITE_ASSET_ID;

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
    public ?string $finding = null;

    #[Url]
    public ?string $page = null;

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'health',
        'visibility',
        'content',
        'performance',
        'infrastructure',
        'connections',
        'activity',
        'settings',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'technical' => 'health',
        'search' => 'visibility',
        'pages' => 'performance',
        'conversions' => 'performance',
        'lifecycle' => 'settings',
        'insights' => 'overview',
        'domain' => 'infrastructure',
        'hosting' => 'infrastructure',
    ];

    /**
     * @var list<string>
     */
    public array $timeBasedTabs = [
        'overview',
        'visibility',
        'content',
        'performance',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::WEBSITE_ASSET_ID;
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
            $this->tab = 'activity';
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

    public function refreshData(): void
    {
        DemoState::flash('Website data refresh queued (Demo Mode · free sources only · DataForSEO not triggered).', 'info');
    }

    public function runDiagnosis(): void
    {
        DemoState::flash('Website diagnosis started (Demo Mode · deterministic catalog checks).', 'info');
        $this->tab = 'health';
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
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    public function render(): View
    {
        $this->normalizeTab();
        $data = WebsiteWorkspaceFixtures::workspace($this->period);

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

        $asset = DemoCatalog::asset($this->assetId) ?? DemoCatalog::asset(DemoCatalog::WEBSITE_ASSET_ID);

        return view('livewire.demo.website.overview', [
            'asset' => $asset,
            'data' => $data,
            'identity' => $data['identity'],
            'healthFindings' => $healthFindings->values()->all(),
            'selectedFinding' => $selectedFinding,
            'contentDirectory' => $directory->values()->all(),
            'selectedPage' => $selectedPage,
            'activityRows' => $activity->values()->all(),
            'infrastructure' => ConnectorWorkspaceFixtures::websiteInfrastructure(),
            'showPeriodBar' => in_array($this->tab, $this->timeBasedTabs, true),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
