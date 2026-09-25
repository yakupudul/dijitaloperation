<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\BrandIntelligence\BrandContextProvider;
use App\Support\BrandIntelligence\BrandIntelligenceCompleteness;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrandIntelligenceContextTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create([
            'customer_id' => $this->customer->id,
            'name' => 'Busranur Özger',
        ]);
    }

    public function test_brand_can_exist_without_intelligence_context(): void
    {
        $this->assertNull($this->brand->intelligenceContext);
        $this->assertFalse($this->brand->intelligenceContext()->exists());

        $snapshot = app(BrandContextProvider::class)->for($this->brand);

        $this->assertFalse($snapshot->hasContext);
        $this->assertSame([], $snapshot->offerings);
        $this->assertSame([], $snapshot->priorityOfferings);
        $this->assertNull($snapshot->businessSummary);
        $this->assertSame(0, $snapshot->completeness['completed']);
    }

    public function test_brand_has_one_intelligence_context(): void
    {
        $context = BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
        ]);

        $this->assertTrue($this->brand->fresh()->intelligenceContext->is($context));
        $this->assertTrue($context->brand->is($this->brand));

        $this->expectException(QueryException::class);
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
        ]);
    }

    public function test_brand_context_provider_normalized_output_never_fabricates(): void
    {
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
            'business_summary' => 'Clinic',
            'business_model' => 'healthcare_clinic',
            'products_services' => [
                ['name' => 'Mommy Makeover', 'description' => ''],
                ['name' => '', 'description' => 'ignored'],
            ],
            'priority_offerings' => ['Mommy Makeover', '', 'Breast aesthetic'],
            'target_audiences' => [['name' => 'Travelers', 'note' => '']],
            'target_markets' => [['name' => 'Germany', 'note' => null]],
            'business_goals' => [['goal' => 'More consults', 'note' => null]],
            'conversion_goals' => [
                ['type' => 'whatsapp_conversation', 'label' => null, 'note' => 'Primary'],
            ],
            'positioning' => 'Specialist',
            'differentiators' => ['Focus', ''],
            'known_competitors' => [
                ['name' => 'Rival', 'url' => 'https://rival.example', 'note' => null],
            ],
            'important_constraints' => 'No before/after ads',
        ]);

        Http::fake();
        $snapshot = app(BrandContextProvider::class)->for($this->brand->fresh());
        Http::assertNothingSent();

        $this->assertTrue($snapshot->hasContext);
        $this->assertSame('Clinic', $snapshot->businessSummary);
        $this->assertSame('Healthcare / clinic', $snapshot->businessModelLabel);
        $this->assertCount(1, $snapshot->offerings);
        $this->assertSame(['Mommy Makeover', 'Breast aesthetic'], $snapshot->priorityOfferings);
        $this->assertSame('WhatsApp conversation', $snapshot->conversionGoals[0]['type_label']);
        $this->assertNull($snapshot->conversionGoals[0]['label']);
        $this->assertSame('Rival', $snapshot->competitors[0]['name']);
        $this->assertGreaterThan(0, $snapshot->completeness['completed']);
        $this->assertLessThanOrEqual(BrandIntelligenceCompleteness::TOTAL_AREAS, $snapshot->completeness['completed']);
        $this->assertStringContainsString('key areas completed', $snapshot->completeness['label']);
        $this->assertStringNotContainsString('/100', $snapshot->completeness['label']);
    }

    public function test_website_seo_market_remains_independent_from_brand_markets(): void
    {
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
            'target_markets' => [
                ['name' => 'Germany', 'note' => null],
                ['name' => 'United Kingdom', 'note' => null],
                ['name' => 'Netherlands', 'note' => null],
            ],
        ]);

        $website = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'website',
            'domain' => 'example.com',
            'seo_market_location_code' => 2826,
            'seo_market_location_name' => 'United Kingdom',
            'seo_market_language_code' => 'en',
            'seo_market_language_name' => 'English',
        ]);

        $snapshot = app(BrandContextProvider::class)->for($this->brand->fresh());
        $marketNames = collect($snapshot->targetMarkets)->pluck('name')->all();

        $this->assertSame(['Germany', 'United Kingdom', 'Netherlands'], $marketNames);
        $this->assertSame(2826, $website->fresh()->seo_market_location_code);
        $this->assertSame('en', $website->fresh()->seo_market_language_code);
        $this->assertNotEquals(
            $marketNames,
            [$website->seo_market_location_name],
        );
    }

    public function test_deleting_brand_cascades_intelligence_context(): void
    {
        $context = BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
        ]);

        // Deleting a brand from the portfolio archives it and keeps its context; only a physical delete cascades.
        $this->brand->delete();
        $this->assertDatabaseHas('brand_intelligence_contexts', ['id' => $context->id]);

        $this->brand->forceDelete();

        $this->assertDatabaseMissing('brand_intelligence_contexts', [
            'id' => $context->id,
        ]);
    }
}
