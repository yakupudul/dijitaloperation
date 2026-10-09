<?php

namespace Tests\Feature\Brand;

use App\Enums\OfferingStatus;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\ServiceCategory;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandDataAudit;
use App\Services\Catalog\ServiceCatalogService;
use App\Services\Repair\RepairDesk;
use App\Services\Website\Pages\PageStore;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marka verisi denetimi (yakup, 2026-10-09): places and services are checked against the brand's own evidence; the
 * changes wait on the Onarım masası and are applied only on approval.
 */
final class BrandDataAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Opc']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website', 'name' => 'opc.com.tr', 'primary_url' => 'https://opc.com.tr/', 'module_id' => 'website']);
        ServiceCategory::query()->create(['code' => 'saglik', 'name' => 'Sağlık', 'normalized_key' => 'saglik']);
    }

    public function test_unevidenced_places_and_services_and_the_place_the_site_keeps_naming_go_to_the_desk_and_apply_on_approval(): void
    {
        foreach (['kadikoy-implant', 'kadikoy-dis-beyazlatma', 'kadikoy-dolgu', 'iletisim', 'hakkimizda', 'blog-1', 'blog-2', 'blog-3', 'blog-4', 'blog-5', 'blog-6'] as $slug) {
            $this->page('/'.$slug.'/', str_replace('-', ' ', ucfirst($slug)));
        }
        $seferihisar = $this->area('İzmir', 'Seferihisar', false);
        $izmir = $this->area('İzmir', null, true);
        $catalog = app(ServiceCatalogService::class);
        $offering = fn (string $name, array $extra = []): BrandOffering => BrandOffering::query()->create(['brand_id' => $this->brand->id,
            'service_catalog_item_id' => $catalog->resolveOrCreate($name, 'saglik', actor: $this->admin)['service']->id, 'status' => 'active', 'priority' => 'secondary'] + $extra);
        $implant = $offering('İmplant');
        $unrelated = $offering('Saç Ekimi');
        $locked = $offering('Botoks', ['locked' => true]);
        BrandOffering::query()->whereKey([$implant->id, $unrelated->id, $locked->id])->update(['created_at' => now()->subDays(30)]);

        $out = app(BrandDataAudit::class)->run();
        $this->assertSame(1, $out['brands']);
        $rows = Suggestion::query()->where('action_type', BrandDataAudit::TYPE)->get()->keyBy(fn (Suggestion $s): string => $s->action['op'].':'.$s->title);

        $this->assertTrue($rows->has('remove_area:Seferihisar, İzmir kaldırılsın'), $rows->keys()->implode(' | '));
        $this->assertTrue($rows->has('remove_area:İzmir (şube) kaldırılsın'));
        $this->assertTrue($rows->has('add_area:Kadıköy, İstanbul hizmet bölgesi olarak eklensin'), 'three of eleven pages name Kadıköy');
        $this->assertTrue($rows->has('archive_service:Saç Ekimi hizmeti arşivlensin'));
        $this->assertFalse($rows->has('archive_service:İmplant hizmeti arşivlensin'), 'pages name it');
        $this->assertFalse($rows->has('archive_service:Botoks hizmeti arşivlensin'), 'the operator locked it');

        $desk = app(RepairDesk::class)->rows($this->brand->id, RepairDesk::BRAND_DATA);
        $this->assertCount(4, $desk);
        $this->assertSame(['Seferihisar, İzmir'], $desk->firstWhere('title', 'Seferihisar, İzmir kaldırılsın')['before']);

        $result = app(RepairDesk::class)->approve($desk->pluck('id')->all(), $this->admin);
        $this->assertSame(4, $result['applied'], json_encode($result['failed']));
        $this->assertSame('archived', $seferihisar->fresh()->status, 'archived, never deleted');
        $this->assertSame('archived', $izmir->fresh()->status);
        $this->assertTrue(BrandServiceArea::query()->where('brand_id', $this->brand->id)->where('status', 'active')->where('district_name', 'Kadıköy')->exists());
        $this->assertSame(OfferingStatus::Archived, $unrelated->fresh()->status);
        $this->assertSame(OfferingStatus::Active, $implant->fresh()->status);

        app(BrandDataAudit::class)->run();
        $this->assertSame(0, app(RepairDesk::class)->rows($this->brand->id, RepairDesk::BRAND_DATA)->count(), 'nothing comes back after approval');
    }

    public function test_business_profile_addresses_decide_branches_and_rejected_rows_stay_rejected(): void
    {
        foreach (range(1, 10) as $i) {
            $this->page('/bornova-'.$i.'/', 'Bornova diş '.$i);
        }
        $bornova = $this->area('İzmir', 'Bornova', false);
        $karsiyaka = $this->area('İzmir', 'Karşıyaka', true);
        $this->page('/karsiyaka/', 'Karşıyaka');
        $this->profile('İzmir', 'Bornova', 'Opc Bornova');
        $this->profile('İzmir', 'Buca', 'Opc Buca');

        app(BrandDataAudit::class)->run();
        $ops = Suggestion::query()->where('action_type', BrandDataAudit::TYPE)->get()->mapWithKeys(fn (Suggestion $s): array => [$s->title => $s->action['op']])->all();

        $this->assertSame(BrandDataAudit::MAKE_BRANCH, $ops['Bornova, İzmir şube olarak işaretlensin'] ?? null, json_encode($ops, JSON_UNESCAPED_UNICODE));
        $this->assertSame(BrandDataAudit::ADD_AREA, $ops['Buca, İzmir şube olarak eklensin'] ?? null);
        $this->assertSame(BrandDataAudit::MAKE_SERVICE_AREA, $ops['Karşıyaka, İzmir şube değil, hizmet bölgesi'] ?? null, 'no profile at Karşıyaka, but a page names it');

        $buca = Suggestion::query()->where('title', 'Buca, İzmir şube olarak eklensin')->sole();
        app(RepairDesk::class)->reject([$buca->id], $this->admin, 'Buca kapandı');
        app(BrandDataAudit::class)->run();
        $this->assertSame(Suggestion::DISMISSED, $buca->fresh()->status, 'a rejected row is not reopened');

        $branch = Suggestion::query()->where('title', 'Bornova, İzmir şube olarak işaretlensin')->sole();
        app(RepairDesk::class)->approve([$branch->id], $this->admin);
        $this->assertTrue($bornova->fresh()->physical_branch);
        $this->assertTrue($karsiyaka->fresh()->physical_branch, 'untouched until approved');
    }

    public function test_nothing_is_removed_without_enough_pages_read(): void
    {
        $this->page('/', 'Opc');
        $this->area('İzmir', 'Seferihisar', false);
        $this->area('Manisa', null, false);

        app(BrandDataAudit::class)->run();

        $this->assertSame(0, Suggestion::query()->where('action_type', BrandDataAudit::TYPE)->count());
    }

    private function area(string $city, ?string $district, bool $physical): BrandServiceArea
    {
        return BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'city_name' => $city, 'district_name' => $district,
            'normalized_key' => mb_strtolower('tr|'.$city.'|'.$district), 'status' => 'active', 'physical_branch' => $physical]);
    }

    private function profile(string $city, string $district, string $name): void
    {
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/'.md5($name),
            'display_name' => $name, 'metadata' => ['storefront_address' => ['regionCode' => 'TR', 'administrativeArea' => $city, 'locality' => $district]],
            'status' => CoreExternalResource::STATUS_AVAILABLE]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'name' => $name]);
        CoreAssetBinding::query()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile',
            'status' => CoreAssetBinding::STATUS_ACTIVE, 'configuration' => []]);
    }

    private function page(string $path, string $title): Page
    {
        $url = 'https://opc.com.tr'.$path;

        return Page::query()->create(['website_asset_id' => $this->site->id, 'url' => $url, 'url_hash' => PageStore::urlHash($url), 'path' => $path,
            'title' => $title, 'language' => 'tr', 'is_indexable' => true, 'content_hash' => hash('sha256', $path)]);
    }
}
