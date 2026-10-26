<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Portfolio\BrandCoverageCard;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\ContentSiteState;
use App\Models\Suggestion;
use App\Services\Brand\BrandDossier;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\ClusterCoverage;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * Küme kapsaması (yakup, 2026-10-26: "bir markada kaç küme için içerik yazılmadı, hangileri yazıldı bilmiyorum"): per
 * service the clusters with a sufficient page, a thin one or none, which got an article, what is written next; the
 * brand file counts each cluster once (main language); pages that lost their clicks become refresh candidates.
 */
final class ClusterCoverageTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Europe/Istanbul'));
    }

    private function row(Cluster $cluster, string $state, ?int $pageId = null, string $language = 'tr'): BrandClusterPage
    {
        return BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id, 'page_id' => $pageId,
            'state' => $state, 'language' => $language]);
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $title, Cluster $cluster, string $status, array $action = []): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => hash('sha256', $title), 'material_hash' => 'm',
            'title' => $title, 'reason' => 'Küme boş.', 'priority' => 3, 'action_type' => SiteSuggestionTypes::CONTENT, 'cluster_id' => $cluster->id,
            'status' => $status, 'action' => array_merge(['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog'], $action),
            'evidence' => [], 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_each_service_shows_its_covered_thin_and_empty_clusters_and_which_got_an_article(): void
    {
        $page = $this->page('/implant', 'İmplant tedavisi', ['category' => 'hizmet']);
        $price = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları']);
        $pain = $this->cluster($this->implant, 'İmplant ağrısı', ['implant ağrısı']);
        $brands = $this->cluster($this->implant, 'İmplant markaları', ['implant markaları']);
        $care = $this->cluster($this->implant, 'İmplant sonrası bakım', ['implant sonrası bakım']);
        $zircon = $this->cluster($this->zirkonyum, 'Zirkonyum fiyatı', ['zirkonyum fiyatı']);
        $this->row($price, 'sufficient', $page->id);
        $this->row($price, 'no_page', null, 'en'); // translation target: never counted
        $this->row($pain, 'thin_coverage', $page->id);
        $this->row($brands, 'no_page');
        $this->row($care, 'no_page');
        $this->row($zircon, 'no_page');
        $this->idea('İmplant ağrısı ne kadar sürer', $pain, Suggestion::APPLIED, ['article_write_id' => 9]);
        $this->idea('İmplant markaları karşılaştırması', $brands, Suggestion::APPROVED);
        $this->idea('Reddedilen fikir', $care, Suggestion::DISMISSED);
        ContentSiteState::for($this->site)->forceFill(['active_service_ids' => [$this->implant->id]])->save();

        [$site] = app(ClusterCoverage::class)->forBrand($this->brand);

        $this->assertSame([5, 1, 1, 3, 1, 1], [$site['total'], $site['ok'], $site['thin'], $site['none'], $site['sent'], $site['planned']]);
        $implant = collect($site['services'])->firstWhere('service_id', $this->implant->id);
        $this->assertTrue($implant['active']);
        $this->assertTrue($implant['main']);
        $this->assertSame([4, 1, 1, 2], [$implant['total'], $implant['ok'], $implant['thin'], $implant['none']]);
        $this->assertSame('Zirkonyum Kaplama', $site['next']);

        $clusters = collect(app(ClusterCoverage::class)->clusters($this->brand, $this->site, $this->implant->id))->keyBy('name');
        // Empty clusters first, sufficient last.
        $this->assertSame('İmplant fiyatları', $clusters->keys()->last());
        $this->assertSame(['writing', 'none', 'sent', 'covered'], [$clusters['İmplant markaları']['content'], $clusters['İmplant sonrası bakım']['content'],
            $clusters['İmplant ağrısı']['content'], $clusters['İmplant fiyatları']['content']]);

        Livewire::test(BrandCoverageCard::class, ['brandId' => $this->brand->id])
            ->assertSee('Küme kapsaması')->assertSee('5 küme · 1 yeterli · 1 kapsam yetersiz · 3 sayfa yok')->assertSee('Sıradaki:')
            ->call('toggle', $this->site->id, $this->implant->id)
            ->assertSee('İmplant sonrası bakım')->assertSee('Henüz plan yok')->assertSee('Yazı gönderildi')->assertSee('Yazılıyor');
    }

    public function test_the_brand_file_counts_each_cluster_once_in_the_main_language(): void
    {
        $price = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları']);
        $this->row($price, 'no_page');
        $this->row($price, 'no_page', null, 'en');
        $this->row($price, 'no_page', null, 'de');

        $file = app(BrandDossier::class)->build($this->brand);

        $this->assertStringContainsString('Küme sayfaları: uygun sayfa yok 1', json_encode($file, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_a_page_that_lost_its_clicks_is_a_refresh_candidate(): void
    {
        $falling = $this->page('/blog/implant-agrisi', 'İmplant ağrısı');
        $steady = $this->page('/blog/implant-bakimi', 'İmplant bakımı');
        $small = $this->page('/blog/implant-markasi', 'İmplant markası');
        $this->fact('implant ağrısı', '/blog/implant-agrisi', 900, 60, 5.0, '2026-06-20');
        $this->fact('implant ağrısı', '/blog/implant-agrisi', 400, 10, 9.0, '2026-10-10');
        $this->fact('implant bakımı', '/blog/implant-bakimi', 500, 30, 4.0, '2026-06-20');
        $this->fact('implant bakımı', '/blog/implant-bakimi', 500, 28, 4.0, '2026-10-10');
        $this->fact('implant markası', '/blog/implant-markasi', 100, 8, 6.0, '2026-06-20');
        $this->fact('implant markası', '/blog/implant-markasi', 100, 1, 9.0, '2026-10-10');

        $declining = app(ContentPlanner::class)->decliningPages($this->site, 'tr');

        $this->assertSame([$falling->id], array_map(fn (array $d): int => (int) $d['page']->id, $declining));
        $this->assertSame([10, 60], [$declining[0]['clicks'], $declining[0]['previous']]);
        $this->assertNotContains($steady->id, array_map(fn (array $d): int => (int) $d['page']->id, $declining));
        $this->assertNotContains($small->id, array_map(fn (array $d): int => (int) $d['page']->id, $declining));
    }
}
