<?php

namespace Tests\Feature\Intel;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Market\CompetitorWatchPage;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\Intel\BrandIntelSetting;
use App\Models\Intel\MapGridPoint;
use App\Models\Intel\MapGridRun;
use App\Models\SearchDemandCompetitor;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Intel\CompetitorSiteWatch;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Services\Intel\PublicPageReader;
use App\Services\Intel\ReviewIntelService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 8d / 8e: review intelligence (brand + grid competitors) and weekly competitor site watch.
 */
final class ReviewAndCompetitorWatchTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.dataforseo.base_url' => 'https://api.dataforseo.com', 'moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null, 'moxdop-intel.reviews.max_competitors' => 1]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $integration = CoreIntegration::factory()->dataforseo()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(DataForSeoProviderCredentialService::class)->save($integration, ['login' => 'agency@example.com', 'password' => 'secret'], $admin);
        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        BrandIntelSetting::for($this->brand)->fill(['gbp_cid' => '9000', 'monthly_usd' => 1])->save();
    }

    public function test_reviews_for_brand_and_grid_competitors(): void
    {
        $run = MapGridRun::query()->create(['brand_id' => $this->brand->id, 'keyword' => 'implant', 'center_lat' => 41, 'center_lng' => 29, 'grid_size' => 3, 'spacing_km' => 1, 'status' => MapGridRun::STATUS_COMPLETED, 'points_total' => 1, 'points_done' => 1, 'started_at' => now()]);
        MapGridPoint::query()->create(['map_grid_run_id' => $run->id, 'row' => 0, 'col' => 0, 'lat' => 41, 'lng' => 29, 'status' => 'done', 'results' => [
            ['rank' => 1, 'title' => 'Rakip Diş', 'cid' => '1111', 'ours' => false],
            ['rank' => 2, 'title' => 'Atlas Dental', 'cid' => '9000', 'ours' => true],
            ['rank' => 3, 'title' => 'Uzak Klinik', 'cid' => '2222', 'ours' => false],
        ]]);

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/business_data/google/reviews/task_post')) {
                $tasks = array_map(fn (array $payload, int $i): array => ['id' => sprintf('00000000-0000-0000-0000-%012d', $payload['cid']), 'status_code' => 20100, 'cost' => 0.003], $request->data(), array_keys($request->data()));

                return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.006, 'tasks' => $tasks]);
            }
            $cid = (int) substr($request->url(), -12);
            $items = $cid === 9000
                ? [['review_id' => 'a1', 'rating' => ['value' => 5], 'review_text' => 'Harika', 'timestamp' => now()->subDays(3)->format('Y-m-d H:i:s P'), 'owner_answer' => 'Teşekkürler', 'profile_name' => 'Ayşe Y.']]
                : [
                    ['review_id' => 'b1', 'rating' => ['value' => 1], 'review_text' => 'Randevu saatine uyulmadı, bekleme süresi çok uzun.', 'timestamp' => now()->subDays(10)->format('Y-m-d H:i:s P'), 'owner_answer' => null],
                    ['review_id' => 'b2', 'rating' => ['value' => 2], 'review_text' => 'Bekleme süresi uzun, fiyatlar yüksek.', 'timestamp' => now()->subDays(40)->format('Y-m-d H:i:s P'), 'owner_answer' => null],
                    ['review_id' => 'b3', 'rating' => ['value' => 5], 'review_text' => 'Güzel', 'timestamp' => now()->subDays(100)->format('Y-m-d H:i:s P'), 'owner_answer' => 'Sağ olun'],
                ];

            return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'tasks' => [['id' => 'x', 'status_code' => 20000, 'result' => [['rating' => ['value' => $cid === 9000 ? 4.9 : 4.1, 'votes_count' => 10], 'reviews_count' => $cid === 9000 ? 120 : 340, 'items' => $items]]]]]);
        });

        $service = app(ReviewIntelService::class);
        $this->assertSame(2, $service->refresh($this->brand), 'own profile + 1 competitor (max 1), ours skipped');
        $this->travel(2)->minutes();
        $this->assertSame(2, app(DataForSeoTaskQueue::class)->collect()['completed']);

        $rows = collect($service->comparison($this->brand))->keyBy('title');
        $this->assertSame([4.9, 120, 1, 100], [$rows['Atlas Dental']['rating'], $rows['Atlas Dental']['reviews_count'], $rows['Atlas Dental']['last30'], $rows['Atlas Dental']['response_rate']]);
        $this->assertSame([4.1, 1, 2, 33], [$rows['Rakip Diş']['rating'], $rows['Rakip Diş']['last30'], $rows['Rakip Diş']['last90'], $rows['Rakip Diş']['response_rate']]);
        $this->assertFalse($rows->has('Uzak Klinik'));
        $this->assertStringNotContainsString('Ayşe', (string) json_encode(DB::table('review_items')->get()), 'reviewer names are not stored');

        $themes = array_column($service->negativeThemes($rows['Rakip Diş']['id']), 'phrase');
        $this->assertContains('bekleme süresi', $themes);
        $this->assertNotContains('bekleme', $themes, 'the word only appears inside the phrase');

        Livewire::test(CompetitorWatchPage::class, ['brand' => $this->brand->id])
            ->assertSee('Rakip Diş')->assertSee('%33')
            ->call('$set', 'profile', $rows['Rakip Diş']['id'])->assertSee('bekleme süresi');
        $this->get(route('operator.market.competitor-watch'))->assertOk();
    }

    public function test_competitor_site_watch_diffs_pages_and_message(): void
    {
        $competitor = SearchDemandCompetitor::query()->create(['uuid' => (string) Str::uuid(), 'brand_id' => $this->brand->id, 'display_name' => 'Rakip Diş', 'normalized_domain' => 'rakipdis.com', 'normalized_domain_hash' => hash('sha256', 'rakipdis.com'), 'status' => 'approved']);
        $pages = [
            'https://rakipdis.com/' => '<html><head><title>Rakip Diş | İmplant</title><meta name="description" content="Kadıköy diş kliniği"></head><body><h1>İmplant</h1></body></html>',
            'https://rakipdis.com/robots.txt' => "User-agent: *\nSitemap: https://rakipdis.com/sitemap_index.xml",
            'https://rakipdis.com/sitemap_index.xml' => '<sitemapindex><sitemap><loc>https://rakipdis.com/page-sitemap.xml</loc></sitemap></sitemapindex>',
            'https://rakipdis.com/page-sitemap.xml' => '<urlset><url><loc>https://rakipdis.com/implant/</loc></url><url><loc><![CDATA[https://rakipdis.com/eski/]]></loc></url></urlset>',
        ];
        $this->mock(PublicPageReader::class, function ($mock) use (&$pages): void {
            $mock->shouldReceive('fetch')->andReturnUsing(function (string $url) use (&$pages): array {
                $body = $pages[$url] ?? null;

                return ['ok' => $body !== null, 'status_code' => $body !== null ? 200 : 404, 'body' => $body, 'content_type' => 'text/html', 'final_url' => $url, 'error' => null];
            });
        });

        $watch = app(CompetitorSiteWatch::class);
        $this->assertSame(['watched' => 1, 'changed' => 0], $watch->watchBrand($this->brand));
        $this->assertSame(['watched' => 0, 'changed' => 0], $watch->watchBrand($this->brand), 'weekly');

        $pages['https://rakipdis.com/'] = '<html><head><title>Rakip Diş | Zirkonyum Kampanyası</title><meta name="description" content="Kadıköy diş kliniği"></head><body><h1>İmplant</h1></body></html>';
        $pages['https://rakipdis.com/page-sitemap.xml'] = '<urlset><url><loc>https://rakipdis.com/implant/</loc></url><url><loc>https://rakipdis.com/zirkonyum/</loc></url><url><loc>https://rakipdis.com/kadikoy-implant/</loc></url></urlset>';
        $this->travel(7)->days();
        $this->assertSame(['watched' => 1, 'changed' => 1], $watch->watchBrand($this->brand));
        $latest = DB::table('competitor_site_snapshots')->orderByDesc('id')->first();
        $this->assertSame(['https://rakipdis.com/kadikoy-implant/', 'https://rakipdis.com/zirkonyum/'], json_decode($latest->new_urls, true));
        $this->assertSame(1, (int) $latest->removed_urls_count);
        $this->assertSame(['title'], array_keys(json_decode($latest->changes, true)));
        $this->assertNull(DB::table('competitor_site_snapshots')->orderBy('id')->value('sitemap_urls'), 'only the latest URL list is kept');

        $this->assertStringContainsString('view_all_page_id=123456', CompetitorSiteWatch::adLibraryUrl('123456', 'x'));
        $this->assertStringContainsString('q=Rakip%20Di%C5%9F', CompetitorSiteWatch::adLibraryUrl(null, 'Rakip Diş'));

        Livewire::test(CompetitorWatchPage::class, ['brand' => $this->brand->id, 'tab' => 'sites'])->assertSee('Zirkonyum Kampanyası')->assertSee('2 yeni sayfa');
        Livewire::test(CompetitorWatchPage::class, ['brand' => $this->brand->id, 'tab' => 'ads'])
            ->set('pageIds.'.$competitor->id, 'abc')->call('savePageIds')->assertSee('yalnız rakamlardan')
            ->set('pageIds.'.$competitor->id, '777777')->call('savePageIds')->assertSee('view_all_page_id=777777', false);
    }
}
