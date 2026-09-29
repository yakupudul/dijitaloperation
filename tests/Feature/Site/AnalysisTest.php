<?php

namespace Tests\Feature\Site;

use App\Livewire\Operator\Website\V2\AnalysisTab;
use App\Models\BrandClusterPage;
use App\Models\BrandServiceArea;
use App\Models\ClusterQuery;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Page;
use App\Services\Site\Analysis\SiteAnalysisReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\InsertsFacts;
use Tests\TestCase;

/** Faz 4b Analiz: Search Console + GA4 per cluster (by target area), page, raw query and conversions. */
final class AnalysisTest extends TestCase
{
    use InsertsFacts;
    use RefreshDatabase;
    use SiteFixtures;

    private CoreExternalResource $gsc;

    private CoreExternalResource $ga4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSite();
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'city_name' => 'Ankara', 'district_name' => 'Keçiören',
            'physical_branch' => false, 'normalized_key' => 'tr|ankara|kecioren', 'status' => 'active', 'priority_rank' => 2]);
        $this->gsc = CoreExternalResource::factory()->searchConsole()->create();
        $this->ga4 = CoreExternalResource::factory()->create();
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->gsc->id, 'capability' => 'search_console']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->site->id, 'external_resource_id' => $this->ga4->id, 'capability' => 'ga4']);

        $merkez = $this->cluster('İmplant merkezi', 'implant merkezi', 'commercial');
        $agri = $this->cluster('İmplant sonrası ağrı', 'implant sonrası ağrı', 'informational');
        foreach ([$merkez, $agri] as $cluster) {
            ClusterQuery::query()->create(['cluster_id' => $cluster->id, 'query_id' => $cluster->main_query_id]);
        }
        foreach (['ankara implant merkezi', 'çankaya implant merkezi', 'keçiören implant merkezi', 'implant merkezi'] as $raw) {
            $this->source($raw, $merkez->main_query_id);
        }
        $this->source('implant sonrası ağrı', $agri->main_query_id);
        $page = Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://panorama.example/implant/', 'url_hash' => hash('sha256', 'implant'), 'path' => '/implant/']);
        BrandClusterPage::query()->create(['brand_id' => $this->brand->id, 'cluster_id' => $merkez->id, 'website_asset_id' => $this->site->id, 'page_id' => $page->id, 'state' => 'sufficient']);

        foreach ([
            ['ankara implant merkezi', '/implant/', '2026-09-20', 10, 100, 4.0],
            ['çankaya implant merkezi', '/implant/', '2026-09-21', 5, 50, 2.0],
            ['keçiören implant merkezi', '/implant/', '2026-09-22', 2, 20, 3.0],
            ['implant merkezi', '/implant/', '2026-09-26', 1, 30, 8.0],
            ['implant sonrası ağrı', '/blog/agri/', '2026-09-10', 3, 40, 6.0],
            ['diş beyazlatma', '/beyazlatma/', '2026-09-15', 2, 20, 9.0],
            ['ankara implant merkezi', '/implant/', '2026-08-15', 4, 60, 5.0],
            ['çok eski sorgu', '/implant/', '2026-07-01', 50, 500, 1.0],
        ] as [$query, $path, $date, $clicks, $impressions, $position]) {
            $this->insertFacts('gsc_query_page_daily', [
                'digital_asset_id' => null, 'external_resource_id' => $this->gsc->id, 'site_url' => 'sc-domain:panorama.example', 'search_type' => 'web',
                'reporting_date' => $date, 'query' => $query, 'page' => 'https://panorama.example'.$path, 'clicks' => $clicks, 'impressions' => $impressions,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
                'metadata' => json_encode(['provider_average_position' => $position]),
            ]);
        }
        foreach ([
            ['/implant/', 'google', 'organic', '2026-09-20', 40, 3],
            ['/implant/?utm_source=x', 'google', 'cpc', '2026-09-22', 10, 1],
            ['/blog/agri/', 'google', 'organic', '2026-09-10', 5, 0],
        ] as [$landing, $source, $medium, $date, $sessions, $keyEvents]) {
            $this->insertFacts('ga4_landing_source_daily', [
                'digital_asset_id' => null, 'external_resource_id' => $this->ga4->id, 'property_id' => 'properties/1', 'reporting_date' => $date,
                'landingPage' => $landing, 'sessionSource' => $source, 'sessionMedium' => $medium, 'sessions' => $sessions, 'keyEvents' => $keyEvents,
                'contract_version' => 1, 'first_collected_at' => now(), 'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
            ]);
        }
    }

    public function test_numbers_come_from_the_seeded_facts(): void
    {
        $reader = app(SiteAnalysisReader::class);
        $site = $this->site->fresh();

        $window = $reader->window($site, 28);
        $this->assertSame(['2026-08-30', '2026-09-26', '2026-08-02', '2026-08-29'], [$window['start'], $window['end'], $window['prev_start'], $window['prev_end']]);
        $totals = $reader->totals($site, 28);
        $this->assertSame(['clicks' => 23, 'impressions' => 260, 'sessions' => 55, 'key_events' => 4.0], array_intersect_key($totals['current'], array_flip(['clicks', 'impressions', 'sessions', 'key_events'])));
        $this->assertSame(4, $totals['previous']['clicks']);

        $clusters = collect($reader->clusters($site, 28))->keyBy('name');
        $merkez = $clusters['İmplant merkezi'];
        $this->assertSame([18, 200, 4.0, 4, 50, 4.0], [$merkez['clicks'], $merkez['impressions'], $merkez['position'], $merkez['prev_clicks'], $merkez['sessions'], $merkez['key_events']]);
        $areas = collect($merkez['areas'])->keyBy('area');
        $this->assertSame([15, 150, 3.3], [$areas['Çankaya şubesi']['clicks'], $areas['Çankaya şubesi']['impressions'], $areas['Çankaya şubesi']['position']], '"ankara" + "çankaya" queries');
        $this->assertSame(2, $areas['Keçiören, Ankara, TR']['clicks'], 'district named in the query');
        $this->assertSame(1, $areas['—']['clicks'], 'no area in the query');
        $this->assertSame([3, null], [$clusters['İmplant sonrası ağrı']['clicks'], $clusters['İmplant sonrası ağrı']['sessions']], 'no mapped page → no GA4 numbers');

        $pages = collect($reader->pages($site, 28))->keyBy('path');
        $this->assertSame([18, 200, 50, 4.0, 4], [$pages['/implant']['clicks'], $pages['/implant']['impressions'], $pages['/implant']['sessions'], $pages['/implant']['key_events'], $pages['/implant']['prev_clicks']]);
        $this->assertSame(5, $pages['/blog/agri']['sessions']);

        $queries = collect($reader->queries($site, 28))->keyBy('query');
        $this->assertSame([10, 4, 4.0], [$queries['ankara implant merkezi']['clicks'], $queries['ankara implant merkezi']['prev_clicks'], $queries['ankara implant merkezi']['position']]);
        $this->assertArrayNotHasKey('çok eski sorgu', $queries->all());

        $conversions = $reader->conversions($site, 28);
        $this->assertSame([['/implant/', 'organic', 3.0], ['/implant/?utm_source=x', 'cpc', 1.0]], array_map(fn (array $r): array => [$r['landing'], $r['medium'], $r['key_events']], $conversions));

        $this->assertSame(18, $reader->totals($site, 7)['current']['clicks'], '7 days: 20–26 Sept');
    }

    public function test_tab_renders_every_sub_tab_and_period(): void
    {
        Livewire::test(AnalysisTab::class, ['websiteId' => $this->site->id])
            ->assertSee('İmplant merkezi')->assertSee('Çankaya şubesi')->assertSee('bölgesiz')->assertSee('+350%')
            ->call('setSub', 'pages')->assertSee('/implant')->assertSee('/blog/agri')
            ->call('setSub', 'queries')->assertSee('keçiören implant merkezi')->assertDontSee('çok eski sorgu')
            ->call('setSub', 'conversions')->assertSee('google / cpc')
            ->set('period', 7)->assertSee('20.09.2026 – 26.09.2026');
        $this->assertSame(0, DB::table('suggestions')->count(), 'read only');
    }

    private function source(string $raw, int $queryId): void
    {
        DB::table('query_sources')->insert(['external_resource_id' => $this->gsc->id, 'source' => 'gsc', 'raw_query' => $raw, 'month' => '2026-09-01',
            'impressions' => 1, 'clicks' => 0, 'query_id' => $queryId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
