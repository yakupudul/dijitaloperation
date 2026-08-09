<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\Customers\Resources\Brands\BrandResource;
use App\Filament\App\Resources\Customers\Resources\Brands\Pages\ViewBrand;
use App\Filament\App\Resources\Customers\Resources\Brands\RelationManagers\BrandIntelligenceRelationManager;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\BrandIntelligence\BrandContextProvider;
use App\Support\BrandIntelligence\BrandIntelligenceCompleteness;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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

    public function test_context_create_update_and_structured_persistence(): void
    {
        Http::fake();

        Livewire::test(BrandIntelligenceRelationManager::class, [
            'ownerRecord' => $this->brand,
            'pageClass' => ViewBrand::class,
        ])
            ->assertOk()
            ->assertSee('Business context')
            ->assertDontSee('"products_services"')
            ->callAction('editIntelligence', data: [
                'business_summary' => 'Aesthetic surgery clinic.',
                'business_model' => 'healthcare_clinic',
                'products_services' => [
                    ['name' => 'Mommy Makeover', 'description' => null],
                    ['name' => 'Post-bariatric surgery', 'description' => 'Body contouring'],
                    ['name' => 'Breast aesthetic', 'description' => null],
                    ['name' => 'Rhinoplasty', 'description' => null],
                ],
                'priority_offerings' => [
                    ['name' => 'Post-bariatric surgery'],
                    ['name' => 'Mommy Makeover'],
                    ['name' => 'Breast aesthetic'],
                ],
                'target_audiences' => [
                    ['name' => 'International medical travelers', 'note' => null],
                ],
                'target_markets' => [
                    ['name' => 'Germany', 'note' => null],
                    ['name' => 'United Kingdom', 'note' => null],
                    ['name' => 'Netherlands', 'note' => null],
                ],
                'business_goals' => [
                    ['goal' => 'Grow qualified consultations', 'note' => null],
                ],
                'conversion_goals' => [
                    ['type' => ConversionGoalTypes::FORM_SUBMISSION, 'label' => 'Consultation form', 'note' => null],
                    ['type' => ConversionGoalTypes::WHATSAPP_CONVERSATION, 'label' => null, 'note' => 'Primary'],
                ],
                'positioning' => 'Post-bariatric specialist care.',
                'differentiators' => [
                    ['name' => 'Multilingual coordination'],
                    ['name' => 'Post-bariatric focus'],
                ],
                'known_competitors' => [
                    ['name' => 'Example Clinic', 'url' => 'https://example-clinic.com', 'note' => null],
                ],
                'important_constraints' => 'Regulated healthcare advertising.',
            ])
            ->assertHasNoActionErrors();

        Http::assertNothingSent();

        $context = $this->brand->fresh()->intelligenceContext;
        $this->assertNotNull($context);
        $this->assertSame('Aesthetic surgery clinic.', $context->business_summary);
        $this->assertSame('healthcare_clinic', $context->business_model);
        $this->assertCount(4, $context->products_services);
        $this->assertSame([
            'Post-bariatric surgery',
            'Mommy Makeover',
            'Breast aesthetic',
        ], $context->priority_offerings);
        $this->assertSame('Germany', $context->target_markets[0]['name']);
        $this->assertSame(ConversionGoalTypes::FORM_SUBMISSION, $context->conversion_goals[0]['type']);
        $this->assertSame('Example Clinic', $context->known_competitors[0]['name']);
        $this->assertSame(BrandIntelligenceContext::SOURCE_OPERATOR, $context->source);
        $this->assertSame($this->admin->id, $context->updated_by);

        Livewire::test(BrandIntelligenceRelationManager::class, [
            'ownerRecord' => $this->brand->fresh(),
            'pageClass' => ViewBrand::class,
        ])
            ->callAction('editIntelligence', data: [
                'business_summary' => 'Updated summary',
                'business_model' => 'healthcare_clinic',
                'products_services' => [
                    ['name' => 'Post-bariatric surgery', 'description' => null],
                ],
                'priority_offerings' => [
                    ['name' => 'Post-bariatric surgery'],
                ],
                'target_audiences' => [],
                'target_markets' => [['name' => 'Germany', 'note' => null]],
                'business_goals' => [],
                'conversion_goals' => [
                    ['type' => ConversionGoalTypes::APPOINTMENT_REQUEST, 'label' => null, 'note' => null],
                ],
                'positioning' => null,
                'differentiators' => [],
                'known_competitors' => [],
                'important_constraints' => null,
            ])
            ->assertHasNoActionErrors();

        $context->refresh();
        $this->assertSame('Updated summary', $context->business_summary);
        $this->assertSame(['Post-bariatric surgery'], $context->priority_offerings);
        $this->assertNull($context->positioning);
    }

    public function test_clear_context_deletes_intelligence_record(): void
    {
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
        ]);

        Livewire::test(BrandIntelligenceRelationManager::class, [
            'ownerRecord' => $this->brand,
            'pageClass' => ViewBrand::class,
        ])
            ->callAction('clearIntelligence')
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('brand_intelligence_contexts', [
            'brand_id' => $this->brand->id,
        ]);
        $this->assertDatabaseHas('brands', [
            'id' => $this->brand->id,
            'name' => 'Busranur Özger',
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

    public function test_overview_shows_compact_intelligence_summary(): void
    {
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
        ]);

        Livewire::test(ViewBrand::class, [
            'record' => $this->brand->getRouteKey(),
            'parentRecord' => $this->customer,
        ])
            ->assertOk()
            ->assertSee('Business context')
            ->assertSee('Priority offerings')
            ->assertSee('Post-bariatric surgery')
            ->assertSee('key areas completed')
            ->assertDontSee('Brand Intelligence Score');
    }

    public function test_intelligence_tab_is_registered_on_brand_workspace(): void
    {
        $relations = BrandResource::getRelations();
        $this->assertArrayHasKey('intelligence', $relations);
        $this->assertSame(BrandIntelligenceRelationManager::class, $relations['intelligence']);

        Livewire::test(ViewBrand::class, [
            'record' => $this->brand->getRouteKey(),
            'parentRecord' => $this->customer,
        ])
            ->assertOk()
            ->set('activeRelationManager', 'intelligence')
            ->assertSee('Intelligence');
    }

    public function test_unauthorized_user_cannot_access_brand_intelligence(): void
    {
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
            'business_summary' => 'Secret clinic context',
        ]);

        $unauthorized = User::factory()->create();
        $this->actingAs($unauthorized);

        $this->get(CustomerResource::getUrl('view', ['record' => $this->customer]))
            ->assertForbidden();

        $this->get(BrandResource::getUrl('view', [
            'record' => $this->brand,
            'customer' => $this->customer,
        ]))
            ->assertForbidden();
    }

    public function test_deleting_brand_cascades_intelligence_context(): void
    {
        $context = BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
        ]);

        $this->brand->delete();

        $this->assertDatabaseMissing('brand_intelligence_contexts', [
            'id' => $context->id,
        ]);
    }
}
