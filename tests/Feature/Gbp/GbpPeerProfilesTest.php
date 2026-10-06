<?php

namespace Tests\Feature\Gbp;

use App\Enums\CustomerStatus;
use App\Livewire\Demo\Gbp\OverviewPage;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Gbp\GbpPeerProfiles;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Kategori ve hizmetler › "Aynı sektördeki işletmelerden getir": category and service names of the other managed
 * profiles of the same sector, counted, without what the profile already has; only names go into the boxes.
 */
final class GbpPeerProfilesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceCategory $dental;

    private DigitalAsset $asset;

    private ?CoreIntegration $google = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->dental = ServiceCategory::query()->firstOrCreate(['code' => 'dental'], ['name' => 'Diş sağlığı', 'normalized_key' => 'dis sagligi']);
        $beauty = ServiceCategory::query()->firstOrCreate(['code' => 'beauty'], ['name' => 'Güzellik', 'normalized_key' => 'guzellik']);

        $this->asset = $this->profile('Panorama Ankara', $this->dental->id, 'Diş kliniği', [['gcid:orthodontist', 'Ortodontist']], [['gcid:dental_clinic', 'Diş İmplantı']]);
        $this->profile('Dentaş İzmir', $this->dental->id, 'Diş kliniği', [['gcid:pediatric_dentist', 'Pedodontist']],
            [['gcid:dental_clinic', 'Diş beyazlatma'], ['gcid:pediatric_dentist', 'Fissür örtücü'], ['gcid:dental_clinic', 'diş implantı']]);
        $this->profile('Gülüş Bursa', $this->dental->id, 'Diş kliniği', [['gcid:pediatric_dentist', 'Pedodontist'], ['gcid:cosmetic_dentist', 'Estetik diş hekimi']],
            [['gcid:dental_clinic', 'Diş Beyazlatma'], ['gcid:dental_clinic', 'Gece plağı']]);
        $this->profile('Güzel Cilt', $beauty->id, 'Güzellik salonu', [], [['gcid:beauty_salon', 'Cilt bakımı']]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $additional
     * @param  list<array{0: string, 1: string}>  $services
     */
    private function profile(string $brandName, int $sectorId, string $primary, array $additional, array $services): DigitalAsset
    {
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => $brandName, 'sector_id' => $sectorId]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'status' => 'active', 'name' => $brandName]);
        $this->google ??= CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => 'google_business_profile',
            'external_id' => 'accounts/11/locations/'.$asset->id, 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $common = ['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'run_id' => $asset->id, 'location_name' => 'locations/'.$asset->id,
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('gbp_location_snapshots')->insert([...$common, 'primary_category' => $primary, 'additional_categories' => json_encode([
            'primaryCategory' => ['name' => 'categories/gcid:'.($primary === 'Diş kliniği' ? 'dental_clinic' : 'beauty_salon'), 'displayName' => $primary],
            'additionalCategories' => array_map(fn (array $c): array => ['name' => 'categories/'.$c[0], 'displayName' => $c[1]], $additional),
        ])]);
        DB::table('gbp_service_snapshots')->insert([...$common, 'service_items' => json_encode(array_map(fn (array $s): array => [
            'freeFormServiceItem' => ['category' => 'categories/'.$s[0], 'label' => ['displayName' => $s[1], 'languageCode' => 'tr', 'description' => 'Başka markanın açıklaması']],
        ], $services))]);

        return $asset;
    }

    public function test_collect_counts_same_sector_names_and_leaves_out_what_the_profile_has(): void
    {
        $found = app(GbpPeerProfiles::class)->collect($this->asset);

        $this->assertSame(2, $found['peers']);
        $this->assertSame(['Dentaş İzmir', 'Gülüş Bursa'], $found['brands']);
        $this->assertSame([['Pedodontist', 2], ['Estetik diş hekimi', 1]], array_map(fn (array $c): array => [$c['name'], $c['count']], $found['categories']));
        $this->assertSame([['Diş beyazlatma', 2, 'Diş kliniği'], ['Fissür örtücü', 1, 'Pedodontist'], ['Gece plağı', 1, 'Diş kliniği']],
            array_map(fn (array $s): array => [$s['name'], $s['count'], $s['category']], $found['services']));
    }

    public function test_boxes_put_a_heading_only_over_a_category_the_profile_has_or_gets(): void
    {
        $peers = app(GbpPeerProfiles::class);
        $found = $peers->collect($this->asset);
        $key = fn (string $name): string => collect([...$found['categories'], ...$found['services']])->firstWhere('name', $name)['key'];

        $boxes = GbpPeerProfiles::boxes($found, [$key('Diş beyazlatma'), $key('Fissür örtücü')], ['Diş kliniği', 'Ortodontist']);
        $this->assertSame([], $boxes['categories']);
        $this->assertSame("Fissür örtücü\n\n**Diş kliniği**\nDiş beyazlatma", $boxes['services']);

        $boxes = GbpPeerProfiles::boxes($found, [$key('Pedodontist'), $key('Fissür örtücü')], ['Diş kliniği']);
        $this->assertSame(['Pedodontist'], $boxes['categories']);
        $this->assertSame("**Pedodontist**\nFissür örtücü", $boxes['services']);
    }

    public function test_a_brand_without_a_sector_is_told_to_pick_one(): void
    {
        $this->asset->brand->update(['sector_id' => null]);

        $this->expectException(RuntimeException::class);
        app(GbpPeerProfiles::class)->collect($this->asset->refresh());
    }

    public function test_the_button_fills_the_boxes_with_names_only(): void
    {
        Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'services'])
            ->assertSee('Aynı sektördeki işletmelerden getir')
            ->call('togglePeers')
            ->assertSee('Dentaş İzmir')
            ->assertSee('Estetik diş hekimi')
            ->call('pickPeers', 'common')
            ->assertCount('peerPick', 2)
            ->call('addPeers')
            ->assertSet('wantCategories', 'Pedodontist')
            ->assertSet('wantServices', "**Diş kliniği**\nDiş beyazlatma")
            ->assertSet('peerOpen', false)
            ->assertDontSee('Başka markanın açıklaması');
    }

    public function test_nothing_picked_adds_nothing(): void
    {
        Livewire::actingAs($this->admin)->test(OverviewPage::class, ['assetId' => (string) $this->asset->id, 'tab' => 'services'])
            ->call('togglePeers')
            ->call('addPeers')
            ->assertSet('wantCategories', '')
            ->assertSet('wantServices', '')
            ->assertSet('peerOpen', true);
    }
}
