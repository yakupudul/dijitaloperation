<?php

namespace Tests\Feature\Portfolio;

use App\Livewire\Operator\Portfolio\BrandApprovalsCard;
use App\Livewire\Operator\Portfolio\BrandFactsCard;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\BrandQuery;
use App\Models\Query;
use App\Models\Suggestion;
use App\Services\Brand\BrandFacts;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Repair\RepairDesk;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * Marka sayfası (panel önerileri §3, 2026-10-11): Özet opens with the period numbers, then the brand's approvals on the
 * Onarım masası, then Yolculuk (linked boxes, the step losing the most in red); Marka bilgi kartı and Kimlik tutarlılığı
 * live under Bilgi dosyası; services are chips with ★ first and at most ten before "+N hizmet".
 */
final class BrandOverviewLayoutTest extends SiteTestCase
{
    public function test_ozet_order_is_numbers_approvals_journey_and_the_cards_moved_to_the_file_tab(): void
    {
        $this->site->forceFill(['status' => 'active'])->save();
        $page = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);
        foreach (['Başlık 1', 'Başlık 2'] as $title) {
            Suggestion::query()->create([
                'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.test', 'fingerprint' => hash('sha256', $title),
                'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => 'title_description',
                'status' => Suggestion::OPEN, 'action' => ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => $title.' yeni']]], 'evidence' => [],
                'page_id' => $page->id,
            ]);
        }
        $package = $this->brand->id.'.'.RepairDesk::SITE_FIELDS.'.'.RepairDesk::LANE_READY;

        $view = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtmlInOrder(['data-brand-kpis', 'operator.portfolio.brand-approvals-card', 'data-brand-journey', 'data-brand-assets'])
            ->assertDontSeeHtml('data-brand-file-tab');
        $approvals = Livewire::test(BrandApprovalsCard::class, ['brandId' => $this->brand->id])
            ->assertSeeHtml('data-brand-approvals="1"')
            ->assertSee(route('operator.repair', ['marka' => $this->brand->id]), false)
            ->assertSee(route('operator.repair', ['marka' => $this->brand->id, 'paket' => $package]))
            ->assertSee(RepairDesk::KINDS[RepairDesk::SITE_FIELDS]);
        $this->assertSame(1, $approvals->viewData('approvals')['total'], 'one package');
        $this->assertSame(2, $approvals->viewData('approvals')['rows']);

        $view->call('setTab', 'dosya')->assertSeeHtml('data-brand-file-tab')
            ->assertSeeHtml('operator.portfolio.brand-facts-card')->assertSeeHtml('operator.portfolio.brand-identity-card');
    }

    public function test_the_journey_links_each_box_and_marks_the_step_losing_the_most(): void
    {
        $this->site->forceFill(['status' => 'active'])->save();
        $clusters = [];
        foreach (['İmplant fiyatları', 'İmplant markaları', 'Tek diş implant'] as $n => $name) {
            $clusters[] = $this->cluster($this->implant, $name, [mb_strtolower($name), mb_strtolower($name).' ankara']);
        }
        foreach (Query::query()->pluck('id') as $id) {
            BrandQuery::query()->create(['brand_id' => $this->brand->id, 'query_id' => $id, 'language' => 'tr', 'impressions_28d' => 10]);
        }
        foreach ($clusters as $n => $cluster) {
            BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
                'state' => $n === 0 ? 'strong' : 'no_page', 'language' => 'tr']);
        }
        Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5('idea'), 'material_hash' => md5('idea'),
            'title' => 'İmplant fiyatı neye göre değişir', 'reason' => 'Talep var.', 'priority' => 1, 'action_type' => 'content',
            'action' => ['site_id' => $this->site->id, 'kind' => 'new'], 'status' => Suggestion::OPEN, 'target_type' => 'site', 'target_id' => $this->site->id,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $journey = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtml('data-journey-worst')
            ->assertSee('En çok kayıp: 2 kümenin sayfası yok ya da zayıf.')
            ->assertSee(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'kumeler', 'eslesme' => 'eslenmedi']))
            ->assertSee(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'fikirler']), false)
            ->viewData('journey');
        $this->assertNull($journey['broken']);
        $this->assertSame('matched', $journey['worst']['key']);
        $this->assertSame(route('operator.brand', ['brand' => $this->brand->id, 'tab' => 'ayarlar']), collect($journey['stages'])->firstWhere('key', 'assigned')['url']);
    }

    public function test_services_are_chips_with_main_ones_first_and_at_most_ten_before_more(): void
    {
        $catalog = app(ServiceCatalogService::class);
        foreach (range(1, 11) as $n) {
            $service = $catalog->resolveOrCreate('Hizmet '.$n, 'dental', actor: $this->admin)['service'];
            BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $service->id, 'status' => 'active', 'priority' => 'secondary']);
        }
        $total = BrandOffering::query()->where('brand_id', $this->brand->id)->where('status', 'active')->count();

        $view = Livewire::test(BrandShow::class, ['brand' => (string) $this->brand->id])
            ->assertSeeHtml('data-services-more')->assertSee('+'.($total - BrandShow::SERVICE_CHIPS).' hizmet');
        $rows = $view->viewData('serviceSummary')['rows'];
        $this->assertCount($total, $rows);
        $this->assertTrue($rows[0]['is_priority'], 'the ★ service comes first');

        app(BrandFacts::class)->build($this->brand);
        Livewire::test(BrandFactsCard::class, ['brandId' => $this->brand->id])
            ->assertSeeHtml('data-fact-chips')->assertSeeHtml('data-fact-chips-more')->assertSeeHtml('data-fact-actions')
            ->assertSeeHtml('aria-label="Ana hizmet"');
    }
}
