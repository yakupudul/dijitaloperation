<?php

namespace App\Livewire\Demo\Gbp;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\GbpWorkspaceFixtures;
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

    public string $assetId = DemoCatalog::GBP_ASSET_ID;

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
        $this->assetId = $assetId ?: DemoCatalog::GBP_ASSET_ID;
        $this->mountPeriod();
        $this->normalizeTab();

        if ($this->keyword === '') {
            $stored = DemoState::getFilter('gbp_keyword');
            $this->keyword = is_string($stored) && $stored !== ''
                ? $stored
                : GbpWorkspaceFixtures::visibility()['default_keyword'];
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
        DemoState::flash('GBP data refresh queued (Demo Mode · no live Google Business Profile API call).', 'info');
    }

    public function runLocalVisibilityScan(): void
    {
        DemoState::flash('Local visibility scan completed (Demo Mode · deterministic fixture timestamps updated in presentation only).', 'info');
        $this->tab = 'visibility';
        $this->scan = 'latest';
    }

    public function createReviewTask(string $reviewId): void
    {
        DemoState::flash('Internal Task created for review '.$reviewId.' (Demo Mode · no Google reply).', 'info');
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
        $data = GbpWorkspaceFixtures::workspace($this->period);
        $visibility = $data['visibility'];
        $keywords = $visibility['keywords'];

        if (! in_array($this->keyword, $keywords, true)) {
            $this->keyword = $visibility['default_keyword'];
        }

        $scanBundle = $visibility['scans'][$this->keyword];
        $currentScan = $scanBundle['current'];
        $previousScanMeta = $scanBundle['previous'];
        $points = $currentScan['points'];

        if ($this->scan === 'previous') {
            $points = collect($points)->map(function (array $p) use ($previousScanMeta): array {
                $p['rank'] = $p['previous_rank'];
                $p['scan_at'] = $previousScanMeta['scanned_at'];
                $p['delta'] = 0;

                return $p;
            })->all();
            $ranks = array_column($points, 'rank');
            $currentScan = [
                ...$currentScan,
                'scanned_at' => $previousScanMeta['scanned_at'],
                'average_rank' => $previousScanMeta['average_rank'],
                'top3_count' => count(array_filter($ranks, fn (int $r): bool => $r <= 3)),
                'top10_count' => count(array_filter($ranks, fn (int $r): bool => $r <= 10)),
                'best' => min($ranks),
                'worst' => max($ranks),
                'points' => $points,
            ];
        }

        $selectedPoint = null;
        if ($this->point) {
            $selectedPoint = collect($points)->firstWhere('id', $this->point);
        }

        $mapMode = ($this->scan_compare || $this->vis_mode === 'change') && $this->scan !== 'previous' ? 'change' : 'rank';

        $mapPayload = [
            'mode' => $mapMode,
            'business' => [
                'name' => $visibility['business']['name'],
                'lat' => $visibility['business']['lat'],
                'lng' => $visibility['business']['lng'],
                'address' => $visibility['business']['label'],
            ],
            'points' => collect($points)->map(fn (array $p): array => [
                'id' => $p['id'],
                'lat' => $p['lat'],
                'lng' => $p['lng'],
                'rank' => $p['rank'],
                'delta' => $p['delta'],
                'label' => $p['direction'].' · '.$p['distance_km'].' km',
            ])->values()->all(),
        ];

        $miniMapPayload = [
            'mode' => 'rank',
            'business' => $mapPayload['business'],
            'points' => $mapPayload['points'],
        ];

        $queryRows = collect($data['performance']['queries']['rows'] ?? []);
        if ($this->query_filter === 'Tracked') {
            $queryRows = $queryRows->where('tracked', true);
        } elseif ($this->query_filter === 'Website gap') {
            $queryRows = $queryRows->where('website', 'Missing');
        } elseif ($this->query_filter === 'Growing') {
            $queryRows = $queryRows->filter(fn (array $r): bool => str_starts_with((string) $r['change'], '+'));
        } elseif ($this->query_filter === 'Declining') {
            $queryRows = $queryRows->filter(fn (array $r): bool => str_starts_with((string) $r['change'], '−') || str_starts_with((string) $r['change'], '-'));
        } elseif ($this->query_filter !== 'all') {
            $queryRows = $queryRows->where('intent', $this->query_filter);
        }

        $inbox = collect($data['reviews']['inbox'] ?? []);
        if ($this->review_stars !== 'all') {
            $inbox = $inbox->where('stars', (int) $this->review_stars);
        }
        if ($this->review_reply !== 'all') {
            $inbox = $inbox->where('reply', $this->review_reply);
        }
        if ($this->review_topic !== '') {
            $topic = $this->review_topic;
            $inbox = $inbox->filter(fn (array $r): bool => in_array($topic, $r['topics'] ?? [], true));
        }
        if ($this->review_q !== '') {
            $q = mb_strtolower($this->review_q);
            $inbox = $inbox->filter(fn (array $r): bool => str_contains(mb_strtolower(($r['excerpt'] ?? '').' '.($r['reviewer'] ?? '')), $q));
        }

        $selectedFinding = null;
        if ($this->finding) {
            $selectedFinding = collect($data['operations']['findings'] ?? [])->firstWhere('id', $this->finding);
            $detail = $data['operations']['finding_detail'][$this->finding] ?? null;
            if ($selectedFinding && $detail) {
                $selectedFinding = array_merge($selectedFinding, $detail);
            }
        }

        $selectedAttention = null;
        if ($this->attention) {
            $selectedAttention = collect($data['needs_attention'] ?? [])->firstWhere('id', $this->attention);
        }

        $discovery = $data['performance']['discovery'];
        $actions = $data['performance']['actions'];

        $asset = DemoCatalog::asset($this->assetId) ?? DemoCatalog::asset(DemoCatalog::GBP_ASSET_ID);

        return view('livewire.demo.gbp.overview', [
            'asset' => $asset,
            'data' => $data,
            'identity' => $data['identity'],
            'visibility' => $visibility,
            'currentScan' => $currentScan,
            'previousScan' => $previousScanMeta,
            'points' => $points,
            'selectedPoint' => $selectedPoint,
            'mapPayload' => $mapPayload,
            'miniMapPayload' => $miniMapPayload,
            'queryRows' => $queryRows->values()->all(),
            'reviewInbox' => $inbox->values()->all(),
            'selectedFinding' => $selectedFinding,
            'selectedAttention' => $selectedAttention,
            'showPeriodBar' => in_array($this->tab, $this->timeBasedTabs, true),
            'discoveryChartOptions' => [
                'chart' => ['type' => 'area', 'height' => 240, 'toolbar' => ['show' => false], 'stacked' => false],
                'series' => [
                    ['name' => 'Search impressions', 'data' => $discovery['series_search']['values']],
                    ['name' => 'Maps impressions', 'data' => $discovery['series_maps']['values']],
                ],
                'xaxis' => ['categories' => $discovery['series_search']['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c', '#0284c7'],
                'legend' => ['position' => 'top'],
                'fill' => [
                    'type' => 'gradient',
                    'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.3, 'opacityTo' => 0.05],
                ],
            ],
            'actionsChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 240, 'toolbar' => ['show' => false]],
                'series' => [['name' => 'Customer actions', 'data' => $actions['series']['values']]],
                'xaxis' => ['categories' => $actions['series']['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#ea580c'],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
