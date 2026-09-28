<?php

namespace Tests\Feature\Website;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Website\PageScorecard;
use App\Models\Brand;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Customer;
use App\Models\DataPool\RawIngestionObject;
use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsiteIntelligenceProjectionRun;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Models\Run;
use App\Models\SearchDemandImprovementRun;
use App\Models\ServiceCategory;
use App\Models\SiteFixItem;
use App\Models\User;
use App\Models\WebsiteUrlAudit;
use App\Models\WebsiteUrlVerdict;
use App\Services\Website\UrlAudit\UrlAuditService;
use App\Services\Website\WebsiteAssessmentService;
use App\Support\Roles;
use App\Support\ServiceScope;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Brain\InsertsFacts;
use Tests\TestCase;

/** Faz 5 URL karnesi: one verdict per document URL, precomputed, with reason, solution and action. */
final class UrlVerdictTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;

    private const string HOST = 'panorama.test';

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 09:00:00', 'UTC'));
        Storage::fake('url-audit-test');
        $this->customer = Customer::factory()->create(['status' => CustomerStatus::Active]);
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Panorama Diş']);
        $category = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş', 'normalized_key' => 'dental']);
        $this->brand->sectors()->attach($category->id);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'status' => 'active',
            'primary_url' => 'https://'.self::HOST.'/', 'domain' => self::HOST]);

        $home = $this->page('/', ['document_head' => ['title' => 'Panorama Diş Kliniği | Ankara']]);
        $this->html($home, '<html lang="tr"><head><title>Panorama Diş</title><script type="application/ld+json">{"@type":"Dentist","name":"Panorama Diş","telephone":"+90 312 555 11 22","address":{"@type":"PostalAddress","postalCode":"06680","addressLocality":"Ankara"}}</script></head><body><h1>Panorama Diş</h1><a href="tel:+903125551122">Ara</a><p>'.str_repeat('Ankara diş kliniği. ', 60).'</p></body></html>');

        // Clean service page: indexable, in sitemap, linked, author, procedure and breadcrumb data.
        $clean = $this->page('/tedavilerimiz/kanal-tedavisi/', ['document_head' => ['title' => 'Kanal Tedavisi', 'canonical_hrefs' => ['https://'.self::HOST.'/tedavilerimiz/kanal-tedavisi/']], 'content' => ['word_count' => 900]]);
        $this->html($clean, '<html lang="tr"><head><script type="application/ld+json">[{"@type":"MedicalProcedure","name":"Kanal tedavisi"},{"@type":"BreadcrumbList","itemListElement":[]}]</script></head><body><h1>Kanal Tedavisi</h1><p class="author">Dr. Ayşe Yılmaz</p><p>'.str_repeat('Kanal tedavisi süreci anlatılır. ', 80).'</p>'
            .'<h2>Tedavi süreci</h2><h2>Kaç seans sürer?</h2><h2>Kimlere uygulanır?</h2><h2>Riskler</h2><h2>Tedavi sonrası bakım</h2><h3>Ağrı olur mu?</h3></body></html>');

        // Doorway group: one service head ("implant") repeated with location / modifier words.
        foreach (['/ankara-implant-merkezi/', '/ankara-implant-klinigi/', '/ankara-implant-yapan-yerler/', '/ankara-dis-implanti/', '/cankaya-implant/'] as $path) {
            $this->page($path, ['document_head' => ['title' => 'Sayfa '.$path]]);
        }
        // Distinct services are not grouped.
        $this->page('/zirkonyum-kaplama/', ['document_head' => ['title' => 'Zirkonyum kaplama']]);
        $whitening = $this->page('/dis-beyazlatma/', ['document_head' => ['title' => 'Diş beyazlatma']]);
        // Sağlık tanıtım yönetmeliği: discount wording on a live page of a dental brand.
        $this->html($whitening, '<html lang="tr"><body><h1>Diş beyazlatma</h1><p>Bu ay diş beyazlatmada %30 indirim kampanyası! '.str_repeat('Beyazlatma bilgisi. ', 60).'</p></body></html>');
        // Failing standard: noindex page listed in the sitemap.
        $this->page('/eski-kampanya/', ['document_head' => ['title' => 'Eski kampanya', 'robots' => 'noindex,follow']]);
        // Junk page open to Google.
        $this->page('/deneme/', ['document_head' => ['title' => 'Deneme']]);
        // Crawl-only page (not in the sitemap, no traffic).
        $this->page('/sadece-tarama/', ['document_head' => ['title' => 'Sadece tarama sayfası']]);

        DB::table('brand_service_areas')->insert(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Çankaya',
            'normalized_key' => hash('sha256', 'ankara-cankaya'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $sitemap = [];
        foreach (['/', '/tedavilerimiz/kanal-tedavisi/', '/ankara-implant-merkezi/', '/ankara-implant-klinigi/', '/ankara-implant-yapan-yerler/', '/ankara-dis-implanti/', '/cankaya-implant/', '/zirkonyum-kaplama/', '/dis-beyazlatma/', '/eski-kampanya/', '/deneme/', '/yeni-sayfa/'] as $path) {
            $sitemap['https://'.self::HOST.$path] = ['m' => null, 's' => 'https://'.self::HOST.'/sitemap.xml'];
        }
        DB::table('website_sitemap_watch')->insert(['digital_asset_id' => $this->site->id, 'pages' => json_encode($sitemap), 'page_count' => count($sitemap), 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        foreach (['/tedavilerimiz/kanal-tedavisi/', '/ankara-implant-merkezi/', '/zirkonyum-kaplama/', '/dis-beyazlatma/', '/eski-kampanya/', '/deneme/', '/sadece-tarama/', '/ankara-implant-klinigi/', '/ankara-implant-yapan-yerler/', '/ankara-dis-implanti/', '/cankaya-implant/'] as $path) {
            $this->link('/', $path);
        }
        // Google clicks make /ankara-implant-merkezi/ the page to keep.
        $this->fact('gsc_page_daily', ['site_url' => 'sc-domain:'.self::HOST, 'reporting_date' => '2026-09-10', 'page' => 'https://'.self::HOST.'/ankara-implant-merkezi/', 'clicks' => 30, 'impressions' => 900]);
    }

    public function test_every_document_url_gets_one_verdict_with_reason_and_solution(): void
    {
        $result = app(UrlAuditService::class)->refresh($this->site);

        $this->assertSame('completed', $result['status']);
        $verdicts = WebsiteUrlVerdict::query()->where('digital_asset_id', $this->site->id)->get()->keyBy('path');
        $this->assertSame($verdicts->count(), array_sum($result['counts']));

        // Clean page: explicitly "no problem", with what was checked.
        $clean = $verdicts['/tedavilerimiz/kanal-tedavisi/'];
        $this->assertSame(WebsiteUrlVerdict::OK, $clean->verdict, $clean->reason);
        $this->assertStringContainsString('Sorun yok — gerek yok', $clean->reason);
        $this->assertStringContainsString('HTTP 200', $clean->reason);
        $this->assertSame('Bir şey yapmanıza gerek yok.', $clean->solution);
        // Questions without FAQPage markup are informational only (no FAQ rich result since 2026-05-07).
        $this->assertSame('info', collect($clean->findings)->firstWhere('id', 'website:url:faq_schema')['state']);
        $this->assertSame('pass', collect($clean->findings)->firstWhere('id', 'website:url:service_content_depth')['state']);

        // Doorway group: merge into the page with Google clicks, the group is listed.
        foreach (['/ankara-implant-klinigi/', '/ankara-implant-yapan-yerler/', '/ankara-dis-implanti/', '/cankaya-implant/'] as $path) {
            $row = $verdicts[$path];
            $this->assertSame(WebsiteUrlVerdict::MERGE, $row->verdict, $path.': '.$row->reason);
            $this->assertStringContainsString('/ankara-implant-merkezi/', (string) $row->solution);
            $this->assertCount(5, $row->facts['group']['members']);
            $this->assertSame(self::HOST.'/ankara-implant-merkezi', $row->facts['group']['keeper']);
        }
        $this->assertNotSame(WebsiteUrlVerdict::MERGE, $verdicts['/ankara-implant-merkezi/']->verdict, 'the kept page is not merged');
        $this->assertNotSame(WebsiteUrlVerdict::MERGE, $verdicts['/zirkonyum-kaplama/']->verdict, 'distinct services are not grouped');
        $this->assertNotSame(WebsiteUrlVerdict::MERGE, $verdicts['/dis-beyazlatma/']->verdict);
        $promotion = collect($verdicts['/dis-beyazlatma/']->findings)->firstWhere('id', 'website:url:tr_health_promotion');
        $this->assertSame('review', $promotion['state']);
        $this->assertStringContainsString('indirim', $promotion['finding']);
        $this->assertSame(WebsiteUrlVerdict::FIX, $verdicts['/dis-beyazlatma/']->verdict);

        // Failing standard: Düzelt with the rule, finding and solution.
        $old = $verdicts['/eski-kampanya/'];
        $this->assertSame(WebsiteUrlVerdict::FIX, $old->verdict);
        $finding = collect($old->findings)->firstWhere('id', 'website:url:sitemap_hygiene');
        $this->assertSame('fail', $finding['state']);
        $this->assertStringContainsString('"noindex"', $finding['finding']);
        $this->assertStringContainsString('sitemap’ten çıkarın', $finding['solution']);

        // Junk page: remove from the index.
        $this->assertSame(WebsiteUrlVerdict::DEINDEX, $verdicts['/deneme/']->verdict);

        // Sitemap-only URL (never crawled): included, "Kontrol et" naming the missing data.
        $new = $verdicts['/yeni-sayfa/'];
        $this->assertSame(WebsiteUrlVerdict::CHECK, $new->verdict);
        $this->assertStringContainsString('HTTP durumu', $new->reason);
        $this->assertSame(['sitemap'], $new->facts['sources']);

        // Crawl-only page is included too.
        $this->assertTrue($verdicts->has('/sadece-tarama/'));
        $this->assertContains('crawl', $verdicts['/sadece-tarama/']->facts['sources']);

        // Site-level checks are stored once on the audit (health brand: E-E-A-T trust pages missing).
        $audit = WebsiteUrlAudit::query()->where('digital_asset_id', $this->site->id)->firstOrFail();
        $this->assertSame('completed', $audit->status);
        $this->assertSame('fail', $audit->site_checks['website:url:eeat_trust_pages']['state']);
        $this->assertSame('pass', $audit->site_checks['website:url:medical_business_schema']['state']);
        $this->assertSame('pass', $audit->site_checks['website:url:organization_nap_schema']['state']);
        $this->assertSame('not_applicable', $audit->site_checks['website:url:gbp_nap_consistency']['state']);
        // Research standards 2026-09: health site without update date / editor is "Öneri"; missing sources are never a failure.
        $this->assertSame('review', $audit->site_checks['website:url:tr_health_disclosure']['state']);
        $this->assertSame('not_applicable', $audit->site_checks['website:url:robots_search_engines']['state'], 'no robots.txt evidence');
        $this->assertSame('unknown', $audit->site_checks['website:url:indexnow']['state'], 'no WordPress Connector');
        $this->assertSame('not_applicable', $audit->site_checks['website:url:ai_referral_tracking']['state'], 'no GA4 data');
        $this->assertSame('pass', $audit->site_checks['website:url:site_reputation_abuse']['state']);
        $this->assertNotEmpty($audit->groups);
    }

    public function test_ranking_opportunity_strengthens_and_thin_page_without_value_leaves_the_index(): void
    {
        $this->fact('gsc_page_daily', ['site_url' => 'sc-domain:'.self::HOST, 'reporting_date' => '2026-09-12', 'page' => 'https://'.self::HOST.'/zirkonyum-kaplama/', 'clicks' => 0, 'impressions' => 120]);
        $this->fact('gsc_query_page_daily', ['site_url' => 'sc-domain:'.self::HOST, 'reporting_date' => '2026-09-12', 'query' => 'zirkonyum kaplama ankara',
            'page' => 'https://'.self::HOST.'/zirkonyum-kaplama/', 'clicks' => 0, 'impressions' => 120, 'metadata' => json_encode(['provider_average_position' => 8.4])]);
        $this->page('/kisa-duyuru/', ['document_head' => ['title' => 'Kısa duyuru'], 'content' => ['word_count' => 60]]);

        app(UrlAuditService::class)->refresh($this->site);

        $zirkon = WebsiteUrlVerdict::query()->where('path', '/zirkonyum-kaplama/')->firstOrFail();
        $this->assertSame(WebsiteUrlVerdict::STRENGTHEN, $zirkon->verdict, $zirkon->reason);
        $this->assertStringContainsString('8,4. sırada', $zirkon->reason);
        $this->assertStringContainsString('zirkonyum kaplama ankara', $zirkon->reason);

        $thin = WebsiteUrlVerdict::query()->where('path', '/kisa-duyuru/')->firstOrFail();
        $this->assertSame(WebsiteUrlVerdict::DEINDEX, $thin->verdict, $thin->reason);
        $this->assertStringContainsString('noindex', (string) $thin->solution);
    }

    public function test_open_site_fix_and_stored_standard_failure_become_fix_findings_with_action(): void
    {
        SiteFixItem::query()->create(['digital_asset_id' => $this->site->id, 'brand_id' => $this->brand->id, 'item_key' => hash('sha256', 'x'), 'type' => 'seo_title', 'phase' => 1,
            'object_id' => '5', 'url' => 'https://'.self::HOST.'/zirkonyum-kaplama/', 'label' => 'Zirkonyum', 'reason' => 'Başlık 8 karakter; 30–60 arası önerilir.', 'current' => [], 'status' => 'open']);
        $activity = Run::query()->create(['digital_asset_id' => $this->site->id, 'module_id' => 'website', 'status' => 'completed', 'started_at' => now(), 'metadata' => []]);
        SearchDemandImprovementRun::query()->create([
            'uuid' => (string) Str::uuid(), 'run_id' => $activity->id, 'brand_id' => $this->brand->id, 'digital_asset_id' => $this->site->id, 'status' => 'completed',
            'input_payload' => ['mode' => WebsiteAssessmentService::MODE, 'standards' => ['website:head:meta-description-missing' => ['title' => 'Meta açıklaması eksik', 'severity' => 'low', 'criterion' => 'Meta açıklaması olmalı.', 'action' => 'Meta açıklaması yazın.']]],
            'input_fingerprint' => hash('sha256', 'a'), 'agent_signature' => 'deterministic', 'skill_signature' => 'v3', 'skill_fingerprint' => hash('sha256', 'b'),
            'route_key' => WebsiteAssessmentService::MODE, 'route_signature' => hash('sha256', 'c'), 'completed_at' => now(),
            'response_payload' => ['pages' => [['url' => 'https://'.self::HOST.'/dis-beyazlatma/', 'checks' => ['website:head:meta-description-missing' => ['state' => 'fail', 'observed' => null]]]], 'site_checks' => []],
        ]);

        app(UrlAuditService::class)->refresh($this->site);

        $zirkon = WebsiteUrlVerdict::query()->where('path', '/zirkonyum-kaplama/')->firstOrFail();
        $this->assertSame(WebsiteUrlVerdict::FIX, $zirkon->verdict);
        $fix = collect($zirkon->findings)->firstWhere('source', 'fix');
        $this->assertSame('Düzeltmeyi hazırla / uygula', $fix['action']['label']);
        $this->assertStringContainsString('tab=fixes', $fix['action']['url']);
        $this->assertStringContainsString('fix_phase=1', $fix['action']['url']);

        $white = WebsiteUrlVerdict::query()->where('path', '/dis-beyazlatma/')->firstOrFail();
        $this->assertSame(WebsiteUrlVerdict::FIX, $white->verdict);
        $stored = collect($white->findings)->firstWhere('source', 'stored_standard');
        $this->assertSame('Meta açıklaması eksik', $stored['rule']);
        $this->assertSame('Meta açıklaması yazın.', $stored['solution']);
    }

    public function test_refresh_button_queues_job_with_activity_run_and_ui_filters(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        $component = Livewire::test(PageScorecard::class, ['websiteId' => $this->site->id])
            ->assertSee('Sayfa Karnesi henüz hesaplanmadı')
            ->call('refresh')
            ->assertSee('Sayfa Karnesi yenileniyor');

        $run = Run::query()->where('metadata->operation_type', UrlAuditService::OPERATION)->firstOrFail();
        $this->assertSame('completed', $run->status, 'sync queue ran the job');
        $this->assertStringContainsString('birleştir', (string) data_get($run->metadata, 'result_summary'));
        $this->assertGreaterThan(0, WebsiteUrlVerdict::query()->where('digital_asset_id', $this->site->id)->count());

        $component->call('$refresh')
            ->assertSee('Birleştir / yönlendir (4)')
            ->assertSee('/tedavilerimiz/kanal-tedavisi/')
            ->call('filter', WebsiteUrlVerdict::MERGE)
            ->assertSeeHtml('title="https://panorama.test/ankara-dis-implanti/"')
            ->assertDontSee('/tedavilerimiz/kanal-tedavisi/')
            ->call('filter', '')
            ->set('search', 'eski-kampanya')
            ->assertSee('/eski-kampanya/')
            ->assertDontSeeHtml('title="https://panorama.test/ankara-dis-implanti/"');

        $row = WebsiteUrlVerdict::query()->where('path', '/eski-kampanya/')->firstOrFail();
        $component->call('toggle', $row->id)
            ->assertSee('Bulgu:')
            ->assertSee('Çözüm:')
            ->assertSee('Sitemap’te olmaması gereken adres');

        $this->get(route('operator.website', ['assetId' => $this->site->id, 'tab' => 'scorecard']))->assertOk()->assertSee('Sayfa Karnesi');
    }

    public function test_projection_rebuild_hook_refreshes_and_rows_are_replaced(): void
    {
        app(UrlAuditService::class)->refresh($this->site);
        $before = WebsiteUrlVerdict::query()->where('digital_asset_id', $this->site->id)->count();
        $this->page('/yeni-hizmet/', ['document_head' => ['title' => 'Yeni hizmet']]);

        UrlAuditService::dispatchFor($this->site->id, 'projection');

        $this->assertSame($before + 1, WebsiteUrlVerdict::query()->where('digital_asset_id', $this->site->id)->count());
        $this->assertSame('projection', WebsiteUrlAudit::query()->where('digital_asset_id', $this->site->id)->value('trigger'));
    }

    public function test_service_scope_passive_customer_gets_no_computation_and_no_rows(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);
        app(UrlAuditService::class)->refresh($this->site);
        $this->customer->update(['status' => CustomerStatus::Inactive]);
        app(ServiceScope::class)->flush();

        $this->assertSame('not_served', app(UrlAuditService::class)->refresh($this->site)['status']);
        Livewire::test(PageScorecard::class, ['websiteId' => $this->site->id])
            ->assertSee(ServiceScope::NOT_SERVED)
            ->assertDontSeeHtml('title="https://panorama.test/ankara-dis-implanti/"')
            ->call('refresh');
        $this->assertSame(0, Run::query()->where('metadata->operation_type', UrlAuditService::OPERATION)->count());
    }

    /** @param  array<string, mixed>  $facts */
    private function page(string $path, array $facts = []): WebsitePageProfile
    {
        $url = 'https://'.self::HOST.$path;
        $identity = IntelligencePageIdentity::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'identity_hash' => hash('sha256', $this->site->id.':'.$url), 'preferred_url' => $url,
            'preferred_url_hash' => hash('sha256', $url), 'scheme' => 'https', 'host' => self::HOST, 'path' => $path,
            'resolution_status' => 'resolved', 'normalization_version' => 'v1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $projection = WebsiteIntelligenceProjectionRun::query()->create([
            'uuid' => (string) Str::uuid(), 'website_asset_id' => $this->site->id, 'trigger' => 'test', 'status' => 'completed',
            'schema_version' => 1, 'intelligence_registry_version' => 1, 'period_start' => now()->subDays(90), 'period_end' => now()->subDay(),
        ]);

        return WebsitePageProfile::query()->create([
            'website_asset_id' => $this->site->id, 'page_identity_id' => $identity->id, 'projection_run_id' => $projection->id,
            'preferred_url' => $url, 'profile_version' => 1, 'projected_at' => now(), 'last_observed_at' => now(),
            'source_states' => ['website' => array_replace_recursive(['url' => $url, 'http' => ['status_code' => 200, 'content_type' => 'text/html'],
                'document_head' => ['robots' => 'index,follow', 'canonical_hrefs' => []], 'content' => ['word_count' => 600]], $facts)],
        ]);
    }

    private function html(WebsitePageProfile $profile, string $html): void
    {
        $resource = CollectionResourceRun::factory()->create(['digital_asset_id' => $this->site->id, 'provider_or_source' => 'website']);
        $key = (string) Str::uuid().'.html';
        Storage::disk('url-audit-test')->put($key, $html);
        $object = RawIngestionObject::query()->create(['uuid' => (string) Str::uuid(),
            'resource_run_id' => $resource->id, 'collection_run_id' => $resource->collection_run_id, 'dataset_id' => 'website_html_snapshot',
            'batch_key' => $key, 'provider_or_source' => 'website', 'storage_disk' => 'url-audit-test', 'object_key' => $key,
            'byte_size' => strlen($html), 'sha256' => hash('sha256', $html), 'captured_at' => now()]);
        DB::table('website_html_snapshot')->insert(['digital_asset_id' => $this->site->id,
            'url' => $profile->preferred_url, 'raw_ingestion_object_id' => $object->id, 'html_hash' => hash('sha256', $html),
            'html_bytes' => strlen($html), 'change_state' => 'new', 'observed_at' => now(), 'contract_version' => 1,
            'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => hash('sha256', $key)]);
    }

    private function link(string $from, string $to): void
    {
        DB::table('website_link_edge')->insert(['digital_asset_id' => $this->site->id, 'edge_key' => hash('sha256', $from.$to),
            'source_url' => 'https://'.self::HOST.$from, 'target_url' => 'https://'.self::HOST.$to, 'normalized_target_url' => 'https://'.self::HOST.$to,
            'is_internal' => true, 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(),
            'record_fingerprint' => hash('sha256', 'e'.$from.$to)]);
    }

    /** @param  array<string, mixed>  $values */
    private function fact(string $table, array $values): void
    {
        $this->insertFact($table, $values + ['digital_asset_id' => $this->site->id, 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(20)]);
    }
}
