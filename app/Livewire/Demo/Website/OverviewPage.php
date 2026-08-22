<?php

namespace App\Livewire\Demo\Website;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Ga4\WebsiteGa4AnalysisService;
use App\Support\Reality\OperatorCanonicalAsset;
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

    public string $message = '';

    public string $messageTone = 'info';

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
        'ga4_analysis',
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
        'analytics' => 'ga4_analysis',
        'ga4' => 'ga4_analysis',
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

    public function render(WebsiteOperatorWorkspace $workspace, WebsiteGa4AnalysisService $ga4AnalysisService): View
    {
        $this->normalizeTab();

        $asset = $this->asset()->loadMissing('brand.customer');
        $data = $workspace->overview(
            $asset,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $ga4Analysis = null;
        $ga4ChartOptions = null;
        if ($this->tab === 'ga4_analysis') {
            $ga4Analysis = $ga4AnalysisService->build(
                $asset,
                $this->period,
                $this->periodStart,
                $this->periodEnd,
                $this->compare,
                $this->compareMode,
            );

            $ga4ChartOptions = [
                'chart' => [
                    'type' => 'line',
                    'height' => 280,
                    'toolbar' => ['show' => false],
                    'zoom' => ['enabled' => false],
                ],
                'series' => [
                    ['name' => __('website_ga4.sessions'), 'data' => $ga4Analysis['trend']['sessions'] ?? []],
                    ['name' => __('website_ga4.views'), 'data' => $ga4Analysis['trend']['views'] ?? []],
                ],
                'xaxis' => ['categories' => $ga4Analysis['trend']['labels'] ?? []],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'top', 'horizontalAlign' => 'right'],
                'grid' => ['strokeDashArray' => 4],
            ];
        }

        return view('livewire.operator.website.overview', [
            'asset' => $asset,
            'brand' => $asset->brand,
            'customer' => $asset->brand?->customer,
            'data' => $data,
            'ga4Analysis' => $ga4Analysis,
            'ga4ChartOptions' => $ga4ChartOptions,
            'showPeriodBar' => in_array($this->tab, ['overview', 'ga4_analysis', 'visibility', 'performance'], true),
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
}
