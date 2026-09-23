<?php

namespace Tests\Feature;

use App\Livewire\Demo\Instagram\OverviewPage;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\User;
use App\Services\InstagramAccountProfileCollectService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Instagram asset page: one honest screen — who owns the account, that analytics are not connected,
 * and the last collected profile fields when a read-only profile collection exists.
 */
final class InstagramAssetPageTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('tr');
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Örnek Marka']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'instagram', 'name' => 'Örnek Instagram']);
    }

    public function test_page_explains_analytics_are_not_connected_without_fake_tabs(): void
    {
        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id])
            ->assertSee('Örnek Instagram')
            ->assertSee('Örnek Marka')
            ->assertSee('Instagram analitiği henüz bağlı değil')
            ->assertSee('Bu hesap için henüz profil bilgisi toplanmadı.')
            ->assertDontSee('Needs attention')
            ->assertDontSee('Recent posts')
            ->assertDontSee('analytics unavailable')
            ->assertDontSeeHtml('wire:click="setTab(');
    }

    public function test_page_shows_last_successful_profile_collection(): void
    {
        Evidence::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'source_module' => InstagramAccountProfileCollectService::MODULE_ID,
            'type' => InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE,
            'payload' => ['ok' => false, 'username' => 'eski_hesap'],
            'observed_at' => now(),
        ]);
        Evidence::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'source_module' => InstagramAccountProfileCollectService::MODULE_ID,
            'type' => InstagramAccountProfileCollectService::EVIDENCE_TYPE_ACCOUNT_PROFILE,
            'payload' => ['ok' => true, 'username' => 'ornekmarka', 'biography' => 'Diş kliniği', 'website' => 'https://ornek.test'],
            'observed_at' => now()->subDay(),
        ]);

        Livewire::test(OverviewPage::class, ['assetId' => (string) $this->asset->id])
            ->assertSee('@ornekmarka')
            ->assertSee('Diş kliniği')
            ->assertSeeHtml('href="https://ornek.test"')
            ->assertDontSee('eski_hesap')
            ->assertDontSee('Bu hesap için henüz profil bilgisi toplanmadı.');
    }

    public function test_non_instagram_asset_is_not_found(): void
    {
        $website = DigitalAsset::factory()->create(['type' => 'website']);

        $this->get(route('operator.instagram', ['assetId' => $website->id]))->assertNotFound();
    }
}
