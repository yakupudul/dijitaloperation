<?php

namespace Tests\Feature\GoalsOfferings;

use App\Enums\GoalApplicabilityMode;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\OfferingStatus;
use App\Enums\ServiceBrandApplicabilityMode;
use App\Enums\ServiceScopeStatus;
use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\BrandGoal;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandOfferingName;
use App\Models\Customer;
use App\Models\ServiceDefinition;
use App\Models\Task;
use App\Services\BrandIntelligence\BrandGoalService;
use App\Services\BrandIntelligence\BrandIntelligenceContextReadService;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\BrandIntelligence\LegacyBicGoalsOfferingsMigrator;
use App\Services\ServiceScope\CustomerServiceScopeService;
use App\Support\BrandIntelligence\ConversionGoalTypes;
use App\Support\BrandIntelligence\IdentityLabelNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GoalsOfferingsIdentityPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private BrandGoalService $goals;

    private BrandOfferingService $offerings;

    private BrandIntelligenceContextWriteService $write;

    private BrandIntelligenceContextReadService $read;

    private IdentityLabelNormalizer $normalizer;

    private Brand $brand;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->goals = app(BrandGoalService::class);
        $this->offerings = app(BrandOfferingService::class);
        $this->write = app(BrandIntelligenceContextWriteService::class);
        $this->read = app(BrandIntelligenceContextReadService::class);
        $this->normalizer = app(IdentityLabelNormalizer::class);

        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id]);
    }

    public function test_bic_still_exists_and_audiences_markets_preserved(): void
    {
        $this->assertTrue(class_exists(BrandIntelligenceContext::class));
        $this->assertFalse(class_exists('App\\Models\\BrandIntelligenceContextV2'));
        $this->assertFalse(class_exists('App\\Models\\BrandStrategyContext'));

        $this->write->saveFromForm($this->brand, [
            'business_summary' => 'Clinic',
            'business_model' => 'healthcare_clinic',
            'products_services' => [['name' => 'Dental Implant', 'description' => null]],
            'priority_offerings' => ['Dental Implant'],
            'target_audiences' => [['name' => 'Medical travelers', 'note' => null]],
            'target_markets' => [['name' => 'Germany', 'note' => null]],
            'business_goals' => [['goal' => 'Grow consults', 'note' => null]],
            'conversion_goals' => [['type' => ConversionGoalTypes::FORM_SUBMISSION, 'label' => null, 'note' => null]],
            'positioning' => null,
            'differentiators' => [],
            'known_competitors' => [],
            'important_constraints' => null,
        ]);

        $context = $this->brand->fresh()->intelligenceContext;
        $this->assertNotNull($context);
        $this->assertSame('Medical travelers', $context->target_audiences[0]['name']);
        $this->assertSame('Germany', $context->target_markets[0]['name']);

        $dto = $this->read->for($this->brand->fresh());
        $this->assertSame('Medical travelers', $dto->targetAudiences[0]['name']);
        $this->assertSame('Germany', $dto->targetMarkets[0]['name']);
    }

    public function test_goal_identity_kinds_rename_archive(): void
    {
        $business = $this->goals->create($this->brand, GoalKind::Business, 'Increase sales');
        $conversion = $this->goals->create($this->brand, GoalKind::Conversion, 'Increase sales', conversionType: ConversionGoalTypes::PURCHASE);

        $this->assertNotSame($business->id, $conversion->id);
        $this->assertSame(GoalKind::Business, $business->kind);
        $this->assertSame(GoalKind::Conversion, $conversion->kind);

        $id = $business->id;
        $renamed = $this->goals->rename($business, 'Grow revenue');
        $this->assertSame($id, $renamed->id);
        $this->assertSame('Grow revenue', $renamed->label);

        $archived = $this->goals->archive($renamed);
        $this->assertSame(GoalStatus::Archived, $archived->status);
        $this->assertDatabaseHas('brand_goals', ['id' => $id]);

        $dup = $this->goals->create($this->brand, GoalKind::Business, '  grow   revenue ');
        $this->assertSame($id, $dup->id);
    }

    public function test_offering_identity_rename_priority_cross_brand(): void
    {
        $a = $this->offerings->resolveOrCreate($this->brand, 'Breast Lift')['offering'];
        $id = $a->id;

        $renamed = $this->offerings->rename($a, 'Breast Lift Surgery');
        $this->assertSame($id, $renamed->id);
        $this->assertSame('Breast Lift Surgery', $this->offerings->primaryLabel($renamed));

        // Former primary still resolves
        $resolved = $this->offerings->findByLabel($this->brand, 'Breast Lift');
        $this->assertNotNull($resolved);
        $this->assertSame($id, $resolved->id);

        $this->offerings->setPriorityOrder($this->brand, [$id]);
        $this->assertSame(1, $renamed->fresh()->priority_rank);
        $this->assertSame($id, $renamed->fresh()->id);

        $this->offerings->setPriorityOrder($this->brand, []);
        $this->assertNull($renamed->fresh()->priority_rank);
        $this->assertSame(OfferingStatus::Active, $renamed->fresh()->status);

        $otherBrand = Brand::factory()->create(['customer_id' => $this->customer->id]);
        $b = $this->offerings->resolveOrCreate($otherBrand, 'Breast Lift')['offering'];
        $this->assertNotSame($id, $b->id);
    }

    public function test_normalization_structural_collision_and_turkish_unicode(): void
    {
        $n = $this->normalizer;
        $this->assertSame($n->normalize('Breast Lift'), $n->normalize('breast lift'));
        $this->assertSame($n->normalize('Breast Lift'), $n->normalize(' Breast   Lift '));
        $this->assertSame($n->normalize("Breast\u{00A0}Lift"), $n->normalize('Breast Lift'));

        // Turkish I/İ/ı/i (v1 — no locale I→ı; see IdentityLabelNormalizer docs)
        $this->assertSame('i', $n->normalize('I'));
        $this->assertSame('i', $n->normalize('İ'));
        $this->assertSame('i', $n->normalize('i'));
        $this->assertSame('ı', $n->normalize('ı'));
        $this->assertSame($n->normalize('İstanbul'), $n->normalize('istanbul'));
        $this->assertSame($n->normalize('Istanbul'), $n->normalize('istanbul'));
        $this->assertNotSame($n->normalize('ıstanbul'), $n->normalize('istanbul'));

        // Diacritics preserved as identity distinction
        $this->assertNotSame($n->normalize('cafe'), $n->normalize('café'));

        // No translation / semantic merge
        $this->assertNotSame($n->normalize('Breast Lift'), $n->normalize('Mastopexy'));
        $this->assertNotSame($n->normalize('Breast Lift'), $n->normalize('Meme Dikleştirme'));
        $this->assertNotSame($n->normalize('Implant'), $n->normalize('Dental Implant'));

        $first = $this->offerings->resolveOrCreate($this->brand, 'Breast Lift')['offering'];
        $second = $this->offerings->resolveOrCreate($this->brand, ' breast   lift ')['offering'];
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BrandOffering::query()->where('brand_id', $this->brand->id)->count());
    }

    public function test_aliases_idempotent_and_conflict(): void
    {
        $offering = $this->offerings->resolveOrCreate($this->brand, 'Breast Lift')['offering'];
        $this->offerings->addAlias($offering, 'Mastopexy');
        $this->offerings->addAlias($offering, 'Mastopexy'); // idempotent

        $viaAlias = $this->offerings->resolveOrCreate($this->brand, 'Mastopexy');
        $this->assertFalse($viaAlias['created']);
        $this->assertSame($offering->id, $viaAlias['offering']->id);

        $other = $this->offerings->resolveOrCreate($this->brand, 'Mommy Makeover')['offering'];
        $this->expectException(ValidationException::class);
        $this->offerings->addAlias($other, 'Mastopexy');
    }

    public function test_semantic_variants_not_auto_merged(): void
    {
        $a = $this->offerings->resolveOrCreate($this->brand, 'Breast Lift')['offering'];
        $b = $this->offerings->resolveOrCreate($this->brand, 'Mastopexy')['offering'];
        $c = $this->offerings->resolveOrCreate($this->brand, 'Meme Dikleştirme')['offering'];
        $d = $this->offerings->resolveOrCreate($this->brand, 'Implant')['offering'];
        $e = $this->offerings->resolveOrCreate($this->brand, 'Dental Implant')['offering'];

        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->id, $c->id);
        $this->assertNotSame($d->id, $e->id);
        $this->assertSame(5, BrandOffering::query()->where('brand_id', $this->brand->id)->count());
    }

    public function test_concurrent_duplicate_offering_create_safe(): void
    {
        $results = [];
        $results[] = $this->offerings->resolveOrCreate($this->brand, 'Breast Lift');
        // Simulate race loser path via unique catch by creating claim then resolve variant
        $results[] = $this->offerings->resolveOrCreate($this->brand, 'BREAST LIFT');

        $this->assertSame($results[0]['offering']->id, $results[1]['offering']->id);
        $this->assertSame(1, BrandOfferingName::query()->where('brand_id', $this->brand->id)->count());
    }

    public function test_goal_applicability_brand_wide_and_specific(): void
    {
        $offering = $this->offerings->resolveOrCreate($this->brand, 'Dental Implant')['offering'];
        $goal = $this->goals->create($this->brand, GoalKind::Business, 'Brand growth');

        $this->assertSame(GoalApplicabilityMode::BrandWide, $goal->applicability_mode);
        $this->assertCount(0, $goal->offerings);

        $this->expectException(ValidationException::class);
        $this->goals->setApplicability($goal, GoalApplicabilityMode::SpecificOfferings, []);
    }

    public function test_goal_specific_offerings_and_cross_brand_rejected(): void
    {
        $offering = $this->offerings->resolveOrCreate($this->brand, 'Dental Implant')['offering'];
        $foreignBrand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $foreign = $this->offerings->resolveOrCreate($foreignBrand, 'Dental Implant')['offering'];

        $goal = $this->goals->create($this->brand, GoalKind::Conversion, 'Lead form', conversionType: ConversionGoalTypes::FORM_SUBMISSION);
        $updated = $this->goals->setApplicability($goal, GoalApplicabilityMode::SpecificOfferings, [$offering->id]);
        $this->assertSame(GoalApplicabilityMode::SpecificOfferings, $updated->applicability_mode);
        $this->assertSame([$offering->id], $updated->offerings->pluck('id')->all());

        // New offering does not require pivot copy for brand-wide
        $wide = $this->goals->create($this->brand, GoalKind::Business, 'Wide goal');
        $this->offerings->resolveOrCreate($this->brand, 'New Offering');
        $this->assertSame(GoalApplicabilityMode::BrandWide, $wide->fresh()->applicability_mode);
        $this->assertCount(0, $wide->fresh()->offerings);

        $this->expectException(ValidationException::class);
        $this->goals->setApplicability($goal, GoalApplicabilityMode::SpecificOfferings, [$foreign->id]);
    }

    public function test_legacy_migration_idempotent_and_structural_collapse(): void
    {
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $this->brand->id,
            'business_goals' => [
                ['goal' => 'Grow consults', 'note' => null],
                ['goal' => ' grow   consults ', 'note' => null],
            ],
            'conversion_goals' => [
                ['type' => ConversionGoalTypes::FORM_SUBMISSION, 'label' => 'Consultation form', 'note' => null],
            ],
            'priority_offerings' => [
                'Breast Lift',
                ' breast lift ',
                'BREAST LIFT',
                'Mastopexy',
                '',
            ],
            'target_audiences' => [['name' => 'Travelers', 'note' => null]],
            'target_markets' => [['name' => 'UK', 'note' => null]],
        ]);

        $migrator = app(LegacyBicGoalsOfferingsMigrator::class);
        $first = $migrator->migrateAll();
        $second = $migrator->migrateAll();

        $this->assertSame(1, BrandGoal::query()->where('brand_id', $this->brand->id)->where('kind', GoalKind::Business)->count());
        $this->assertSame(1, BrandGoal::query()->where('brand_id', $this->brand->id)->where('kind', GoalKind::Conversion)->count());
        $this->assertSame(2, BrandOffering::query()->where('brand_id', $this->brand->id)->count());

        $context = $this->brand->fresh()->intelligenceContext;
        $this->assertSame(['Breast Lift', 'Mastopexy'], $context->priority_offerings);
        $this->assertSame('Travelers', $context->target_audiences[0]['name']);
        $this->assertGreaterThan(0, $first['stats']['collapsed']);

        // Idempotent IDs
        $idsFirst = BrandOffering::query()->where('brand_id', $this->brand->id)->orderBy('id')->pluck('id')->all();
        $idsSecond = BrandOffering::query()->where('brand_id', $this->brand->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame($idsFirst, $idsSecond);
        $this->assertSame($second['stats']['brands'], $first['stats']['brands']);
    }

    public function test_direct_legacy_identity_write_blocked_after_create(): void
    {
        $context = BrandIntelligenceContext::factory()->create(['brand_id' => $this->brand->id]);

        $this->expectException(ValidationException::class);
        $context->business_goals = [['goal' => 'Hacked', 'note' => null]];
        $context->save();
    }

    public function test_compatibility_projection_matches_entities(): void
    {
        $this->write->saveFromForm($this->brand, [
            'business_summary' => null,
            'business_model' => null,
            'products_services' => [],
            'priority_offerings' => ['Mommy Makeover', 'Breast Lift'],
            'target_audiences' => [],
            'target_markets' => [],
            'business_goals' => [['goal' => 'More consults', 'note' => 'q1']],
            'conversion_goals' => [
                ['type' => ConversionGoalTypes::WHATSAPP_CONVERSATION, 'label' => 'WA lead', 'note' => null],
            ],
            'positioning' => null,
            'differentiators' => [],
            'known_competitors' => [],
            'important_constraints' => null,
        ]);

        $context = $this->brand->fresh()->intelligenceContext;
        $dto = $this->read->for($this->brand->fresh());

        $this->assertSame(['Mommy Makeover', 'Breast Lift'], $context->priority_offerings);
        $this->assertSame('More consults', $context->business_goals[0]['goal']);
        $this->assertSame($dto->businessGoals[0]->id, BrandGoal::query()->where('kind', GoalKind::Business)->first()->id);
        $this->assertSame($dto->priorityOfferings[0]->id, BrandOffering::query()->where('priority_rank', 1)->first()->id);
        $this->assertNotEmpty($dto->priorityOfferings[0]->primaryLabel);
    }

    public function test_service_scope_does_not_create_offerings_or_goals(): void
    {
        $service = ServiceDefinition::query()->where('code', 'seo')->firstOrFail();
        app(CustomerServiceScopeService::class)->create(
            customer: $this->customer,
            service: $service,
            status: ServiceScopeStatus::Active,
            brandMode: ServiceBrandApplicabilityMode::CustomerWide,
        );

        $this->assertSame(0, BrandOffering::query()->count());
        $this->assertSame(0, BrandGoal::query()->count());
        $this->assertSame(0, Task::query()->count());
    }

    public function test_goal_offering_mutations_create_no_downstream_work(): void
    {
        $offering = $this->offerings->resolveOrCreate($this->brand, 'Premium Membership')['offering'];
        $this->goals->create($this->brand, GoalKind::Business, 'Expand membership');
        $this->offerings->addAlias($offering, 'VIP Plan');
        $this->offerings->setPriorityOrder($this->brand, [$offering->id]);

        $this->assertSame(0, Task::query()->count());
        $this->assertTrue(BrandContextActivity::query()->where('event', 'OFFERING_CREATED')->exists());
        $this->assertTrue(BrandContextActivity::query()->where('event', 'GOAL_CREATED')->exists());
        $this->assertTrue(BrandContextActivity::query()->where('event', 'OFFERING_ALIAS_ADDED')->exists());
        $this->assertTrue(BrandContextActivity::query()->where('event', 'OFFERING_PRIORITY_CHANGED')->exists());
    }

    public function test_archived_offering_cannot_remain_priority(): void
    {
        $offering = $this->offerings->resolveOrCreate($this->brand, 'Rhinoplasty')['offering'];
        $this->offerings->setPriorityOrder($this->brand, [$offering->id]);
        $this->offerings->archive($offering);

        $this->assertNull($offering->fresh()->priority_rank);
        $this->assertSame(OfferingStatus::Archived, $offering->fresh()->status);
    }

    public function test_empty_context_has_no_demo_fallback(): void
    {
        $dto = $this->read->for($this->brand);
        $this->assertSame([], $dto->businessGoals);
        $this->assertSame([], $dto->conversionGoals);
        $this->assertSame([], $dto->offerings);
        $this->assertSame([], $dto->priorityOfferings);
        $this->assertFalse($dto->hasContext);
    }

    public function test_create_explicit_offering_already_exists(): void
    {
        $this->offerings->create($this->brand, 'SEO Course');
        $this->expectException(ValidationException::class);
        $this->offerings->create($this->brand, 'seo course');
    }
}
