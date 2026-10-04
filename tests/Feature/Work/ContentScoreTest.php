<?php

namespace Tests\Feature\Work;

use App\Livewire\Operator\Work\WorkPage;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\Query;
use App\Models\Suggestion;
use App\Services\Work\ContentScore;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/** İçerik fikri öncelik puanı: Talep 35 · Hizmet 25 · Boşluk 25 · Niyet 15, and the titles sort by it. */
final class ContentScoreTest extends SiteTestCase
{
    public function test_ideas_get_a_rule_based_score_and_the_waiting_titles_sort_by_it(): void
    {
        $implant = $this->cluster($this->implant, 'İmplant Çankaya', ['çankaya implant', 'implant fiyatları çankaya']);
        $zirkonyum = $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama']);
        $this->volume($implant, 1000);
        $this->volume($zirkonyum, 100);
        $page = $this->page('/zirkonyum/', 'Zirkonyum Kaplama');
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $implant->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page']);
        $row = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $zirkonyum->id, 'website_asset_id' => $this->site->id, 'page_id' => $page->id, 'state' => 'sufficient']);
        DB::table('cluster_page_scores')->insert(['brand_cluster_page_id' => $row->id, 'brand_id' => $this->brand->id, 'cluster_id' => $zirkonyum->id,
            'page_id' => $page->id, 'state' => 'scored', 'score' => 75, 'computed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $low = $this->idea('Diş fırçası nasıl seçilir', ['kind' => 'new', 'page_type' => 'blog']);
        $update = $this->idea('Zirkonyum kaplama sayfasını güncelle', ['kind' => 'update', 'page_type' => 'hizmet'], $zirkonyum);
        $top = $this->idea('Çankaya implant tedavisi', ['kind' => 'new', 'page_type' => 'lokasyon'], $implant);

        $scores = app(ContentScore::class)->forSuggestions(Suggestion::query()->whereKey([$low->id, $update->id, $top->id])->get());

        $this->assertSame(100, $scores[$top->id]['score'], '★ service, largest cluster, no page, location page');
        $this->assertSame(58, $scores[$low->id]['score'], 'no cluster: demand 0,5, no service 0,3, blog 0,5');
        $this->assertSame(['talep verisi yok'], $scores[$low->id]['notes']);
        $this->assertSame(56, $scores[$update->id]['score'], 'secondary service, page already scores 75');
        $this->assertSame(0.67, $scores[$update->id]['parts']['demand'], 'log scale against the brand\'s largest cluster');

        Livewire::test(WorkPage::class)->assertSeeHtml('data-score="100"')->assertSeeHtml('data-score="58"')
            ->assertSeeInOrder(['Çankaya implant tedavisi', 'Diş fırçası nasıl seçilir', 'Zirkonyum kaplama sayfasını güncelle']);
    }

    public function test_one_click_writes_the_highest_scores_first(): void
    {
        $cluster = $this->cluster($this->implant, 'İmplant', ['implant']);
        $this->volume($cluster, 500);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'state' => 'no_page']);
        $blog = $this->idea('İmplant blog yazısı', ['kind' => 'new', 'page_type' => 'blog']);
        $service = $this->idea('İmplant hizmet sayfası', ['kind' => 'new', 'page_type' => 'hizmet'], $cluster);

        $scores = app(ContentScore::class)->forSuggestions(collect([$blog->fresh(), $service->fresh()]));

        $this->assertGreaterThan($scores[$blog->id]['score'], $scores[$service->id]['score']);
        $this->assertStringContainsString('Talep 1 · Hizmet 1 · Boşluk 1 · Niyet 1', ContentScore::explain($scores[$service->id]));
    }

    private function volume(Cluster $cluster, int $total): void
    {
        $ids = DB::table('cluster_queries')->where('cluster_id', $cluster->id)->pluck('query_id');
        Query::query()->whereKey($ids->first())->update(['volume' => $total]);
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $title, array $action, ?Cluster $cluster = null): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5($title), 'material_hash' => md5($title),
            'title' => $title, 'reason' => 'x', 'priority' => 3, 'evidence' => [], 'action_type' => 'content', 'cluster_id' => $cluster?->id,
            'action' => ['site_id' => $this->site->id] + $action, 'status' => Suggestion::OPEN,
            'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
