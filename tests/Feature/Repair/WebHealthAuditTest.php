<?php

namespace Tests\Feature\Repair;

use App\Jobs\ExecuteExternalWriteJob;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\WebHealthAudit;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Site\SiteTestCase;
use Tests\Support\InsertsFacts;

/**
 * Onarım Faz 3: the nightly technical site check puts what the system can fix (noindex on a service page, 301 for a
 * dead internal link) on the desk ready to approve, and what only the operator can do as a "Yaptım" task; rows close
 * by themselves once the problem is gone.
 */
final class WebHealthAuditTest extends SiteTestCase
{
    use InsertsFacts;

    private Page $implantPage;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->implantPage = $this->page('/implant-tedavisi/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet', 'wp_post_id' => 42]);
        CoreConnection::factory()->create([
            'digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.12.0'],
        ]);
        Http::fake(['https://panorama.com.tr/' => Http::response('<html></html>', 200, ['Strict-Transport-Security' => 'max-age=31536000'])]);
    }

    public function test_index_problems_become_a_fix_for_service_pages_and_grouped_tasks_for_the_rest(): void
    {
        $blog = $this->page('/blog/implant-agrisi/', 'İmplant ağrısı', ['category' => 'blog', 'wp_post_id' => 77]);
        $this->implantPage->forceFill(['is_indexable' => false])->save(); // noindex set in the SEO plugin
        $this->inspection($this->implantPage->url, ['verdict' => 'NEUTRAL', 'coverage_state' => 'Excluded by ‘noindex’ tag', 'indexing_state' => 'BLOCKED_BY_META_TAG']);
        $this->inspection($blog->url, ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']);
        $this->inspection('https://panorama.com.tr/zirkonyum/', ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']);
        $this->inspection('https://panorama.com.tr/hakkimizda/', ['verdict' => 'PASS', 'coverage_state' => 'Submitted and indexed']);
        $this->inspection('https://other.com/x/', ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']);

        $this->artisan('moxdop:repair:audit')->expectsOutputToContain('Teknik site denetimi')->assertSuccessful();

        $rows = app(RepairDesk::class)->rows()->whereIn('kind', [RepairDesk::WEB_FIX, RepairDesk::WEB_TASK])->keyBy('title');
        $fix = $rows['Hizmet sayfası Google\'a kapalı (noindex): /implant-tedavisi/'];
        $this->assertSame([RepairDesk::WEB_FIX, RepairDesk::MEDIUM], [$fix['kind'], $fix['risk']]);
        $task = $rows['Google dizin sorunu: Google taradı ama dizine almadı (2 sayfa)'];
        $this->assertSame([RepairDesk::WEB_TASK, RepairDesk::MANUAL, ['/blog/implant-agrisi/', '/zirkonyum/']], [$task['kind'], $task['risk'], $task['before']]);
        $this->assertStringContainsString('Dizine eklenmesini iste', implode(' ', $task['after']));

        $result = app(RepairDesk::class)->approve([$fix['id'], $task['id']], $this->admin);

        $this->assertSame(2, $result['applied'], implode(' ', $result['failed']));
        $write = ExternalWriteAction::query()->sole();
        $this->assertSame([['type' => 'noindex', 'object_id' => 42, 'reference' => 'web-health-noindex-'.$this->implantPage->id, 'value' => false]], $write->request_payload['changes']);
        Queue::assertPushed(ExecuteExternalWriteJob::class);
        $this->assertSame(Suggestion::SNOOZED, Suggestion::query()->find($task['id'])->status);
        $this->assertFalse(app(RepairDesk::class)->rows()->contains('id', $task['id']), '"Yaptım" hides the task');

        // The problems are gone at the next check: both rows close by themselves.
        DB::table('gsc_url_inspection_snapshot')->delete();
        foreach ([$this->implantPage->url, $blog->url, 'https://panorama.com.tr/zirkonyum/'] as $url) {
            $this->inspection($url, ['verdict' => 'PASS', 'coverage_state' => 'Submitted and indexed']);
        }
        app(WebHealthAudit::class)->audit($this->site);
        $this->assertSame([Suggestion::APPLIED, Suggestion::APPLIED], Suggestion::query()->whereIn('id', [$fix['id'], $task['id']])->pluck('status')->all());
    }

    public function test_a_dead_internal_link_gets_a_301_to_the_closest_live_page_and_a_failed_write_returns(): void
    {
        $this->edge('https://panorama.com.tr/', 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/');
        $this->edge('https://panorama.com.tr/blog/a/', 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/');
        $this->edge('https://panorama.com.tr/', 'https://panorama.com.tr/eski-kampanya-sayfasi/');
        $this->html('https://panorama.com.tr/ankara-implant-tedavisi-fiyat/', 404);
        $this->html('https://panorama.com.tr/eski-kampanya-sayfasi/', 404);
        $this->html('https://panorama.com.tr/implant-tedavisi/', 200);
        $this->page('/ankara-implant-tedavisi/', 'Ankara implant', ['category' => 'hizmet', 'wp_post_id' => 43]);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $fix = $rows['Kırık iç bağlantı: /ankara-implant-tedavisi-fiyat/'];
        $this->assertSame(RepairDesk::WEB_FIX, $fix['kind']);
        $this->assertSame(['/ankara-implant-tedavisi-fiyat/ → 404', '2 sayfa bu adrese link veriyor:', '/', '/blog/a/'], $fix['before']);
        $this->assertSame('301 → https://panorama.com.tr/ankara-implant-tedavisi/', $fix['after'][0]);
        $this->assertSame(RepairDesk::WEB_TASK, $rows['Kırık iç bağlantı: /eski-kampanya-sayfasi/']['kind'], 'no similar live page: the operator fixes the link');

        app(RepairDesk::class)->approve([$fix['id']], $this->admin);
        $write = ExternalWriteAction::query()->sole();
        $this->assertSame(['type' => 'redirect', 'from' => '/ankara-implant-tedavisi-fiyat/', 'value' => 'https://panorama.com.tr/ankara-implant-tedavisi/'],
            array_intersect_key($write->request_payload['changes'][0], array_flip(['type', 'from', 'value'])));

        $write->forceFill(['status' => 'failed', 'error' => 'SEO fixes are disabled on this site.'])->save();
        app(WebHealthAudit::class)->audit($this->site);
        $row = app(RepairDesk::class)->rows()->firstWhere('id', $fix['id']);
        $this->assertStringContainsString('Önceki gönderim başarısız: SEO fixes are disabled on this site.', $row['reason']);
    }

    public function test_search_console_404s_duplicates_and_unindexed_pages_are_prepared_automatically(): void
    {
        $ankara = $this->page('/ankara-implant-tedavisi/', 'Ankara implant', ['category' => 'hizmet', 'wp_post_id' => 43]);
        $duplicate = $this->page('/zirkonyum-kaplama/', 'Zirkonyum kaplama', ['category' => 'hizmet', 'wp_post_id' => 50]);
        $weak = $this->page('/blog/implant-tedavisi-sonrasi/', 'İmplant tedavisi sonrası bakım', ['category' => 'blog', 'wp_post_id' => 60]);
        $this->inspection('https://panorama.com.tr/ankara-implant-tedavisi-eski/', ['verdict' => 'FAIL', 'coverage_state' => 'Not found (404)']);
        $this->inspection($duplicate->url, ['verdict' => 'NEUTRAL', 'coverage_state' => 'Duplicate without user-selected canonical']);
        $this->inspection($weak->url, ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']);
        $this->inspection('https://panorama.com.tr/eski/', ['verdict' => 'NEUTRAL', 'coverage_state' => 'Page with redirect']);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $this->assertSame(['301 → https://panorama.com.tr/ankara-implant-tedavisi/', 'Adres en yakın canlı sayfaya gider (MoxDOP eklentisine yazılır)'],
            $rows['Google 404 görüyor: /ankara-implant-tedavisi-eski/']['after']);
        $canonical = $rows['Yinelenen sayfalara kendi asıl adresini (canonical) ver (1 sayfa)'];
        $this->assertSame(RepairDesk::WEB_FIX, $canonical['kind']);
        $this->assertFalse($rows->keys()->contains(fn (string $t): bool => str_contains($t, 'taradı ama') || str_contains($t, 'Yönlendirilen')),
            'the unindexed page got link suggestions; a redirected address is normal');

        $links = Suggestion::query()->where('action_type', 'internal_links')->get();
        $this->assertSame([$this->implantPage->id, $ankara->id], $links->pluck('page_id')->sort()->values()->all());
        $this->assertStringContainsString($weak->url, $links->first()->reason);

        app(RepairDesk::class)->approve([$canonical['id']], $this->admin);
        $this->assertSame([['type' => 'canonical', 'object_id' => 50, 'reference' => 'web-health-canonical-'.$duplicate->id, 'value' => $duplicate->url]],
            ExternalWriteAction::query()->sole()->request_payload['changes']);

        // Google indexed the page: the open link suggestions close by themselves.
        DB::table('gsc_url_inspection_snapshot')->delete();
        $this->inspection($weak->url, ['verdict' => 'PASS', 'coverage_state' => 'Submitted and indexed']);
        app(WebHealthAudit::class)->audit($this->site);
        $this->assertSame([Suggestion::APPLIED], Suggestion::query()->where('action_type', 'internal_links')->pluck('status')->unique()->values()->all());
    }

    public function test_a_301_needs_connector_1_12_0(): void
    {
        CoreConnection::query()->update(['config->plugin_version' => '1.11.0']);
        $this->edge('https://panorama.com.tr/', 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/');
        $this->html('https://panorama.com.tr/ankara-implant-tedavisi-fiyat/', 404);
        $this->page('/ankara-implant-tedavisi/', 'Ankara implant', ['category' => 'hizmet', 'wp_post_id' => 43]);
        app(WebHealthAudit::class)->audit($this->site);

        $result = app(RepairDesk::class)->approve(app(RepairDesk::class)->rows()->pluck('id')->all(), $this->admin);

        $this->assertStringContainsString('en az 1.12.0', implode(' ', $result['failed']));
        $this->assertSame(0, ExternalWriteAction::query()->count());
    }

    public function test_headers_speed_bloat_and_external_links_become_operator_tasks_and_conversion_an_ai_suggestion(): void
    {
        $ga4 = CoreExternalResource::factory()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $ga4->id, 'capability' => 'ga4']);
        $this->edge('https://panorama.com.tr/implant-tedavisi/', 'https://kapanmis-site.example/rehber/', false);
        $this->edge('https://panorama.com.tr/implant-tedavisi/', 'https://saglam.example/', false);
        $this->edge('https://panorama.com.tr/implant-tedavisi/', 'https://www.instagram.com/panorama/', false);
        Http::fake([
            'https://panorama.com.tr/' => Http::response('<html></html>', 200, ['Strict-Transport-Security' => 'max-age=31536000']),
            'https://kapanmis-site.example/*' => Http::response('', 404),
            'https://saglam.example/*' => Http::response('', 200),
        ]);
        DB::table('website_performance_measurement')->insert(['digital_asset_id' => $this->site->id, 'url' => 'https://panorama.com.tr/', 'strategy' => 'mobile',
            'observed_at' => now()->subDay(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'metadata' => json_encode(['lcp_ms' => 3000, 'field' => ['scope' => 'origin', 'lcp_ms' => 5200, 'inp_ms' => 150, 'cls' => 0.3]])]);
        foreach (['https://panorama.com.tr/tag/implant/' => 40, 'https://panorama.com.tr/blog/page/29/' => 53, 'https://panorama.com.tr/implant-tedavisi/' => 900] as $page => $impressions) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
                'search_type' => 'web', 'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'implant', 'page' => $page, 'clicks' => 1, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }
        foreach ([['/implant-tedavisi/', 120, 0], ['/', 300, 12]] as [$landing, $sessions, $keyEvents]) {
            $this->insertFacts('ga4_landing_source_daily', ['digital_asset_id' => null, 'external_resource_id' => $ga4->id, 'property_id' => 'properties/1',
                'reporting_date' => now()->subDays(3)->toDateString(), 'landingPage' => $landing, 'sessionSource' => 'google', 'sessionMedium' => 'organic',
                'sessions' => $sessions, 'keyEvents' => $keyEvents, 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $this->assertSame(RepairDesk::MANUAL, $rows['Güvenlik başlıkları eksik (3)']['risk']);
        $this->assertContains('Header always set X-Content-Type-Options "nosniff"', $rows['Güvenlik başlıkları eksik (3)']['after']);
        $this->assertNotContains('Header always set Strict-Transport-Security "max-age=31536000"', $rows['Güvenlik başlıkları eksik (3)']['after'], 'HSTS is already sent');
        $this->assertStringContainsString('5,2 sn', implode(' ', $rows['Site yavaş (mobil)']['before']));
        $this->assertSame(['/tag/implant/ · 40 gösterim'], $rows['Google gereksiz adresleri gösteriyor (1 adres)']['before']);
        $this->assertStringContainsString('etiket ve yazar', $rows['Google gereksiz adresleri gösteriyor (1 adres)']['after'][0], 'pagination stays indexable; only the steps the list needs');
        $this->assertCount(1, $rows['Google gereksiz adresleri gösteriyor (1 adres)']['after']);
        $conversion = Suggestion::query()->where('action_type', 'conversion')->sole();
        $this->assertSame([$this->implantPage->id, 'Dönüşüm adımı ekle: /implant-tedavisi/', Suggestion::OPEN],
            [$conversion->page_id, $conversion->title, $conversion->status], 'prepared overnight by "AI ile yap", no manual row');
        $this->assertStringContainsString('120 oturum', $conversion->reason);
        $this->assertSame(['https://kapanmis-site.example/rehber/ (404) ← /implant-tedavisi/'], $rows['Kırık dış bağlantı (1)']['before']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'instagram.com'));

        // A second night uses the stored link results (no new requests to the linked sites).
        app(WebHealthAudit::class)->audit($this->site);
        Http::assertSentCount(4);
    }

    public function test_a_301_goes_only_to_a_page_whose_words_fit_the_dead_address_and_numbers_match(): void
    {
        $this->page('/all-on-4-implant/', 'All-on-4 implant', ['category' => 'hizmet', 'wp_post_id' => 70]);
        $this->page('/hurda-aluminyum-fiyatlari/', 'Hurda alüminyum fiyatları', ['category' => 'hizmet', 'wp_post_id' => 71]);
        $this->page('/hurda-bakir-alimi/', 'Hurda bakır alımı', ['category' => 'hizmet', 'wp_post_id' => 72]);
        foreach (['/all-on-6-implant/', '/hurda-bakir-fiyatlari/', '/hurda-bakir-alimi-ankara/', '/ankara-implant-tedavisi-fiyat/'] as $dead) {
            $this->edge('https://panorama.com.tr/blog/a/', 'https://panorama.com.tr'.$dead);
            $this->html('https://panorama.com.tr'.$dead, 404, now()->subDays(2));
        }
        // The crawl later found this one open again: not a broken link any more.
        $this->html('https://panorama.com.tr/ankara-implant-tedavisi-fiyat/', 200);
        $this->inspection('https://panorama.com.tr/hurda-bakir-alimi-ankara/', ['verdict' => 'FAIL', 'coverage_state' => 'Not found (404)']);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $this->assertSame(RepairDesk::WEB_TASK, $rows['Kırık iç bağlantı: /all-on-6-implant/']['kind'], 'All-on-6 is not All-on-4');
        $this->assertSame(RepairDesk::WEB_TASK, $rows['Kırık iç bağlantı: /hurda-bakir-fiyatlari/']['kind'], 'copper prices are not aluminium prices');
        $this->assertSame('301 → https://panorama.com.tr/hurda-bakir-alimi/', $rows['Kırık iç bağlantı: /hurda-bakir-alimi-ankara/']['after'][0]);
        $this->assertFalse($rows->has('Google 404 görüyor: /hurda-bakir-alimi-ankara/'), 'the broken-link row already handles this address');
        $this->assertFalse($rows->has('Kırık iç bağlantı: /ankara-implant-tedavisi-fiyat/'));
    }

    public function test_duplicates_follow_googles_chosen_page_and_never_freeze_a_canonical_the_seo_plugin_prints(): void
    {
        $fiyat = $this->page('/implant-fiyatlari/', 'İmplant fiyatları', ['category' => 'hizmet', 'wp_post_id' => 80]);
        $en = $this->page('/en/dental-implant/', 'Dental implant', ['category' => 'hizmet', 'wp_post_id' => 81, 'language' => 'en']);
        $plugin = $this->page('/zirkonyum-kaplama/', 'Zirkonyum kaplama', ['category' => 'hizmet', 'wp_post_id' => 82]);
        DB::table('website_cms_seo_snapshot')->insert(['digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => '82',
            'seo_provider' => 'yoast', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'seo82'), 'created_at' => now(), 'updated_at' => now()]);
        $duplicate = ['verdict' => 'NEUTRAL', 'coverage_state' => 'Duplicate without user-selected canonical'];
        $this->inspection($fiyat->url, $duplicate + ['google_canonical' => $this->implantPage->url]);
        $this->inspection($en->url, $duplicate + ['google_canonical' => $this->implantPage->url]);
        $this->inspection($plugin->url, $duplicate + ['google_canonical' => $plugin->url]);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $same = $rows['Google başka sayfayı asıl sayıyor (1 sayfa)'];
        $this->assertSame([RepairDesk::WEB_TASK, ['/implant-fiyatlari/ → Google: /implant-tedavisi/']], [$same['kind'], $same['before']]);
        $language = $rows['Google çeviri sayfasını başka dildeki sayfanın kopyası sayıyor (1 sayfa)'];
        $this->assertSame(['/en/dental-implant/ → Google: /implant-tedavisi/'], $language['before']);
        $this->assertStringContainsString('hreflang', implode(' ', $language['after']));
        $this->assertStringNotContainsString('301', implode(' ', $language['after']), 'a translation is never merged into another language');
        $this->assertSame(RepairDesk::WEB_TASK, $rows['Yinelenen sayfa: Google asıl adresi görmüyor (1 sayfa)']['kind'], 'the SEO plugin prints the canonical');
        $this->assertSame(0, Suggestion::query()->where('action_type', WebHealthAudit::TYPE)->get()->filter(fn (Suggestion $s): bool => ($s->action['changes'] ?? []) !== [])->count(),
            'no literal canonical is written');
    }

    public function test_noindex_is_switched_off_only_where_the_seo_plugin_set_it_and_tags_and_pagination_are_not_index_rows(): void
    {
        $alternate = $this->page('/implant-tedavisi-2/', 'İmplant tedavisi', ['category' => 'hizmet', 'wp_post_id' => 90, 'is_indexable' => false,
            'canonical' => $this->implantPage->url]);
        $noindex = ['verdict' => 'NEUTRAL', 'coverage_state' => 'Excluded by ‘noindex’ tag', 'indexing_state' => 'BLOCKED_BY_META_TAG'];
        $this->inspection($this->implantPage->url, $noindex);
        $this->inspection($alternate->url, $noindex);
        $this->inspection('https://panorama.com.tr/tag/implant/', ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']);
        $this->inspection('https://panorama.com.tr/blog/page/3/', ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $this->assertSame(['Hizmet sayfası Google\'a kapalı, SEO eklentisinde açık (1 sayfa)'], $rows->keys()->filter(fn (string $t): bool => str_contains($t, 'kapalı'))->values()->all(),
            'the SEO plugin has the page open: the noindex comes from elsewhere; the alternate page is left alone');
        $row = $rows['Hizmet sayfası Google\'a kapalı, SEO eklentisinde açık (1 sayfa)'];
        $this->assertSame([RepairDesk::WEB_TASK, ['/implant-tedavisi/']], [$row['kind'], $row['before']]);
        $this->assertStringContainsString('Ayarlar › Okuma', implode(' ', $row['after']));
        $this->assertFalse($rows->keys()->contains(fn (string $t): bool => str_contains($t, 'taradı ama')), 'tags and pagination belong to the bloat check');
    }

    public function test_speed_steps_follow_the_failed_metric_and_name_the_measured_page_and_data_source(): void
    {
        DB::table('website_performance_measurement')->insert(['digital_asset_id' => $this->site->id, 'url' => 'https://panorama.com.tr/implant-tedavisi/', 'strategy' => 'mobile',
            'observed_at' => now()->subDay(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            'metadata' => json_encode(['lcp_ms' => 4800])]);

        app(WebHealthAudit::class)->audit($this->site);

        $row = app(RepairDesk::class)->rows()->firstWhere('title', 'Site yavaş (mobil)');
        $this->assertStringContainsString('Ölçülen adres: /implant-tedavisi/', $row['reason']);
        $this->assertStringContainsString('laboratuvar', $row['reason']);
        $this->assertStringContainsString('laboratuvar', $row['before'][0], 'lab LCP is named as lab');
        $after = implode(' ', $row['after']);
        $this->assertStringContainsString('/implant-tedavisi/ sayfasının', $after);
        $this->assertStringContainsString('lazy-load', $after);
        $this->assertStringNotContainsString('Ana sayfa', $after, 'the measured page is not the home page');
        $this->assertStringNotContainsString('CLS', $after, 'no layout-shift steps without a layout-shift problem');
        $this->assertStringNotContainsString('INP', $after);
    }

    public function test_bloat_gives_files_search_and_feeds_their_own_steps_and_a_fallback_for_the_rest(): void
    {
        foreach (['https://panorama.com.tr/wp-content/uploads/fiyat-listesi.pdf' => 30, 'https://panorama.com.tr/?s=implant' => 12,
            'https://panorama.com.tr/implant-tedavisi/feed/' => 5, 'https://panorama.com.tr/?replytocom=12' => 4, 'https://panorama.com.tr/blog/page/2/' => 50] as $page => $impressions) {
            $this->insertFacts('gsc_query_page_daily', ['digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
                'search_type' => 'web', 'reporting_date' => now()->subDays(5)->toDateString(), 'query' => 'implant', 'page' => $page, 'clicks' => 0, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
        }

        app(WebHealthAudit::class)->audit($this->site);

        $row = app(RepairDesk::class)->rows()->firstWhere('title', 'Google gereksiz adresleri gösteriyor (4 adres)');
        $this->assertNotContains('/blog/page/2/ · 50 gösterim', $row['before'], 'pagination stays indexable');
        $this->assertCount(4, $row['after']);
        $this->assertStringContainsString('X-Robots-Tag', $row['after'][0], 'a PDF is not noindexed through the SEO plugin');
        $this->assertStringContainsString('(?s=)', $row['after'][1]);
        $this->assertStringContainsString('(/feed/)', $row['after'][2]);
        $this->assertStringStartsWith('Listedeki diğer adresleri', $row['after'][3], '?replytocom= matched no step');
    }

    public function test_link_suggestions_ignore_brand_words_and_the_title_suffix(): void
    {
        $weak = $this->page('/zirkonyum-kaplama/', 'Zirkonyum Kaplama | Panorama Ankara', ['category' => 'hizmet', 'wp_post_id' => 100]);
        $this->implantPage->forceFill(['title' => 'Ankara İmplant Tedavisi | Panorama Ankara'])->save();
        $this->page('/hakkimizda/', 'Hakkımızda | Panorama Ankara Diş Kliniği', ['category' => 'kurumsal', 'wp_post_id' => 101]);
        $related = $this->page('/blog/zirkonyum-kaplama-fiyatlari/', 'Zirkonyum kaplama fiyatları | Panorama Ankara', ['category' => 'blog', 'wp_post_id' => 102]);
        $this->inspection($weak->url, ['verdict' => 'NEUTRAL', 'coverage_state' => 'Crawled - currently not indexed']);

        app(WebHealthAudit::class)->audit($this->site);

        $this->assertSame([$related->id], Suggestion::query()->where('action_type', 'internal_links')->pluck('page_id')->all(),
            '"Panorama Ankara" is on every page: it makes no two pages related');
    }

    public function test_image_and_video_sitemaps_are_not_junk_and_the_reason_names_what_a_junk_one_lists(): void
    {
        $this->assertFalse(SeoText::isJunkSitemap('https://panorama.com.tr/image-sitemap.xml'));
        $this->assertFalse(SeoText::isJunkSitemap('https://panorama.com.tr/video-sitemap.xml'));
        $this->assertTrue(SeoText::isJunkSitemap('https://panorama.com.tr/author-sitemap.xml'));
        DB::table('gsc_sitemap_snapshot')->insert(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
            'sitemap_path' => 'https://panorama.com.tr/author-sitemap.xml', 'retrieved_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'author'), 'metadata' => json_encode(['errors' => 0, 'warnings' => 0])]);

        app(WebHealthAudit::class)->audit($this->site);

        $this->assertStringStartsWith('Bu harita yazar sayfalarını listeliyor.', app(RepairDesk::class)->rows()->firstWhere('title', 'Gereksiz site haritası gönderilmiş: /author-sitemap.xml')['reason']);
    }

    /** @param  array<string, mixed>  $meta */
    public function test_a_sitemap_error_is_explained_by_opening_the_sitemap_and_a_failed_fix_returns_at_once(): void
    {
        foreach (['https://panorama.com.tr/sitemap.xml' => 1, 'https://panorama.com.tr/doc_tag-sitemap1.xml' => 1] as $path => $errors) {
            DB::table('gsc_sitemap_snapshot')->insert(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
                'sitemap_path' => $path, 'retrieved_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
                'record_fingerprint' => hash('sha256', $path), 'metadata' => json_encode(['errors' => $errors, 'warnings' => 0])]);
        }
        Http::fake([
            'https://panorama.com.tr/sitemap.xml' => Http::response('<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://panorama.com.tr/page-sitemap.xml</loc></sitemap><sitemap><loc>https://panorama.com.tr/doc_tag-sitemap1.xml</loc></sitemap></sitemapindex>'),
            'https://panorama.com.tr/page-sitemap.xml' => Http::response('', 200),
            'https://panorama.com.tr/doc_tag-sitemap1.xml' => Http::response('', 404),
        ]);

        app(WebHealthAudit::class)->audit($this->site);

        $rows = app(RepairDesk::class)->rows()->keyBy('title');
        $index = $rows['Site haritasında 1 hata, 0 uyarı: /sitemap.xml'];
        $this->assertSame(['1 hata · 0 uyarı (Search Console)', 'Harita 2 alt harita listeliyor.', 'Neden: 1 alt harita düzgün açılmıyor. Haritada yalnız açılan sayfalar olmalı:',
            '/doc_tag-sitemap1.xml → HTTP 404'], $index['before']);
        $this->assertStringContainsString('Neden: harita açılmıyor (HTTP 404)', implode(' ', $rows['Site haritasında 1 hata, 0 uyarı: /doc_tag-sitemap1.xml']['before']));
        $this->assertStringContainsString('yeni yazma türleri', implode(' ', $rows['Gereksiz site haritası gönderilmiş: /doc_tag-sitemap1.xml']['after']));

        // A fix that the site refused comes back to the desk as soon as the write fails, in plain words.
        $this->edge('https://panorama.com.tr/', 'https://panorama.com.tr/ankara-implant-tedavisi-fiyat/');
        $this->html('https://panorama.com.tr/ankara-implant-tedavisi-fiyat/', 404);
        $this->page('/ankara-implant-tedavisi/', 'Ankara implant', ['category' => 'hizmet', 'wp_post_id' => 43]);
        app(WebHealthAudit::class)->audit($this->site);
        $fix = app(RepairDesk::class)->rows()->firstWhere('title', 'Kırık iç bağlantı: /ankara-implant-tedavisi-fiyat/');
        app(RepairDesk::class)->approve([$fix['id']], $this->admin);
        $write = ExternalWriteAction::query()->sole();
        $write->forceFill(['status' => 'failed', 'error' => 'no SEO plugin can hold redirects on this site (Rank Math, SEOPress Pro, Yoast SEO Premium or Redirection needed)'])->save();
        app(WebHealthAudit::class)->writeFinished($write);
        $row = app(RepairDesk::class)->rows()->firstWhere('id', $fix['id']);
        $this->assertStringContainsString('Sitedeki MoxDOP eklentisi eski; 1.12.0', $row['reason']);
    }

    private function inspection(string $url, array $meta): void
    {
        DB::table('gsc_url_inspection_snapshot')->insert(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.com.tr',
            'page' => $url, 'inspected_at' => now()->subDay(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(20), 'metadata' => json_encode($meta)]);
    }

    private function edge(string $from, string $to, bool $internal = true): void
    {
        DB::table('website_link_edge')->insert(['digital_asset_id' => $this->site->id, 'edge_key' => hash('sha256', $from.'>'.$to), 'source_url' => $from, 'target_url' => $to,
            'normalized_target_url' => $to, 'is_internal' => $internal, 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
    }

    /**
     * What the crawler stores for one fetch: a page that opened goes to the HTML snapshot; a 4xx only to the HTTP snapshot
     * and as an HTTP_4XX crawl issue (WebsiteDatasetExecutor::persistPage).
     */
    private function html(string $url, int $status, ?\DateTimeInterface $at = null): void
    {
        $at ??= now();
        $common = ['digital_asset_id' => $this->site->id, 'url' => $url, 'observed_at' => $at, 'contract_version' => 1, 'first_collected_at' => $at,
            'last_collected_at' => $at, 'record_fingerprint' => Str::random(20)];
        DB::table('website_http_snapshot')->insert($common + ['metadata' => json_encode(['requested_url' => $url, 'final_url' => $url, 'status_code' => $status])]);
        if ($status >= 400) {
            DB::table('website_crawl_issue_snapshot')->insert($common + ['issue_code' => 'HTTP_4XX', 'severity' => 'high', 'message' => 'Sayfa 4xx yanıtı döndürüyor.',
                'metadata' => json_encode(['evidence' => ['status_code' => $status], 'deterministic' => true])]);

            return;
        }
        DB::table('website_html_snapshot')->insert($common + ['status_code' => $status, 'html_hash' => hash('sha256', $url), 'html_bytes' => 10, 'change_state' => 'new']);
    }
}
