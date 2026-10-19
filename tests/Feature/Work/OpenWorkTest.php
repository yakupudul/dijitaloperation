<?php

namespace Tests\Feature\Work;

use App\Models\Suggestion;
use App\Services\Brand\BrandDossier;
use App\Services\Operator\BrandOverviewReader;
use App\Services\Operator\PortfolioSignalsReader;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\SiteAudit;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Site\SiteMetrics;
use App\Services\Work\OpenWork;
use App\Services\Work\PageTraffic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-11: the brand file said "Toplam N açık iş · … Arama 10672" — a number nobody can act on. Open work is
 * counted and listed in packages (same brand + same work type = one item) by one reader, ordered by expected gain, and
 * the brand file, the brand page, the portfolio lists, Bugün and the desk all agree.
 */
final class OpenWorkTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_the_same_type_of_work_is_one_package_and_every_screen_counts_the_same(): void
    {
        foreach (range(1, 5) as $i) {
            $this->work('search', SiteAudit::TYPE, 'Başlık ve açıklamayı düzelt: /kw/sayfa-'.$i.'/', ['page_id' => $this->page('/kw/sayfa-'.$i.'/', 'Sayfa '.$i)->id]);
        }
        $this->work('google_ads', 'ads_check', 'Bütçe', ['action' => ['check' => 'budget']]);
        $this->work('google_ads', 'ads_check', 'Dönüşüm', ['action' => ['check' => 'conversion_tracking']]);
        $this->work('maps', 'gbp_standard', 'Saat', ['action' => ['standard_id' => 'hours']]);
        $this->work('search', SiteAudit::TYPE, 'Kapandı', ['status' => Suggestion::APPLIED]);
        $this->work('search', 'content', 'Ertelendi', ['status' => Suggestion::SNOOZED, 'snoozed_until' => now()->addDay()]);

        $counts = app(OpenWork::class)->counts([(int) $this->brand->id])[$this->brand->id];
        $this->assertSame(['packages' => 4, 'items' => 8], ['packages' => $counts['packages'], 'items' => $counts['items']]);
        $this->assertEquals(['search' => 1, 'google_ads' => 2, 'maps' => 1], $counts['by_channel'], 'two different Ads checks are two kinds of work');

        $packages = app(OpenWork::class)->packages((int) $this->brand->id);
        $this->assertCount(4, $packages);
        $this->assertSame('SEO başlık / açıklama: 5 sayfa', OpenWork::line($packages->firstWhere('channel', 'search')));
        $this->assertContains('Bütçe kullanımı', $packages->pluck('label')->all());
        $this->assertContains('Saat', $packages->pluck('label')->all(), 'a profile standard is named by its title, never "Gbp_standard"');
        $this->assertSame('Google Ads ayarı', OpenWork::label(['channel' => 'google_ads', 'action_type' => 'ads_change', 'title' => 'Bütçeyi artır']));
        $this->assertSame('Harita işi', OpenWork::label(['channel' => 'maps', 'action_type' => 'some_new_check', 'title' => 'Yeni kontrol']), 'an unknown code is never shown');

        $brandPage = app(BrandOverviewReader::class)->openWork($this->brand, null, 10);
        $this->assertSame(4, $brandPage['total']);
        $this->assertSame(8, $brandPage['rows']);
        $this->assertEquals(['Arama' => 1, 'Google Ads' => 2, 'Harita' => 1], $brandPage['by_channel'], 'per channel in packages');

        $signals = app(PortfolioSignalsReader::class)->forBrands(collect([$this->brand->fresh()]));
        $this->assertSame(4, $signals['brands'][$this->brand->id]['open_work']);

        $file = app(BrandDossier::class)->build($this->brand)['sections']['open']['markdown'];
        $this->assertStringContainsString('Toplam 4 iş paketi (8 açık satır)', $file);
        $this->assertStringContainsString('[Arama] SEO başlık / açıklama: 5 sayfa', $file);
        $this->assertStringNotContainsString('Toplam 8', $file);
    }

    public function test_packages_are_ordered_by_expected_gain_and_unseen_pages_come_last(): void
    {
        $seen = $this->page('/implant/', 'İmplant');
        $unseen = $this->page('/kw/bakir-boru/', 'Bakır boru');
        $this->fact('implant ankara', '/implant/', 900, 40, 6.0, '2026-09-20');
        $this->work('search', 'title_description', 'Başlık / açıklama: /kw/bakir-boru/', ['page_id' => $unseen->id, 'priority' => 1]);
        $this->work('search', 'internal_links', 'İç bağlantı: /implant/', ['page_id' => $seen->id, 'priority' => 3]);
        $this->work('google_ads', 'ads_check', 'Bütçe', ['action' => ['check' => 'budget'], 'priority' => 2]);

        $packages = app(OpenWork::class)->packages((int) $this->brand->id);

        $this->assertSame(['internal_links', 'ads_check', 'title_description'], $packages->map(fn (array $p): string => (string) $p['sample'][0]['action_type'])->all(),
            'a seen page with 900 impressions first, work without a page by its priority, a critical row on a page Google never showed last');
        $this->assertSame([900, 40], [$packages[0]['impressions'], $packages[0]['clicks']]);
        $this->assertTrue($packages[2]['unseen']);
        $this->assertSame(0.0, $packages[2]['gain']);
        $this->assertFalse($packages[1]['measured']);

        $file = app(BrandDossier::class)->build($this->brand)['sections']['open']['markdown'];
        $this->assertStringContainsString('90 günde 900 gösterim, 40 tıklama', $file);
        $this->assertStringContainsString('sayfaları 90 günde aramada görünmedi', $file);
        $this->assertLessThan(strpos($file, 'Bakır boru') ?: strpos($file, 'bakir-boru'), strpos($file, 'İç bağlantı'));
    }

    public function test_page_stakes_are_read_from_the_warmed_cache_without_scanning_search_console_on_open(): void
    {
        $page = $this->page('/implant/', 'İmplant');
        $this->fact('implant ankara', '/implant/', 900, 40, 6.0, '2026-09-20');

        $this->assertSame(1, app(PageTraffic::class)->warm());
        DB::enableQueryLog();
        $traffic = app(PageTraffic::class)->forPages([$page->id]);
        $scans = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'gsc_query_page_daily'))->count();

        $this->assertSame(['impressions' => 900, 'clicks' => 40], $traffic[$page->id]);
        $this->assertSame(0, $scans, 'Bugün / desk open: 13 Oct 2026 every open re-scanned Search Console per site (~10 s)');
        $this->assertSame('2026-09-20', app(SiteMetrics::class)->lastGscDay($this->brand)?->toDateString());
    }

    public function test_last_search_console_day_counts_web_rows_only_and_is_shared_by_the_brand_page(): void
    {
        $this->fact('implant', '/implant/', 300, 10, 5.0, '2026-09-18');
        $this->fact('implant', '/implant/', 5, 1, 2.0, '2026-09-25', 'image');

        $this->assertSame('2026-09-18', app(SiteMetrics::class)->lastSearchConsoleDay([$this->gsc->id, $this->gsc->id]));
        $this->fact('implant', '/implant/', 300, 10, 5.0, '2026-09-19');
        $this->assertSame('2026-09-18', app(SiteMetrics::class)->lastSearchConsoleDay([$this->gsc->id]), 'cached for an hour');
        $this->assertSame('2026-09-19', app(SiteAnalysisReader::class)->lastDay($this->site)->toDateString(), 'the brand page reads it fresh');
        $this->assertSame('2026-09-19', app(SiteMetrics::class)->lastSearchConsoleDay([$this->gsc->id]), 'and refreshes the cache');
        $this->assertNull(app(SiteMetrics::class)->lastSearchConsoleDay([]));
    }

    public function test_desk_packages_and_brand_health_use_the_same_gain_order(): void
    {
        $seen = $this->page('/implant/', 'İmplant');
        $unseen = $this->page('/kw/bakir-boru/', 'Bakır boru');
        $this->fact('implant', '/implant/', 300, 10, 5.0, '2026-09-20');
        foreach ([[$unseen, 'title_description', 1], [$seen, 'title_description', 3]] as $n => [$page, $type, $priority]) {
            $this->work('search', $type, 'Başlık: '.$page->path, ['page_id' => $page->id, 'priority' => $priority,
                'action' => ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => 'Yeni başlık '.$n]]]]);
        }
        $desk = app(RepairDesk::class);
        $rows = $desk->rows();
        $byPage = $rows->keyBy('page_id');

        $this->assertSame(300, $byPage[$seen->id]['impressions']);
        $this->assertSame(0, $byPage[$unseen->id]['impressions']);
        $this->assertSame(0.0, RepairDesk::gain($byPage[$unseen->id]), 'even a critical fix gains nothing on a page Google did not show');
        $this->assertGreaterThan(1.0, RepairDesk::gain($byPage[$seen->id]));
        $this->assertSame($seen->id, $desk->packages($rows)->first()['sample'][0]['page_id'], 'the seen page leads its package');

        $health = $desk->health($rows)->first();
        $this->assertSame([1, 2], [$health['open'], $health['rows']], 'one package of two fixes');
    }

    /** @param  array<string, mixed>  $extra */
    private function work(string $channel, string $type, string $title, array $extra = []): Suggestion
    {
        return Suggestion::query()->create($extra + [
            'brand_id' => $this->brand->id, 'channel' => $channel, 'decision_key' => 'test.'.$type, 'fingerprint' => Str::random(40),
            'material_hash' => 'm', 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'evidence' => [], 'action_type' => $type,
            'target_type' => isset($extra['page_id']) ? 'page' : null, 'target_id' => $extra['page_id'] ?? null,
            'status' => Suggestion::OPEN, 'action' => [],
        ]);
    }
}
