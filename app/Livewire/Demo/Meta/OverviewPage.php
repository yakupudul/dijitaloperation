<?php

namespace App\Livewire\Demo\Meta;

use App\Jobs\CollectMetaGeoResultsJob;
use App\Livewire\Concerns\WithAiInsights;
use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Services\MetaAds\MetaAdsCampaignExplorer;
use App\Services\MetaAds\MetaAdsCreativeFatigueReadService;
use App\Services\MetaAds\MetaAdsProfessionalWorkspaceEnhancer;
use App\Services\MetaAds\MetaAdsProfessionalWorkspaceReadService;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Services\MetaAds\MetaGeoResultsReader;
use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Support\Demo\DemoState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('operator.layouts.app')]
#[Title('Meta Ads')]
class OverviewPage extends Component
{
    use InteractsWithDemoPeriod;
    use ResolvesCanonicalOperatorAsset;
    use WithAiInsights;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url(as: 'level')]
    public string $campaign_level = 'campaigns';

    /** Drill-down parent campaign id on the campaigns tab. */
    #[Url(as: 'campaign')]
    public string $campaign_filter = '';

    /** Drill-down parent ad set id on the campaigns tab. */
    #[Url(as: 'adset')]
    public string $adset_filter = '';

    #[Url(as: 'sort')]
    public string $campaign_sort = 'spend';

    #[Url(as: 'dir')]
    public string $campaign_direction = 'desc';

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
        'advisor',
        'campaigns',
        'creatives',
        'audience',
        'funnel',
        'measurement',
        'operations',
    ];

    /** @var array<string, string> */
    private const LEGACY_TAB_MAP = [
        'adsets' => 'campaigns',
        'ads' => 'campaigns',
        'breakdowns' => 'audience',
        'insights' => 'operations',
        'delivery' => 'audience',
        'destinations' => 'funnel',
    ];

    public function mount(?string $assetId = null, ?string $tab = null): void
    {
        $this->bindCanonicalAsset($assetId, ['meta_ads']);
        if (filled($tab)) {
            $this->tab = $tab;
        }

        $this->mountPeriod();
        $this->normalizeMetaPeriodState();
        $this->normalizeTab();
    }

    /**
     * Livewire can hydrate an old period/from/to trio from browser history.
     * A named preset is authoritative: its dates must always match the preset.
     */
    public function hydrate(): void
    {
        $this->normalizeMetaPeriodState();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    public function setCampaignLevel(string $level): void
    {
        if (! in_array($level, ['campaigns', 'adsets', 'ads'], true)) {
            return;
        }

        $this->campaign_level = $level;
        $this->tab = 'campaigns';

        if ($level === 'campaigns') {
            $this->campaign_filter = '';
            $this->adset_filter = '';
        } elseif ($level === 'adsets') {
            $this->adset_filter = '';
        }
    }

    /** Campaign row click: show that campaign's ad sets. */
    public function drillCampaign(string $campaignId): void
    {
        $this->tab = 'campaigns';
        $this->campaign_level = 'adsets';
        $this->campaign_filter = $campaignId;
        $this->adset_filter = '';
    }

    /** Ad set row click: show that ad set's ads. */
    public function drillAdset(string $adsetId, ?string $campaignId = null): void
    {
        $this->tab = 'campaigns';
        $this->campaign_level = 'ads';
        $this->adset_filter = $adsetId;
        if (filled($campaignId)) {
            $this->campaign_filter = (string) $campaignId;
        }
    }

    /** Breadcrumb navigation: '' = all campaigns, 'campaign' = the selected campaign's ad sets. */
    public function drillUp(string $target = ''): void
    {
        $this->tab = 'campaigns';

        if ($target === 'campaign' && $this->campaign_filter !== '') {
            $this->campaign_level = 'adsets';
            $this->adset_filter = '';

            return;
        }

        $this->campaign_level = 'campaigns';
        $this->campaign_filter = '';
        $this->adset_filter = '';
    }

    public function sortCampaignsBy(string $column): void
    {
        if (! in_array($column, MetaAdsCampaignExplorer::SORTS, true)) {
            return;
        }

        if ($this->campaign_sort === $column) {
            $this->campaign_direction = $this->campaign_direction === 'desc' ? 'asc' : 'desc';

            return;
        }

        $this->campaign_sort = $column;
        // Cost per result: cheapest first is the useful default.
        $this->campaign_direction = $column === 'cost_per_result' ? 'asc' : 'desc';
    }

    /** CSV of the list currently shown on the campaigns tab (UTF-8 BOM, ';' separated). */
    public function exportCsv(): StreamedResponse
    {
        $this->normalizeMetaPeriodState();
        $professional = $this->professionalWorkspace();
        $explorer = app(MetaAdsCampaignExplorer::class);
        $view = $this->campaignExplorerView($professional);
        $lines = $explorer->csvLines($view['rows'], $view['level']);

        $filename = sprintf(
            'meta-ads-%s-%s-%s.csv',
            $view['level'],
            (string) ($professional['period_start'] ?? $this->periodStart ?? 'start'),
            (string) ($professional['period_end'] ?? $this->periodEnd ?? 'end'),
        );

        return response()->streamDownload(static function () use ($lines): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($lines as $line) {
                fputcsv($handle, $line, ';', '"', '');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $professional
     * @return array<string, mixed>
     */
    protected function campaignExplorerView(array $professional): array
    {
        return app(MetaAdsCampaignExplorer::class)->explore(
            $professional,
            $this->campaign_level,
            $this->campaign_filter !== '' ? $this->campaign_filter : null,
            $this->adset_filter !== '' ? $this->adset_filter : null,
            $this->campaign_sort,
            $this->campaign_direction,
        );
    }

    /** @return array<string, mixed> */
    protected function professionalWorkspace(): array
    {
        $professional = app(MetaAdsProfessionalWorkspaceReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        return app(MetaAdsProfessionalWorkspaceEnhancer::class)->enhance(
            $professional,
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );
    }

    public function refreshData(): void
    {
        $binding = app(MetaAdsSpecialistBindingResolver::class)->resolve($this->assetId);

        if ($binding->mode !== MetaAdsBindingMode::RealBound) {
            DemoState::flash(__('operator.flash.meta_refresh_unconfigured'), 'info');

            return;
        }

        $asset = DigitalAsset::query()->find($binding->digitalAssetId);
        if (! $asset instanceof DigitalAsset) {
            DemoState::flash(__('operator.flash.meta_refresh_missing_asset'), 'warning');

            return;
        }

        $result = app(StartIncrementalCollectionService::class)->startForBindingIds(
            [$binding->coreAssetBindingId],
            auth()->user(),
            ['META_ADS'],
        );

        DemoState::flash(match ($result->outcome) {
            'started' => app()->getLocale() === 'tr'
                ? 'Meta Ads verileri yenilenmek üzere sıraya alındı.'
                : 'Meta Ads incremental collection started.',
            'active_equivalent' => app()->getLocale() === 'tr'
                ? 'Aynı Meta Ads yenileme işlemi zaten çalışıyor.'
                : 'An equivalent Meta Ads collection is already running.',
            'data_current' => app()->getLocale() === 'tr'
                ? 'Meta Ads verileri güncel; yeni toplama gerekmiyor.'
                : 'Meta Ads data is current; no collection is due.',
            default => $result->message,
        }, $result->outcome === 'started' ? 'success' : 'info');
    }

    /**
     * Kept for backwards-compatible Livewire calls. Analysis is not pretended to
     * run; users are taken to the actual analysis state instead.
     */
    public function runAnalysis(): void
    {
        $this->tab = 'operations';
    }

    /** Country + city results: queue a collection now (daily run keeps it fresh afterwards). */
    public function collectGeoResults(): void
    {
        $binding = app(MetaAdsSpecialistBindingResolver::class)->resolve($this->assetId);
        if ($binding->mode !== MetaAdsBindingMode::RealBound || $binding->digitalAssetId === null) {
            DemoState::flash('Meta reklam hesabı bağlı değil.', 'info');

            return;
        }
        Cache::put(CollectMetaGeoResultsJob::stateKey($binding->digitalAssetId), ['state' => 'running', 'at' => now()->toIso8601String()], now()->addHour());
        CollectMetaGeoResultsJob::dispatch($binding->digitalAssetId, 90);
        DemoState::flash('Ülke ve şehir verisi Meta’dan çekiliyor; birkaç dakika sürebilir.', 'info');
    }

    protected function insightSubject(string $kind, int $subjectId): ?Model
    {
        return $kind === 'meta.geo_results' && (string) $subjectId === (string) $this->assetId
            ? DigitalAsset::query()->where('type', 'meta_ads')->find($subjectId) : null;
    }

    /** @return array<string, mixed>|null */
    private function geoView(array $professional): ?array
    {
        if ($this->tab !== 'audience' || ! ctype_digit((string) $this->assetId)) {
            return null;
        }
        $asset = DigitalAsset::query()->find((int) $this->assetId);
        if ($asset === null) {
            return null;
        }
        $start = (string) ($professional['period_start'] ?? $this->periodStart ?? now()->subDays(28)->toDateString());
        $end = (string) ($professional['period_end'] ?? $this->periodEnd ?? now()->toDateString());

        return [
            'summary' => app(MetaGeoResultsReader::class)->summary((int) $asset->id, $start, $end),
            'state' => Cache::get(CollectMetaGeoResultsJob::stateKey((int) $asset->id)),
            'insight' => $this->insightView('meta.geo_results', $asset),
        ];
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $this->tab = self::LEGACY_TAB_MAP[$this->tab];
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    protected function normalizeMetaPeriodState(): void
    {
        if ($this->period === 'custom') {
            return;
        }

        $bounds = $this->periodBounds($this->period);
        $start = $bounds['start']->toDateString();
        $end = $bounds['end']->toDateString();

        if ($this->periodStart === $start && $this->periodEnd === $end) {
            return;
        }

        $this->periodStart = $start;
        $this->periodEnd = $end;
        $this->draftPeriodStart = $start;
        $this->draftPeriodEnd = $end;
        DemoState::setPeriod($this->period, $start, $end);
    }

    /**
     * Meta Ads read services always compare with the immediately preceding period of equal length,
     * so the year-over-year option is never offered here.
     */
    public function supportsYearOverYearComparison(): bool
    {
        return false;
    }

    private function localizedComparisonLabel(): string
    {
        if (! $this->compare || ! filled($this->periodStart) || ! filled($this->periodEnd)) {
            return app()->getLocale() === 'tr' ? 'Kapalı' : 'Off';
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $start = CarbonImmutable::parse($this->periodStart, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($this->periodEnd, $timezone)->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);

        $compareEnd = $start->subDay();
        $compareStart = $compareEnd->subDays($days - 1);

        if (app()->getLocale() === 'tr') {
            return $compareStart->locale('tr')->translatedFormat('j M')
                .' – '
                .$compareEnd->locale('tr')->translatedFormat('j M');
        }

        return $compareStart->format('M j').' – '.$compareEnd->format('M j');
    }

    /**
     * Findings / recommendations / tasks / outcomes recorded for this asset. The base page has none;
     * the operator page reads them from the database.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function recordedOperations(): array
    {
        return [];
    }

    public function render(): View
    {
        $this->normalizeMetaPeriodState();
        $this->normalizeTab();

        $data = app(MetaAdsSpecialistReadService::class)->workspace(
            $this->assetId,
            $this->period,
            $this->periodStart,
            $this->periodEnd,
        );

        $professional = $this->professionalWorkspace();

        $isDemo = ($data['migration_mode'] ?? 'demo_catalog') === 'demo_catalog';
        if (! $isDemo) {
            $data['operations'] = array_merge($data['operations'] ?? [], $this->recordedOperations());
        }

        $trend = $professional['trend'] ?? [];
        $currency = (string) ($professional['currency'] ?? $data['currency'] ?? '');
        $isTr = app()->getLocale() === 'tr';

        return view('livewire.demo.meta.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'professional' => $professional,
            'identity' => $data['identity'],
            'metaCompareLabel' => $this->localizedComparisonLabel(),
            'explorer' => $this->tab === 'campaigns' ? $this->campaignExplorerView($professional) : null,
            'fatigue' => $this->tab === 'creatives'
                ? app(MetaAdsCreativeFatigueReadService::class)->analyse(
                    $this->assetId,
                    (string) ($professional['period_end'] ?? $this->periodEnd ?? ''),
                    collect($professional['ads'] ?? [])->pluck('name', 'id')->map(static fn ($name): string => (string) $name)->all(),
                )
                : null,
            'geo' => $this->geoView($professional),
            'showPeriodBar' => in_array($this->tab, ['overview', 'campaigns', 'creatives', 'audience', 'funnel', 'measurement'], true),
            'performanceChartOptions' => [
                'chart' => ['type' => 'line', 'height' => 260, 'toolbar' => ['show' => false]],
                'series' => [
                    ['name' => ($isTr ? 'Reklam Harcaması' : 'Ad Spend').($currency !== '' ? ' ('.$currency.')' : ''), 'data' => array_column($trend, 'spend')],
                    ['name' => $isTr ? 'Toplam Tıklamalar' : 'Total Clicks', 'data' => array_column($trend, 'clicks')],
                ],
                'xaxis' => ['categories' => array_column($trend, 'date')],
                'stroke' => ['curve' => 'smooth', 'width' => 2],
                'dataLabels' => ['enabled' => false],
                'legend' => ['position' => 'top'],
                'yaxis' => [
                    ['title' => ['text' => $isTr ? 'Harcama' : 'Spend']],
                    ['opposite' => true, 'title' => ['text' => $isTr ? 'Tıklamalar' : 'Clicks']],
                ],
            ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
