<?php

namespace Tests\Feature\Intel;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Market\MapRankingsPage;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Intel\BrandIntelSetting;
use App\Models\Intel\MapGridRun;
use App\Models\User;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\Integrations\DataForSeo\DataForSeoProviderCredentialService;
use App\Services\Intel\BrandGbpIdentity;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Services\Intel\MapGridService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Faz 8a: Google Maps grid rank tracking over the DataForSEO standard queue.
 */
final class MapGridTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.dataforseo.base_url' => 'https://api.dataforseo.com', 'moxdop.dataforseo.login' => null, 'moxdop.dataforseo.password' => null]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $integration = CoreIntegration::factory()->dataforseo()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        app(DataForSeoProviderCredentialService::class)->save($integration, ['login' => 'agency@example.com', 'password' => 'secret'], $admin);

        $this->brand = Brand::factory()->create(['name' => 'Atlas Dental', 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'domain' => 'atlasdis.com', 'primary_url' => 'https://www.atlasdis.com/']);
        BrandIntelSetting::for($this->brand)->fill(['grid_center_lat' => 40.99, 'grid_center_lng' => 29.03, 'grid_size' => 3, 'grid_spacing_km' => 1, 'monthly_usd' => 1, 'grid_keywords' => ['implant kadıköy'], 'grid_enabled' => true])->save();
    }

    public function test_grid_geometry_and_metrics(): void
    {
        $points = MapGridService::points(41.0, 29.0, 3, 1.0);
        $this->assertCount(9, $points);
        $this->assertSame([41.0, 29.0], [$points[4]['lat'], $points[4]['lng']], 'middle point is the centre');
        $this->assertGreaterThan($points[7]['lat'], $points[1]['lat'], 'row 0 is north');
        $this->assertGreaterThan($points[3]['lng'], $points[5]['lng'], 'col 2 is east');
        $this->assertEqualsWithDelta(1 / 111.32, $points[1]['lat'] - 41.0, 0.00001);

        $this->assertSame(['arp' => 2.67, 'atrp' => 7.25, 'solv' => 50.0], MapGridService::metrics([1, 5, null, 2]));
        $this->assertSame(['arp' => null, 'atrp' => 21.0, 'solv' => 0.0], MapGridService::metrics([null, null]));
        $this->assertSame('1234567890123', BrandGbpIdentity::cidFromMapsUri('https://maps.google.com/maps?cid=1234567890123'));
        $this->assertTrue(DataForSeoEndpointAllowlist::isAllowed('serp/google/maps/task_get/advanced/09241234-1535-0066-0000-5a2b3c4d5e6f'));
        $this->assertFalse(DataForSeoEndpointAllowlist::isAllowed('serp/google/maps/task_get/advanced/../../appendix'));
    }

    public function test_scan_posts_tasks_collects_results_and_computes_metrics(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/serp/google/maps/task_post')) {
                $tasks = [];
                foreach ($request->data() as $i => $payload) {
                    $tasks[] = ['id' => sprintf('00000000-0000-0000-0000-%012d', $i), 'status_code' => 20100, 'status_message' => 'Task Created.', 'cost' => 0.0006, 'data' => $payload];
                }

                return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.0054, 'tasks_count' => count($tasks), 'tasks_error' => 0, 'tasks' => $tasks]);
            }
            $index = (int) substr($request->url(), -12);
            if ($index === 8) {
                return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'tasks' => [['id' => 'x', 'status_code' => 40602, 'status_message' => 'Task In Queue.']]]);
            }
            $items = [
                ['type' => 'maps_search', 'rank_group' => 1, 'title' => 'Rakip Diş', 'cid' => '111', 'domain' => 'rakipdis.com', 'rating' => ['value' => 4.8, 'votes_count' => 300]],
                ['type' => 'maps_paid_item', 'rank_group' => 1, 'title' => 'Reklam'],
            ];
            if ($index % 2 === 0) {
                $items[] = ['type' => 'maps_search', 'rank_group' => 2, 'title' => 'Atlas Dental', 'domain' => 'www.atlasdis.com', 'rating' => ['value' => 4.9, 'votes_count' => 120]];
            }

            return Http::response(['status_code' => 20000, 'status_message' => 'Ok.', 'tasks' => [['id' => 'x', 'status_code' => 20000, 'status_message' => 'Ok.', 'result' => [['items' => $items]]]]]);
        });

        $run = app(MapGridService::class)->start($this->brand, 'implant kadıköy');
        $this->assertSame(9, DB::table('dataforseo_tasks')->where('status', 'posted')->count());
        $this->assertEqualsWithDelta(0.0054, app(DataForSeoTaskQueue::class)->spentThisMonth($this->brand->id), 0.00001);
        $payload = json_decode((string) DB::table('dataforseo_tasks')->value('payload'), true);
        $this->assertMatchesRegularExpression('/^40\.\d{7},29\.\d{7},15z$/', $payload['location_coordinate']);

        $this->assertSame(['completed' => 0, 'failed' => 0, 'waiting' => 0], app(DataForSeoTaskQueue::class)->collect(), 'too early to poll');
        $this->travel(2)->minutes();
        $this->assertSame(['completed' => 8, 'failed' => 0, 'waiting' => 1], app(DataForSeoTaskQueue::class)->collect());
        $this->assertSame(MapGridRun::STATUS_RUNNING, $run->fresh()->status);

        $this->travel(25)->hours();
        $this->assertSame(1, app(DataForSeoTaskQueue::class)->collect()['failed'], 'gives up after the limit');
        $run = $run->fresh();
        $this->assertSame(MapGridRun::STATUS_PARTIAL, $run->status);
        // Resolved points 0..7: even → rank 2 (4 points), odd → not found (4 points).
        $this->assertSame([2.0, 11.5, 50.0], [$run->arp, $run->atrp, $run->solv], 'rank 2 counts toward the top-3 share');
        $competitors = MapGridService::competitors($run);
        $this->assertSame(['Rakip Diş', 8, 8], [$competitors[0]['title'], $competitors[0]['top3'], $competitors[0]['top20']]);
        $this->assertTrue(collect($competitors)->firstWhere('title', 'Atlas Dental')['ours']);
        $this->assertFalse(collect($competitors)->contains('title', 'Reklam'), 'ads are not ranks');

        Livewire::test(MapRankingsPage::class, ['brand' => $this->brand->id])->assertSee('implant kadıköy')->assertSee('Rakip Diş')->assertSee('11.5');
        $this->get(route('operator.market.map-rankings'))->assertOk();
    }

    public function test_budget_setup_and_schedule_guards(): void
    {
        Http::preventStrayRequests();
        BrandIntelSetting::query()->update(['monthly_usd' => 0.001]);
        try {
            app(MapGridService::class)->start($this->brand, 'implant kadıköy');
            $this->fail('cap should block');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Aylık tavan', collect($exception->errors())->flatten()->first());
        }
        $this->assertSame(['started' => 0, 'skipped' => 1], app(MapGridService::class)->runDue());

        $this->brand->customer->forceFill(['status' => CustomerStatus::Inactive])->save();
        $this->assertSame(['started' => 0, 'skipped' => 0], app(MapGridService::class)->runDue(), 'passive customer: nothing scheduled');

        BrandIntelSetting::query()->update(['grid_center_lat' => null, 'grid_center_lng' => null, 'monthly_usd' => 5]);
        Livewire::test(MapRankingsPage::class, ['brand' => $this->brand->id])->set('scanKeyword', 'implant')->call('scan')->assertSee('Merkez konumu yok');
        $this->assertSame(0, MapGridRun::query()->count());
    }
}
