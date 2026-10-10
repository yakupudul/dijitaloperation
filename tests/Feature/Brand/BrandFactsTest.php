<?php

namespace Tests\Feature\Brand;

use App\Jobs\Brand\RefreshBrandFilesJob;
use App\Livewire\Operator\Portfolio\BrandFactsCard;
use App\Models\Brand;
use App\Models\BrandConversionSource;
use App\Models\BrandExpert;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Brand\BrandDossier;
use App\Services\Brand\BrandFacts;
use App\Services\Catalog\ServiceCatalogService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Marka bilgi kartı (yakup, 2026-10-09): brand facts filled by rules from the brand's own data, each with its source;
 * the operator's text locks a field from the nightly rebuild, and the AI reads the operator's text instead.
 */
final class BrandFactsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id, 'name' => 'Panorama']);
    }

    public function test_the_card_is_filled_from_the_brands_own_data_with_sources_and_the_operators_text_is_locked(): void
    {
        $catalog = app(ServiceCatalogService::class);
        BrandOffering::query()->create(['brand_id' => $this->brand->id, 'service_catalog_item_id' => $catalog->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service']->id,
            'status' => 'active', 'priority' => 'main']);
        BrandExpert::query()->create(['brand_id' => $this->brand->id, 'name' => 'Dt. Ayşe Kaya', 'is_default' => true]);
        BrandConversionSource::query()->create(['brand_id' => $this->brand->id, 'source' => 'ga4_key_event', 'source_key' => 'generate_lead', 'label' => 'Form gönderimi',
            'conversion_type' => 'form_submission', 'counts' => true, 'origin' => BrandConversionSource::ORIGIN_OPERATOR]);
        $this->reviews([
            ['ONE', 'Çok bekledim, randevu saatinde alınmadım.'],
            ['TWO', 'Sırada iki saat bekledik, kimse ilgilenmedi.'],
            ['FIVE', 'Ağrısız bir tedaviydi, hiç ağrı hissetmedim.'],
            ['FIVE', 'Ağrısız ve hızlı, Mehmet Bey her şeyi anlattı.'],
            ['FOUR', 'Mehmet Bey çok ilgili, ağrısız geçti.'],
        ]);

        $card = app(BrandFacts::class)->build($this->brand);
        $fields = $card['fields'];
        $this->assertSame(['★ İmplant Tedavisi'], $fields['services']['items']);
        $this->assertSame('Dt. Ayşe Kaya', $fields['experts']['items'][0]);
        $this->assertContains('Mehmet (2 yorumda)', $fields['experts']['items'], 'a name the reviews repeat');
        $this->assertSame('Bekleme (2 yorum)', $fields['objections']['items'][0]);
        $this->assertSame('Ağrısız tedavi (3 yorum)', $fields['praise']['items'][0]);
        $this->assertStringContainsString('İşletme Profili yorumları', $fields['praise']['source']);
        $this->assertSame(['Form gönderimi'], $fields['conversions']['items']);
        $this->assertSame([], $fields['seasonality']['items']);
        $this->assertSame('Search Console bağlı değil.', $fields['seasonality']['source'], 'an empty field says why');

        BrandFacts::write($this->brand, 'praise', "Ağrısız tedavi\nAynı gün implant", $this->admin->id);
        $rebuilt = app(BrandFacts::class)->build($this->brand)['fields']['praise'];
        $this->assertTrue($rebuilt['locked'], 'the nightly rebuild keeps the operator\'s text');
        $this->assertSame(['Ağrısız tedavi', 'Aynı gün implant'], app(BrandFacts::class)->forPrompt($this->brand)['praise']);
        $this->assertStringContainsString('Aynı gün implant', app(BrandDossier::class)->build($this->brand)['sections']['facts']['markdown']);

        BrandFacts::write($this->brand, 'praise', '', $this->admin->id);
        $this->assertFalse(app(BrandFacts::class)->card($this->brand)['fields']['praise']['locked']);
    }

    public function test_the_card_on_the_brand_page_edits_locks_and_unlocks_a_field(): void
    {
        Livewire::test(BrandFactsCard::class, ['brandId' => $this->brand->id])
            ->assertSeeHtml('data-brand-facts')
            ->assertSeeHtml('data-fact-state="empty"')
            ->call('edit', 'voice')
            ->set('text', 'Sen dili kullanma')
            ->call('save')
            ->assertSee('Sen dili kullanma')
            ->assertSeeHtml('data-fact-unlock="voice"')
            ->call('unlock', 'voice')
            ->assertDontSeeHtml('data-fact-unlock="voice"');
    }

    /** @param  list<array{0: string, 1: string}>  $reviews */
    public function test_the_card_and_file_are_built_again_soon_after_services_or_areas_change(): void
    {
        $this->assertSame([], app(BrandFacts::class)->build($this->brand)['fields']['services']['items'], 'built before the services were added');
        config(['moxdop.brand_files_live_refresh' => true]);
        Queue::fake();

        $offering = BrandOffering::query()->create(['brand_id' => $this->brand->id, 'status' => 'active', 'priority' => 'main',
            'service_catalog_item_id' => app(ServiceCatalogService::class)->resolveOrCreate('İmplant Tedavisi', 'saglik', actor: $this->admin)['service']->id]);
        Queue::assertPushed(RefreshBrandFilesJob::class, fn (RefreshBrandFilesJob $job): bool => $job->brandId === $this->brand->id && $job->delay !== null);

        Queue::fake();
        BrandServiceArea::query()->create(['brand_id' => $this->brand->id, 'country_code' => 'TR', 'city_name' => 'Ankara', 'normalized_key' => 'tr|ankara', 'status' => 'active']);
        Queue::assertPushed(RefreshBrandFilesJob::class);

        Queue::fake();
        config(['moxdop.brand_files_live_refresh' => false]);
        $offering->update(['priority' => 'secondary']);
        Queue::assertNothingPushed();

        app()->call([new RefreshBrandFilesJob($this->brand->id), 'handle']);
        $this->assertSame(['İmplant Tedavisi'], app(BrandFacts::class)->card($this->brand)['fields']['services']['items'], 'the other tabs now see the service');
    }

    private function reviews(array $reviews): void
    {
        $asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_business_profile', 'name' => 'Panorama Kadıköy']);
        $resource = CoreExternalResource::factory()->create(['provider' => 'google', 'resource_type' => 'google_business_profile', 'external_id' => 'locations/1',
            'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        foreach ($reviews as $i => [$stars, $comment]) {
            DB::table('gbp_reviews')->insert(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'location_name' => 'locations/1', 'run_id' => 1, 'review_id' => 'r'.$i,
                'star_rating' => $stars, 'comment' => $comment, 'create_time' => now()->subDays($i + 1), 'update_time' => now(), 'reviewer' => '{}',
                'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
