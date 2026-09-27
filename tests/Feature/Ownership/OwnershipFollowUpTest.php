<?php

namespace Tests\Feature\Ownership;

use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Livewire\Demo\Portfolio\AssetEdit;
use App\Livewire\Demo\Sales\ProspectConvert;
use App\Livewire\Demo\Sales\ProspectShow;
use App\Models\BrainProposal;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\CoreIntegrationDiscoveryContext;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\OwnershipTransfer;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Brain\Proposals\Kinds\AccountMappingKind;
use App\Services\Ownership\OwnershipTransferService;
use App\Services\Prospects\ConvertProspectService;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Yetki devri follow-ups: one website per domain on edit / any save, account mapping reset on transfer,
 * prospect conversion report and the Meta bind-modal transfer.
 */
final class OwnershipFollowUpTest extends TestCase
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

    public function test_editing_a_website_to_another_customers_domain_is_refused_and_names_the_owner(): void
    {
        Livewire::test(AssetEdit::class, ['assetId' => (string) $this->atlasSite->id])
            ->set('primary_url', 'https://www.adadent.com.tr/')
            ->set('domain', 'adadent.com.tr')
            ->call('save')
            ->assertHasErrors('domain')
            ->assertNoRedirect()
            ->assertSee('Bu adres zaten Adadent müşterisinin Adadent Web Sitesi varlığında kayıtlı.')
            ->assertSee('taşıyın');

        $this->assertSame('atlasdental.com', $this->atlasSite->fresh()->domain);
    }

    public function test_editing_a_website_to_a_domain_of_the_same_brand_is_refused(): void
    {
        $blog = DigitalAsset::factory()->create(['brand_id' => $this->atlas->id, 'type' => 'website', 'name' => 'Atlas Blog', 'domain' => 'blog.atlasdental.com', 'primary_url' => 'https://blog.atlasdental.com']);

        Livewire::test(AssetEdit::class, ['assetId' => (string) $blog->id])
            ->set('domain', 'atlasdental.com')
            ->set('primary_url', '')
            ->call('save')
            ->assertHasErrors('domain')
            ->assertSee('Bu adres bu markada zaten kayıtlı: Atlas Web Sitesi.');

        $this->assertSame('blog.atlasdental.com', $blog->fresh()->domain);
    }

    public function test_editing_a_website_keeping_its_own_domain_still_saves(): void
    {
        Livewire::test(AssetEdit::class, ['assetId' => (string) $this->atlasSite->id])
            ->set('name', 'Atlas Ana Site')
            ->set('primary_url', 'https://www.atlasdental.com/')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Atlas Ana Site', $this->atlasSite->fresh()->name);
    }

    public function test_model_refuses_a_duplicate_website_from_any_path(): void
    {
        try {
            DigitalAsset::query()->create(['brand_id' => $this->atlas->id, 'type' => 'website', 'name' => 'Kopya', 'primary_url' => 'http://www.ADADENT.com.tr/iletisim']);
            $this->fail('A second website with the same domain must not be saved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Adadent müşterisinin Adadent Web Sitesi', (string) collect($e->errors())->flatten()->first());
        }

        try {
            $this->atlasSite->update(['domain' => 'adadent.com.tr', 'primary_url' => null]);
            $this->fail('Changing a website to a used domain must be refused.');
        } catch (ValidationException) {
            $this->assertSame('atlasdental.com', $this->atlasSite->fresh()->domain);
        }

        $this->assertSame(1, DigitalAsset::query()->where('domain', 'adadent.com.tr')->count());
        // Non-website assets and other hosts are not affected.
        DigitalAsset::factory()->create(['brand_id' => $this->atlas->id, 'type' => 'google_ads', 'name' => 'Ads', 'domain' => 'adadent.com.tr']);
        DigitalAsset::factory()->create(['brand_id' => $this->atlas->id, 'type' => 'website', 'name' => 'Başka', 'domain' => 'atlas-ortodonti.com']);
    }

    public function test_transfer_to_another_customer_resets_the_account_mapping_and_logs_it(): void
    {
        $ads = $this->boundResource($this->adadentSite, 'search_console', 'sc-domain:adadent.com.tr', 'Adadent GSC');
        $automation = ResourceAutomation::query()->create([
            'external_resource_id' => $ads->id, 'collection_enabled' => false, 'interval_days' => 3, 'preferred_hour' => 5,
            'query_enabled' => true, 'sector' => 'dental', 'service_ids' => [11, 12], 'revision' => 4, 'mapping_revision' => 2,
            'next_collection_at' => now()->addDays(2), 'query_error' => 'mapping_invalid',
        ]);
        $proposal = $this->mappingProposal($automation, $this->adadent->id);

        app(OwnershipTransferService::class)->transferResource($ads, $this->atlasSite, $this->admin, confirmed: true, note: 'Atlas’a geçti');

        $automation->refresh();
        $this->assertNull($automation->sector);
        $this->assertSame([], $automation->service_ids);
        $this->assertFalse($automation->query_enabled, 'query intake waits for a new mapping');
        $this->assertNull($automation->query_error);
        $this->assertSame(3, (int) $automation->mapping_revision);
        $this->assertSame(5, (int) $automation->revision);
        $this->assertTrue($automation->next_collection_at->lessThanOrEqualTo(now()), 're-evaluated for the new owner now');
        $this->assertFalse($automation->collection_enabled, 'neutral settings are kept');
        $this->assertSame(3, $automation->interval_days);
        $this->assertSame(5, $automation->preferred_hour);
        $this->assertSame(BrainProposal::STATUS_STALE, $proposal->fresh()->status, 'old-brand mapping proposal is not applied any more');

        $transfer = OwnershipTransfer::query()->sole();
        $mapping = $transfer->snapshot['mapping'][0];
        $this->assertSame('reset', $mapping['action']);
        $this->assertSame(['sector' => 'dental', 'service_ids' => [11, 12], 'query_enabled' => true], $mapping['cleared']);
        $this->assertSame(1, $mapping['proposals_stale']);
        $this->assertStringContainsString('eşlemesi sıfırlandı', (string) $transfer->mappingSummary());
    }

    public function test_transfer_within_the_same_customer_keeps_the_mapping_and_rescopes_proposals(): void
    {
        $ortodonti = Brand::factory()->create(['customer_id' => $this->adadent->customer_id, 'name' => 'Adadent Ortodonti']);
        $secondSite = DigitalAsset::factory()->create(['brand_id' => $ortodonti->id, 'type' => 'website', 'name' => 'Ortodonti Sitesi', 'domain' => 'ortodonti.adadent.com.tr']);
        $gsc = $this->boundResource($this->adadentSite, 'search_console', 'sc-domain:adadent.com.tr', 'Adadent GSC');
        $automation = ResourceAutomation::query()->create([
            'external_resource_id' => $gsc->id, 'query_enabled' => true, 'sector' => 'dental', 'service_ids' => [11],
        ]);
        $proposal = $this->mappingProposal($automation, $this->adadent->id);

        app(OwnershipTransferService::class)->transferResource($gsc, $secondSite, $this->admin, confirmed: true);

        $automation->refresh();
        $this->assertSame('dental', $automation->sector);
        $this->assertSame([11], $automation->service_ids);
        $this->assertTrue($automation->query_enabled);
        $this->assertSame(BrainProposal::STATUS_PENDING, $proposal->fresh()->status);
        $this->assertSame($ortodonti->id, (int) $proposal->fresh()->brand_id);
        $this->assertSame('rescoped', OwnershipTransfer::query()->sole()->snapshot['mapping'][0]['action']);
    }

    public function test_moving_an_asset_to_another_customer_resets_its_accounts_mapping(): void
    {
        $gsc = $this->boundResource($this->adadentSite, 'search_console', 'sc-domain:adadent.com.tr', 'Adadent GSC');
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $gsc->id, 'query_enabled' => true, 'sector' => 'dental', 'service_ids' => [11]]);

        app(OwnershipTransferService::class)->moveAsset($this->adadentSite, $this->atlas, $this->admin, confirmed: true);

        $this->assertNull($automation->fresh()->sector);
        $this->assertSame('reset', OwnershipTransfer::query()->sole()->snapshot['mapping'][0]['action']);
    }

    public function test_prospect_conversion_reports_a_website_owned_by_another_brand(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Yeni Klinik', 'website_url' => 'https://www.adadent.com.tr/', 'owner_user_id' => $this->admin->id]);

        $report = app(ConvertProspectService::class)->convertWithReport($prospect, [
            'customer_name' => 'Yeni Klinik', 'brand_name' => 'Yeni Klinik',
            'confirm_create_despite_duplicates' => true,
            'selected_assets' => ['website:https://www.adadent.com.tr/'],
        ], $this->admin);

        $this->assertSame(1, DigitalAsset::query()->where('type', 'website')->where('domain', 'like', '%adadent.com.tr')->count(), 'no duplicate');
        $this->assertCount(1, $report['notices']);
        $notice = $report['notices'][0];
        $this->assertSame('Web sitesi (adadent.com.tr) zaten Adadent müşterisinin Adadent Marka markasında kayıtlı; yeni markaya eklenmedi. Gerekirse varlık sayfasından yetki devri yapabilirsiniz.', $notice['message']);
        $this->assertSame($this->adadentSite->id, $notice['asset_id']);
        $this->assertSame(route('operator.asset.edit', ['assetId' => $this->adadentSite->id]), $notice['asset_url']);
        $this->assertSame([$notice['message']], ProspectActivity::query()->where('type', 'prospect.converted')->sole()->metadata['notices']);
    }

    public function test_prospect_convert_page_shows_the_notice_with_a_link_to_the_asset(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Yeni Klinik', 'website_url' => 'https://adadent.com.tr/', 'owner_user_id' => $this->admin->id]);

        Livewire::test(ProspectConvert::class, ['prospectId' => (string) $prospect->id])
            ->set('confirm_create_despite_duplicates', true)
            ->call('convert')
            ->assertHasNoErrors()
            ->assertRedirect(route('operator.prospect', ['prospectId' => $prospect->id]));

        $notices = session(ConvertProspectService::NOTICES_FLASH);
        $this->assertIsArray($notices);

        Livewire::test(ProspectShow::class, ['prospectId' => (string) $prospect->id])
            ->assertSee('zaten')
            ->assertSeeHtml('<strong>Adadent</strong> müşterisinin <strong>Adadent Marka</strong> markasında kayıtlı; yeni markaya eklenmedi.')
            ->assertSeeHtml(route('operator.asset.edit', ['assetId' => $this->adadentSite->id]));
    }

    public function test_meta_integration_bind_modal_asks_for_the_transfer_before_moving(): void
    {
        Queue::fake();
        $meta = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE, 'name' => 'Agency Meta',
            'config' => ['auth_method' => 'oauth', 'credential_status' => 'valid'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $meta->id, 'encrypted_payload' => ['access_token' => 'EAAG-synthetic-token-never-real'], 'expires_at' => now()->addDay(),
        ]);
        $business = CoreExternalResource::factory()->create([
            'integration_id' => $meta->id, 'provider' => ProviderRegistry::META, 'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_100', 'display_name' => 'Ajans Business', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['selectable' => true, 'bindable' => false, 'container' => true],
        ]);
        CoreIntegrationDiscoveryContext::query()->create([
            'integration_id' => $meta->id, 'external_resource_id' => $business->id,
            'purpose' => CoreIntegrationDiscoveryContext::PURPOSE_DISCOVERY_CONTEXT,
            'status' => CoreIntegrationDiscoveryContext::STATUS_ACTIVE, 'selected_at' => now(),
        ]);
        $account = CoreExternalResource::factory()->create([
            'integration_id' => $meta->id, 'provider' => ProviderRegistry::META, 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_2020', 'display_name' => 'Adadent Meta', 'parent_external_id' => 'biz_100',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['selectable' => true, 'bindable' => true, 'business_id' => 'biz_100', 'business_name' => 'Ajans Business'],
        ]);
        $adadentMeta = DigitalAsset::factory()->create(['brand_id' => $this->adadent->id, 'type' => 'meta_ads', 'name' => 'Adadent Meta Ads', 'module_id' => 'meta-ads']);
        $atlasMeta = DigitalAsset::factory()->create(['brand_id' => $this->atlas->id, 'type' => 'meta_ads', 'name' => 'Atlas Meta Ads', 'module_id' => 'meta-ads']);
        CoreAssetBinding::query()->create([
            'digital_asset_id' => $adadentMeta->id, 'external_resource_id' => $account->id, 'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE, 'configuration' => [],
        ]);

        $page = Livewire::test(MetaIntegrationPage::class)
            ->call('bindResource', (string) $account->id)
            ->set('brandId', $this->atlas->id)
            ->set('bindMode', ResourceBindingPlan::MODE_EXISTING_ASSET)
            ->set('digitalAssetId', $atlasMeta->id)
            ->call('confirmBind')
            ->assertSet('showBindModal', true)
            ->assertSet('ownershipConflict.current_customer_name', 'Adadent')
            ->assertSet('ownershipConflict.same_customer', false)
            ->assertSee('Yetki devri gerekiyor');
        $this->assertSame($adadentMeta->id, (int) CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);

        $page->call('transferBind')->assertHasErrors('transferAcknowledged')->assertSet('showBindModal', true);
        $this->assertSame(0, OwnershipTransfer::query()->count());

        $page->set('transferAcknowledged', true)->set('transferNote', 'Reklam hesabı Atlas’a geçti')
            ->call('transferBind')
            ->assertSet('showBindModal', false)
            ->assertSet('ownershipConflict', null);

        $this->assertSame($atlasMeta->id, (int) CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->sole()->digital_asset_id);
        $old = CoreAssetBinding::query()->where('digital_asset_id', $adadentMeta->id)->sole();
        $this->assertSame(CoreAssetBinding::STATUS_DISABLED, $old->status);
        $this->assertSame('transferred', data_get($old->configuration, 'closed_reason'));
        $transfer = OwnershipTransfer::query()->sole();
        $this->assertSame(OwnershipTransfer::SUBJECT_RESOURCE, $transfer->subject_type);
        $this->assertSame($account->id, (int) $transfer->subject_id);
        $this->assertSame($adadentMeta->id, (int) $transfer->from_asset_id);
        $this->assertSame($atlasMeta->id, (int) $transfer->to_asset_id);
        $this->assertSame('Reklam hesabı Atlas’a geçti', $transfer->note);
    }

    private function boundResource(DigitalAsset $asset, string $type, string $externalId, string $name): CoreExternalResource
    {
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => ProviderRegistry::GOOGLE, 'resource_type' => $type,
            'external_id' => $externalId, 'display_name' => $name, 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::query()->create([
            'digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => $type,
            'status' => CoreAssetBinding::STATUS_ACTIVE, 'configuration' => [],
        ]);

        return $resource;
    }

    private function mappingProposal(ResourceAutomation $automation, int $brandId): BrainProposal
    {
        return BrainProposal::query()->create([
            'kind' => AccountMappingKind::KIND, 'subject_type' => 'resource_automation', 'subject_id' => $automation->id,
            'brand_id' => $brandId, 'title' => 'Adadent GSC → Diş', 'current' => [], 'proposed' => ['sector' => 'dental', 'service_ids' => [11]],
            'source' => 'ai', 'status' => BrainProposal::STATUS_PENDING, 'fingerprint' => str_repeat('a', 64), 'confidence' => 0.8,
        ]);
    }
}
