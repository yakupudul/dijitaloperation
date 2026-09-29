<?php

namespace App\Livewire\Operator\Website\V2;

use App\Jobs\Site\AnalyzeCompetitorClusterJob;
use App\Jobs\Site\RefreshCompetitorsJob;
use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\BrandClusterSerp;
use App\Models\CompetitorPage;
use App\Models\Suggestion;
use App\Services\Site\Competitors\CompetitorAnalyzer;
use App\Services\Site\Competitors\CompetitorPageStore;
use App\Services\Site\Competitors\CompetitorRefresher;
use App\Services\Site\Competitors\CompetitorTargets;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * SEO Yapılacaklar › Rakipler: per approved brand cluster — representative query, our rank, the top-10 (sıra, domain,
 * tür), "Analiz et" and the competitor suggestions. "Rakipleri güncelle" queues the refresh (operational brands only);
 * the screen reads only `brand_cluster_serps`, `competitor_pages` and `suggestions`.
 */
final class CompetitorsTab extends Component
{
    use WebsiteTab;

    public function refreshCompetitors(): void
    {
        $this->actor();
        $site = $this->site();
        if (! $this->operational($site)) {
            $this->message = ServiceScope::NOT_SERVED;

            return;
        }
        CompetitorRefresher::markRunning((int) $site->id);
        RefreshCompetitorsJob::dispatch((int) $site->id);
        $this->message = 'Rakipler güncelleniyor.';
    }

    public function analyze(int $serpId): void
    {
        $this->actor();
        $site = $this->site();
        $serp = BrandClusterSerp::query()->where('website_asset_id', $site->id)->findOrFail($serpId);
        if (! $this->operational($site)) {
            $this->message = ServiceScope::NOT_SERVED;

            return;
        }
        CompetitorAnalyzer::markRunning((int) $serp->id);
        AnalyzeCompetitorClusterJob::dispatch((int) $serp->id);
        $this->message = 'Analiz başladı.';
    }

    public function render(CompetitorTargets $targets): View
    {
        $site = $this->site();
        $brand = $site->brand;
        $clusters = $brand !== null ? $targets->clusters($brand) : collect();
        $term = $brand !== null ? CompetitorTargets::areaTerm($targets->mainArea($brand)) : null;
        $serps = BrandClusterSerp::query()->where('website_asset_id', $site->id)->when($brand !== null, fn ($q) => $q->where('brand_id', $brand->id))
            ->get()->keyBy('cluster_id');
        $urls = $serps->flatMap(fn (BrandClusterSerp $s): array => array_column((array) $s->results, 'url'))->unique()->values();
        $missing = $urls->isEmpty() ? [] : CompetitorPage::query()->whereIn('url_hash', $urls->map(fn (string $u): string => CompetitorPageStore::hash($u)))
            ->where('status', CompetitorPage::MISSING)->pluck('url_hash')->flip()->all();
        $suggestions = $brand === null ? collect() : Suggestion::query()->where('brand_id', $brand->id)->where('action_type', CompetitorAnalyzer::TYPE)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK])->orderBy('priority')->orderBy('id')->get()
            ->filter(fn (Suggestion $s): bool => (int) ($s->evidence['website_asset_id'] ?? 0) === (int) $site->id)->groupBy('cluster_id');
        $refresh = Cache::get(CompetitorRefresher::statusKey((int) $site->id));
        $analyzing = $serps->mapWithKeys(fn (BrandClusterSerp $s): array => [$s->cluster_id => Cache::get(CompetitorAnalyzer::statusKey((int) $s->id))])->filter()->all();
        $running = ($refresh['status'] ?? null) === 'running' || collect($analyzing)->contains(fn ($s): bool => ($s['status'] ?? null) === 'running');

        return view('livewire.operator.website.v2.competitors-tab', [
            'site' => $site,
            'operational' => $this->operational($site),
            'rows' => $clusters->map(fn ($cluster): array => [
                'cluster' => $cluster,
                'query' => CompetitorTargets::representativeQuery($cluster, $term),
                'serp' => $serps->get($cluster->id),
                'suggestions' => $suggestions->get($cluster->id, collect()),
                'analyze' => $analyzing[$cluster->id] ?? null,
            ])->all(),
            'missing' => $missing,
            'refresh' => $refresh,
            'polling' => $running,
        ]);
    }
}
