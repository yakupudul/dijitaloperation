<?php

namespace Tests\Feature\Site;

use App\Jobs\ApproveRepairRowsJob;
use App\Livewire\Operator\Website\V2\SiteHealthCard;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\SiteScore;
use App\Models\Suggestion;
use App\Models\WorkBatch;
use App\Services\Repair\SiteAudit;
use App\Services\Repair\WebHealthAudit;
use App\Services\Site\SiteHealthScore;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Aşama 7 (2026-10-22): the site's findings in four layers (teknik, SEO, AEO, kurgu) make a 0-100 score next to the
 * sector's median and leader; AI answer engines are checked (robots.txt, llms.txt, business schema); service pages under
 * 150 words are "ana sayfayı güçlendir" work; each problem type has one "Hepsini onar"; last month's fixes get one
 * result sentence.
 */
final class SiteHealthScoreTest extends SiteTestCase
{
    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake()->except([ApproveRepairRowsJob::class]);
        $this->implantPage = $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42, 'headings' => [['level' => 2, 'text' => 'İmplant ne kadar sürer?']]]);
        CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.24.0'],
        ]);
    }

    public function test_robots_txt_rules_that_shut_out_answer_engines_are_found(): void
    {
        $this->assertSame(['GPTBot'], WebHealthAudit::blockedAiBots("User-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nDisallow: /wp-admin/"));
        $this->assertSame(WebHealthAudit::AI_BOTS, WebHealthAudit::blockedAiBots("User-agent: *\nDisallow: /"));
        $this->assertSame([], WebHealthAudit::blockedAiBots("User-agent: *\nDisallow: /\n\nUser-agent: ClaudeBot\nUser-agent: GPTBot\nUser-agent: OAI-SearchBot\nUser-agent: ChatGPT-User\nUser-agent: Claude-SearchBot\nUser-agent: PerplexityBot\nUser-agent: Google-Extended\nAllow: /"));
        $this->assertSame([], WebHealthAudit::blockedAiBots("User-agent: *\nDisallow: /wp-admin/ # admin\nAllow: /wp-admin/admin-ajax.php"));
    }

    public function test_the_night_check_opens_aeo_work_and_llms_txt_goes_with_one_approval(): void
    {
        $site = ['robots.txt' => ["User-agent: GPTBot\nDisallow: /\n", 200], 'llms.txt' => ['Not found', 404]];
        Http::fake(function ($request) use (&$site) {
            $file = basename((string) parse_url($request->url(), PHP_URL_PATH));

            return Http::response(...($site[$file] ?? ['', 404]));
        });

        app(WebHealthAudit::class)->audit($this->site);

        $rows = Suggestion::query()->where('action_type', WebHealthAudit::TYPE)->get()->filter(fn (Suggestion $s): bool => data_get($s->action, 'check') === 'aeo')->keyBy(fn (Suggestion $s): string => (string) data_get($s->action, 'target'));
        $robots = $rows['https://panorama.com.tr/robots.txt'];
        $this->assertStringContainsString('AI asistanları siteyi okuyamıyor', $robots->title);
        $this->assertSame([], data_get($robots->action, 'changes'), 'the operator edits robots.txt');
        $llms = $rows['https://panorama.com.tr/llms.txt'];
        $this->assertSame('llms_txt', data_get($llms->action, 'changes.0.type'));
        $this->assertStringContainsString('Panorama Ankara', (string) data_get($llms->action, 'changes.0.value'));

        $problems = collect(app(SiteHealthScore::class)->problems($this->site))->keyBy('key');
        $this->assertSame('aeo', $problems['web_health.aeo.robots']['layer']);
        $this->assertSame('manual', $problems['web_health.aeo.robots']['fix']);
        $this->assertSame('desk', $problems['web_health.aeo.llms']['fix'], 'llms.txt is sent by the system');
        $this->assertSame(11, app(SiteHealthScore::class)->score($this->site)['layers']['aeo'], 'robots 0.35 + llms.txt 0.2 of 25 points');

        $site = ['robots.txt' => ["User-agent: *\nDisallow: /wp-admin/\n", 200], 'llms.txt' => ["# Panorama Ankara\n> Diş kliniği", 200]];
        app(WebHealthAudit::class)->audit($this->site);
        $this->assertSame(Suggestion::APPLIED, $robots->fresh()->status, 'fixed on the site: the night closes it');
        $this->assertSame(Suggestion::APPLIED, $llms->fresh()->status);
    }

    public function test_short_service_pages_and_pages_without_questions_lower_kurgu_and_aeo(): void
    {
        $this->page('/zirkonyum/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'word_count' => 90]);
        $this->page('/cankaya-dis-klinigi/', 'Çankaya Diş Kliniği', ['category' => 'lokasyon', 'word_count' => 120]);
        $this->page('/blog/dis-fircalama/', 'Diş fırçalama', ['category' => 'blog', 'word_count' => 60]);

        $this->assertTrue(SiteHealthScore::isThin(Page::query()->where('path', '/zirkonyum/')->sole()));
        $this->assertFalse(SiteHealthScore::isThin(Page::query()->where('path', '/blog/dis-fircalama/')->sole()), 'a short blog post is not a service page');

        $problems = collect(app(SiteHealthScore::class)->problems($this->site))->keyBy('key');
        $this->assertSame(2, $problems['thin']['count']);
        $this->assertSame('content', $problems['thin']['fix']);
        $this->assertSame(['/zirkonyum/ · 90 kelime', '/cankaya-dis-klinigi/ · 120 kelime'], $problems['thin']['pages']);
        $this->assertSame(2, $problems['faq']['count'], 'the implant page answers a question, the other two do not');

        $score = app(SiteHealthScore::class)->score($this->site);
        // kurgu: 2 of 3 service pages thin × 0.5 → 25 × (1 − 0.333) = 17; aeo: 2 of 3 without questions × 0.25 → 21.
        $this->assertSame(['teknik' => 25, 'seo' => 25, 'aeo' => 21, 'kurgu' => 17], $score['layers']);
        $this->assertSame(88, $score['score']);
    }

    public function test_hepsini_onar_sends_every_writable_row_of_one_type_through_the_desk(): void
    {
        $other = $this->page('/zirkonyum/', 'Zirkonyum Kaplama', ['category' => 'hizmet', 'wp_post_id' => 43]);
        $this->suggestion('Başlık 1', $this->implantPage);
        $this->suggestion('Başlık 2', $other);

        $problem = collect(app(SiteHealthScore::class)->problems($this->site))->firstWhere('key', 'title_description');
        $this->assertSame('seo', $problem['layer']);
        $this->assertSame('desk', $problem['fix']);
        $this->assertCount(2, $problem['ids']);

        Livewire::test(SiteHealthCard::class, ['assetId' => $this->site->id])
            ->assertSee('Site skoru')->assertSee('Hepsini onar (2)')
            ->call('fixAll', 'title_description')->assertSee('2 iş onaylandı');

        $this->assertSame(1, WorkBatch::query()->count(), 'one row of Akış');
        $this->assertNull(collect(app(SiteHealthScore::class)->problems($this->site))->firstWhere('key', 'title_description'));
    }

    public function test_the_score_is_stored_nightly_and_read_next_to_the_sector(): void
    {
        $this->suggestion('Eksik başlık', $this->implantPage, ['action_type' => SiteAudit::TYPE, 'action' => []]);

        $this->artisan('moxdop:repair:audit')->expectsOutputToContain('Site skoru')->assertSuccessful();

        $own = SiteScore::query()->where('digital_asset_id', $this->site->id)->sole();
        // Two other dental sites scored the same night (their own nights are not re-run here).
        foreach ([70, 90] as $i => $score) {
            $brand = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => 'Rakip '.$i, 'sector_id' => $this->dental->id]);
            $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'domain' => 'rakip'.$i.'.com', 'primary_url' => 'https://rakip'.$i.'.com/']);
            SiteScore::query()->create(['digital_asset_id' => $site->id, 'brand_id' => $brand->id, 'day' => now()->toDateString(), 'score' => $score, 'layers' => []]);
        }
        $this->assertSame(8, $own->layers['seo'], 'the one page of the site has a weak title: 25 × (1 − 0.7)');
        $sector = app(SiteHealthScore::class)->sector($this->site);
        $this->assertSame(['median' => 83, 'leader' => 90, 'sites' => 3, 'rank' => 2], $sector, 'no other brand is named');
    }

    public function test_last_months_fixes_get_one_result_sentence(): void
    {
        $this->suggestion('Başlık', $this->implantPage, ['status' => Suggestion::APPLIED, 'applied_at' => now()->subDays(40),
            'outcome' => ['d30' => ['clicks' => 52, 'impressions' => 900, 'before' => ['clicks' => 20, 'impressions' => 700], 'verdict' => 'worked']]]);
        $this->suggestion('Henüz ölçülmedi', $this->implantPage, ['status' => Suggestion::APPLIED, 'applied_at' => now()->subDays(5)]);

        $result = app(SiteHealthScore::class)->result($this->site);

        $this->assertSame(['pages' => 1, 'clicks' => 32, 'sentence' => 'Son 60 günde düzeltilen 1 sayfa: 30 gün sonra +32 tık.'], $result);
    }

    /** @param  array<string, mixed>  $extra */
    private function suggestion(string $title, Page $page, array $extra = []): Suggestion
    {
        return Suggestion::query()->create(array_merge([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site-health.test', 'fingerprint' => hash('sha256', $title.random_int(1, PHP_INT_MAX)),
            'material_hash' => hash('sha256', 'x'), 'title' => $title, 'reason' => 'Gerekçe.', 'priority' => 2, 'action_type' => 'title_description',
            'status' => Suggestion::OPEN, 'action' => ['proposal' => ['kind' => 'fields', 'current' => [], 'new' => ['seo_title' => $title.' yeni']]], 'evidence' => [],
            'page_id' => $page->id,
        ], $extra));
    }
}
