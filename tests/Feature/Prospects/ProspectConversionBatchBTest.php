<?php

namespace Tests\Feature\Prospects;

use App\Enums\ProspectEvidenceProvenance;
use App\Enums\ProspectSalesIntelligenceStatus;
use App\Enums\ProspectStatus;
use App\Livewire\Demo\Sales\ProspectConvert;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Prospect;
use App\Models\ProspectDiscoveryCandidate;
use App\Models\ProspectEvidence;
use App\Models\ProspectSalesIntelligence;
use App\Models\User;
use App\Services\Prospects\ConvertProspectService;
use App\Services\Prospects\UpdateProspectService;
use App\Support\Prospects\ProspectResearchConfig;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ProspectConversionBatchBTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_won_status_does_not_create_customer_or_brand(): void
    {
        $prospect = Prospect::factory()->create(['status' => ProspectStatus::Qualified]);

        app(UpdateProspectService::class)->update($prospect, [
            'status' => ProspectStatus::Won->value,
        ], $this->admin);

        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Brand::query()->count());
        $this->assertNull($prospect->fresh()?->converted_customer_id);
    }

    public function test_explicit_conversion_creates_customer_and_brand(): void
    {
        $prospect = Prospect::factory()->create([
            'company_name' => 'ABC Dental',
            'country' => 'TR',
            'city' => 'Manisa',
            'website_url' => 'http://'.ProspectResearchConfig::FIXTURE_HOST.'/',
            'owner_user_id' => $this->admin->id,
        ]);

        $converted = app(ConvertProspectService::class)->convert($prospect, [
            'customer_name' => 'ABC Dental',
            'brand_name' => 'ABC Dental',
            'selected_assets' => ['website:http://'.ProspectResearchConfig::FIXTURE_HOST.'/'],
        ], $this->admin);

        $this->assertNotNull($converted->converted_customer_id);
        $this->assertNotNull($converted->converted_brand_id);
        $this->assertNotNull($converted->converted_at);
        $this->assertDatabaseHas('customers', ['id' => $converted->converted_customer_id, 'name' => 'ABC Dental']);
        $this->assertDatabaseHas('brands', ['id' => $converted->converted_brand_id, 'name' => 'ABC Dental']);
        $this->assertDatabaseHas('digital_assets', [
            'brand_id' => $converted->converted_brand_id,
            'type' => 'website',
        ]);
        $this->assertDatabaseHas('prospects', ['id' => $prospect->id, 'company_name' => 'ABC Dental']);
    }

    public function test_conversion_is_idempotent(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Idempotent Co']);
        $service = app(ConvertProspectService::class);

        $first = $service->convert($prospect, [
            'customer_name' => 'Idempotent Co',
            'brand_name' => 'Idempotent Co',
        ], $this->admin);
        $second = $service->convert($first->fresh(), [
            'customer_name' => 'Idempotent Co',
            'brand_name' => 'Idempotent Co',
        ], $this->admin);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Brand::query()->count());
        $this->assertSame($first->converted_customer_id, $second->converted_customer_id);
        $this->assertSame($first->converted_brand_id, $second->converted_brand_id);
    }

    public function test_duplicate_customer_requires_explicit_choice(): void
    {
        $existing = Customer::factory()->create(['name' => 'Duplicate Clinics']);
        $prospect = Prospect::factory()->create(['company_name' => 'Duplicate Clinics']);

        try {
            app(ConvertProspectService::class)->convert($prospect, [
                'customer_name' => 'Duplicate Clinics',
                'brand_name' => 'Duplicate Clinics',
            ], $this->admin);
            $this->fail('Expected duplicate validation');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('duplicates', $exception->errors());
        }

        $this->assertSame(1, Customer::query()->count());

        $converted = app(ConvertProspectService::class)->convert($prospect->fresh(), [
            'customer_name' => 'Duplicate Clinics',
            'brand_name' => 'Duplicate Clinics',
            'existing_customer_id' => $existing->id,
        ], $this->admin);

        $this->assertSame($existing->id, $converted->converted_customer_id);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Brand::query()->where('customer_id', $existing->id)->count());
    }

    public function test_domain_duplicate_surfaces_owning_customer_and_brand(): void
    {
        $customer = Customer::factory()->create(['name' => 'Existing Portfolio']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Existing Brand']);
        DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'domain' => ProspectResearchConfig::FIXTURE_HOST,
            'primary_url' => 'http://'.ProspectResearchConfig::FIXTURE_HOST.'/',
        ]);

        $prospect = Prospect::factory()->create([
            'company_name' => 'Different Name Co',
            'website_url' => 'http://'.ProspectResearchConfig::FIXTURE_HOST.'/',
        ]);

        $preview = app(ConvertProspectService::class)->preview($prospect);
        $this->assertNotSame([], $preview['duplicates']['digital_assets']);
        $this->assertTrue(collect($preview['duplicates']['customers'])->contains('id', $customer->id));
        $this->assertTrue(collect($preview['duplicates']['brands'])->contains('id', $brand->id));

        $converted = app(ConvertProspectService::class)->convert($prospect, [
            'customer_name' => 'Different Name Co',
            'brand_name' => 'Different Name Co',
            'existing_customer_id' => $customer->id,
            'existing_brand_id' => $brand->id,
            'selected_assets' => [],
        ], $this->admin);

        $this->assertSame($customer->id, $converted->converted_customer_id);
        $this->assertSame($brand->id, $converted->converted_brand_id);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Brand::query()->count());
    }

    public function test_unsupported_social_asset_is_not_promoted(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Social Co']);
        ProspectDiscoveryCandidate::query()->create([
            'prospect_id' => $prospect->id,
            'fingerprint' => hash('sha256', 'facebook'),
            'candidate_kind' => ProspectDiscoveryCandidate::KIND_FACT,
            'candidate_type' => 'social_links',
            'target_field' => 'facebook_url',
            'proposed_value' => 'https://facebook.com/socialco',
            'provenance' => ProspectEvidenceProvenance::Observed,
        ]);

        $converted = app(ConvertProspectService::class)->convert($prospect, [
            'customer_name' => 'Social Co',
            'brand_name' => 'Social Co',
            'selected_assets' => ['facebook:https://facebook.com/socialco'],
        ], $this->admin);

        $this->assertSame(0, DigitalAsset::query()->where('brand_id', $converted->converted_brand_id)->count());
    }

    public function test_accepted_summary_promotion_and_history_preserved(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'History Co']);
        ProspectEvidence::query()->create([
            'prospect_id' => $prospect->id,
            'fingerprint' => hash('sha256', 'ev'),
            'type' => 'page',
            'title' => 'Public page',
            'source_url' => 'http://'.ProspectResearchConfig::FIXTURE_HOST.'/',
            'payload' => ['excerpt' => 'Public page'],
            'provenance' => ProspectEvidenceProvenance::Observed,
            'observed_at' => now(),
        ]);
        ProspectSalesIntelligence::query()->create([
            'prospect_id' => $prospect->id,
            'summary' => 'Observed clinic summary for brand context.',
            'status' => ProspectSalesIntelligenceStatus::Available,
        ]);

        $converted = app(ConvertProspectService::class)->convert($prospect, [
            'customer_name' => 'History Co',
            'brand_name' => 'History Co',
            'promote_observed_summary' => true,
        ], $this->admin);

        $this->assertDatabaseHas('brand_intelligence_contexts', [
            'brand_id' => $converted->converted_brand_id,
            'business_summary' => 'Observed clinic summary for brand context.',
            'source' => BrandIntelligenceContext::SOURCE_PUBLIC_DISCOVERY,
        ]);
        $this->assertDatabaseHas('prospects', ['id' => $prospect->id]);
        $this->assertDatabaseHas('prospect_evidence', ['prospect_id' => $prospect->id]);
        $this->assertDatabaseHas('prospect_sales_intelligence', ['prospect_id' => $prospect->id]);
    }

    public function test_guest_cannot_convert(): void
    {
        $prospect = Prospect::factory()->create();
        $this->get('/prospects/'.$prospect->id.'/convert')->assertRedirect('/login');
    }

    public function test_operator_can_open_conversion_review(): void
    {
        $prospect = Prospect::factory()->create(['company_name' => 'Review Co']);

        $this->actingAs($this->admin)
            ->get('/prospects/'.$prospect->id.'/convert')
            ->assertOk()
            ->assertSee(__('operator.prospects.conversion.convert'))
            ->assertSee('Review Co');
    }

    public function test_livewire_confirm_creates_new_customer_when_domain_duplicate_exists(): void
    {
        $customer = Customer::factory()->create(['name' => 'Existing Portfolio']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Existing Brand']);
        DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'domain' => ProspectResearchConfig::FIXTURE_HOST,
            'primary_url' => 'http://'.ProspectResearchConfig::FIXTURE_HOST.'/',
        ]);

        $prospect = Prospect::factory()->create([
            'company_name' => 'New Despite Domain Dup',
            'website_url' => 'http://'.ProspectResearchConfig::FIXTURE_HOST.'/',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ProspectConvert::class, ['prospectId' => (string) $prospect->id])
            ->assertSee(__('operator.prospects.conversion.potential_duplicate'))
            ->set('selected_assets', [])
            ->set('confirm_create_despite_duplicates', true)
            ->call('convert')
            ->assertHasNoErrors()
            ->assertRedirect(route('operator.prospect', ['prospectId' => $prospect->id]));

        $fresh = $prospect->fresh();
        $this->assertNotNull($fresh?->converted_customer_id);
        $this->assertNotSame($customer->id, $fresh?->converted_customer_id);
        $this->assertSame(2, Customer::query()->count());
        $this->assertDatabaseHas('customers', ['name' => 'New Despite Domain Dup']);
    }
}
