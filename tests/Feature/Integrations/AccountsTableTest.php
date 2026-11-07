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

        $page->call('notServed', $free->id)->assertSee('kullanılmıyor olarak işaretlendi')
            ->assertDontSeeHtml('data-account-row="'.$free->id.'"'); // the default view is the accounts the agency works with
        $this->assertSame(2, $page->viewData('counts')['']);
        $this->assertSame('not_served', collect($page->set('state', 'not_served')->viewData('rows'))->firstWhere('id', $free->id)['state']);
        $page->set('state', '')->call('notServed', $free->id);

        $page->set('state', 'unbound')->assertSeeHtml('data-account-row="'.$free->id.'"')->assertDontSeeHtml('data-account-row="'.$working->id.'"');

        ResourceAutomation::query()->create(['external_resource_id' => $working->id, 'collection_enabled' => false]);
        $page->set('state', '')->call('refreshNow', $working->id)->assertSee('Veri çekimi öne alındı');
        $this->assertTrue((bool) ResourceAutomation::query()->where('external_resource_id', $working->id)->value('collection_enabled'));

        // The brand's Dijital varlıklar: the same table, its own accounts only.
        $brandTable = Livewire::test(AccountsTable::class, ['brandId' => $brand->id]);
        $this->assertSame([$working->id], collect($brandTable->viewData('rows'))->pluck('id')->all());
        Livewire::withQueryParams(['tab' => 'varliklar'])->test(BrandShow::class, ['brand' => (string) $brand->id])->assertSeeHtml('data-brand-accounts');
    }

    /** V4 (admin audit 2026-11-07): 100+ old customers' accounts under the agency MCC are inventory, not work. */
    public function test_unbound_accounts_nobody_claims_become_not_served_in_one_click(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Panorama Ankara']);
        DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'status' => 'active', 'domain' => 'panoramaankara.com', 'primary_url' => 'https://panoramaankara.com/']);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => 'active', 'name' => 'Panorama Ads']);
        $working = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'display_name' => 'Panorama Ads', 'external_id' => '111']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $working->id, 'capability' => 'google_ads']);
        $claimed = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'display_name' => 'Panorama Ankara Meta', 'external_id' => '222']);
        $old = CoreExternalResource::factory()->create(['resource_type' => 'google_ads', 'display_name' => 'Eski Müşteri Ads', 'external_id' => '333']);
        $older = CoreExternalResource::factory()->create(['resource_type' => 'meta_ads', 'display_name' => 'Kapanan Kafe', 'external_id' => 'act_4']);
        $lost = CoreExternalResource::factory()->create(['resource_type' => 'google_business_profile', 'display_name' => 'Kayıp Profil', 'external_id' => 'locations/5', 'status' => CoreExternalResource::STATUS_UNAVAILABLE]);

        $page = Livewire::test(AccountsTable::class)->assertSeeHtml('data-accounts-not-served-all')->assertSee('Bağlı olmayan 3 hesap');
        $page->call('notServedAll')->assertSee('2 hesap kullanılmıyor olarak işaretlendi')->assertSee('eşleşen 1 hesap "Bağlı değil" kaldı');

        $states = collect($page->viewData('rows'))->pluck('state', 'name')->all();
        $this->assertSame(['Kayıp Profil' => 'no_access', 'Panorama Ankara Meta' => 'unbound', 'Panorama Ads' => 'ok'], $states, 'the default view: no not_served rows');
        $this->assertSame(['' => 3, 'no_access' => 1, 'unbound' => 1, 'ok' => 1, 'no_spend' => 0, 'not_served' => 2], $page->viewData('counts'));
        $this->assertNotEmpty($old->fresh()->metadata['not_served_at']);
        $this->assertNotEmpty($older->fresh()->metadata['not_served_at']);
        $this->assertArrayNotHasKey('not_served_at', (array) $claimed->fresh()->metadata, 'a brand\'s own candidate stays suggested');
        $this->assertArrayNotHasKey('not_served_at', (array) $lost->fresh()->metadata, 'no access is a different problem');

        $this->assertSame(['Eski Müşteri Ads', 'Kapanan Kafe'], collect($page->set('state', 'not_served')->viewData('rows'))->pluck('name')->sort()->values()->all());
        $page->call('notServed', $old->id)->assertSee('yeniden önerilebilir');
        $this->assertSame('unbound', collect($page->set('state', '')->viewData('rows'))->firstWhere('id', $old->id)['state']);
    }
}
