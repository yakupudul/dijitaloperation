<?php

namespace Tests\Feature\Repair;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Operator\PortfolioSignalsReader;
use App\Services\Repair\SiteAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-11: avrupadent.com.tr showed "6686 kritik öneri" — every page without a description was critical.
 * Critical is now a missing / duplicate title on a seen (Search Console, 90 days) or service / location page only;
 * description issues are 3–5; the rendered description of the crawl counts; Dikkat counts check types, not pages.
 */
final class SeoFieldSeverityTest extends SiteTestCase
{
    private const string DESCRIPTION = 'Çankaya şubemizde implant tedavisi süreci, kimlere uygun olduğu ve randevu adımları bu sayfada anlatılıyor.';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_without_search_console_data_a_missing_description_is_priority_3_and_a_service_title_critical(): void
    {
        $service = $this->wpPage('/implant/', 'Ankara İmplant Tedavisi | Panorama Diş', 42, ['category' => 'hizmet', 'meta_description' => null]);
        $blogTitle = $this->wpPage('/blog/yazi/', '', 43, ['category' => 'blog', 'meta_description' => self::DESCRIPTION]);
        $serviceTitle = $this->wpPage('/zirkonyum/', '', 44, ['category' => 'hizmet', 'meta_description' => self::DESCRIPTION.' Zirkonyum']);
        $long = $this->wpPage('/kopru/', 'Diş köprüsü tedavisi Çankaya | Panorama', 45, ['meta_description' => str_repeat('Diş köprüsü tedavisi hakkında bilgi. ', 6)]);

        app(SiteAudit::class)->audit($this->site);

        $priorities = Suggestion::query()->where('action_type', SiteAudit::TYPE)->pluck('priority', 'page_id');
        $this->assertSame(3, $priorities[$service->id], 'missing description');
        $this->assertSame(2, $priorities[$blogTitle->id], 'missing title on an unseen blog page');
        $this->assertSame(1, $priorities[$serviceTitle->id], 'missing title on a service page');
        $this->assertSame(4, $priorities[$long->id], 'description too long');
    }

    public function test_impressions_decide_title_criticality_and_unseen_description_issues_drop_to_5(): void
    {
        $seen = $this->wpPage('/blog/goruldu/', '', 42, ['category' => 'blog', 'meta_description' => self::DESCRIPTION]);
        $unseenTitle = $this->wpPage('/blog/gorulmedi/', '', 43, ['category' => 'blog', 'meta_description' => self::DESCRIPTION.' İki']);
        $unseenDescription = $this->wpPage('/blog/aciklama/', 'Diş beyazlatma sonrası beslenme | Panorama', 44, ['category' => 'blog', 'meta_description' => null]);
        $seenDescription = $this->wpPage('/blog/aciklama-2/', 'Diş taşı temizliği ne sıklıkla yapılır | Panorama', 45, ['category' => 'blog', 'meta_description' => null]);
        $this->fact('diş bakımı', '/blog/goruldu/', 40, 2, 8.0, '2026-09-20');
        $this->fact('diş taşı', '/blog/aciklama-2/', 10, 0, 12.0, '2026-07-15');
        $this->fact('eski', '/blog/gorulmedi/', 99, 1, 9.0, '2026-05-01');

        app(SiteAudit::class)->audit($this->site);

        $priorities = Suggestion::query()->where('action_type', SiteAudit::TYPE)->pluck('priority', 'page_id');
        $this->assertSame(1, $priorities[$seen->id]);
        $this->assertSame(2, $priorities[$unseenTitle->id], 'impressions older than 90 days do not count');
        $this->assertSame(5, $priorities[$unseenDescription->id]);
        $this->assertSame(3, $priorities[$seenDescription->id]);
    }

    public function test_the_rendered_description_of_the_crawl_counts_when_the_plugin_field_is_empty(): void
    {
        $page = $this->wpPage('/implant/', 'Ankara İmplant Tedavisi | Panorama Diş', 42, ['meta_description' => null]);
        $this->metaSnapshot('https://panorama.com.tr/implant', self::DESCRIPTION);

        app(SiteAudit::class)->audit($this->site);

        $this->assertFalse(Suggestion::query()->where('action_type', SiteAudit::TYPE)->where('page_id', $page->id)->exists());
    }

    public function test_the_migration_reprioritizes_waiting_fixes_but_never_approved_or_applied_ones(): void
    {
        $page = $this->wpPage('/implant/', 'Ankara İmplant Tedavisi | Panorama Diş', 42, ['category' => 'hizmet']);
        $open = $this->fix($page, Suggestion::OPEN, ['meta_description:missing']);
        $approved = $this->fix($page, Suggestion::APPROVED, ['meta_description:missing']);
        $applied = $this->fix($page, Suggestion::APPLIED, ['meta_description:missing']);
        $title = $this->fix($page, Suggestion::SNOOZED, ['seo_title:missing', 'meta_description:short']);

        $this->assertSame(1, app(SiteAudit::class)->reprioritizeWaiting());

        $this->assertSame(3, $open->fresh()->priority);
        $this->assertSame(1, $approved->fresh()->priority);
        $this->assertSame(1, $applied->fresh()->priority);
        $this->assertSame(1, $title->fresh()->priority, 'missing title on a service page stays critical');
    }

    public function test_dikkat_counts_one_critical_issue_per_asset_and_check_type(): void
    {
        foreach (range(1, 5) as $i) {
            $page = $this->wpPage('/hizmet-'.$i.'/', '', 100 + $i, ['category' => 'hizmet']);
            $this->fix($page, Suggestion::OPEN, ['seo_title:missing']);
        }
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads', 'name' => 'Ads']);
        Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'google_ads', 'decision_key' => 'ads.x', 'fingerprint' => Str::random(20),
            'material_hash' => 'x', 'title' => 'Ads', 'reason' => 'r', 'priority' => 1, 'action_type' => 'ads_budget', 'target_type' => 'google_ads',
            'target_id' => $ads->id, 'status' => Suggestion::OPEN, 'evidence' => []]);

        $signals = app(PortfolioSignalsReader::class)->forBrands(collect([$this->brand->fresh()]));

        $this->assertSame(6, $signals['brands'][$this->brand->id]['open_work']);
        $this->assertSame(2, $signals['brands'][$this->brand->id]['critical']);
        $this->assertSame(1, $signals['assets'][$this->site->id]['critical']);
        $this->assertStringContainsString('2 kritik öneri', (string) $signals['brands'][$this->brand->id]['reason']);
    }

    /** @param  array<string, mixed>  $extra */
    private function wpPage(string $path, string $title, int $postId, array $extra = []): Page
    {
        return $this->page($path, $title, array_merge(['wp_post_id' => $postId, 'title_source' => 'seo', 'meta_description' => self::DESCRIPTION.' '.$postId], $extra));
    }

    /** @param  list<string>  $problems */
    private function fix(Page $page, string $status, array $problems): Suggestion
    {
        return Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.seo_fields',
            'fingerprint' => Str::random(40), 'material_hash' => 'x', 'title' => 'Başlık ve açıklamayı düzelt: '.$page->path, 'reason' => 'r',
            'priority' => 1, 'action_type' => SiteAudit::TYPE, 'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id,
            'status' => $status, 'action' => ['site_id' => $this->site->id, 'problems' => $problems], 'evidence' => []]);
    }

    private function metaSnapshot(string $url, string $description): void
    {
        DB::table('website_metadata_snapshot')->insert(['digital_asset_id' => $this->site->id, 'url' => $url, 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'metadata' => json_encode(['meta_description' => $description, 'meta_description_present' => true]), 'created_at' => now(), 'updated_at' => now()]);
    }
}
