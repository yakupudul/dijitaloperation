<?php

namespace Tests\Feature\Ownership;

use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\AssetEdit;
use App\Livewire\Operator\AssetDataSourcesPage;
use App\Livewire\Operator\Integrations\WebsiteIntegrationIndex;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Services\BrandSetup\BrandSetupApplier;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Ownership\OwnershipTransferService;
use App\Services\Portfolio\PortfolioGroupCreator;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Yetki devri: an account or asset belongs to one customer; connecting something another customer (or another asset)
 * owns is an error naming the owner, or an explicit, recorded Admin transfer. Automatic flows skip and report.
 */
final class OwnershipTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $google;

    private Brand $adadent;

    private DigitalAsset $adadentSite;

    private Brand $atlas;

    private DigitalAsset $atlasSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Http::preventStrayRequests();
        Bus::fake();

        $this->google = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->adadent = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Adadent'])->id, 'name' => 'Adadent Marka']);
        $this->adadentSite = DigitalAsset::factory()->create(['brand_id' => $this->adadent->id, 'type' => 'website', 'name' => 'Adadent Web Sitesi', 'domain' => 'adadent.com.tr', 'primary_url' => 'https://adadent.com.tr']);
        $this->atlas = Brand::factory()->create(['customer_id' => Customer::factory()->create(['name' => 'Atlas Dental'])->id, 'name' => 'Atlas Marka']);
        $this->atlasSite = DigitalAsset::factory()->create(['brand_id' => $this->atlas->id, 'type' => 'website', 'name' => 'Atlas Web Sitesi', 'domain' => 'atlasdental.com', 'primary_url' => 'https://atlasdental.com']);
    }

    public function test_binding_an_account_of_another_customer_fails_and_names_the_owner(): void
    {
        $ga4 = $this->boundGa4($this->adadentSite);

        try {
            app(ConfirmGoogleResourceBindingService::class)->bindExisting($this->atlasSite, $ga4, $this->admin, allowReplace: true);
            $this->fail('A foreign-owned account must not be bound silently.');
        } catch (ValidationException $e) {
            $message = (string) collect($e->errors())->flatten()->first();
            $this->assertStringContainsString('Adadent müşterisinin Adadent Web Sitesi varlığına bağlı', $message);
            $this->assertStringContainsString('Devretmek için onaylayın.', $message);
        }

        $this->assertSame($this->adadentSite->id, (int) CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
        $this->assertSame(0, OwnershipTransfer::query()->count());
    }

    public function test_admin_confirms_the_transfer_on_data_sources_and_it_is_recorded(): void
    {
        $ga4 = $this->boundGa4($this->adadentSite);
        $old = CoreAssetBinding::query()->sole();

        $page = Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $this->atlasSite->id])
            ->assertSee('Başka varlığa bağlı')
            ->set('selectedResource.ga4', (string) $ga4->id)
            ->call('bind', 'ga4')
            ->assertHasNoErrors()
            ->assertSet('ownershipConflict.same_customer', false)
            ->assertSee('Yetki devri gerekiyor')
            ->assertSee('Yetki devrini onaylıyorum');
        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $old->fresh()->status, 'nothing moves before the confirmation');

        $page->call('transferResource')->assertHasErrors('transferAcknowledged');
        $this->assertSame(CoreAssetBinding::STATUS_ACTIVE, $old->fresh()->status);

        $page->set('transferAcknowledged', true)
            ->set('transferNote', 'Müşteri hesabı Atlas’a devretti')
            ->call('transferResource')
            ->assertHasNoErrors()
            ->assertSet('ownershipConflict', null)
            ->assertSee('Devir geçmişi');

        $old->refresh();
        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $old->status, 'old binding kept for history');
        $this->assertSame('transferred', data_get($old->configuration, 'closed_reason'));
        $this->assertFalse($this->adadentSite->assetBindings()->where('status', CoreAssetBinding::STATUS_ACTIVE)->exists(), 'old asset no longer receives the account');
        $this->assertSame($ga4->id, (int) $this->atlasSite->assetBindings()->where('status', CoreAssetBinding::STATUS_ACTIVE)->where('capability', 'ga4')->sole()->external_resource_id);

        $transfer = OwnershipTransfer::query()->sole();
        $this->assertSame(OwnershipTransfer::SUBJECT_RESOURCE, $transfer->subject_type);
        $this->assertSame($ga4->id, (int) $transfer->subject_id);
        $this->assertSame($this->adadent->customer_id, (int) $transfer->from_customer_id);
        $this->assertSame($this->adadentSite->id, (int) $transfer->from_asset_id);
        $this->assertSame($this->atlas->customer_id, (int) $transfer->to_customer_id);
        $this->assertSame($this->atlasSite->id, (int) $transfer->to_asset_id);
        $this->assertSame($this->admin->id, (int) $transfer->transferred_by);
        $this->assertSame('Müşteri hesabı Atlas’a devretti', $transfer->note);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $this->adadentSite->id])
            ->assertSee('Devir geçmişi')
            ->assertSee('Adadent › Adadent Marka › Adadent Web Sitesi');
    }

    public function test_non_admin_cannot_transfer(): void
    {
        $ga4 = $this->boundGa4($this->adadentSite);
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);

        $this->expectException(ValidationException::class);
        try {
            app(OwnershipTransferService::class)->transferResource($ga4, $this->atlasSite, $member, confirmed: true);
        } finally {
            $this->assertSame($this->adadentSite->id, (int) CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
            $this->assertSame(0, OwnershipTransfer::query()->count());

            $this->actingAs($member);
            Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $this->atlasSite->id])
                ->set('ownershipConflict', app(OwnershipGuard::class)->forResource($ga4, $this->atlasSite)->toArray())
                ->set('pendingTransfer', ['capability' => 'ga4', 'resource_id' => $ga4->id])
                ->set('transferAcknowledged', true)
                ->call('transferResource')
                ->assertForbidden();
        }
    }

    public function test_admin_without_confirmation_flag_cannot_transfer_through_the_service(): void
    {
        $ga4 = $this->boundGa4($this->adadentSite);

        $this->expectException(ValidationException::class);
        app(OwnershipTransferService::class)->transferResource($ga4, $this->atlasSite, $this->admin, confirmed: false);
    }

    public function test_google_integration_bind_modal_asks_for_the_transfer_before_moving(): void
    {
        $ga4 = $this->boundGa4($this->adadentSite);

        $page = Livewire::test(GoogleIntegrationPage::class)
            ->call('bindResource', (string) $ga4->id)
            ->set('brandId', $this->atlas->id)
            ->set('bindMode', ResourceBindingPlan::MODE_EXISTING_ASSET)
            ->set('digitalAssetId', $this->atlasSite->id)
            ->call('confirmBind')
            ->assertSet('showBindModal', true)
            ->assertSet('ownershipConflict.current_customer_name', 'Adadent')
            ->assertSee('Yetki devri gerekiyor');
        $this->assertSame($this->adadentSite->id, (int) CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);

        $page->set('transferAcknowledged', true)->call('transferBind')->assertSet('showBindModal', false);

        $this->assertSame($this->atlasSite->id, (int) CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
        $this->assertSame(1, OwnershipTransfer::query()->count());
    }

    public function test_same_customer_other_asset_is_a_move_that_still_needs_confirmation(): void
    {
        $secondSite = DigitalAsset::factory()->create(['brand_id' => $this->adadent->id, 'type' => 'website', 'name' => 'Adadent Blog', 'domain' => 'blog.adadent.com.tr']);
        $ga4 = $this->boundGa4($this->adadentSite);

        $conflict = app(OwnershipGuard::class)->forResource($ga4, $secondSite);
        $this->assertNotNull($conflict);
        $this->assertTrue($conflict->sameCustomer);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $secondSite->id])
            ->set('selectedResource.ga4', (string) $ga4->id)
            ->call('bind', 'ga4')
            ->assertSet('ownershipConflict.same_customer', true)
            ->assertSee('Aynı müşteri içinde taşıma')
            ->set('transferAcknowledged', true)
            ->call('transferResource')
            ->assertHasNoErrors();

        $this->assertSame($secondSite->id, (int) CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
        $this->assertSame(1, OwnershipTransfer::query()->count());
    }

    public function test_asset_edit_to_another_customers_brand_requires_confirmation_and_is_logged(): void
    {
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->adadent->id, 'type' => 'google_ads', 'name' => 'Adadent Ads']);

        $edit = Livewire::test(AssetEdit::class, ['assetId' => (string) $ads->id])
            ->assertSet('customer_id', (string) $this->adadent->customer_id)
            ->set('customer_id', (string) $this->atlas->customer_id)
            ->assertSet('brand_id', '')
            ->set('brand_id', (string) $this->atlas->id)
            ->call('save')
            ->assertNoRedirect()
            ->assertSee('Yetki devri gerekiyor');
        $this->assertSame($this->adadent->id, (int) $ads->fresh()->brand_id);

        $edit->call('confirmAssetMove')->assertHasErrors('transferAcknowledged');
        $this->assertSame($this->adadent->id, (int) $ads->fresh()->brand_id);

        $edit->set('transferAcknowledged', true)->call('confirmAssetMove')->assertHasNoErrors()->assertRedirect();
        $this->assertSame($this->atlas->id, (int) $ads->fresh()->brand_id);

        $transfer = OwnershipTransfer::query()->sole();
        $this->assertSame(OwnershipTransfer::SUBJECT_ASSET, $transfer->subject_type);
        $this->assertSame($ads->id, (int) $transfer->subject_id);
        $this->assertSame($this->adadent->id, (int) $transfer->from_brand_id);
        $this->assertSame($this->atlas->id, (int) $transfer->to_brand_id);
    }

    public function test_asset_edit_to_a_brand_of_the_same_customer_needs_no_confirmation(): void
    {
        $otherBrand = Brand::factory()->create(['customer_id' => $this->adadent->customer_id, 'name' => 'Adadent Ortodonti']);
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->adadent->id, 'type' => 'google_ads', 'name' => 'Adadent Ads']);

        Livewire::test(AssetEdit::class, ['assetId' => (string) $ads->id])
            ->set('brand_id', (string) $otherBrand->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('ownershipConflict', null)
            ->assertRedirect();

        $this->assertSame($otherBrand->id, (int) $ads->fresh()->brand_id);
        $this->assertSame(0, OwnershipTransfer::query()->count());
    }

    public function test_non_admin_cannot_move_an_asset_to_another_customer(): void
    {
        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $ads = DigitalAsset::factory()->create(['brand_id' => $this->adadent->id, 'type' => 'google_ads']);

        $this->expectException(ValidationException::class);
        try {
            app(OwnershipTransferService::class)->moveAsset($ads, $this->atlas, $member, confirmed: true);
        } finally {
            $this->assertSame($this->adadent->id, (int) $ads->fresh()->brand_id);
        }
    }

    public function test_asset_create_with_a_website_host_of_another_customer_offers_the_move_instead_of_a_duplicate(): void
    {
        $create = Livewire::test(AssetCreate::class, ['brandId' => (string) $this->atlas->id])
            ->set('name', 'Adadent kopya')
            ->set('type', 'website')
            ->set('domain', 'www.adadent.com.tr')
            ->call('save')
            ->assertNoRedirect()
            ->assertSee('Yetki devri gerekiyor')
            ->assertSee('Adadent Web Sitesi');
        $this->assertSame(1, DigitalAsset::query()->where('domain', 'like', '%adadent.com.tr')->count(), 'no duplicate website');

        $create->set('transferAcknowledged', true)->call('moveExistingSite')->assertHasNoErrors()->assertRedirect();

        $this->assertSame($this->atlas->id, (int) $this->adadentSite->fresh()->brand_id);
        $this->assertSame(1, DigitalAsset::query()->where('domain', 'like', '%adadent.com.tr')->count());
        $this->assertSame(OwnershipTransfer::SUBJECT_ASSET, OwnershipTransfer::query()->sole()->subject_type);
    }

    public function test_asset_create_with_the_host_of_an_unassigned_website_moves_it_without_yetki_devri(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => null, 'type' => 'website', 'name' => 'yeni.com', 'domain' => 'yeni.com', 'primary_url' => 'https://yeni.com']);

        Livewire::test(AssetCreate::class, ['brandId' => (string) $this->atlas->id])
            ->set('name', 'Yeni')
            ->set('type', 'website')
            ->set('primary_url', 'https://yeni.com/')
            ->call('save')
            ->assertNoRedirect()
            ->assertSet('ownershipConflict', null)
            ->assertSet('existingSite.movable', true)
            ->assertSee('Mevcut siteyi bu markaya taşı')
            ->call('moveExistingSite')
            ->assertRedirect();

        $this->assertSame($this->atlas->id, (int) $site->fresh()->brand_id);
        $this->assertSame(0, OwnershipTransfer::query()->count());
    }

    public function test_otomatik_kur_skips_accounts_and_websites_owned_elsewhere_and_reports(): void
    {
        $foreignAds = $this->boundResource($this->adadentSite, 'google_ads', '1112223333', 'Adadent Ads', 'google_ads');
        $sameCustomerAsset = DigitalAsset::factory()->create(['brand_id' => $this->atlas->id, 'type' => 'google_business_profile', 'name' => 'Atlas Profil']);
        $ownGbp = $this->boundResource($sameCustomerAsset, 'google_business_profile', 'locations/1', 'Atlas Profil', 'google_business_profile');
        $newBrand = Brand::factory()->create(['customer_id' => $this->atlas->customer_id, 'name' => 'Atlas Yeni']);
        $free = CoreExternalResource::factory()->create(['integration_id' => $this->google->id, 'resource_type' => 'google_ads', 'external_id' => '9990001111', 'display_name' => 'Atlas Yeni Ads']);

        $proposal = BrandSetupProposal::query()->create([
            'brand_id' => $newBrand->id, 'status' => BrandSetupProposal::STATUS_READY, 'website_url' => 'https://adadent.com.tr/',
            'items' => [
                ['key' => 'asset:website', 'kind' => 'asset', 'group' => 'website', 'label' => 'Web sitesi: adadent.com.tr', 'asset_id' => null, 'url' => 'https://adadent.com.tr/', 'status' => 'proposed'],
                $this->bindItem($foreignAds, 'new:google_ads'),
                $this->bindItem($ownGbp, 'new:google_business_profile'),
                $this->bindItem($free, 'new:google_ads'),
            ],
            'services' => [], 'services_status' => 'none', 'summary' => [], 'created_by' => $this->admin->id,
        ]);

        $results = collect(app(BrandSetupApplier::class)->apply($proposal, $this->admin, array_column($proposal->items, 'key'), []))->keyBy('key');

        $this->assertFalse($results['asset:website']['ok']);
        $this->assertStringContainsString('ikinci bir web sitesi oluşturulmadı', $results['asset:website']['message']);
        $this->assertSame(1, DigitalAsset::query()->where('type', 'website')->where('domain', 'adadent.com.tr')->count());
        $this->assertFalse($results['google_ads:'.$foreignAds->id]['ok']);
        $this->assertStringContainsString('Başka müşteriye ait olduğu için atlandı', $results['google_ads:'.$foreignAds->id]['message']);
        $this->assertStringContainsString('Aynı müşterinin başka varlığında', $results['google_business_profile:'.$ownGbp->id]['message']);
        $this->assertTrue($results['google_ads:'.$free->id]['ok']);
        $this->assertStringContainsString('2 hesap başka bir varlığa bağlı', $results['ownership']['message']);

        $this->assertSame($this->adadentSite->id, (int) CoreAssetBinding::query()->where('external_resource_id', $foreignAds->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
        $this->assertSame($sameCustomerAsset->id, (int) CoreAssetBinding::query()->where('external_resource_id', $ownGbp->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
        $this->assertSame(0, OwnershipTransfer::query()->count(), 'automatic flows never transfer');
    }

    public function test_discover_and_group_skips_foreign_owned_accounts_and_reports(): void
    {
        $foreignAds = $this->boundResource($this->adadentSite, 'google_ads', '1112223333', 'Adadent Ads', 'google_ads');
        $free = CoreExternalResource::factory()->create(['integration_id' => $this->google->id, 'resource_type' => 'google_ads', 'external_id' => '9990001111', 'display_name' => 'Yıldız Ads']);

        $outcome = app(PortfolioGroupCreator::class)->create(['customer_name' => 'Yıldız Optik', 'brand_name' => 'Yıldız'], [$foreignAds->id, $free->id], $this->admin);
        $results = collect($outcome['results'])->keyBy('key');

        $this->assertFalse($results['google_ads:'.$foreignAds->id]['ok']);
        $this->assertStringContainsString('Adadent müşterisinin', $results['google_ads:'.$foreignAds->id]['message']);
        $this->assertTrue($results['google_ads:'.$free->id]['ok']);
        $this->assertArrayHasKey('ownership', $results->all());
        $this->assertSame($this->adadentSite->id, (int) CoreAssetBinding::query()->where('external_resource_id', $foreignAds->id)->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
        $this->assertSame(0, OwnershipTransfer::query()->count());
    }

    public function test_unassigned_website_is_assigned_to_a_customer_brand_from_the_integrations_list(): void
    {
        $site = DigitalAsset::factory()->create(['brand_id' => null, 'type' => 'website', 'name' => 'bos.com', 'domain' => 'bos.com', 'primary_url' => 'https://bos.com']);

        Livewire::test(WebsiteIntegrationIndex::class)
            ->assertSee('Markaya ata')
            ->call('startAssign', $site->id)
            ->set('assignCustomerId', (string) $this->atlas->customer_id)
            ->assertSee('Atlas Marka')
            ->call('assignWebsite')
            ->assertHasErrors('assignBrandId')
            ->set('assignBrandId', (string) $this->atlas->id)
            ->call('assignWebsite')
            ->assertHasNoErrors()
            ->assertSet('assigningSiteId', null)
            ->assertSet('messageTone', 'success');

        $this->assertSame($this->atlas->id, (int) $site->fresh()->brand_id);
    }

    private function boundGa4(DigitalAsset $asset): CoreExternalResource
    {
        return $this->boundResource($asset, 'ga4', 'properties/555', 'Adadent GA4', 'ga4');
    }

    private function boundResource(DigitalAsset $asset, string $type, string $externalId, string $name, string $capability): CoreExternalResource
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => $type,
            'external_id' => $externalId, 'display_name' => $name, 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::query()->create([
            'digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $capability,
            'status' => CoreAssetBinding::STATUS_ACTIVE, 'configuration' => [],
        ]);

        return $resource;
    }

    /** @return array<string, mixed> */
    private function bindItem(CoreExternalResource $resource, string $target): array
    {
        return [
            'key' => $resource->resource_type.':'.$resource->id, 'kind' => 'bind', 'group' => $resource->resource_type,
            'capability' => $resource->resource_type, 'resource_id' => $resource->id, 'label' => $resource->display_name,
            'target' => $target, 'status' => 'proposed',
        ];
    }
}
