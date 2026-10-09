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
        foreach (['https://panorama.com.tr/tag/implant/' => 40, 'https://panorama.com.tr/implant-tedavisi/' => 900] as $page => $impressions) {
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

    /** @param  array<string, mixed>  $meta */
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

    private function html(string $url, int $status): void
    {
        DB::table('website_html_snapshot')->insert(['digital_asset_id' => $this->site->id, 'url' => $url, 'status_code' => $status, 'html_hash' => hash('sha256', $url),
            'html_bytes' => 10, 'change_state' => 'new', 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => Str::random(20)]);
    }
}
