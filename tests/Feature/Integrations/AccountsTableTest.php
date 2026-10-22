<?php

namespace Tests\Feature\Integrations;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Integrations\AccountsTable;
use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bağlantılar › Hesaplar (Aşama 6): every ad and profile account in one table with brand, state and "Şimdi güncelle";
 * the brand's Dijital varlıklar shows the same table for its own accounts.
 */
final class AccountsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_account_is_one_row_with_its_brand_and_state(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara']);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => 'active', 'name' => 'Panorama Ads']);
        $working = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'display_name' => 'Panorama Ads', 'external_id' => '111']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $working->id, 'capability' => 'google_ads']);
        $free = CoreExternalResource::factory()->create(['resource_type' => 'meta_ads', 'display_name' => 'Boşta Meta', 'external_id' => 'act_2']);
        CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'display_name' => 'Kayıp Profil', 'external_id' => 'locations/3', 'status' => CoreExternalResource::STATUS_UNAVAILABLE]);
        CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'display_name' => 'MCC', 'metadata' => ['is_manager' => true]]);

        $this->get(route('operator.integrations.accounts'))->assertOk()->assertSee('data-accounts-table', false);
        $page = Livewire::test(AccountsTable::class)->assertDontSee('MCC');
        $states = collect($page->viewData('rows'))->pluck('state', 'name')->all();
        $this->assertSame(['Kayıp Profil' => 'no_access', 'Boşta Meta' => 'unbound', 'Panorama Ads' => 'ok'], $states);

        $page->call('notServed', $free->id)->assertSee('kullanılmıyor olarak işaretlendi');
        $this->assertSame('not_served', collect($page->viewData('rows'))->firstWhere('id', $free->id)['state']);
        $page->call('notServed', $free->id);

        $page->set('state', 'unbound')->assertSeeHtml('data-account-row="'.$free->id.'"')->assertDontSeeHtml('data-account-row="'.$working->id.'"');

        ResourceAutomation::query()->create(['external_resource_id' => $working->id, 'collection_enabled' => false]);
        $page->set('state', '')->call('refreshNow', $working->id)->assertSee('Veri çekimi öne alındı');
        $this->assertTrue((bool) ResourceAutomation::query()->where('external_resource_id', $working->id)->value('collection_enabled'));

        // The brand's Dijital varlıklar: the same table, its own accounts only.
        $brandTable = Livewire::test(AccountsTable::class, ['brandId' => $brand->id]);
        $this->assertSame([$working->id], collect($brandTable->viewData('rows'))->pluck('id')->all());
        Livewire::withQueryParams(['tab' => 'varliklar'])->test(BrandShow::class, ['brand' => (string) $brand->id])->assertSeeHtml('data-brand-accounts');
    }
}
