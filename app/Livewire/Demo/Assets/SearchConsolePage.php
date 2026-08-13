<?php

namespace App\Livewire\Demo\Assets;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Models\DigitalAsset;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistReadService;
use App\Services\Gsc\Support\GscBindingMode;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Search Console')]
class SearchConsolePage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = DemoCatalog::GSC_ASSET_ID;

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $demand_sub = 'clusters';

    #[Url]
    public string $index_sub = 'coverage';

    #[Url]
    public string $ops = 'findings';

    #[Url]
    public string $metric = 'clicks';

    #[Url]
    public ?string $attention = null;

    #[Url]
    public ?string $cluster = null;

    #[Url]
    public ?string $page = null;

    #[Url]
    public ?string $finding = null;

    #[Url]
    public ?string $url = null;

    /**
     * @var list<string>
     */
    public array $allowedTabs = [
        'overview',
        'performance',
        'demand',
        'pages',
        'indexing',
        'operations',
    ];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'relationships' => 'overview',
        'queries' => 'demand',
        'countries' => 'performance',
        'devices' => 'performance',
        'sitemaps' => 'indexing',
        'url_inspection' => 'indexing',
        'search_performance' => 'performance',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->assetId = $assetId ?: DemoCatalog::GSC_ASSET_ID;
        $this->mountPeriod();
        $this->normalizeTab();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
        $this->closeDrawers();
    }

    public function setDemandSub(string $sub): void
    {
        if (in_array($sub, ['clusters', 'queries', 'momentum', 'ownership'], true)) {
            $this->demand_sub = $sub;
            $this->tab = 'demand';
        }
    }

    public function setIndexSub(string $sub): void
    {
        if (in_array($sub, ['coverage', 'inspection', 'sitemaps', 'reconciliation'], true)) {
            $this->index_sub = $sub;
            $this->tab = 'indexing';
        }
    }

    public function setOps(string $ops): void
    {
        if (in_array($ops, ['findings', 'recommendations', 'tasks', 'outcomes'], true)) {
            $this->ops = $ops;
            $this->tab = 'operations';
        }
    }

    public function setMetric(string $metric): void
    {
        if (in_array($metric, ['clicks', 'impressions', 'ctr', 'position'], true)) {
            $this->metric = $metric;
        }
    }

    public function openAttention(string $id): void
    {
        $this->attention = $id;
        $this->cluster = null;
        $this->page = null;
        $this->finding = null;
        $this->url = null;
    }

    public function openCluster(string $id): void
    {
        $this->cluster = $id;
        $this->tab = 'demand';
        $this->demand_sub = 'clusters';
        $this->attention = null;
        $this->page = null;
        $this->finding = null;
        $this->url = null;
    }

    public function openPage(string $id): void
    {
        $this->page = $id;
        $this->tab = 'pages';
        $this->attention = null;
        $this->cluster = null;
        $this->finding = null;
        $this->url = null;
    }

    public function openUrl(string $id): void
    {
        $this->url = $id;
        $this->tab = 'indexing';
        $this->index_sub = 'inspection';
        $this->attention = null;
    }

    public function openFinding(string $id): void
    {
        $this->finding = $id;
        $this->ops = 'findings';
        $this->tab = 'operations';
        $this->attention = null;
    }

    public function closeDrawers(): void
    {
        $this->attention = null;
        $this->cluster = null;
        $this->page = null;
        $this->finding = null;
        $this->url = null;
    }

    public function refreshData(): void
    {
        $binding = app(GscSpecialistBindingResolver::class)->resolve($this->assetId);

        if ($binding->mode !== GscBindingMode::RealBound) {
            DemoState::flash('Search Console data refresh queued (Demo Mode · no live Search Console API expansion).', 'info');

            return;
        }

        $asset = DigitalAsset::query()->find($binding->digitalAssetId);
        if (! $asset instanceof DigitalAsset) {
            DemoState::flash('Search Console refresh unavailable — Digital Asset not found.', 'warning');

            return;
        }

        $result = app(StartIncrementalCollectionService::class)->startForBindingIds(
            [$binding->coreAssetBindingId],
            auth()->user(),
            ['SEARCH_CONSOLE'],
        );

        DemoState::flash(match ($result->outcome) {
            'started' => 'Search Console incremental collection started in the background.',
            'active_equivalent' => 'An equivalent Search Console incremental collection is already running.',
            'data_current' => 'Search Console data is current — no incremental collection is due.',
            default => $result->message,
        }, $result->outcome === 'started' ? 'success' : 'info');
    }

    public function runAnalysis(): void
    {
        DemoState::flash('Organic demand analysis completed (Demo Mode · deterministic fixtures).', 'info');
        $this->tab = 'overview';
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];
            if ($legacy === 'sitemaps') {
                $this->index_sub = 'sitemaps';
            }
            if ($legacy === 'url_inspection') {
                $this->index_sub = 'inspection';
            }
            if ($legacy === 'queries') {
                $this->demand_sub = 'queries';
            }
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }

        if (! in_array($this->metric, ['clicks', 'impressions', 'ctr', 'position'], true)) {
            $this->metric = 'clicks';
        }
    }

    public function render(): View
    {
        $this->normalizeTab();
        $data = app(GscSpecialistReadService::class)->workspace($this->assetId, $this->period, $this->periodStart, $this->periodEnd);

        $selectedAttention = $this->attention
            ? collect($data['needs_attention'])->firstWhere('id', $this->attention)
            : null;
        $selectedCluster = $this->cluster
            ? collect($data['demand']['clusters'] ?? [])->firstWhere('id', $this->cluster)
            : null;
        $pageRows = $data['pages']['directory'] ?? $data['page_pulse'] ?? [];
        $selectedPage = $this->page
            ? collect($pageRows)->firstWhere('id', $this->page)
                ?? collect($pageRows)->firstWhere('path', $this->page)
            : null;
        $selectedUrl = $this->url
            ? collect($data['indexing']['urls'] ?? [])->firstWhere('id', $this->url)
                ?? collect($data['indexing']['urls'] ?? [])->firstWhere('path', $this->url)
            : null;
        $selectedFinding = null;
        if ($this->finding) {
            $selectedFinding = collect($data['operations']['findings'])->firstWhere('id', $this->finding);
            $detail = $data['operations']['finding_detail'][$this->finding] ?? null;
            if ($selectedFinding && $detail) {
                $selectedFinding = array_merge($selectedFinding, $detail);
            }
        }

        // metric_series is always supplied by GscSpecialistReadService (real / demo / unavailable).
        // Never fall back to fixtures here — that would mix Demo series into a real-bound workspace.
        $allSeries = $data['metric_series'] ?? [
            'labels' => [],
            'clicks' => [],
            'impressions' => [],
            'ctr' => [],
            'position' => [],
        ];
        $metricLabels = [
            'clicks' => 'Clicks',
            'impressions' => 'Impressions',
            'ctr' => 'CTR',
            'position' => 'Average position',
        ];
        $metricKey = $this->metric;
        $metricLabel = $metricLabels[$metricKey] ?? 'Clicks';
        $metricValues = $allSeries[$metricKey] ?? $allSeries['clicks'];
        if ($metricKey === 'ctr') {
            $metricValues = array_map(static fn (float $v): float => round($v * 100, 2), $metricValues);
        }

        return view('livewire.demo.search-console.overview', [
            'asset' => DemoCatalog::asset($this->assetId),
            'data' => $data,
            'identity' => $data['identity'],
            'selectedAttention' => $selectedAttention,
            'selectedCluster' => $selectedCluster,
            'selectedPage' => $selectedPage,
            'selectedUrl' => $selectedUrl,
            'selectedFinding' => $selectedFinding,
            'showPeriodBar' => in_array($this->tab, ['overview', 'performance', 'demand', 'pages', 'operations'], true),
            'performanceChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 220, 'toolbar' => ['show' => false]],
                'series' => [
                    ['name' => $metricLabel, 'data' => $metricValues],
                ],
                'xaxis' => ['categories' => $allSeries['labels']],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'colors' => ['#2563eb'],
                'legend' => ['show' => false],
                'yaxis' => [
                    ['title' => ['text' => $metricLabel]],
                ],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
