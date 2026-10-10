<?php

namespace Tests\Feature\Site;

use App\Ai\Agents\Site\PageCategoriesAgent;
use App\Livewire\Operator\Website\V2\ContentIdeasTab;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\Brand\BrandGaps;
use App\Services\Site\Analysis\SitePagesReader;
use App\Services\Site\PageCategorizer;
use App\Services\Site\ServicePageMapper;
use App\Services\Site\SiteOperations;
use App\Support\Ai\AiOperationLabels;
use Livewire\Livewire;

/**
 * Bornova Hurda: thousands of "/hurda/…" articles and "/kws/izmir-…-mahallesi-hurdaci" keyword pages must never become
 * main service pages; the nightly upkeep does not send hundreds of pages to AI on its own; pending clusters of the
 * brand's services are listed (and approved) on İçerik fikirleri.
 */
final class PageTemplateSectionsTest extends SiteTestCase
{
    public function test_template_and_archive_sections_are_never_service_pages_and_lose_their_service_links(): void
    {
        $real = $this->page('/implant/', 'Diş İmplantı');
        for ($i = 1; $i <= PageCategorizer::BULK_SECTION; $i++) {
            $this->page('/dis/dis-implanti-fiyatlari-'.$i.'/', 'Diş İmplantı fiyatları '.$i);
        }
        $place = $this->page('/dis/cankaya-dis-implanti/', 'Çankaya diş implantı');
        $wrong = Page::query()->where('path', '/dis/dis-implanti-fiyatlari-1/')->firstOrFail();
        $wrong->forceFill(['category' => 'hizmet', 'category_source' => 'ai'])->save();
        $keyword = $this->page('/kws/ankara-cankaya-implant/', 'Ankara Çankaya implant');
        OfferingPage::query()->create(['brand_offering_id' => $this->implantOffering->id, 'page_id' => $wrong->id, 'source' => 'ai', 'locked' => false]);

        $this->assertSame(['dis'], PageCategorizer::bulkSections((int) $this->site->id));
        $result = app(PageCategorizer::class)->categorize($this->site, useAi: false);

        $this->assertSame('ready', $result['status']);
        $this->assertSame('hizmet', $real->fresh()->category);
        $this->assertSame('blog', $wrong->fresh()->category, 'an AI "hizmet" in a template section is corrected by the rules');
        $this->assertSame('rule', $wrong->fresh()->category_source);
        $this->assertSame('lokasyon', $place->fresh()->category);
        $this->assertSame('diger', $keyword->fresh()->category);

        $this->assertSame(1, ServicePageMapper::prune($this->site));
        $this->assertFalse(OfferingPage::query()->where('page_id', $wrong->id)->exists());

        $rows = app(SitePagesReader::class)->rows($this->site->fresh(), 28);
        $main = array_keys(array_filter($rows, fn (array $row): bool => $row['is_main']));
        $this->assertSame(1, count($main), 'only the real service page is a main service page');
        $this->assertStringContainsString('/implant', $main[0]);

        // The services gap never proposes template pages as missing services.
        $this->assertSame([], BrandGaps::uncoveredServicePages($this->site, ['Diş İmplantı', 'Zirkonyum Kaplama']));
    }

    public function test_unattended_setup_sends_a_slice_of_pages_to_ai_shallow_first_and_the_rest_follows(): void
    {
        $this->enableAi();
        $asked = [];
        PageCategoriesAgent::fake(function (string $prompt) use (&$asked): array {
            $asked[] = $prompt;

            return ['pages' => []];
        });
        $this->page('/hurda/bakir/eski-kablo-alimi/', 'Eski kablo alımı');
        for ($i = 1; $i <= PageCategorizer::UNATTENDED_AI_LIMIT; $i++) {
            $this->page('/sayfa-'.$i.'/', 'Sayfa '.$i);
        }

        $result = app(PageCategorizer::class)->categorize($this->site, onlyNew: true, aiLimit: PageCategorizer::UNATTENDED_AI_LIMIT);

        $this->assertSame('partial', $result['status']);
        $this->assertCount((int) ceil(PageCategorizer::UNATTENDED_AI_LIMIT / PageCategorizer::AI_BATCH), $asked, 'one night: one slice');
        $this->assertStringNotContainsString('eski-kablo-alimi', implode("\n", $asked), 'deep paths wait for the next night');
        $gaps = collect(app(BrandGaps::class)->detect($this->brand))->keyBy('key');
        $this->assertTrue($gaps->has('categories:'.$this->site->id));
        $this->assertSame(BrandGaps::FIX_SITE_SETUP, $gaps['categories:'.$this->site->id]['fix']);
        $this->assertSame('Site hazırlığı (sayfa sınıflandırma, hizmet ↔ sayfa)', AiOperationLabels::for('site.setup'));
        $this->assertSame('Site · Yeniden keşfet', AiOperationLabels::for('site.'.SiteOperations::REDISCOVER));
    }

    public function test_pending_clusters_of_the_brand_services_are_listed_and_approved_on_content_ideas(): void
    {
        $approved = $this->cluster($this->implant, 'İmplant fiyatları', ['implant fiyatları']);
        $pending = $this->cluster($this->implant, 'Tek gün implant', ['tek gün implant']);
        $pending->forceFill(['approved' => false])->save();
        $other = $this->cluster($this->zirkonyum, 'Zirkonyum kaplama', ['zirkonyum kaplama']);
        $other->forceFill(['approved' => false])->save();

        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])
            ->assertSee('Onay bekleyen kümeler (2)')
            ->assertSee('Tek gün implant')
            ->assertDontSee('data-approve-cluster="'.$approved->id.'"', false)
            ->call('approveCluster', $pending->id)
            ->assertSee('1 küme onaylandı');

        $this->assertTrue($pending->fresh()->approved);
        $this->assertFalse($other->fresh()->approved);
        $this->assertTrue(BrandClusterPage::query()->where('website_asset_id', $this->site->id)->where('cluster_id', $pending->id)->exists(), 'its row lands on the site');

        Livewire::test(ContentIdeasTab::class, ['assetId' => $this->site->id])->call('approveAllClusters');
        $this->assertSame(0, Cluster::query()->where('approved', false)->count());
    }
}
