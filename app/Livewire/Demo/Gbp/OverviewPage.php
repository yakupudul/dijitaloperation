<?php

namespace App\Livewire\Demo\Gbp;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Support\Demo\DemoState;
use App\Support\Reality\UnavailableWorkspaceShells;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Business Profile')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $keyword = '';

    #[Url]
    public string $scan = 'latest';

    #[Url]
    public bool $scan_compare = false;

    #[Url]
    public string $vis_mode = 'rank';

    #[Url]
    public string $perf_sub = 'discovery';

    #[Url]
    public string $query_period = 'Last month';

    #[Url]
    public string $query_filter = 'all';

    #[Url]
    public string $reviews_sub = 'inbox';

    #[Url]
    public string $review_stars = 'all';

    #[Url]
    public string $review_reply = 'all';

    #[Url]
    public string $review_topic = '';

    #[Url]
    public string $review_q = '';

    #[Url]
    public string $ops = 'findings';

    #[Url]
    public ?string $finding = null;

    #[Url]
    public ?string $point = null;

    #[Url(as: 'attention')]
    public ?string $attention = null;

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'profile',
        'visibility',
        'performance',
        'reviews',
        'competitors',
        'operations',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'queries' => 'performance',
        'insights' => 'overview',
    ];

    /**
     * @var list<string>
     */
    public array $timeBasedTabs = [
        'performance',
        'reviews',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['google_business_profile', 'gbp']);
        $this->mountPeriod();
        $this->normalizeTab();

        if ($this->keyword === '') {
            $stored = DemoState::getFilter('gbp_keyword');
            if (is_string($stored) && $stored !== '') {
                $this->keyword = $stored;
            }
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
        $this->finding = null;
        $this->point = null;
    }

    public function setKeyword(string $keyword): void
    {
        $this->keyword = $keyword;
        DemoState::setFilter('gbp_keyword', $keyword);
        $this->point = null;
        $this->tab = 'visibility';
    }

    public function updatedKeyword(string $value): void
    {
        DemoState::setFilter('gbp_keyword', $value);
        $this->point = null;
    }

    public function setScan(string $scan): void
    {
        if (in_array($scan, ['latest', 'previous'], true)) {
            $this->scan = $scan;
        }
    }

    public function toggleScanCompare(): void
    {
        $this->scan_compare = ! $this->scan_compare;
    }

    public function setVisMode(string $mode): void
    {
        if (in_array($mode, ['rank', 'change'], true)) {
            $this->vis_mode = $mode;
        }
    }

    public function setPerfSub(string $sub): void
    {
        if (in_array($sub, ['discovery', 'actions', 'queries'], true)) {
            $this->perf_sub = $sub;
            $this->tab = 'performance';
        }
    }

    public function setQueryFilter(string $filter): void
    {
        $allowed = ['all', 'Brand', 'Service', 'Local service', 'Location', 'Discovery', 'Growing', 'Declining', 'Website gap', 'Tracked'];
        if (in_array($filter, $allowed, true)) {
            $this->query_filter = $filter;
            $this->perf_sub = 'queries';
            $this->tab = 'performance';
        }
    }

    public function setReviewsSub(string $sub): void
    {
        if (in_array($sub, ['inbox', 'topics', 'queue'], true)) {
            $this->reviews_sub = $sub;
            $this->tab = 'reviews';
        }
    }

    public function setOps(string $ops): void
    {
        if (in_array($ops, ['findings', 'recommendations', 'tasks', 'outcomes'], true)) {
            $this->ops = $ops;
            $this->tab = 'operations';
        }
    }

    public function selectPoint(string $id): void
    {
        $this->point = $id;
        $this->tab = 'visibility';
    }

    public function clearPoint(): void
    {
        $this->point = null;
    }

    public function openFinding(string $id): void
    {
        $this->finding = $id;
        $this->attention = null;
        $this->ops = 'findings';
        $this->tab = 'operations';
    }

    public function closeFinding(): void
    {
        $this->finding = null;
    }

    public function openAttention(string $id): void
    {
        $this->attention = $id;
    }

    public function closeAttention(): void
    {
        $this->attention = null;
    }

    public function refreshData(): void
    {
        DemoState::flash('GBP data refresh is unavailable until the integration is configured.', 'info');
    }

    public function runLocalVisibilityScan(): void
    {
        DemoState::flash('Local visibility scan is unavailable until a rank provider is configured.', 'info');
        $this->tab = 'visibility';
        $this->scan = 'latest';
    }

    public function createReviewTask(string $reviewId): void
    {
        DemoState::flash('Internal Task for review '.$reviewId.' was not created — Google reply is not available.', 'info');
        $this->reviews_sub = 'queue';
        $this->tab = 'reviews';
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];
            if ($legacy === 'queries') {
                $this->perf_sub = 'queries';
            }
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    public function render(): View
    {
        $this->normalizeTab();

        $data = UnavailableWorkspaceShells::gbp($this->assetId);
        $emptySeries = ['labels' => [], 'values' => []];

        return view('livewire.demo.gbp.overview', [
            'asset' => [
                'id' => $this->assetId,
                'name' => $data['identity']['title'] ?? 'GBP',
                'type' => 'gbp',
            ],
            'data' => $data,
            'identity' => $data['identity'],
            'visibility' => array_merge([
                'subtitle' => 'Local visibility has not been measured for this production GBP.',
                'keywords' => [],
                'default_keyword' => '',
                'scans' => [],
                'coverage_regions' => [],
                'comparison' => [],
                'opportunities' => [],
                'business' => ['name' => null, 'lat' => null, 'lng' => null, 'label' => null],
                'note' => 'Local visibility grid is unavailable — no fabricated rankings.',
            ], is_array($data['visibility'] ?? null) ? $data['visibility'] : []),
            'currentScan' => [
                'points' => [],
                'scanned_at' => '—',
                'average_rank' => '—',
                'top3_count' => 0,
                'top10_count' => 0,
                'best' => '—',
                'worst' => '—',
                'grid' => '—',
                'radius' => '—',
                'source' => 'Not collected',
                'weakness' => 'No local visibility scan has been collected.',
            ],
            'previousScan' => [
                'points' => [],
                'scanned_at' => '—',
                'average_rank' => '—',
                'top3_count' => 0,
                'top10_count' => 0,
                'best' => '—',
                'worst' => '—',
                'grid' => '—',
                'radius' => '—',
                'source' => 'Not collected',
                'weakness' => 'No previous scan exists.',
            ],
            'points' => [],
            'selectedPoint' => null,
            'mapPayload' => ['mode' => 'rank', 'business' => ['name' => null, 'lat' => null, 'lng' => null, 'address' => null], 'points' => []],
            'miniMapPayload' => ['mode' => 'rank', 'business' => ['name' => null, 'lat' => null, 'lng' => null, 'address' => null], 'points' => []],
            'queryRows' => [],
            'reviewInbox' => [],
            'selectedFinding' => null,
            'selectedAttention' => null,
            'showPeriodBar' => in_array($this->tab, $this->timeBasedTabs, true),
            'discoveryChartOptions' => [
                'chart' => ['type' => 'area', 'height' => 240, 'toolbar' => ['show' => false], 'stacked' => false],
                'series' => [],
                'xaxis' => ['categories' => []],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c', '#0284c7'],
                'legend' => ['position' => 'top'],
            ],
            'actionsChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 240, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Customer actions', 'data' => []]],
                'xaxis' => ['categories' => []],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c'],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
