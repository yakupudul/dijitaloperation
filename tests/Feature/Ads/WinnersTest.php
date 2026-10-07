<?php

namespace Tests\Feature\Ads;

use App\Livewire\Operator\Winners\LibrariesPage;
use App\Livewire\Operator\Winners\ServicePage;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\Ads\AdLibraries;
use App\Services\Ads\AdServiceStats;
use App\Services\Ads\Winners;
use App\Services\Intel\SerpResults;
use App\Services\Meta\MetaCampaignServices;
use App\Services\Site\Analysis\SiteAnalysisReader;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\SeedsMetaAccount;
use Tests\TestCase;

/**
 * Kazananlar and Kütüphaneler: the daily website / Business Profile numbers per service, the four-channel race with the
 * fair-race rules, the overall ranking with history, and the libraries (texts, targetings, season, map).
 */
class WinnersTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAccount;

    private BrandOffering $implant;

    private int $serviceId;

    /** @var array<string, Brand> */
    private array $others = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Http::preventStrayRequests();
        $this->seedMetaAccount();
        $services = app(MetaCampaignServices::class);
        $services->sync($this->asset->load('brand'));
        $id = collect($services->offerings($this->brand))->firstWhere('name', 'Diş İmplantı')['id'];
        $this->implant = BrandOffering::query()->findOrFail($id);
        $this->serviceId = (int) $this->implant->service_catalog_item_id;
        app(AdServiceStats::class)->refresh($this->asset); // Panorama on Meta: 3.000 TRY, 64 forms
    }

    /**
     * Atlas and Beta race Panorama on implants in Ankara; Gama advertises messages in İzmir.
     * Meta (forms): Panorama 46,88, Atlas 40, Beta under the spend threshold. Google Ads: Beta 100, Atlas 150.
     * Web: Atlas 2,5. sıra, Panorama 4,2, Beta too few impressions. Profile: Atlas 4,9 · 120, Beta 4,7 · 300, Panorama 5 reviews.
     */
    private function race(): void
    {
        foreach (['Atlas' => 'Ankara', 'Beta' => 'Ankara', 'Gama' => 'İzmir'] as $name => $city) {
            $brand = Brand::factory()->create(['customer_id' => $this->brand->customer_id, 'name' => $name, 'sector_id' => $this->brand->sector_id]);
            $this->others[$name] = $brand;
            BrandOffering::query()->create(['brand_id' => $brand->id, 'service_catalog_item_id' => $this->serviceId, 'status' => 'active', 'priority' => 'main']);
        }
        $ad = fn (string $channel, string $brand, string $type, float $spend, float $results, string $city = 'Ankara', ?string $campaign = null) => DB::table('ad_service_stats')->insert([
            'channel' => $channel, 'digital_asset_id' => DigitalAsset::factory()->create(['brand_id' => $this->others[$brand]->id, 'type' => $channel === 'meta' ? 'meta_ads' : 'google_ads'])->id,
            'brand_id' => $this->others[$brand]->id, 'brand_offering_id' => 999, 'service_id' => $this->serviceId, 'sector_id' => $this->brand->sector_id, 'city' => $city,
            'result_type' => $type, 'spend' => $spend, 'results' => $results, 'period_end' => '2026-10-29', 'top_campaign' => $campaign, 'currency' => 'TRY', 'created_at' => now(), 'updated_at' => now()]);
        $ad('meta', 'Atlas', 'leads', 2400, 60, 'Ankara', 'Atlas implant form');
        $ad('meta', 'Beta', 'leads', 1500, 50);
        $ad('meta', 'Gama', 'messages', 5000, 100, 'İzmir');
        $ad('google_ads', 'Atlas', 'conversions', 3000, 20, 'Ankara', 'Atlas arama');
        $ad('google_ads', 'Beta', 'conversions', 2500, 25, 'Ankara', 'Beta implant arama');
        $web = fn (Brand $brand, int $clicks, int $impressions, ?float $position, ?string $url) => DB::table('web_service_stats')->insert([
            'digital_asset_id' => DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website'])->id, 'brand_id' => $brand->id, 'brand_offering_id' => 999,
            'service_id' => $this->serviceId, 'sector_id' => $brand->sector_id, 'city' => 'Ankara', 'pages' => 2, 'clicks' => $clicks, 'impressions' => $impressions, 'position' => $position,
            'top_url' => $url, 'top_clicks' => $clicks, 'period_end' => '2026-10-29', 'created_at' => now(), 'updated_at' => now()]);
        $web($this->brand, 300, 2000, 4.2, 'https://panorama.test/implant/');
        $web($this->others['Atlas'], 100, 1500, 2.5, 'https://atlas.test/implant-fiyatlari/');
        $web($this->others['Beta'], 5, 50, 1.0, null);
        $gbp = fn (Brand $brand, ?float $rating, int $reviews, int $new) => DB::table('gbp_profile_stats')->insert([
            'digital_asset_id' => DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile'])->id, 'brand_id' => $brand->id, 'name' => $brand->name.' Çankaya',
            'city' => 'Ankara', 'rating' => $rating, 'reviews' => $reviews, 'new_reviews' => $new, 'labels' => '["İmplant"]', 'service_ids' => json_encode([$this->serviceId]),
            'period_end' => '2026-10-29', 'created_at' => now(), 'updated_at' => now()]);
        $gbp($this->others['Atlas'], 4.9, 120, 6);
        $gbp($this->others['Beta'], 4.7, 300, 31);
        $gbp($this->brand, 4.8, 5, 1);
    }

    public function test_website_and_profile_numbers_are_kept_per_service(): void
    {
        $site = DigitalAsset::query()->where('brand_id', $this->brand->id)->where('type', 'website')->firstOrFail();
        $cluster = DB::table('clusters')->insertGetId(['sector_id' => $this->brand->sector_id, 'service_id' => $this->serviceId, 'name' => 'İmplant Ankara', 'approved' => true, 'created_at' => now(), 'updated_at' => now()]);
        $page = Page::query()->where('website_asset_id', $site->id)->firstOrFail();
        OfferingPage::query()->firstOrCreate(['brand_offering_id' => $this->implant->id, 'page_id' => $page->id], ['source' => 'manual']);
        // SiteAnalysisReader is final: its hourly cache is filled with the numbers the reader would build.
        $w = app(SiteAnalysisReader::class)->window($site, AdServiceStats::DAYS);
        $key = fn (string $part): string => 'site:analysis:'.$site->id.':'.$part.':'.$w['start'].':'.$w['end'].':'.$w['prev_start'].':'.md5(json_encode([$w['gsc'], $w['ga4']]));
        Cache::put($key('clusters'), [
            ['cluster_id' => $cluster, 'clicks' => 80, 'impressions' => 1000, 'position' => 3.0],
            ['cluster_id' => $cluster, 'clicks' => 20, 'impressions' => 1000, 'position' => 5.0],
        ], now()->addHour());
        Cache::put($key('pages'), [['path' => SiteAnalysisReader::path((string) $page->url), 'clicks' => 70]], now()->addHour());

        $this->assertSame(1, app(AdServiceStats::class)->refresh($site));
        $row = DB::table('web_service_stats')->sole();
        $this->assertSame([$this->serviceId, 100, 2000, 4.0, 1, (string) $page->url, 70], [(int) $row->service_id, (int) $row->clicks, (int) $row->impressions, (float) $row->position, (int) $row->pages, $row->top_url, (int) $row->top_clicks]);

        $profile = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'name' => 'Panorama Çankaya']);
        $google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/77', 'parent_external_id' => 'accounts/11', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $profile->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        DB::table('gbp_service_snapshots')->insert(['digital_asset_id' => $profile->id, 'external_resource_id' => $resource->id, 'run_id' => 1, 'location_name' => 'locations/77', 'captured_at' => now(),
            'service_items' => json_encode([['freeFormServiceItem' => ['label' => ['displayName' => 'Diş implantı']]], ['freeFormServiceItem' => ['label' => ['displayName' => 'Kanal tedavisi']]]]),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('gbp_reviews')->insert(['external_resource_id' => $resource->id, 'digital_asset_id' => $profile->id, 'run_id' => 1, 'location_name' => 'locations/77', 'review_id' => 'r1', 'star_rating' => 'FIVE', 'create_time' => now()->subDays(3), 'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(1, app(AdServiceStats::class)->refresh($profile));
        $stats = DB::table('gbp_profile_stats')->sole();
        $this->assertSame([[$this->serviceId], 1, 1], [json_decode($stats->service_ids, true), (int) $stats->reviews, (int) $stats->new_reviews], 'only Diş İmplantı is a brand service on the profile');
    }

    public function test_the_race_follows_the_fair_rules_and_scores_every_channel(): void
    {
        $this->race();
        $winners = app(Winners::class);
        $race = $winners->race($winners->load(''), $this->serviceId);
        $names = fn (string $channel): array => array_map(fn (array $e): string => Brand::query()->find($e['brand_id'])->name, $race['channels'][$channel]['entries']);

        $this->assertSame('leads', $race['channels']['meta']['type'], 'forms: two brands pass, messages one');
        $this->assertSame(['Atlas', 'Panorama Ankara'], $names('meta'), 'Beta is under 2.000 TL');
        $this->assertSame(['Beta', 'Atlas'], $names('google_ads'));
        $this->assertSame(['Atlas', 'Panorama Ankara'], $names('web'), 'Beta has too few impressions');
        $this->assertSame(['Atlas', 'Beta'], $names('gbp'), 'Panorama has 5 reviews');
        $this->assertSame([['Atlas', 88], ['Beta', 38], ['Panorama Ankara', 25]],
            array_map(fn (array $r): array => [Brand::query()->find($r['brand_id'])->name, $r['score']], $race['ranking']));
        $this->assertSame(4, $race['competing']);

        $volume = $winners->race($winners->load(''), $this->serviceId, 'volume');
        $this->assertSame((int) $this->brand->id, $volume['channels']['meta']['entries'][0]['brand_id'], 'more forms');
        $izmir = $winners->race($winners->load('İzmir'), $this->serviceId);
        $this->assertSame(['messages', 1, 0], [$izmir['channels']['meta']['type'], $izmir['competing'], $izmir['eligible']], 'Gama spends in İzmir but alone');
    }

    public function test_screens_show_sectors_the_podium_the_ranking_and_history(): void
    {
        $this->race();
        $yesterday = CarbonImmutable::today('Europe/Istanbul')->subDays(8)->toDateString();
        DB::table('ad_winner_snapshots')->insert(['service_id' => $this->serviceId, 'snapshot_date' => $yesterday,
            'leaders' => json_encode(['meta' => ['brand_id' => $this->brand->id, 'value' => '45 TRY / form'], 'web' => null]),
            'ranking' => json_encode([['brand_id' => $this->brand->id, 'score' => 60, 'rank' => 1], ['brand_id' => $this->others['Atlas']->id, 'score' => 40, 'rank' => 3]]),
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, app(Winners::class)->snapshot());

        $this->actingAs($this->admin)->get(route('operator.winners'))->assertOk()
            ->assertSee('Kazananlar')->assertSee('Diş sağlığı')->assertSee('Diş İmplantı')->assertSee('4 marka yarışıyor')->assertSee('Lider değişti');
        $this->actingAs($this->admin)->get(route('operator.winner-service', ['serviceId' => $this->serviceId]))->assertOk()
            ->assertSee('Genel sıralama')->assertSee('Atlas implant form')->assertSee('/implant-fiyatlari/')->assertSee('Beta implant arama')
            ->assertSee('Kazananın tarifi')->assertSee('Marka adıyla gelen aramalar sayılmadı')->assertSee('Adil yarış kuralları')
            ->assertSee('↑ 2')->assertSee('↓ 2')->assertSee('Meta liderliği Panorama Ankara markasından Atlas markasına geçti');
        Livewire::actingAs($this->admin)->test(ServicePage::class, ['serviceId' => $this->serviceId])
            ->set('measure', 'volume')->assertSee('64 form')->set('measure', 'bogus')->assertSet('measure', 'cost')
            ->set('city', 'İzmir')->assertSee('Eşiği geçen marka yok.');
    }

    public function test_libraries_show_saved_items_the_season_and_the_map(): void
    {
        $this->race();
        DB::table('ad_library_items')->insert(['kind' => 'text', 'channel' => 'meta', 'service_id' => $this->serviceId, 'result_type' => 'leads', 'brand_id' => $this->others['Atlas']->id,
            'campaign_name' => 'Atlas implant form', 'title' => 'İmplantta ücretsiz muayene', 'payload' => json_encode(['title' => 'İmplantta ücretsiz muayene', 'body' => 'Tedavi planınızı aynı gün öğrenin.', 'video' => true]),
            'cpr' => 40, 'results' => 60, 'currency' => 'TRY', 'fingerprint' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        $query = DB::table('queries')->insertGetId(['text' => 'implant ankara', 'text_hash' => hash('sha256', 'implant ankara'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('clusters')->insert(['sector_id' => $this->brand->sector_id, 'service_id' => $this->serviceId, 'name' => 'İmplant Ankara', 'main_query_id' => $query, 'approved' => true, 'created_at' => now(), 'updated_at' => now()]);
        $monthly = array_map(fn (int $m): array => ['year' => 2026, 'month' => $m, 'volume' => $m === 11 ? 1000 : 400], range(1, 12));
        DB::table('query_volumes')->insert(['query' => 'implant ankara', 'query_hash' => hash('sha256', SerpResults::normalize('implant ankara')), 'location_code' => 2792, 'language_code' => 'tr',
            'volume' => 450, 'monthly' => json_encode($monthly), 'fetched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $season = app(AdLibraries::class)->season((int) $this->brand->sector_id);
        $this->assertSame([1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 4, 1], $season['rows'][0]['levels']);
        $this->assertSame('Kas', $season['rows'][0]['peak']);
        $this->assertStringContainsString('Kas ayında tepe', (string) $season['note'], 'October: November is a month ahead');

        $map = app(AdLibraries::class)->map((int) $this->brand->sector_id);
        $row = collect($map['rows'])->firstWhere('service', 'Diş İmplantı');
        $cells = array_combine(array_values($map['brands']), $row['cells']);
        $this->assertSame(['Atlas' => 4, 'Beta' => 4, 'Gama' => 1, 'Panorama Ankara' => 3], $cells);

        $this->actingAs($this->admin)->get(route('operator.libraries'))->assertOk()->assertSee('Tedavi planınızı aynı gün öğrenin.')->assertSee('İmplantta ücretsiz muayene');
        Livewire::actingAs($this->admin)->test(LibrariesPage::class)
            ->set('tab', 'mevsim')->assertSee('Hizmet talebi, aylara göre')->assertSee('Kas ayında tepe')
            ->set('tab', 'harita')->assertSee('Hangi marka hangi hizmette kaç kanalda')->assertSee('Gama')
            ->set('tab', 'metin')->call('remove', (int) DB::table('ad_library_items')->value('id'))->assertSee('Kütüphane boş');
        $this->assertSame(0, DB::table('ad_library_items')->count());
    }
}
