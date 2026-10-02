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
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestions;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * SEO Yapılacaklar › Rakipler: per approved brand cluster — representative query, our rank, the top-10 (sıra, domain,
 * tür), "Analiz et" and the competitor suggestions with Onayla / Reddet, "AI ile yap" (our page exists; the new version
 * is shown in Öneriler) or "Yeni içerik olarak ekle" → "Taslak hazırla" (no page: İçerik plan item + AI draft).
 * "Rakipleri güncelle" queues the refresh (operational brands only); the screen reads only `brand_cluster_serps`,
 * `competitor_pages` and `suggestions`.
 */
final class CompetitorsTab extends Component
{
    use WebsiteTab;

    /** @var array<int|string, string> suggestion id => dismiss reason */
    public array $reasons = [];

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

    public function approve(int $id, SiteSuggestions $suggestions): void
    {
        $suggestions->approve($this->suggestion($id), $this->actor());
        $this->message = 'Onaylandı.';
    }

    public function dismiss(int $id, SiteSuggestions $suggestions): void
    {
        $suggestions->dismiss($this->suggestion($id), $this->actor(), (string) ($this->reasons[$id] ?? ''));
        unset($this->reasons[$id]);
        $this->message = 'Reddedildi.';
    }

    /** Our page exists: the new version is prepared like any URL suggestion (shown in Öneriler). */
    public function aiDo(int $id): void
    {
        $suggestion = $this->suggestion($id);
        if ($suggestion->page_id === null) {
            return;
        }
        SiteOperations::dispatch($this->websiteId, SiteOperations::APPLY_CHANGE, ['suggestion_id' => $id]);
        $this->message = 'Yeni sürüm hazırlanıyor · Öneriler sekmesinde.';
    }

    /** No page of ours: an İçerik plan item. */
    public function addContent(int $id, ContentPlanner $planner): void
    {
        $planner->fromCompetitor($this->suggestion($id), $this->actor());
        $this->message = 'İçerik planına eklendi.';
    }

    public function prepareDraft(int $id): void
    {
        $contentId = data_get($this->suggestion($id)->action, 'content_suggestion_id');
        if (! is_int($contentId)) {
            return;
        }
        SiteOperations::dispatch($this->websiteId, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => $contentId]);
        $this->message = 'Taslak hazırlanıyor · İçerik sekmesinde.';
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
            ->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK])->whereJsonContains('evidence->website_asset_id', (int) $site->id)
            ->orderBy('priority')->orderBy('id')->get()->groupBy('cluster_id');
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

    private function suggestion(int $id): Suggestion
    {
        $brandId = (int) $this->site()->brand_id;

        return Suggestion::query()->where('brand_id', $brandId)->where('action_type', CompetitorAnalyzer::TYPE)
            ->whereJsonContains('evidence->website_asset_id', $this->websiteId)->findOrFail($id);
    }
}
