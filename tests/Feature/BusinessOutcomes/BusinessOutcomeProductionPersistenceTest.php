<?php

namespace Tests\Feature\BusinessOutcomes;

use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantIntentType;
use App\Enums\AssistantSourceClass;
use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BusinessOutcomeAggregateStatus;
use App\Enums\BusinessOutcomeKind;
use App\Enums\BusinessOutcomeObservationStatus;
use App\Enums\BusinessOutcomeSourceKind;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BusinessOutcomeObservation;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\Assistant\MoxdopAssistantService;
use App\Services\BrandExperiences\BrandExperienceService;
use App\Services\BusinessOutcomes\BusinessOutcomeCsvImportService;
use App\Services\BusinessOutcomes\BusinessOutcomeDefinitionService;
use App\Services\BusinessOutcomes\BusinessOutcomeObservationService;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Support\Assistant\AssistantSourceAuthority;
use App\Support\Assistant\Dto\AssistantIntentCandidate;
use App\Support\BusinessOutcomes\BusinessOutcomeKindRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessOutcomeProductionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_architecture_forbids_crm_and_has_kind_registry(): void
    {
        foreach (['leads', 'patients', 'deals', 'pipelines', 'appointments', 'invoices', 'payments'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasTable('business_outcome_definitions'));
        $this->assertFalse(class_exists('App\\Models\\Lead'));
        $this->assertFalse(class_exists('App\\Models\\Patient'));
        $this->assertFalse(class_exists('App\\Services\\BusinessOutcomes\\BusinessOutcomeV2'));

        $snap = app(BusinessOutcomeKindRegistry::class)->snapshot();
        $this->assertFalse($snap['crm']);
        $this->assertFalse($snap['provider_auto_mapping']);
        $this->assertArrayHasKey('qualified_lead', $snap['kinds']);
        $this->assertSame('count', $snap['kinds']['qualified_lead']['unit']);
        $this->assertSame('money', $snap['kinds']['revenue']['unit']);
    }

    public function test_definitions_and_manual_observation_happy_path(): void
    {
        [$user, $brand] = $this->seedBrand();
        $defs = app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user, 'EUR');
        $this->assertCount(4, $defs);

        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        $obs = app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 27,
            'completeness' => 'complete',
        ], $user);

        $this->assertSame(BusinessOutcomeObservationStatus::Active, $obs->status);
        $this->assertSame(27, (int) $obs->currentRevision->value_count);
        $this->assertSame(BusinessOutcomeSourceKind::Manual, $obs->currentRevision->source_kind);
        $this->assertSame($user->id, $obs->currentRevision->recorded_by);
    }

    public function test_missing_differs_from_explicit_zero(): void
    {
        [$user, $brand] = $this->seedBrand();
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);

        $missing = app(BusinessOutcomeReadService::class)->aggregate(
            $brand,
            BusinessOutcomeKind::QualifiedLead,
            '2026-07-01',
            '2026-07-31',
        );
        $this->assertSame(BusinessOutcomeAggregateStatus::NoData, $missing->status);
        $this->assertNull($missing->value);

        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 0,
            'completeness' => 'complete',
        ], $user);

        $zero = app(BusinessOutcomeReadService::class)->aggregate(
            $brand,
            BusinessOutcomeKind::QualifiedLead,
            '2026-07-01',
            '2026-07-31',
        );
        $this->assertSame('0', $zero->value);
        $this->assertSame(BusinessOutcomeAggregateStatus::Complete, $zero->status);
    }

    public function test_count_decimal_and_negative_rejected_revenue_currency_required(): void
    {
        [$user, $brand] = $this->seedBrand();
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        $rev = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::Revenue);

        try {
            app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'value' => '20.5',
                'completeness' => 'complete',
            ], $user);
            $this->fail('Expected decimal count rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        try {
            app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'value' => -1,
                'completeness' => 'complete',
            ], $user);
            $this->fail('Expected negative rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $ok = app(BusinessOutcomeObservationService::class)->record($brand, $rev, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => '31500.50',
            'currency' => 'EUR',
            'completeness' => 'complete',
        ], $user);
        $this->assertSame('31500.5000', (string) $ok->currentRevision->value_numeric);
        $this->assertSame('EUR', $ok->currentRevision->currency_code);

        try {
            app(BusinessOutcomeObservationService::class)->record($brand, $rev, [
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'value' => '100',
                'currency' => 'TRY',
                'completeness' => 'complete',
            ], $user);
            $this->fail('Expected currency mismatch');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency', $e->errors());
        }
    }

    public function test_overlap_and_correction_revision(): void
    {
        [$user, $brand] = $this->seedBrand();
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);

        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 20,
            'completeness' => 'complete',
        ], $user);

        try {
            app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
                'period_start' => '2026-07-15',
                'period_end' => '2026-07-20',
                'value' => 3,
                'completeness' => 'complete',
            ], $user);
            $this->fail('Expected overlap rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->errors());
        }

        try {
            app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'value' => 22,
                'completeness' => 'complete',
            ], $user);
            $this->fail('Expected correction required');
        } catch (ValidationException $e) {
            $this->assertContains(BusinessOutcomeObservationService::ERROR_CORRECTION_REQUIRED, $e->errors()['value']);
        }

        $obs = BusinessOutcomeObservation::query()->first();
        $corrected = app(BusinessOutcomeObservationService::class)->correct($obs, [
            'value' => 22,
            'completeness' => 'complete',
            'correction_reason' => 'Client corrected July total',
        ], $user);

        $this->assertSame(2, $corrected->revisions()->count());
        $this->assertSame(22, (int) $corrected->currentRevision->value_count);
        $agg = app(BusinessOutcomeReadService::class)->aggregate($brand, BusinessOutcomeKind::QualifiedLead, '2026-07-01', '2026-07-31');
        $this->assertSame('22', $agg->value);
    }

    public function test_csv_import_atomic_privacy_and_idempotency(): void
    {
        [$user, $brand] = $this->seedBrand();
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $csv = implode("\n", [
            'outcome_code,period_start,period_end,value,currency,completeness',
            'qualified_lead,2026-07-01,2026-07-31,27,,complete',
            'consultation,2026-07-01,2026-07-31,16,,complete',
            'sale_or_patient,2026-07-01,2026-07-31,7,,complete',
            'revenue,2026-07-01,2026-07-31,31500.00,EUR,complete',
        ]);

        $preview = app(BusinessOutcomeCsvImportService::class)->preview($brand, $csv, $user, 'july.csv');
        $this->assertTrue($preview['preview']['ok']);
        $this->assertSame(0, $preview['preview']['writes']);
        $this->assertSame(0, BusinessOutcomeObservation::query()->count());

        $commit = app(BusinessOutcomeCsvImportService::class)->commit($brand, $preview['batch'], $csv, $user);
        $this->assertSame(4, $commit['committed']);
        $this->assertSame(4, BusinessOutcomeObservation::query()->count());

        $again = app(BusinessOutcomeCsvImportService::class)->commit($brand, $preview['batch']->fresh(), $csv, $user);
        $this->assertTrue($again['idempotent']);
        $this->assertSame(4, BusinessOutcomeObservation::query()->count());

        $bad = "outcome_code,period_start,period_end,value,currency,completeness,email\nqualified_lead,2026-08-01,2026-08-31,1,,complete,a@b.com";
        $rejected = app(BusinessOutcomeCsvImportService::class)->validate($brand, $bad);
        $this->assertFalse($rejected['ok']);
        $this->assertTrue(collect($rejected['errors'])->contains(fn ($e) => $e['code'] === 'UNKNOWN_COLUMN'));
    }

    public function test_aggregation_no_proration_and_mixed_currency_blocked(): void
    {
        [$user, $brand] = $this->seedBrand();
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);

        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'value' => 10,
            'completeness' => 'complete',
        ], $user);
        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'value' => 20,
            'completeness' => 'complete',
        ], $user);

        $sum = app(BusinessOutcomeReadService::class)->aggregate($brand, BusinessOutcomeKind::QualifiedLead, '2026-01-01', '2026-02-28');
        $this->assertSame('30', $sum->value);

        $subset = app(BusinessOutcomeReadService::class)->aggregate($brand, BusinessOutcomeKind::QualifiedLead, '2026-01-15', '2026-01-31');
        $this->assertSame(BusinessOutcomeAggregateStatus::UnsupportedGrain, $subset->status);
        $this->assertNull($subset->value);
    }

    public function test_brand_isolation_and_assistant_uses_business_outcome_not_provider(): void
    {
        [$user, $brandA] = $this->seedBrand();
        $brandB = Brand::factory()->create([
            'customer_id' => $brandA->customer_id,
            'name' => 'Other Brand',
        ]);
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brandA, $user);
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brandB, $user);

        $qlA = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brandA, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brandA, $qlA, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 27,
            'completeness' => 'complete',
        ], $user);

        $this->assertNull(
            app(BusinessOutcomeReadService::class)->getObservationForBrand(
                $brandB,
                (int) BusinessOutcomeObservation::query()->first()->id,
            )
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16'));

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                metricId: 'business_outcome.qualified_lead',
                periodToken: 'last_month',
            ),
            authorizedCustomerIds: [(int) $brandA->customer_id],
            authorizedBrandIds: [(int) $brandA->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $brandA->customer_id,
            brandId: (int) $brandA->id,
            timezone: 'UTC',
        );
        $this->assertSame(AssistantAnswerStrategy::DeterministicFact, $answer->strategy);
        $this->assertSame(AssistantSourceClass::BusinessOutcome, $answer->claims[0]->requiredSourceClass);
        $this->assertSame(27.0, $answer->claims[0]->numericValue);
        $this->assertFalse($answer->runtimeProvenance['provider_conversion_fallback']);
        $this->assertFalse($answer->runtimeProvenance['ai_used']);
        CarbonImmutable::setTestNow();

        $this->assertArrayHasKey('business_outcome', app(AssistantSourceAuthority::class)->matrix());
    }

    public function test_brand_experience_may_pin_same_brand_outcome_revision_without_causality(): void
    {
        [$user, $brand] = $this->seedBrand();
        $other = Brand::factory()->create(['customer_id' => $brand->customer_id, 'name' => 'B2']);
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($other, $user);

        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        $obs = app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 27,
            'completeness' => 'complete',
        ], $user);
        $revisionId = (int) $obs->current_revision_id;

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
        ]);

        $experience = app(BrandExperienceService::class)->createConfirmed([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'situation_summary' => 'Agency adjusted landing page messaging.',
            'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed->value,
            'action_summary' => 'Landing page copy updated.',
            'action_occurred_at' => '2026-06-01 10:00:00',
            'external_action_confirmed' => true,
            'outcome_summary' => 'Client reported 27 qualified leads in July.',
            'outcome_observed_at' => '2026-08-01 10:00:00',
            'outcome_clarity' => BrandExperienceOutcomeClarity::FactualState->value,
            'business_outcome_observation_revision_id' => $revisionId,
            'quality_hints' => ['operator_observation_only' => true],
        ], $user);

        $this->assertSame(
            $revisionId,
            (int) $experience->currentRevision->business_outcome_observation_revision_id,
        );
        $this->assertSame(
            BrandExperienceCausalityStatus::CausalityNotEstablished,
            $experience->currentRevision->causality_status,
        );

        $otherQl = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($other, BusinessOutcomeKind::QualifiedLead);
        $otherObs = app(BusinessOutcomeObservationService::class)->record($other, $otherQl, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 9,
            'completeness' => 'complete',
        ], $user);

        try {
            app(BrandExperienceService::class)->createConfirmed([
                'customer_id' => $brand->customer_id,
                'brand_id' => $brand->id,
                'digital_asset_id' => $asset->id,
                'situation_summary' => 'Cross brand attempt',
                'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed->value,
                'action_summary' => 'Should fail',
                'action_occurred_at' => '2026-06-01 10:00:00',
                'external_action_confirmed' => true,
                'outcome_summary' => 'Wrong brand outcome',
                'outcome_observed_at' => '2026-08-01 10:00:00',
                'outcome_clarity' => BrandExperienceOutcomeClarity::FactualState->value,
                'business_outcome_observation_revision_id' => $otherObs->current_revision_id,
                'quality_hints' => ['operator_observation_only' => true],
            ], $user);
            $this->fail('Expected cross-brand BO link rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('business_outcome_observation_revision_id', $e->errors());
        }

        app(BusinessOutcomeObservationService::class)->correct($obs->fresh(), [
            'value' => 30,
            'completeness' => 'complete',
            'correction_reason' => 'Later correction',
        ], $user);

        $experience->refresh()->load('currentRevision');
        $this->assertSame($revisionId, (int) $experience->currentRevision->business_outcome_observation_revision_id);
        $this->assertNotSame($revisionId, (int) $obs->fresh()->current_revision_id);
    }

    public function test_no_auto_domain_writes_and_source_authority(): void
    {
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, BrandExperience::query()->count());

        [$user, $brand] = $this->seedBrand();
        app(BusinessOutcomeDefinitionService::class)->createStandardDefinitionsForBrand($brand, $user);
        $ql = app(BusinessOutcomeReadService::class)->findActiveDefinitionByKind($brand, BusinessOutcomeKind::QualifiedLead);
        app(BusinessOutcomeObservationService::class)->record($brand, $ql, [
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'value' => 5,
            'completeness' => 'complete',
        ], $user);

        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Opportunity::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, BrandExperience::query()->count());

        $auth = app(AssistantSourceAuthority::class)->matrix()['business_outcome'];
        $this->assertTrue($auth['current_measured_fact']);
        $this->assertFalse($auth['can_satisfy_provider_metric']);
        $this->assertFalse($auth['is_crm_record']);
    }

    /**
     * @return array{0: User, 1: Brand}
     */
    private function seedBrand(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);

        return [$user, $brand];
    }
}
