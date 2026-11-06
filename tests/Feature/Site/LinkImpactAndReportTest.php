<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\WriteLinkPack;
use App\Models\BacklinkSource;
use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\LinkTarget;
use App\Models\Page;
use App\Services\Site\Backlinks\LinkDataStory;
use App\Services\Site\Backlinks\LinkDesk;
use App\Services\Site\Backlinks\LinkTargets;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InsertsFacts;

/**
 * Backlink hizmeti, adım 5 (2026-11-06): a target's numbers are kept 60 and 90 days after its first verified link, the
 * brand's most asked questions become a quarterly press text for local news, and the month's link report is a plain
 * page to print or share.
 */
final class LinkImpactAndReportTest extends SiteTestCase
{
    use InsertsFacts;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_the_numbers_are_kept_60_and_90_days_after_the_first_link_and_the_report_shows_the_month(): void
    {
        $page = $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
        $row = $this->row($this->cluster($this->implant, 'Ankara implant', ['ankara implant']), $page, 9.4, 800, 'ankara implant');
        app(LinkTargets::class)->refresh($this->brand);
        $target = LinkTarget::query()->sole();
        $link = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Ankara Haber', 'url' => 'https://haber.example/', 'domain' => 'haber.example',
            'status' => BacklinkSource::VERIFIED, 'verified_at' => now(), 'link_target_id' => $target->id, 'engine' => 'anilma', 'anchor' => 'Panorama Ankara', 'rel' => 'follow',
            'link_url' => 'https://haber.example/yazi']);
        $targets = app(LinkTargets::class);

        $this->assertSame(0, $targets->measure($this->brand));
        $this->assertSame(now()->toDateString(), $target->fresh()->impact['first_link_at']);

        $this->travel(61)->days();
        BrandClusterPage::query()->whereKey($row->id)->update(['position_28d' => 6.2, 'clicks_28d' => 40]);
        $this->assertSame(1, $targets->measure($this->brand));
        $this->assertSame([6.2, 40], [$target->fresh()->impact['d60']['position'], $target->fresh()->impact['d60']['clicks']]);
        $this->assertSame(0, $targets->measure($this->brand), 'kept once');
        $this->travel(30)->days();
        BrandClusterPage::query()->whereKey($row->id)->update(['position_28d' => 4.8]);
        $this->assertSame(1, $targets->measure($this->brand));
        $this->assertSame(4.8, $target->fresh()->impact['d90']['position']);

        $month = $link->verified_at->timezone('Europe/Istanbul')->format('Y-m');
        $this->get(route('operator.links.report', ['brand' => $this->brand->id, 'month' => $month]))->assertOk()
            ->assertSee('Bağlantı raporu')->assertSee('Ankara Haber')->assertSee('Ankara İmplant Tedavisi')->assertSee('60. gün: 6,2. sıra', false)->assertSee('90. gün: 4,8. sıra', false);
        $this->get(route('operator.links.report', ['brand' => $this->brand->id]))->assertOk()->assertSee('Bu ay doğrulanan yeni link yok.');
    }

    public function test_the_most_asked_questions_become_a_quarterly_press_text(): void
    {
        $insert = fn (string $query, int $impressions) => $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => $this->site->id, 'site_url' => 'sc-domain:panorama.com.tr', 'page' => 'https://panorama.com.tr/',
            'reporting_date' => now()->subDays(10)->toDateString(), 'query' => $query, 'clicks' => 1, 'impressions' => $impressions, 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $query), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['implant tedavisi ne kadar sürer' => 900, 'implant ağrılı mı' => 800, 'implant kaç yıl dayanır' => 700, 'zirkonyum kaplama nedir' => 600,
            'implant sonrası ne yenir' => 500, 'panorama ankara implant ne kadar' => 999, 'ankara implant' => 2000, 'diş eti çekilmesi neden olur' => 400] as $query => $n) {
            $insert($query, $n);
        }

        $questions = LinkDataStory::questions($this->brand);
        $this->assertSame(['implant tedavisi ne kadar sürer', 'implant ağrılı mı', 'implant kaç yıl dayanır', 'zirkonyum kaplama nedir', 'implant sonrası ne yenir',
            'diş eti çekilmesi neden olur'], $questions, 'questions only, most asked first, never the brand\'s own name');

        $this->brand->forceFill(['link_package' => 'temel'])->save();
        app(LinkDesk::class)->fill($this->brand->fresh());
        $story = BacklinkSource::query()->where('engine', 'veri')->sole();
        $this->assertSame(['queued', 'masa', 'veri-'.$this->brand->id.'-'.LinkDataStory::quarter()], [$story->material_status, $story->lane, $story->domain]);
        $this->assertStringContainsString('implant tedavisi ne kadar sürer', $story->reason);
        Queue::assertPushed(WriteLinkPack::class, fn (WriteLinkPack $job): bool => true);
        $this->assertNull(LinkDataStory::propose($this->brand), 'once a quarter');
    }

    private function row(Cluster $cluster, Page $page, float $position, int $impressions, string $query): BrandClusterPage
    {
        $row = BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $cluster->id, 'website_asset_id' => $this->site->id,
            'page_id' => $page->id, 'state' => 'weak_performance', 'target_query' => $query, 'language' => 'tr', 'clicks_28d' => 12,
            'impressions_28d' => $impressions, 'position_28d' => $position]);
        $row->forceFill(['coverage' => 'full'])->save();

        return $row;
    }
}
