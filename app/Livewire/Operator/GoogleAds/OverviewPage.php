<?php

namespace App\Livewire\Operator\GoogleAds;

use App\Livewire\Demo\GoogleAds\OverviewPage as LegacyOverviewPage;
use App\Models\DigitalAsset;
use App\Models\GoogleAdsBudgetPlan;
use App\Services\Async\AsyncOperationService;
use App\Services\GoogleAds\GoogleAdsBudgetBiddingControlService;
use App\Services\GoogleAds\GoogleAdsCampaignAnalyticsReadService;
use App\Services\GoogleAds\GoogleAdsEntityHierarchyReconciler;
use App\Services\GoogleAds\GoogleAdsSearchExpertWorkspaceService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsWorkspaceTruthReconciler;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Production operator behavior layered over the Google Ads specialist workspace. */
class OverviewPage extends LegacyOverviewPage
{
    public string $search_query = '';

    public string $search_campaign = 'all';

    public string $search_ad_group = 'all';

    public string $search_source = 'all';

    public string $search_match = 'all';

    public string $keyword_status = 'all';

    public int $search_page = 1;

    public int $keyword_page = 1;

    public int $search_per_page = 100;

    public ?string $budget_plan_amount = null;

    public ?string $budget_target_cpa = null;

    public ?string $budget_target_roas = null;

    public ?string $budget_plan_notes = null;

    /** Campaign table sort column: '' keeps the default order; otherwise one of CAMPAIGN_SORTS. */
    public string $campaign_sort = '';

    public string $campaign_sort_dir = 'desc';

    /** @var list<string> */
    private const CAMPAIGN_SORTS = ['cost', 'clicks', 'conversions', 'cpa'];

    public function mount(?string $assetId = null): void
    {
        parent::mount($assetId);
        $this->loadBudgetPlanFields();
    }

    public function saveBudgetPlan(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'google_ads')
            ->firstOrFail();

        if (! filled($this->periodStart) || ! filled($this->periodEnd)) {
            DemoState::flash('Bütçe planı kaydedilemedi: geçerli bir raporlama aralığı seçin.', 'warning');

            return;
        }

        $validated = $this->validate([
            'budget_plan_amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'budget_target_cpa' => ['nullable', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'budget_target_roas' => ['nullable', 'numeric', 'min:0.0001', 'max:99999'],
            'budget_plan_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $binding = app(GoogleAdsSpecialistBindingResolver::class)->resolve($this->assetId);
        $currency = $binding->currency ?: 'TRY';
        $userId = auth()->id();

        $plan = GoogleAdsBudgetPlan::query()->firstOrNew([
            'digital_asset_id' => $asset->id,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
        ]);

        if (! $plan->exists) {
            $plan->created_by_user_id = $userId;
        }

        $plan->fill([
            'currency' => $currency,
            'planned_budget' => (float) $validated['budget_plan_amount'],
            'target_cpa' => filled($validated['budget_target_cpa'] ?? null) ? (float) $validated['budget_target_cpa'] : null,
            'target_roas' => filled($validated['budget_target_roas'] ?? null) ? (float) $validated['budget_target_roas'] : null,
            'notes' => filled($validated['budget_plan_notes'] ?? null) ? trim((string) $validated['budget_plan_notes']) : null,
            'updated_by_user_id' => $userId,
        ])->save();

        $this->loadBudgetPlanFields();
        DemoState::flash('Ajans bütçe planı kaydedildi. Pacing ve bütçe kararları bu planı kullanacak.', 'success');
    }

    public function clearBudgetPlan(): void
    {
        if (! Schema::hasTable('google_ads_budget_plans') || ! filled($this->periodStart) || ! filled($this->periodEnd)) {
            return;
        }

        GoogleAdsBudgetPlan::query()
            ->where('digital_asset_id', (int) $this->assetId)
            ->whereDate('period_start', $this->periodStart)
            ->whereDate('period_end', $this->periodEnd)
            ->delete();

        $this->budget_plan_amount = null;
        $this->budget_target_cpa = null;
        $this->budget_target_roas = null;
        $this->budget_plan_notes = null;
        DemoState::flash('Seçili dönem için ajans bütçe planı kaldırıldı.', 'info');
    }

    public function setSearchSub(string $sub): void
    {
        $sub = match ($sub) {
            'inbox', 'drift' => 'insights',
            default => $sub,
        };
        if (! in_array($sub, ['terms', 'keywords', 'negatives', 'insights'], true)) {
            return;
        }

        $this->search_sub = $sub;
        $this->tab = 'search_demand';
        $this->cluster = null;
        $this->search_page = 1;
        $this->keyword_page = 1;
    }

    public function updatedSearchQuery(): void
    {
        $this->resetSearchPages();
    }

    public function updatedSearchCampaign(): void
    {
        $this->search_ad_group = 'all';
        $this->resetSearchPages();
    }

    public function updatedSearchAdGroup(): void
    {
        $this->resetSearchPages();
    }

    public function updatedSearchSource(): void
    {
        $this->search_page = 1;
    }

    public function updatedSearchMatch(): void
    {
        $this->search_page = 1;
    }

    public function updatedKeywordStatus(): void
    {
        $this->keyword_page = 1;
    }

    public function setSearchPerPage(int $perPage): void
    {
        if (! in_array($perPage, [50, 100, 250], true)) {
            return;
        }
        $this->search_per_page = $perPage;
        $this->resetSearchPages();
    }

    public function clearSearchFilters(): void
    {
        $this->search_query = '';
        $this->search_campaign = 'all';
        $this->search_ad_group = 'all';
        $this->search_source = 'all';
        $this->search_match = 'all';
        $this->keyword_status = 'all';
        $this->resetSearchPages();
    }

    public function previousSearchPage(): void
    {
        $this->search_page = max(1, $this->search_page - 1);
    }

    public function nextSearchPage(): void
    {
        $this->search_page++;
    }

    public function previousKeywordPage(): void
    {
        $this->keyword_page = max(1, $this->keyword_page - 1);
    }

    public function nextKeywordPage(): void
    {
        $this->keyword_page++;
    }

    private function resetSearchPages(): void
    {
        $this->search_page = 1;
        $this->keyword_page = 1;
    }

    protected function resetPeriodDependentState(): void
    {
        parent::resetPeriodDependentState();
        $this->loadBudgetPlanFields();
    }

    public function runAnalysis(): void
    {
        $asset = DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->where('type', 'google_ads')
            ->firstOrFail();

        $result = app(AsyncOperationService::class)->queueFindingEvaluation($asset, auth()->user());
        DemoState::flash((string) ($result['message'] ?? __('operator.async.finding_evaluation_queued')), ($result['ok'] ?? false) ? 'success' : 'info');
        $this->tab = 'overview';
    }

    public function createRecommendation(?string $term = null): void
    {
        DemoState::flash(__('operator.flash.recommendation_requires_finding'), 'info');
        $this->tab = 'advisor';
    }

    public function markClusterReviewed(string $id): void
    {
        DemoState::flash(__('operator.flash.cluster_review_not_persisted'), 'info');
        $this->cluster = $id;
        $this->tab = 'search_demand';
        $this->search_sub = 'insights';
    }

    public function setCampaignSub(string $sub): void
    {
        if (! in_array($sub, ['campaigns', 'ad_groups', 'ads'], true)) {
            return;
        }

        $this->campaign_sub = $sub;
        $this->tab = 'campaigns';
        $this->campaign = null;
        $this->entity_type = 'all';

        if ($sub !== 'ads') {
            $this->entity_ad_group = 'all';
        }
    }

    public function updatedEntityCampaign(): void
    {
        $this->entity_ad_group = 'all';
    }

    public function showCampaignAdGroups(string $campaignId): void
    {
        $this->entity_campaign = $campaignId;
        $this->entity_ad_group = 'all';
        $this->entity_type = 'all';
        $this->campaign_sub = 'ad_groups';
        $this->tab = 'campaigns';
        $this->campaign = null;
    }

    public function sortCampaigns(string $column): void
    {
        if (! in_array($column, self::CAMPAIGN_SORTS, true)) {
            return;
        }

        if ($this->campaign_sort === $column) {
            $this->campaign_sort_dir = $this->campaign_sort_dir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->campaign_sort = $column;
            $this->campaign_sort_dir = $column === 'cpa' ? 'asc' : 'desc';
        }
    }

    /** Campaign table (with previous-period comparison) as a Turkish-Excel friendly CSV. */
    public function exportCampaignsCsv(): StreamedResponse
    {
        $this->tab = 'campaigns';
        $rows = $this->filterCampaignRowsForExport(collect($this->render()->getData()['campaignRows'] ?? []));
        $header = [
            __('operator_gads.columns.campaign'), 'ID', __('operator_gads.columns.status'), __('operator_gads.export.type'),
            __('operator_gads.export.daily_budget'), __('operator_gads.columns.spend'), __('operator_gads.export.delta_cost'),
            __('operator_gads.columns.clicks'), __('operator_gads.export.delta_clicks'),
            __('operator_gads.columns.conversions'), __('operator_gads.export.delta_conversions'),
            'CPA', __('operator_gads.export.delta_cpa'),
            __('operator_gads.comparison.lost_is_budget'), __('operator_gads.comparison.lost_is_rank'),
        ];

        $lines = $rows->map(function (array $row): array {
            $cmp = is_array($row['comparison'] ?? null) ? $row['comparison'] : [];

            return [
                $this->csvText((string) ($row['name'] ?? '')),
                (string) ($row['id'] ?? ''),
                $this->csvText((string) ($row['status'] ?? '')),
                $this->csvText((string) ($row['type'] ?? '')),
                $this->csvNumber($row['budget'] ?? null, 2),
                $this->csvNumber($cmp['cost'] ?? $row['spend'] ?? null, 2),
                $this->csvNumber($cmp['delta_cost'] ?? null, 1),
                $this->csvNumber($cmp['clicks'] ?? $row['clicks'] ?? null, 0),
                $this->csvNumber($cmp['delta_clicks'] ?? null, 1),
                $this->csvNumber($cmp['conversions'] ?? $row['leads'] ?? null, 2),
                $this->csvNumber($cmp['delta_conversions'] ?? null, 1),
                $this->csvNumber($cmp['cpa'] ?? null, 2),
                $this->csvNumber($cmp['delta_cpa'] ?? null, 1),
                $this->csvNumber($row['lost_is_budget'] ?? null, 1),
                $this->csvNumber($row['lost_is_rank'] ?? null, 1),
            ];
        })->all();

        return $this->csvDownload('google-ads-kampanyalar-'.$this->exportPeriodSlug().'.csv', $header, $lines);
    }

    /** Filtered Search terms (all pages, not only the visible page) as CSV. */
    public function exportSearchTermsCsv(): StreamedResponse
    {
        $this->tab = 'search_demand';
        $data = $this->render()->getData()['data'] ?? [];
        $terms = $this->filterTerms(collect(is_array($data) ? ($data['search']['terms'] ?? []) : []));
        $header = [
            __('operator_gads.export.search_term'), __('operator_gads.export.source'), __('operator_gads.columns.campaign'),
            __('operator_gads.export.ad_group'), __('operator_gads.columns.impressions'), __('operator_gads.columns.clicks'),
            'CTR %', 'Ort. TBM', __('operator_gads.columns.spend'), __('operator_gads.columns.conversions'), 'CVR %', 'CPA',
        ];

        $lines = $terms->map(fn (array $row): array => [
            $this->csvText((string) ($row['term'] ?? '')),
            $this->csvText((string) ($row['source'] ?? '')),
            $this->csvText((string) ($row['campaign'] ?? '')),
            $this->csvText((string) ($row['ad_group'] ?? '')),
            $this->csvNumber($row['impressions'] ?? null, 0),
            $this->csvNumber($row['clicks'] ?? null, 0),
            $this->csvNumber($row['ctr'] ?? null, 2),
            $this->csvNumber($row['avg_cpc'] ?? null, 2),
            $this->csvNumber($row['spend'] ?? null, 2),
            $this->csvNumber($row['leads'] ?? null, 2),
            $this->csvNumber($row['cvr'] ?? null, 2),
            $this->csvNumber($row['cpa'] ?? null, 2),
        ])->all();

        return $this->csvDownload('google-ads-arama-terimleri-'.$this->exportPeriodSlug().'.csv', $header, $lines);
    }

    public function render(): View
    {
        if (in_array($this->search_sub, ['inbox', 'drift'], true)) {
            $this->search_sub = 'insights';
        }

        $view = parent::render();
        $payload = $view->getData();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $professional = is_array($payload['professional'] ?? null) ? $payload['professional'] : [];
        $start = (string) ($data['period_start'] ?? $this->periodStart ?? '');
        $end = (string) ($data['period_end'] ?? $this->periodEnd ?? '');

        if ($start !== '' && $end !== '') {
            $data = app(GoogleAdsWorkspaceTruthReconciler::class)->reconcile(
                $this->assetId,
                $start,
                $end,
                $data,
            );
        }

        $hierarchy = app(GoogleAdsEntityHierarchyReconciler::class)->reconcile(
            $this->assetId,
            $data,
            $professional,
        );
        $data = $hierarchy['data'];
        $professional = $hierarchy['professional'];

        if ($start !== '' && $end !== '') {
            $data = app(GoogleAdsSearchExpertWorkspaceService::class)->reconcile(
                $this->assetId,
                $start,
                $end,
                $data,
                $professional,
            );
        }

        $campaignAnalytics = null;
        $monthlyPacing = null;
        if (in_array($this->tab, ['campaigns', 'budget_bidding'], true)) {
            $analyticsService = app(GoogleAdsCampaignAnalyticsReadService::class);
            $campaignAnalytics = $analyticsService->campaignComparison($this->assetId, $start !== '' ? $start : null, $end !== '' ? $end : null);
            $data['campaigns'] = $this->campaignRowsWithAnalytics(collect($data['campaigns'] ?? [])->values()->all(), $campaignAnalytics);
            if ($this->tab === 'budget_bidding') {
                $monthlyPacing = $analyticsService->monthlyPacing($this->assetId);
            }
        }

        $campaigns = $this->sortCampaignRows(collect($data['campaigns'] ?? []));

        $allTerms = collect($data['search']['terms'] ?? []);
        $terms = $this->filterTerms($allTerms);
        $termTotal = $terms->count();
        $termLastPage = max(1, (int) ceil($termTotal / $this->search_per_page));
        $this->search_page = min(max(1, $this->search_page), $termLastPage);
        $termRows = $terms
            ->slice(($this->search_page - 1) * $this->search_per_page, $this->search_per_page)
            ->values();

        $allKeywords = collect($data['search']['keywords'] ?? []);
        $keywords = $this->filterKeywords($allKeywords);
        $keywordTotal = $keywords->count();
        $keywordLastPage = max(1, (int) ceil($keywordTotal / $this->search_per_page));
        $this->keyword_page = min(max(1, $this->keyword_page), $keywordLastPage);
        $keywordRows = $keywords
            ->slice(($this->keyword_page - 1) * $this->search_per_page, $this->search_per_page)
            ->values();

        $selectedCampaign = $this->campaign
            ? collect($data['campaigns'] ?? [])->firstWhere('id', $this->campaign)
            : null;
        $selectedCluster = $this->cluster
            ? collect($data['search']['clusters'] ?? [])->firstWhere('id', $this->cluster)
            : null;

        $trend = $data['performance_trend'] ?? ['labels' => [], 'spend' => [], 'leads' => []];
        $chart = is_array($payload['performanceChartOptions'] ?? null)
            ? $payload['performanceChartOptions']
            : [];
        $chart['series'] = [
            ['name' => 'Spend', 'data' => $trend['spend'] ?? []],
            [
                'name' => 'Google Ads conversions',
                'data' => $trend['leads'] ?? [],
            ],
        ];
        $chart['xaxis']['categories'] = $trend['labels'] ?? [];

        $budgetControl = app(GoogleAdsBudgetBiddingControlService::class)->workspace(
            $this->assetId,
            $start !== '' ? $start : $this->periodStart,
            $end !== '' ? $end : $this->periodEnd,
            collect($data['campaigns'] ?? [])->values()->all(),
            $professional,
            $data,
        );

        return $view->with([
            'data' => $data,
            'professional' => $professional,
            'identity' => $data['identity'] ?? ($payload['identity'] ?? []),
            'campaignRows' => $campaigns->values()->all(),
            'termRows' => $termRows->all(),
            'termRowsTotal' => $termTotal,
            'termRowsLastPage' => $termLastPage,
            'keywordRows' => $keywordRows->all(),
            'keywordRowsTotal' => $keywordTotal,
            'keywordRowsLastPage' => $keywordLastPage,
            'selectedCampaign' => $selectedCampaign,
            'selectedCluster' => $selectedCluster,
            'performanceChartOptions' => $chart,
            'budgetControl' => $budgetControl,
            'budgetPlanEditable' => true,
            'campaignAnalytics' => $campaignAnalytics,
            'monthlyPacing' => $monthlyPacing,
        ]);
    }

    /**
     * Attaches previous-period comparison to each campaign row and fills lost impression
     * share from the daily facts when the row does not carry it yet.
     *
     * @param  list<array<string,mixed>>  $campaigns
     * @param  array{available: bool, rows: array<string, array<string, mixed>>}  $analytics
     * @return list<array<string,mixed>>
     */
    private function campaignRowsWithAnalytics(array $campaigns, array $analytics): array
    {
        return array_map(static function (array $row) use ($analytics): array {
            $cmp = $analytics['rows'][(string) ($row['id'] ?? '')] ?? null;
            $row['comparison'] = $cmp;
            if ($cmp !== null) {
                $row['lost_is_budget'] = is_numeric($row['lost_is_budget'] ?? null) ? $row['lost_is_budget'] : $cmp['lost_is_budget'];
                $row['lost_is_rank'] = is_numeric($row['lost_is_rank'] ?? null) ? $row['lost_is_rank'] : $cmp['lost_is_rank'];
            }

            return $row;
        }, $campaigns);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $campaigns
     * @return Collection<int,array<string,mixed>>
     */
    private function sortCampaignRows(Collection $campaigns): Collection
    {
        if (! in_array($this->campaign_sort, self::CAMPAIGN_SORTS, true)) {
            return $campaigns->values();
        }

        $column = $this->campaign_sort;
        $descending = $this->campaign_sort_dir === 'desc';
        $value = static function (array $row) use ($column): ?float {
            $spend = is_numeric($row['spend'] ?? null) ? (float) $row['spend'] : null;
            $leads = is_numeric($row['leads'] ?? null) ? (float) $row['leads'] : null;

            return match ($column) {
                'cost' => $spend,
                'clicks' => is_numeric($row['clicks'] ?? null) ? (float) $row['clicks'] : (is_numeric(data_get($row, 'comparison.clicks')) ? (float) data_get($row, 'comparison.clicks') : null),
                'conversions' => $leads,
                'cpa' => $spend !== null && $leads !== null && $leads > 0 ? $spend / $leads : null,
                default => null,
            };
        };

        // Rows without a value (e.g. no conversions for CPA) always sort last.
        return $campaigns
            ->sort(static function (array $a, array $b) use ($value, $descending): int {
                $left = $value($a);
                $right = $value($b);
                if ($left === null || $right === null) {
                    return ($left === null) <=> ($right === null);
                }

                return $descending ? $right <=> $left : $left <=> $right;
            })
            ->values();
    }

    /**
     * Applies the same entity filters the campaigns table uses in the view.
     *
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return Collection<int,array<string,mixed>>
     */
    private function filterCampaignRowsForExport(Collection $rows): Collection
    {
        $query = mb_strtolower(trim($this->entity_query));

        return $rows->filter(function (array $row) use ($query): bool {
            if ($this->entity_campaign !== 'all' && (string) ($row['id'] ?? '') !== $this->entity_campaign) {
                return false;
            }
            if ($this->entity_status !== 'all' && strtoupper((string) ($row['status'] ?? '')) !== strtoupper($this->entity_status)) {
                return false;
            }
            if ($this->entity_type !== 'all' && (string) ($row['type'] ?? '') !== $this->entity_type) {
                return false;
            }

            return $query === '' || str_contains(mb_strtolower((string) ($row['name'] ?? '').' '.(string) ($row['id'] ?? '')), $query);
        })->values();
    }

    /**
     * UTF-8 BOM + `;` separator so Turkish Excel opens the file with correct characters and columns.
     *
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function csvDownload(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(static function () use ($header, $rows): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $header, ';', '"', '');
            foreach ($rows as $row) {
                fputcsv($output, $row, ';', '"', '');
            }
            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Neutralises spreadsheet formula injection in free-text cells. */
    private function csvText(string $value): string
    {
        return preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $value) === 1 ? "'".$value : $value;
    }

    private function csvNumber(mixed $value, int $decimals): string
    {
        return is_numeric($value) ? number_format((float) $value, $decimals, ',', '') : '';
    }

    private function exportPeriodSlug(): string
    {
        $start = (string) ($this->periodStart ?? '');
        $end = (string) ($this->periodEnd ?? '');

        return $start !== '' && $end !== '' ? $start.'_'.$end : now()->format('Ymd');
    }

    /** @param Collection<int,array<string,mixed>> $terms */
    private function filterTerms(Collection $terms): Collection
    {
        $query = mb_strtolower(trim($this->search_query));
        if ($query !== '') {
            $terms = $terms->filter(static function (array $row) use ($query): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['term'] ?? null,
                    $row['campaign'] ?? null,
                    $row['ad_group'] ?? null,
                    $row['matched_keyword'] ?? null,
                    $row['match_source'] ?? null,
                ])));

                return str_contains($haystack, $query);
            });
        }
        if ($this->search_campaign !== 'all') {
            $campaign = $this->search_campaign;
            $terms = $terms->filter(static fn (array $row): bool => in_array($campaign, $row['campaign_ids'] ?? [], true));
        }
        if ($this->search_ad_group !== 'all') {
            $adGroup = $this->search_ad_group;
            $terms = $terms->filter(static fn (array $row): bool => in_array($adGroup, $row['ad_group_ids'] ?? [], true));
        }
        if ($this->search_source !== 'all') {
            $source = $this->search_source;
            $terms = $terms->filter(static fn (array $row): bool => ($row['source'] ?? '') === $source);
        }
        if ($this->search_match !== 'all') {
            $match = $this->search_match;
            $terms = $terms->filter(static fn (array $row): bool => ($row['match_type'] ?? '') === $match);
        }
        if ($this->intent_filter !== 'all') {
            $terms = $terms->where('intent', $this->intent_filter);
        }
        if ($this->decision_filter !== 'all') {
            $terms = $terms->where('decision', $this->decision_filter);
        }

        return $terms->values();
    }

    /** @param Collection<int,array<string,mixed>> $keywords */
    private function filterKeywords(Collection $keywords): Collection
    {
        $query = mb_strtolower(trim($this->search_query));
        if ($query !== '') {
            $keywords = $keywords->filter(static function (array $row) use ($query): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $row['keyword'] ?? null,
                    $row['campaign'] ?? null,
                    $row['ad_group'] ?? null,
                    $row['match'] ?? null,
                    $row['status'] ?? null,
                ])));

                return str_contains($haystack, $query);
            });
        }
        if ($this->search_campaign !== 'all') {
            $campaign = $this->search_campaign;
            $keywords = $keywords->filter(static fn (array $row): bool => (string) ($row['campaign_id'] ?? '') === $campaign);
        }
        if ($this->search_ad_group !== 'all') {
            $adGroup = $this->search_ad_group;
            $keywords = $keywords->filter(static fn (array $row): bool => (string) ($row['ad_group_id'] ?? '') === $adGroup);
        }
        if ($this->keyword_status !== 'all') {
            $status = strtoupper($this->keyword_status);
            $keywords = $keywords->filter(static fn (array $row): bool => strtoupper((string) ($row['status'] ?? '')) === $status);
        }

        return $keywords->values();
    }

    private function loadBudgetPlanFields(): void
    {
        if (! Schema::hasTable('google_ads_budget_plans') || ! ctype_digit($this->assetId) || ! filled($this->periodStart) || ! filled($this->periodEnd)) {
            $this->budget_plan_amount = null;
            $this->budget_target_cpa = null;
            $this->budget_target_roas = null;
            $this->budget_plan_notes = null;

            return;
        }

        $plan = GoogleAdsBudgetPlan::query()
            ->where('digital_asset_id', (int) $this->assetId)
            ->whereDate('period_start', $this->periodStart)
            ->whereDate('period_end', $this->periodEnd)
            ->first();

        $this->budget_plan_amount = $plan?->planned_budget !== null ? (string) $plan->planned_budget : null;
        $this->budget_target_cpa = $plan?->target_cpa !== null ? (string) $plan->target_cpa : null;
        $this->budget_target_roas = $plan?->target_roas !== null ? (string) $plan->target_roas : null;
        $this->budget_plan_notes = $plan?->notes;
    }
}
