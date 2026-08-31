<?php

namespace App\Livewire\Demo\Website;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Ga4\WebsiteGa4AnalysisService;
use App\Services\Gsc\WebsiteSearchConsoleAnalysisService;
use App\Services\IntelligenceProjection\Website\WebsitePagesContentReadService;
use App\Services\IntelligenceProjection\Website\WebsiteTechnicalHealthReadService;
use App\Support\Reality\OperatorCanonicalAsset;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Legacy namespace retained temporarily for route compatibility.
 * The component renders canonical production data, not Demo fixtures.
 */
#[Layout('operator.layouts.app')]
#[Title('Website')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $perf_sub = 'search';

    #[Url(as: 'content_search')]
    public string $contentSearch = '';

    #[Url(as: 'content_filter')]
    public string $contentFilter = 'all';

    #[Url(as: 'content_source')]
    public string $contentSource = 'all';

    #[Url(as: 'content_page')]
    public int $contentPage = 1;

    #[Url(as: 'page_profile')]
    public ?int $selectedPageProfileId = null;

    #[Url(as: 'health_search')]
    public string $healthSearch = '';

    #[Url(as: 'health_filter')]
    public string $healthFilter = 'all';

    #[Url(as: 'health_page')]
    public int $healthPage = 1;

    #[Url(as: 'health_profile')]
    public ?int $selectedHealthProfileId = null;

    public string $message = '';

    public string $messageTone = 'info';

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
        'content',
        'health',
        'visibility',
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
        'analytics' => 'performance',
        'ga4' => 'performance',
        'ga4_analysis' => 'performance',
        'gsc' => 'visibility',
        'search-console' => 'visibility',
        'search_console' => 'visibility',
    ];

    public function mount(?string $assetId = null): void
    {
        $asset = OperatorCanonicalAsset::require($assetId, ['website']);
        $this->assetId = (string) $asset->id;
        $this->mountPeriod();
        $this->normalizeTab();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    public function updatedContentSearch(): void
    {
        $this->contentPage = 1;
        $this->selectedPageProfileId = null;
    }

    public function updatedContentFilter(): void
    {
        $this->contentPage = 1;
        $this->selectedPageProfileId = null;
    }

    public function updatedContentSource(): void
    {
        $this->contentPage = 1;
        $this->selectedPageProfileId = null;
    }

    public function setContentPage(int $page): void
    {
        $this->contentPage = max(1, $page);
        $this->selectedPageProfileId = null;
    }

    public function selectPageProfile(int $profileId): void
    {
        $this->selectedPageProfileId = $profileId > 0 ? $profileId : null;
    }

    public function closePageProfile(): void
    {
        $this->selectedPageProfileId = null;
    }

    public function updatedHealthSearch(): void
    {
        $this->healthPage = 1;
        $this->selectedHealthProfileId = null;
    }

    public function updatedHealthFilter(): void
    {
        $this->healthPage = 1;
        $this->selectedHealthProfileId = null;
    }

    public function setHealthPage(int $page): void
    {
        $this->healthPage = max(1, $page);
        $this->selectedHealthProfileId = null;
    }

    public function selectHealthProfile(int $profileId): void
    {
        $this->selectedHealthProfileId = $profileId > 0 ? $profileId : null;
    }

    public function closeHealthProfile(): void
    {
        $this->selectedHealthProfileId = null;
    }

    public function refreshData(AsyncOperationService $async): void
    {
        $messages = [];
        $ok = false;

        try {
            $collectionRun = app(WebsiteCollectionOrchestrator::class)->start($this->asset(), auth()->user());
            $messages[] = __('operator.async.website_production_collection_queued', ['id' => $collectionRun->id]);
            $ok = true;
        } catch (Throwable) {
            $messages[] = __('operator.async.website_production_collection_unavailable');
        }

        $bound = $async->queueBoundCollect($this->asset(), auth()->user());
        $messages[] = (string) ($bound['message'] ?? '');
        if (($bound['ok'] ?? false) === true) {
            $ok = true;
        }

        $this->showResult([
            'ok' => $ok,
            'message' => trim(implode(' ', array_filter($messages))),
        ]);
    }

    public function runDiagnosis(AsyncOperationService $async): void
    {
        $this->showResult($async->queueWebsiteDiagnosis($this->asset(), auth()->user()));
        $this->tab = 'health';
    }

    public function refreshSeoIntelligence(AsyncOperationService $async): void
    {
        $this->showResult($async->queueSeoIntelligenceRefresh($this->asset(), auth()->user()));
        $this->tab = 'visibility';
    }

    public function generateAiGuidance(AsyncOperationService $async): void
    {
        $this->showResult($async->queueWebsiteAiGuidance($this->asset(), auth()->user()));
        $this->tab = 'overview';
    }

    public function render(
        WebsiteOperatorWorkspace $workspace,
        WebsiteGa4AnalysisService $ga4AnalysisService,
        WebsiteSearchConsoleAnalysisService $gscAnalysisService,
        WebsitePagesContentReadService $pagesContentReadService,
        WebsiteTechnicalHealthReadService $technicalHealthReadService,
    ): View {
        $this->normalizeTab();

        $asset = $this->asset()->loadMissing('brand.customer');
        $data = $workspace->overview(
            $asset,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $ga4Analysis = null;
        $ga4Charts = [];
        if ($this->tab === 'ga4_analysis') {
            $ga4Analysis = $ga4AnalysisService->build(
                $asset,
                $this->period,
                $this->periodStart,
                $this->periodEnd,
                $this->compare,
                $this->compareMode,
            );

            $sessions = $this->ga4MetricValue($ga4Analysis, 'sessions');
            $views = $this->ga4MetricValue($ga4Analysis, 'views');
            $ga4Analysis['secondary_metrics']['pages_per_visit'] = $sessions !== null && $sessions > 0 && $views !== null
                ? $views / $sessions
                : null;

            $ga4Charts = $this->buildGa4Charts($ga4Analysis);
        }

        $gscAnalysis = null;
        $gscCharts = [];
        if ($this->tab === 'search_console') {
            $gscAnalysis = $gscAnalysisService->build(
                $asset,
                $this->period,
                $this->periodStart,
                $this->periodEnd,
                $this->compare,
                $this->compareMode,
            );
            $gscCharts = $this->buildGscCharts($gscAnalysis);
        }

        $pagesContent = $this->tab === 'content'
            ? $pagesContentReadService->workspace(
                asset: $asset,
                search: $this->contentSearch,
                filter: $this->contentFilter,
                source: $this->contentSource,
                page: $this->contentPage,
                selectedProfileId: $this->selectedPageProfileId,
            )
            : null;

        $technicalHealth = $this->tab === 'health'
            ? $technicalHealthReadService->workspace(
                asset: $asset,
                search: $this->healthSearch,
                filter: $this->healthFilter,
                page: $this->healthPage,
                selectedProfileId: $this->selectedHealthProfileId,
            )
            : null;

        return view('livewire.operator.website.overview', [
            'asset' => $asset,
            'brand' => $asset->brand,
            'customer' => $asset->brand?->customer,
            'data' => $data,
            'ga4Analysis' => $ga4Analysis,
            'ga4Charts' => $ga4Charts,
            'gscAnalysis' => $gscAnalysis,
            'gscCharts' => $gscCharts,
            'pagesContent' => $pagesContent,
            'technicalHealth' => $technicalHealth,
            'showPeriodBar' => in_array($this->tab, ['overview', 'ga4_analysis', 'search_console', 'visibility', 'performance'], true),
        ]);
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];

            if ($legacy === 'conversions') {
                $this->perf_sub = 'conversions';
            } elseif ($legacy === 'pages') {
                $this->perf_sub = 'landing';
            }
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    /** @param array{ok: bool, message: string} $result */
    private function showResult(array $result): void
    {
        $this->message = (string) ($result['message'] ?? __('operator_runtime.sources.collect_failed'));
        $this->messageTone = ($result['ok'] ?? false) ? 'success' : 'info';
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'website')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function buildGa4Charts(array &$analysis): array
    {
        $trend = $this->ga4TrendForChart($analysis['trend'] ?? []);
        $analysis['trend']['display_granularity'] = $trend['granularity'];

        $channels = array_values(array_slice($analysis['channels'] ?? [], 0, 6));
        $devices = array_values(array_slice($analysis['devices'] ?? [], 0, 5));
        $countries = array_values(array_slice($analysis['countries'] ?? [], 0, 8));
        $engagement = $this->ga4MetricValue($analysis, 'engagement_rate') ?? 0.0;

        return [
            'trend' => [
                'chart' => [
                    'type' => 'area',
                    'height' => 320,
                    'toolbar' => ['show' => false],
                    'zoom' => ['enabled' => false],
                    'fontFamily' => 'Outfit, sans-serif',
                ],
                'series' => [
                    ['name' => __('website_ga4.sessions'), 'data' => $trend['sessions']],
                    ['name' => __('website_ga4.views'), 'data' => $trend['views']],
                ],
                'xaxis' => [
                    'categories' => $trend['labels'],
                    'tickAmount' => min(6, max(1, count($trend['labels']) - 1)),
                    'labels' => ['rotate' => 0, 'hideOverlappingLabels' => true],
                    'axisBorder' => ['show' => false],
                    'axisTicks' => ['show' => false],
                ],
                'yaxis' => ['min' => 0, 'forceNiceScale' => true],
                'stroke' => ['curve' => 'smooth', 'width' => [2.5, 2]],
                'fill' => [
                    'type' => 'gradient',
                    'gradient' => [
                        'shadeIntensity' => 1,
                        'opacityFrom' => 0.28,
                        'opacityTo' => 0.03,
                        'stops' => [0, 90, 100],
                    ],
                ],
                'colors' => ['#f97316', '#0ea5e9'],
                'markers' => ['size' => 0, 'hover' => ['sizeOffset' => 4]],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'top', 'horizontalAlign' => 'right'],
                'tooltip' => ['shared' => true, 'intersect' => false],
                'grid' => ['strokeDashArray' => 4, 'borderColor' => '#eaecf0'],
            ],
            'channels' => [
                'chart' => ['type' => 'donut', 'height' => 290, 'fontFamily' => 'Outfit, sans-serif'],
                'series' => array_map(static fn (array $row): int => (int) ($row['sessions'] ?? 0), $channels),
                'labels' => array_map(fn (array $row): string => $this->ga4ChannelLabel($row['label'] ?? null), $channels),
                'colors' => ['#f97316', '#0ea5e9', '#10b981', '#8b5cf6', '#f59e0b', '#64748b'],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'bottom', 'fontSize' => '12px'],
                'stroke' => ['width' => 3, 'colors' => ['#ffffff']],
                'plotOptions' => ['pie' => ['donut' => ['size' => '68%']]],
                'tooltip' => ['enabled' => true],
            ],
            'engagement' => [
                'chart' => ['type' => 'radialBar', 'height' => 245, 'sparkline' => ['enabled' => true], 'fontFamily' => 'Outfit, sans-serif'],
                'series' => [round(max(0, min(100, (float) $engagement)), 1)],
                'labels' => [__('website_ga4.engagement_rate')],
                'colors' => ['#10b981'],
                'plotOptions' => [
                    'radialBar' => [
                        'startAngle' => -135,
                        'endAngle' => 135,
                        'hollow' => ['size' => '66%'],
                        'track' => ['background' => '#f2f4f7', 'strokeWidth' => '100%'],
                        'dataLabels' => [
                            'name' => ['show' => true, 'fontSize' => '12px', 'offsetY' => 48],
                            'value' => ['show' => true, 'fontSize' => '28px', 'fontWeight' => 700, 'offsetY' => -6],
                        ],
                    ],
                ],
                'stroke' => ['lineCap' => 'round'],
            ],
            'devices' => [
                'chart' => ['type' => 'donut', 'height' => 260, 'fontFamily' => 'Outfit, sans-serif'],
                'series' => array_map(static fn (array $row): int => (int) ($row['sessions'] ?? 0), $devices),
                'labels' => array_map(fn (array $row): string => $this->ga4DeviceLabel($row['label'] ?? null), $devices),
                'colors' => ['#465fff', '#9cb9ff', '#12b76a', '#f79009', '#7a5af8'],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'bottom', 'fontSize' => '12px'],
                'stroke' => ['width' => 3, 'colors' => ['#ffffff']],
                'plotOptions' => ['pie' => ['donut' => ['size' => '70%']]],
            ],
            'countries' => [
                'chart' => ['type' => 'bar', 'height' => 280, 'toolbar' => ['show' => false], 'fontFamily' => 'Outfit, sans-serif'],
                'series' => [[
                    'name' => __('website_ga4.sessions_col'),
                    'data' => array_map(static fn (array $row): int => (int) ($row['sessions'] ?? 0), $countries),
                ]],
                'xaxis' => [
                    'categories' => array_map(static fn (array $row): string => (string) ($row['label'] ?: '(not set)'), $countries),
                    'axisBorder' => ['show' => false],
                    'axisTicks' => ['show' => false],
                ],
                'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 5, 'barHeight' => '58%']],
                'colors' => ['#0ea5e9'],
                'dataLabels' => ['enabled' => false],
                'grid' => ['strokeDashArray' => 4, 'borderColor' => '#eaecf0'],
                'legend' => ['show' => false],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function buildGscCharts(array &$analysis): array
    {
        $trend = $this->gscTrendForChart($analysis['trend'] ?? []);
        $analysis['trend']['display_granularity'] = $trend['granularity'];
        $devices = array_values(array_slice($analysis['devices'] ?? [], 0, 5));
        $countries = array_values(array_slice($analysis['countries'] ?? [], 0, 8));
        $surfaces = array_values(array_slice($analysis['surfaces'] ?? [], 0, 6));

        return [
            'trend' => [
                'chart' => ['type' => 'area', 'height' => 330, 'toolbar' => ['show' => false], 'zoom' => ['enabled' => false], 'fontFamily' => 'Outfit, sans-serif'],
                'series' => [
                    ['name' => __('website_gsc.clicks'), 'data' => $trend['clicks']],
                    ['name' => __('website_gsc.impressions'), 'data' => $trend['impressions']],
                ],
                'xaxis' => [
                    'categories' => $trend['labels'],
                    'tickAmount' => min(6, max(1, count($trend['labels']) - 1)),
                    'labels' => ['rotate' => 0, 'hideOverlappingLabels' => true],
                    'axisBorder' => ['show' => false],
                    'axisTicks' => ['show' => false],
                ],
                'yaxis' => [
                    ['seriesName' => __('website_gsc.clicks'), 'min' => 0, 'forceNiceScale' => true],
                    ['seriesName' => __('website_gsc.impressions'), 'opposite' => true, 'min' => 0, 'forceNiceScale' => true],
                ],
                'stroke' => ['curve' => 'smooth', 'width' => [2.5, 2]],
                'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.22, 'opacityTo' => 0.02, 'stops' => [0, 90, 100]]],
                'colors' => ['#465fff', '#12b76a'],
                'markers' => ['size' => 0, 'hover' => ['sizeOffset' => 4]],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'top', 'horizontalAlign' => 'right'],
                'tooltip' => ['shared' => true, 'intersect' => false],
                'grid' => ['strokeDashArray' => 4, 'borderColor' => '#eaecf0'],
            ],
            'devices' => [
                'chart' => ['type' => 'donut', 'height' => 270, 'fontFamily' => 'Outfit, sans-serif'],
                'series' => array_map(static fn (array $row): int => (int) ($row['clicks'] ?? 0), $devices),
                'labels' => array_map(fn (array $row): string => $this->gscDeviceLabel($row['device'] ?? null), $devices),
                'colors' => ['#465fff', '#9cb9ff', '#12b76a', '#f79009', '#7a5af8'],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'bottom', 'fontSize' => '12px'],
                'stroke' => ['width' => 3, 'colors' => ['#ffffff']],
                'plotOptions' => ['pie' => ['donut' => ['size' => '70%']]],
            ],
            'countries' => [
                'chart' => ['type' => 'bar', 'height' => 290, 'toolbar' => ['show' => false], 'fontFamily' => 'Outfit, sans-serif'],
                'series' => [[
                    'name' => __('website_gsc.clicks'),
                    'data' => array_map(static fn (array $row): int => (int) ($row['clicks'] ?? 0), $countries),
                ]],
                'xaxis' => [
                    'categories' => array_map(static fn (array $row): string => mb_strtoupper((string) ($row['country'] ?? '—')), $countries),
                    'axisBorder' => ['show' => false],
                    'axisTicks' => ['show' => false],
                ],
                'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 5, 'barHeight' => '58%']],
                'colors' => ['#465fff'],
                'dataLabels' => ['enabled' => false],
                'grid' => ['strokeDashArray' => 4, 'borderColor' => '#eaecf0'],
                'legend' => ['show' => false],
            ],
            'surfaces' => [
                'chart' => ['type' => 'donut', 'height' => 270, 'fontFamily' => 'Outfit, sans-serif'],
                'series' => array_map(static fn (array $row): int => (int) ($row['clicks'] ?? 0), $surfaces),
                'labels' => array_map(fn (array $row): string => $this->gscSurfaceLabel($row['search_type'] ?? null), $surfaces),
                'colors' => ['#465fff', '#12b76a', '#f79009', '#7a5af8', '#0ba5ec', '#667085'],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'bottom', 'fontSize' => '12px'],
                'stroke' => ['width' => 3, 'colors' => ['#ffffff']],
                'plotOptions' => ['pie' => ['donut' => ['size' => '70%']]],
            ],
        ];
    }

    /** @return array{labels:list<string>,sessions:list<int>,views:list<int>,granularity:string} */
    private function ga4TrendForChart(array $trend): array
    {
        $labels = array_values($trend['labels'] ?? []);
        $sessions = array_values($trend['sessions'] ?? []);
        $views = array_values($trend['views'] ?? []);

        if (count($labels) <= 45) {
            return [
                'labels' => array_map(fn (string $date): string => $this->shortDateLabel($date), $labels),
                'sessions' => array_map('intval', $sessions),
                'views' => array_map('intval', $views),
                'granularity' => 'daily',
            ];
        }

        $weeks = [];
        foreach ($labels as $index => $date) {
            $weekStart = CarbonImmutable::parse($date)->startOfWeek()->toDateString();
            $weeks[$weekStart] ??= ['sessions' => 0, 'views' => 0];
            $weeks[$weekStart]['sessions'] += (int) ($sessions[$index] ?? 0);
            $weeks[$weekStart]['views'] += (int) ($views[$index] ?? 0);
        }
        ksort($weeks);

        return [
            'labels' => array_map(fn (string $date): string => $this->shortDateLabel($date), array_keys($weeks)),
            'sessions' => array_values(array_map(static fn (array $week): int => $week['sessions'], $weeks)),
            'views' => array_values(array_map(static fn (array $week): int => $week['views'], $weeks)),
            'granularity' => 'weekly',
        ];
    }

    /** @return array{labels:list<string>,clicks:list<int>,impressions:list<int>,granularity:string} */
    private function gscTrendForChart(array $trend): array
    {
        $labels = array_values($trend['labels'] ?? []);
        $clicks = array_values($trend['clicks'] ?? []);
        $impressions = array_values($trend['impressions'] ?? []);

        if (count($labels) <= 45) {
            return [
                'labels' => array_map(fn (string $date): string => $this->shortDateLabel($date), $labels),
                'clicks' => array_map('intval', $clicks),
                'impressions' => array_map('intval', $impressions),
                'granularity' => 'daily',
            ];
        }

        $weeks = [];
        foreach ($labels as $index => $date) {
            $weekStart = CarbonImmutable::parse($date)->startOfWeek()->toDateString();
            $weeks[$weekStart] ??= ['clicks' => 0, 'impressions' => 0];
            $weeks[$weekStart]['clicks'] += (int) ($clicks[$index] ?? 0);
            $weeks[$weekStart]['impressions'] += (int) ($impressions[$index] ?? 0);
        }
        ksort($weeks);

        return [
            'labels' => array_map(fn (string $date): string => $this->shortDateLabel($date), array_keys($weeks)),
            'clicks' => array_values(array_map(static fn (array $week): int => $week['clicks'], $weeks)),
            'impressions' => array_values(array_map(static fn (array $week): int => $week['impressions'], $weeks)),
            'granularity' => 'weekly',
        ];
    }

    private function shortDateLabel(string $date): string
    {
        return CarbonImmutable::parse($date)
            ->locale(app()->getLocale())
            ->translatedFormat('j M');
    }

    private function ga4MetricValue(array $analysis, string $key): ?float
    {
        foreach ($analysis['metrics'] ?? [] as $metric) {
            if (($metric['key'] ?? null) === $key) {
                return $metric['value'] === null ? null : (float) $metric['value'];
            }
        }

        return null;
    }

    private function ga4ChannelLabel(?string $label): string
    {
        return match ($label) {
            'Organic Search' => __('website_ga4.channel_organic_search'),
            'Direct' => __('website_ga4.channel_direct'),
            'Referral' => __('website_ga4.channel_referral'),
            'Organic Social' => __('website_ga4.channel_organic_social'),
            'Paid Search' => __('website_ga4.channel_paid_search'),
            'Paid Social' => __('website_ga4.channel_paid_social'),
            'Display' => __('website_ga4.channel_display'),
            'Email' => __('website_ga4.channel_email'),
            'Unassigned', '(not set)', null, '' => __('website_ga4.channel_unassigned'),
            default => $label,
        };
    }

    private function ga4DeviceLabel(?string $label): string
    {
        return match (mb_strtolower((string) $label)) {
            'mobile' => __('website_ga4.device_mobile'),
            'desktop' => __('website_ga4.device_desktop'),
            'tablet' => __('website_ga4.device_tablet'),
            default => $label ?: '—',
        };
    }

    private function gscDeviceLabel(?string $label): string
    {
        return match (mb_strtolower((string) $label)) {
            'mobile' => __('website_gsc.device_mobile'),
            'desktop' => __('website_gsc.device_desktop'),
            'tablet' => __('website_gsc.device_tablet'),
            default => $label ?: '—',
        };
    }

    private function gscSurfaceLabel(?string $label): string
    {
        return match ((string) $label) {
            'web' => __('website_gsc.surface_web'),
            'image' => __('website_gsc.surface_image'),
            'video' => __('website_gsc.surface_video'),
            'news' => __('website_gsc.surface_news'),
            'discover' => __('website_gsc.surface_discover'),
            'googleNews' => __('website_gsc.surface_google_news'),
            default => $label ?: '—',
        };
    }
}
