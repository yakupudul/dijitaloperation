<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\ClusterPagesAgent;
use App\Ai\Agents\Site\PageCategoriesAgent;
use App\Ai\Agents\Site\ServicePagesAgent;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Site\ClusterPageMapper;
use App\Services\Site\PageCategorizer;
use App\Services\Site\ServicePageMapper;

/**
 * Faz 4a: URL categorization (rules → one AI batch → operator lock), AI adım 1 (service ↔ page) and AI adım 2
 * (cluster ↔ page, 7 states from Search Console query × page facts, coverage and service links).
 */
final class SiteMappingTest extends SiteTestCase
{
    public function test_categorization_rules_first_unsure_pages_in_one_ai_call_and_operator_lock(): void
    {
        $this->enableAi();
        $pages = [
            'home' => $this->page('/', 'Panorama Ankara Diş Kliniği'),
            'about' => $this->page('/hakkimizda/', 'Hakkımızda'),
            'faq' => $this->page('/sikca-sorulan-sorular/', 'Sıkça Sorulan Sorular'),
            'post' => $this->page('/implant-mi-kopru-mu/', 'İmplant mı köprü mü?', ['wp_post_type' => 'post']),
            'blog' => $this->page('/blog/implant-sonrasi-agri/', 'İmplant sonrası ağrı'),
            'implant' => $this->page('/implant/', 'Ankara İmplant Tedavisi', ['wp_post_type' => 'page']),
            'location' => $this->page('/cankaya-dis-klinigi/', 'Çankaya Diş Kliniği'),
            'campaign' => $this->page('/kampanyalar/', 'Kampanyalar'),
            'locked' => $this->page('/yeni-hizmet/', 'Yeni Hizmet', ['category' => 'blog', 'category_locked' => true, 'category_source' => 'manual']),
        ];
        $prompts = [];
        PageCategoriesAgent::fake(function (string $prompt) use (&$prompts, $pages): array {
            $prompts[] = $prompt;

            return ['pages' => [
                ['page_id' => $pages['campaign']->id, 'category' => 'diger'],
                ['page_id' => 987654, 'category' => 'hizmet'],
                ['page_id' => $pages['locked']->id, 'category' => 'hizmet'],
            ]];
        });

        $result = app(PageCategorizer::class)->categorize($this->site);

        $this->assertSame('ready', $result['status']);
        $this->assertCount(1, $prompts, 'one batched AI call');
        $this->assertStringContainsString('/kampanyalar/', $prompts[0]);
        $this->assertStringNotContainsString('/hakkimizda/', $prompts[0], 'rule-decided pages are not sent');
        $categories = Page::query()->pluck('category', 'path')->all();
        $this->assertEquals(['/' => 'kurumsal', '/hakkimizda/' => 'kurumsal', '/sikca-sorulan-sorular/' => 'sss', '/implant-mi-kopru-mu/' => 'blog', '/blog/implant-sonrasi-agri/' => 'blog',
            '/implant/' => 'hizmet', '/cankaya-dis-klinigi/' => 'lokasyon', '/kampanyalar/' => 'diger', '/yeni-hizmet/' => 'blog'], $categories);
        $this->assertSame('ai', $pages['campaign']->fresh()->category_source);

        // Operator correction is locked: no rule or AI pass changes it.
        app(PageCategorizer::class)->setCategory($pages['implant']->fresh(), 'lokasyon');
        app(PageCategorizer::class)->categorize($this->site);
        $this->assertSame('lokasyon', $pages['implant']->fresh()->category);
        $this->assertTrue($pages['implant']->fresh()->category_locked);
    }

    public function test_service_pages_by_name_rule_then_ai_with_validated_ids_and_locked_operator_choice(): void
    {
        $this->enableAi();
        $implant = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
        $zirkonyum = $this->page('/zirkonyum-kaplama/', 'Zirkonyum Kaplama', ['category' => 'hizmet']);
        $smile = $this->page('/gulus-tasarimi/', 'Gülüş Tasarımı', ['category' => 'hizmet']);
        $blog = $this->page('/blog/implant/', 'İmplant rehberi', ['category' => 'blog']);
        $prompts = [];
        ServicePagesAgent::fake(function (string $prompt) use (&$prompts, $smile): array {
            $prompts[] = $prompt;

            return ['pages' => [['page_id' => $smile->id, 'service_id' => $this->zirkonyumOffering->id], ['page_id' => 424242, 'service_id' => $this->implantOffering->id]]];
        });

        $result = app(ServicePageMapper::class)->map($this->site);

        $this->assertSame(['status' => 'ready', 'rule' => 2, 'ai' => 1, 'unmatched' => 0], $result);
        $this->assertCount(1, $prompts);
        $this->assertStringNotContainsString('/implant/', $prompts[0], 'rule matches are not sent');
        $links = ServicePageMapper::links([$implant->id, $zirkonyum->id, $smile->id, $blog->id]);
        $this->assertSame($this->implantOffering->id, $links[$implant->id]['offering_id']);
        $this->assertSame('rule', $links[$implant->id]['source']);
        $this->assertSame($this->zirkonyumOffering->id, $links[$zirkonyum->id]['offering_id']);
        $this->assertSame('ai', $links[$smile->id]['source']);
        $this->assertArrayNotHasKey($blog->id, $links, 'blog posts are not service pages');

        app(ServicePageMapper::class)->setOffering($implant, $this->zirkonyumOffering->id);
        ServicePagesAgent::fake([['pages' => []]]);
        app(ServicePageMapper::class)->map($this->site);
        $link = OfferingPage::query()->where('page_id', $implant->id)->sole();
        $this->assertSame($this->zirkonyumOffering->id, $link->brand_offering_id);
        $this->assertTrue($link->locked, 'operator choice is locked');
    }

    public function test_cluster_page_states_from_search_console_facts_coverage_and_service_links(): void
    {
        $whitening = app(ServiceCatalogService::class)->resolveOrCreate('Diş Beyazlatma', 'dental', actor: $this->admin)['service'];
        BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $whitening->id, 'status' => 'active', 'priority' => 'secondary']);
        $implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet',
            'content_text' => 'İmplant süreci adım adım anlatılır. İyileşme süresi ve implant markaları karşılaştırılır. Tek dişte implant uygulaması yapılır.']);
        $zirkonyumPage = $this->page('/zirkonyum-kaplama/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'content_text' => 'Kaplama hakkında kısa bilgi.']);
        $this->page('/blog/implant-fiyatlari/', 'İmplant fiyatları', ['category' => 'blog']);
        $this->page('/blog/implant-sonrasi/', 'İmplant sonrası ağrı', ['category' => 'blog']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $implantPage->id, 'source' => 'rule']);
        OfferingPage::query()->create(['brand_offering_id' => $this->zirkonyumOffering->id, 'page_id' => $zirkonyumPage->id, 'source' => 'rule']);

        $clusters = [
            'sufficient' => $this->cluster($this->implant, 'İmplant tedavisi', ['implant tedavisi'], ['implant süreci', 'iyileşme süresi']),
            'weak_performance' => $this->cluster($this->implant, 'İmplant markaları', ['implant markaları'], ['implant markaları']),
            'possible_conflict' => $this->cluster($this->implant, 'İmplant fiyatı', ['implant fiyatları'], ['implant süreci']),
            'wrong_page' => $this->cluster($this->implant, 'İmplant sonrası', ['implant sonrası ağrı'], ['iyileşme süresi'], 'informational'),
            'insufficient_data' => $this->cluster($this->implant, 'Tek diş implant', ['tek dişte implant']),
            'thin_coverage' => $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama'], ['renk seçimi', 'kullanım ömrü', 'günlük bakım']),
            'no_page' => $this->cluster($whitening, 'Diş beyazlatma', ['diş beyazlatma']),
        ];
        $this->fact('implant tedavisi', '/implant/', 200, 20, 3.0);
        $this->fact('implant markaları', '/implant/', 100, 1, 18.0);
        $this->fact('implant fiyatları', '/implant/', 60, 3, 6.0);
        $this->fact('implant fiyatları', '/blog/implant-fiyatlari/', 60, 2, 7.0);
        $this->fact('implant sonrası ağrı', '/blog/implant-sonrasi/', 180, 9, 4.0);
        $this->fact('implant sonrası ağrı', '/implant/', 20, 0, 9.0);

        $result = app(ClusterPageMapper::class)->refresh($this->site);

        $this->assertSame('ready', $result['status']);
        $rows = BrandClusterPage::query()->get()->keyBy('cluster_id');
        foreach ($clusters as $state => $cluster) {
            $this->assertSame($state, $rows[$cluster->id]->state, $cluster->name.': '.$rows[$cluster->id]->reason);
        }
        $this->assertSame($implantPage->id, $rows[$clusters['sufficient']->id]->page_id);
        $this->assertSame('ankara implant tedavisi', $rows[$clusters['sufficient']->id]->target_query, 'area in front for commercial intent');
        $this->assertSame('implant sonrası ağrı', $rows[$clusters['wrong_page']->id]->target_query, 'no area for informational intent');
        $this->assertSame(20, $rows[$clusters['sufficient']->id]->clicks_28d);
        $this->assertEqualsWithDelta(18.0, $rows[$clusters['weak_performance']->id]->position_28d, 0.01);
        $this->assertStringContainsString('/blog/implant-sonrasi/', $rows[$clusters['wrong_page']->id]->reason);
        $this->assertNull($rows[$clusters['no_page']->id]->page_id);

        // Operator's URL / state is locked: a refresh only updates its numbers.
        app(ClusterPageMapper::class)->setManual($rows[$clusters['possible_conflict']->id], $implantPage->id, 'sufficient');
        app(ClusterPageMapper::class)->refresh($this->site);
        $locked = BrandClusterPage::query()->where('cluster_id', $clusters['possible_conflict']->id)->sole();
        $this->assertSame('sufficient', $locked->state);
        $this->assertTrue($locked->locked);
        $this->assertSame(60, $locked->impressions_28d);
    }

    public function test_ambiguous_coverage_goes_to_one_ai_call_per_service_and_answers_are_validated(): void
    {
        $this->enableAi();
        $implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'content_text' => 'İmplant süreci anlatılır. Kemik yapısı incelenir.']);
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $implantPage->id, 'source' => 'rule']);
        $half = $this->cluster($this->implant, 'İmplant süreci', ['implant nasıl yapılır'], ['implant süreci', 'kemik yapısı', 'dikiş alma', 'anestezi türleri']);
        $this->fact('implant nasıl yapılır', '/implant/', 300, 30, 2.0);
        $prompts = [];
        ClusterPagesAgent::fake(function (string $prompt) use (&$prompts, $half, $implantPage): array {
            $prompts[] = $prompt;

            return ['clusters' => [
                ['cluster_id' => $half->id, 'page_id' => $implantPage->id, 'state' => 'sufficient', 'reason' => 'Sayfa süreci ve kemik yapısını anlatıyor.'],
                ['cluster_id' => 99999, 'page_id' => $implantPage->id, 'state' => 'wrong_page', 'reason' => 'x'],
            ]];
        });

        $result = app(ClusterPageMapper::class)->refresh($this->site);

        $this->assertSame(1, $result['ai']);
        $this->assertCount(1, $prompts);
        $row = BrandClusterPage::query()->where('cluster_id', $half->id)->sole();
        $this->assertSame('sufficient', $row->state);
        $this->assertSame('ai', $row->decided_by);
        $this->assertSame('Sayfa süreci ve kemik yapısını anlatıyor.', $row->reason);
    }
}
