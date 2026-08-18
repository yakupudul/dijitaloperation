<?php

namespace Tests\Feature\Assistant;

use App\Enums\AssistantAnswerBlockType;
use App\Enums\AssistantAnswerStrategy;
use App\Enums\AssistantCapabilityId;
use App\Enums\AssistantClarificationReason;
use App\Enums\AssistantCoverageState;
use App\Enums\AssistantIntentType;
use App\Enums\AssistantSourceClass;
use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceChannel;
use App\Enums\BrandExperienceOrigin;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Enums\DigitalAssetStatus;
use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningArtifactStatus;
use App\Enums\SectorLearningCohortBand;
use App\Enums\SectorPrivacyDisposition;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BrandExperienceRevision;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Opportunity;
use App\Models\SectorLearningArtifact;
use App\Models\SectorLearningRevision;
use App\Models\User;
use App\Services\Assistant\AssistantAnswerGroundingValidator;
use App\Services\Assistant\AssistantBoundaryGuard;
use App\Services\Assistant\AssistantDateRangeResolver;
use App\Services\Assistant\AssistantIntentInterpreter;
use App\Services\Assistant\MoxdopAssistantService;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Support\Assistant\AssistantCapabilityRegistry;
use App\Support\Assistant\AssistantEvaluationHooks;
use App\Support\Assistant\AssistantMetricRegistry;
use App\Support\Assistant\AssistantSourceAuthority;
use App\Support\Assistant\Dto\AssistantAnswer;
use App\Support\Assistant\Dto\AssistantAnswerSourceManifest;
use App\Support\Assistant\Dto\AssistantClaim;
use App\Support\Assistant\Dto\AssistantIntentCandidate;
use App\Support\Assistant\Dto\AssistantQueryPlan;
use App\Support\Assistant\Dto\AssistantSessionScope;
use App\Support\Assistant\Dto\AssistantSourceRef;
use App\Support\Assistant\Dto\AssistantThreadState;
use App\Support\BrandExperiences\BrandExperienceContextSnapshot;
use App\Support\BrandExperiences\Dto\BrandExperienceEvidenceQualityAssessment;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FutureAssistantArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_architecture_forbids_chat_ui_and_raw_db_tools(): void
    {
        $snap = app(MoxdopAssistantService::class)->architectureSnapshot();
        $this->assertFalse($snap['chat_ui']);
        $this->assertFalse($snap['sidebar_item']);
        $this->assertFalse($snap['floating_button']);
        $this->assertFalse($snap['text_to_sql']);
        $this->assertFalse($snap['raw_db_tool']);
        $this->assertFalse($snap['assistant_v2']);
        $this->assertTrue($snap['read_only']);
        $this->assertFalse($snap['fine_tuning']);
        $this->assertFalse($snap['embeddings']);
        $this->assertFalse($snap['vector_db']);
        $this->assertFalse($snap['similar_customer']);

        app(AssistantBoundaryGuard::class)->assertSafeArchitecture();
        $registry = app(AssistantCapabilityRegistry::class);
        foreach ($registry->forbiddenCapabilityIds() as $id) {
            $this->assertFalse($registry->has($id));
        }
        $this->assertFalse(class_exists('App\\Services\\Assistant\\AssistantV2'));
    }

    public function test_no_customer_scope_requires_clarification(): void
    {
        $user = User::factory()->create();
        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                capabilityId: AssistantCapabilityId::ProviderMetricLookup,
                metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
                periodToken: 'last_30_days',
            ),
            authorizedCustomerIds: [1],
            authorizedBrandIds: [],
            authorizedDigitalAssetIds: [],
            customerId: null,
        );

        $this->assertSame(AssistantAnswerStrategy::Clarification, $answer->strategy);
        $this->assertSame(AssistantClarificationReason::CustomerScopeRequired, $answer->clarificationReason);
    }

    public function test_brand_required_no_first_brand_fallback(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brandA = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand A']);
        $brandB = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand B']);

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
                periodToken: 'last_30_days',
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brandA->id, (int) $brandB->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $customer->id,
            brandId: null,
        );

        $this->assertSame(AssistantClarificationReason::BrandScopeRequired, $answer->clarificationReason);
    }

    public function test_google_ads_spend_deterministic_from_persisted_data(): void
    {
        if (! Schema::hasTable('google_ads_account_daily')) {
            $this->markTestSkipped('google_ads_account_daily table not present');
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16', 'UTC'));
        [$user, $customer, $brand, $asset, $resource] = $this->seedGoogleAdsFixture();

        $dates = [];
        for ($i = 0; $i < 30; $i++) {
            $dates[] = CarbonImmutable::parse('2026-08-16')->subDays(29 - $i)->toDateString();
        }
        foreach ($dates as $date) {
            DB::table('google_ads_account_daily')->insert([
                'digital_asset_id' => $asset->id,
                'external_resource_id' => $resource->id,
                'customer_id' => '9998887777',
                'reporting_date' => $date,
                'impressions' => 100,
                'clicks' => 10,
                'cost_micros' => 1_000_000,
                'cost_amount' => 1.0,
                'conversions' => 0,
                'currency' => 'EUR',
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'Europe/Berlin',
                'record_fingerprint' => hash('sha256', 'acct-'.$date),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
                periodToken: 'last_30_days',
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [(int) $asset->id],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            digitalAssetId: (int) $asset->id,
            timezone: 'UTC',
        );

        $this->assertSame(AssistantAnswerStrategy::DeterministicFact, $answer->strategy);
        $this->assertFalse($answer->runtimeProvenance['ai_used']);
        $this->assertFalse($answer->runtimeProvenance['llm_arithmetic']);
        $this->assertSame(0, $answer->runtimeProvenance['provider_calls']);
        $this->assertSame(AssistantCoverageState::Complete, $answer->coverage);
        $this->assertCount(1, $answer->claims);
        $this->assertSame(30.0, $answer->claims[0]->numericValue);
        $this->assertSame('EUR', $answer->claims[0]->unit);
        $this->assertSame(AssistantSourceClass::ProviderData, $answer->claims[0]->requiredSourceClass);
        $this->assertNotNull($answer->requestedPeriod);
        $this->assertNotNull($answer->coveredPeriod);
        CarbonImmutable::setTestNow();
    }

    public function test_missing_data_is_not_zero(): void
    {
        if (! Schema::hasTable('google_ads_account_daily')) {
            $this->markTestSkipped('google_ads_account_daily table not present');
        }

        [$user, $customer, $brand, $asset] = $this->seedGoogleAdsFixture();

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
                periodToken: 'last_30_days',
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [(int) $asset->id],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            digitalAssetId: (int) $asset->id,
        );

        $this->assertTrue($answer->abstained);
        $this->assertSame(AssistantAnswerStrategy::Unavailable, $answer->strategy);
        $json = json_encode($answer->toArray());
        $this->assertStringContainsString('"missing_as_zero":false', (string) $json);
    }

    public function test_multiple_opportunities_without_unique_priority_no_first_row(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);

        Opportunity::factory()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'status' => Opportunity::STATUS_OPEN,
            'title' => 'SEO opportunity A',
            'qualitative_priority' => 'high',
            'category' => 'seo',
            'service_definition_code' => 'seo',
            'rule_id' => 'eval.opp.a',
            'fingerprint' => hash('sha256', 'opp-a'),
            'semantic_fingerprint' => hash('sha256', 'opp-a-sem'),
            'last_detected_at' => now(),
        ]);
        Opportunity::factory()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'status' => Opportunity::STATUS_OPEN,
            'title' => 'SEO opportunity B',
            'qualitative_priority' => 'high',
            'category' => 'seo',
            'service_definition_code' => 'seo',
            'rule_id' => 'eval.opp.b',
            'fingerprint' => hash('sha256', 'opp-b'),
            'semantic_fingerprint' => hash('sha256', 'opp-b-sem'),
            'last_detected_at' => now(),
        ]);

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::DomainLookup,
                capabilityId: AssistantCapabilityId::OpportunityLookup,
                domainFilter: 'opportunity',
                parameters: ['most_important' => true],
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        );

        $this->assertSame(AssistantClarificationReason::CanonicalOrderUnavailable, $answer->clarificationReason);
        $this->assertFalse($answer->blocks[0]['first_row_fallback'] ?? true);
        $this->assertArrayHasKey('magic_score', $answer->blocks[0]);
        $this->assertNull($answer->blocks[0]['magic_score']);
    }

    public function test_sector_question_uses_released_artifacts_only(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $other = Brand::factory()->create([
            'customer_id' => Customer::factory()->create(['industry' => 'dental'])->id,
            'sector' => 'dental',
        ]);
        $this->seedReleasedSector('dental');
        $this->createExperience($other, 'MOXDOP_CANARY_OTHER_BRAND_ASSISTANT');

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::SectorContext,
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        );

        $this->assertSame(
            AssistantAnswerStrategy::CanonicalDomainSummary,
            $answer->strategy,
            'abstention='.($answer->abstentionReason ?? 'null').' limitations='.json_encode($answer->limitations)
        );
        $json = strtolower((string) json_encode($answer->toArray()));
        $this->assertStringNotContainsString('moxdop_canary_other_brand_assistant', $json);
        $this->assertDoesNotMatchRegularExpression('/"(contributor_id|contributor_ids|lineage_entries)"\s*:/', $json);
        $this->assertNull($answer->sourceManifest->toArray()['sector_contributor_identities']);
        $this->assertFalse($answer->blocks[0]['payload']['raw_similar_customer']);
    }

    public function test_write_action_rejected(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                requestsWrite: true,
                parameters: ['action' => 'pause_campaign'],
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        );

        $this->assertSame(AssistantIntentType::UnsupportedWriteAction, $answer->intentType);
        $this->assertFalse($answer->blocks[0]['write_allowed']);
        $this->assertSame(0, $answer->runtimeProvenance['domain_writes']);
        $this->assertSame(0, $answer->runtimeProvenance['provider_writes']);
    }

    public function test_grounding_rejects_sector_as_provider_fact(): void
    {
        $scope = new AssistantSessionScope(
            userId: 1,
            authorizedCustomerIds: [1],
            authorizedBrandIds: [1],
            customerId: 1,
            brandId: 1,
        );
        $plan = new AssistantQueryPlan(
            scope: $scope,
            intentType: AssistantIntentType::FactLookup,
            capabilities: [AssistantCapabilityId::ProviderMetricLookup],
            answerStrategy: AssistantAnswerStrategy::DeterministicFact,
            validated: true,
        );
        $badRef = new AssistantSourceRef(
            sourceClass: AssistantSourceClass::SectorPattern,
            opaqueRef: 'sector_artifact:x',
        );
        $claim = new AssistantClaim(
            claimId: 'bad',
            blockType: AssistantAnswerBlockType::Fact,
            statement: 'Spend was 50k',
            requiredSourceClass: AssistantSourceClass::ProviderData,
            sourceRefs: [$badRef],
            numericValue: 50000.0,
        );
        $answer = new AssistantAnswer(
            strategy: AssistantAnswerStrategy::DeterministicFact,
            intentType: AssistantIntentType::FactLookup,
            scope: $scope,
            claims: [$claim],
            blocks: [],
            sourceManifest: new AssistantAnswerSourceManifest([$badRef]),
            answeredAt: now()->toIso8601String(),
        );

        $validated = app(AssistantAnswerGroundingValidator::class)->validate($answer, $plan);
        $this->assertTrue($validated->abstained);
        $this->assertSame('unsupported_factual_claim', $validated->abstentionReason);
    }

    public function test_multi_turn_reuses_structured_state_and_revalidates(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads']);

        $thread = new AssistantThreadState(
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            digitalAssetId: (int) $asset->id,
            metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
            periodToken: 'last_30_days',
        );

        // Unauthorized customer in thread must not expand access
        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                periodToken: 'last_month',
            ),
            authorizedCustomerIds: [],
            authorizedBrandIds: [],
            authorizedDigitalAssetIds: [],
            threadState: $thread,
        );
        $this->assertSame(AssistantClarificationReason::CustomerScopeRequired, $answer->clarificationReason);
        $this->assertFalse($thread->toArray()['is_brand_memory']);
        $this->assertFalse($thread->toArray()['is_authorization']);
    }

    public function test_date_range_resolver_last_30_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16', 'UTC'));
        $range = app(AssistantDateRangeResolver::class)->resolve('last_30_days', 'UTC');
        $this->assertSame('2026-07-18', $range->startDate);
        $this->assertSame('2026-08-16', $range->endDate);
        CarbonImmutable::setTestNow();
    }

    public function test_source_authority_has_no_numeric_score(): void
    {
        $snap = app(AssistantSourceAuthority::class)->snapshot();
        $this->assertNull($snap['numeric_authority_score']);
        $this->assertTrue($snap['current_fact_wins_over_history']);
        $this->assertTrue($snap['brand_fact_wins_over_sector']);
    }

    public function test_partial_coverage_is_marked_partial(): void
    {
        if (! Schema::hasTable('google_ads_account_daily')) {
            $this->markTestSkipped('google_ads_account_daily table not present');
        }

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-16', 'UTC'));
        [$user, $customer, $brand, $asset, $resource] = $this->seedGoogleAdsFixture();

        for ($i = 0; $i < 10; $i++) {
            $date = CarbonImmutable::parse('2026-08-16')->subDays(9 - $i)->toDateString();
            DB::table('google_ads_account_daily')->insert([
                'digital_asset_id' => $asset->id,
                'external_resource_id' => $resource->id,
                'customer_id' => '9998887777',
                'reporting_date' => $date,
                'impressions' => 100,
                'clicks' => 10,
                'cost_micros' => 1_000_000,
                'cost_amount' => 1.0,
                'conversions' => 0,
                'currency' => 'EUR',
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'Europe/Berlin',
                'record_fingerprint' => hash('sha256', 'partial-'.$date),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
                periodToken: 'last_30_days',
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [(int) $asset->id],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            digitalAssetId: (int) $asset->id,
            timezone: 'UTC',
        );

        $this->assertSame(AssistantAnswerStrategy::DeterministicFact, $answer->strategy);
        $this->assertSame(AssistantCoverageState::Partial, $answer->coverage);
        $this->assertContains('partial_coverage', $answer->limitations);
        $this->assertSame(10.0, $answer->claims[0]->numericValue);
        CarbonImmutable::setTestNow();
    }

    public function test_intent_interpreter_never_invents_ids_or_sql(): void
    {
        $candidate = app(AssistantIntentInterpreter::class)
            ->interpretDeterministic('What was this customer\'s Google Ads spend in the last 30 days?');

        $this->assertSame(AssistantIntentType::FactLookup, $candidate->intentType);
        $this->assertSame(AssistantMetricRegistry::GOOGLE_ADS_SPEND, $candidate->metricId);
        $this->assertArrayNotHasKey('customer_id', $candidate->parameters);
        $this->assertArrayNotHasKey('table', $candidate->parameters);
        $this->assertArrayNotHasKey('sql', $candidate->parameters);

        $write = app(AssistantIntentInterpreter::class)
            ->interpretDeterministic('Pause this campaign now');
        $this->assertTrue($write->requestsWrite);
    }

    public function test_prompt55_evaluation_hooks_compatible(): void
    {
        $hooks = app(AssistantEvaluationHooks::class);
        $this->assertContains('ASSISTANT_PROVIDER_FACT_GOOGLE_ADS_SPEND', $hooks->goldenCaseKeys());
        $this->assertContains('ASSISTANT_CROSS_BRAND_PRIVACY', $hooks->goldenCaseKeys());

        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::MethodologyGuidance,
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        );
        $compat = $hooks->assertCompatible($answer);
        $this->assertTrue($compat['compatible']);
        $this->assertFalse($compat['auto_tune']);
        $this->assertSame('intelligence_evaluation_v1', $compat['policy_compat']);
    }

    public function test_model_provided_customer_id_rejected(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        $answer = app(MoxdopAssistantService::class)->ask(
            userId: (int) $user->id,
            candidate: new AssistantIntentCandidate(
                intentType: AssistantIntentType::FactLookup,
                metricId: AssistantMetricRegistry::GOOGLE_ADS_SPEND,
                periodToken: 'last_30_days',
                parameters: ['customer_id' => 999, 'table' => 'users'],
            ),
            authorizedCustomerIds: [(int) $customer->id],
            authorizedBrandIds: [(int) $brand->id],
            authorizedDigitalAssetIds: [],
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
        );

        $this->assertSame(AssistantAnswerStrategy::Clarification, $answer->strategy);
    }

    /**
     * @return array{0: User, 1: Customer, 2: Brand, 3: DigitalAsset, 4: CoreExternalResource}
     */
    private function seedGoogleAdsFixture(): array
    {
        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
        ]);

        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'module_id' => 'google_ads',
            'name' => 'Eval Google Ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::ADWORDS],
            ],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'sample-access-token',
                'refresh_token' => 'sample-refresh-token',
            ],
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'external_id' => '9998887777',
            'display_name' => 'Assistant Google Ads Customer',
            'metadata' => [
                'timezone' => 'Europe/Berlin',
                'currency_code' => 'EUR',
            ],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$user, $customer, $brand, $asset, $resource];
    }

    private function seedReleasedSector(string $sectorCode): void
    {
        $artifact = SectorLearningArtifact::query()->create([
            'sector_code' => $sectorCode,
            'stable_key' => hash('sha256', 'assistant-sector-'.$sectorCode.uniqid()),
            'artifact_kind' => SectorLearningArtifactKind::ActionOutcomeAssociation,
            'status' => SectorLearningArtifactStatus::Active,
        ]);
        $revision = SectorLearningRevision::query()->create([
            'artifact_id' => $artifact->id,
            'revision_number' => 1,
            'status' => SectorLearningArtifactStatus::Active,
            'dimension_contract' => ['sector_code' => $sectorCode, 'dimensions' => ['sector_code']],
            'time_scope' => ['granularity' => 'month'],
            'metric_family' => 'outcome_clarity_distribution',
            'action_category' => null,
            'aggregate_result' => [
                'schema' => 'sector_aggregate_action_outcome_v1',
                'causality' => 'causality_not_established',
                'industry_benchmark_claim' => false,
                'cells' => [],
            ],
            'cohort_band' => SectorLearningCohortBand::Band5To9,
            'limitations' => ['MOXDOP_COHORT_OBSERVATION', 'OBSERVATIONAL_ONLY'],
            'privacy_policy_version' => SectorLearningPrivacyPolicy::VERSION,
            'aggregation_method_version' => 'sector_aggregation_v1',
            'projection_version' => 'sector_projection_v1',
            'aggregate_fingerprint' => hash('sha256', 'assistant-fp'),
            'observational_label' => 'MOXDOP_COHORT_OBSERVATION',
            'summary_text' => 'Privacy-qualified MoxDOP cohort observation.',
            'privacy_assessment' => [
                'disposition' => SectorPrivacyDisposition::Eligible->value,
                'reason_codes' => [],
                'privacy_score' => null,
            ],
            'internal_distinct_brands' => 5,
            'internal_distinct_customers' => 5,
        ]);
        $artifact->forceFill(['current_revision_id' => $revision->id])->save();
    }

    private function createExperience(Brand $brand, string $summary): void
    {
        $quality = new BrandExperienceEvidenceQualityAssessment(
            supportStatus: BrandExperienceSupportStatus::Sufficient,
            reasonCodes: ['causality_not_established'],
        );
        $context = new BrandExperienceContextSnapshot(
            brandId: (int) $brand->id,
            customerId: (int) $brand->customer_id,
        );
        $experience = BrandExperience::query()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'status' => BrandExperienceStatus::Confirmed,
            'origin' => BrandExperienceOrigin::OperatorCaptured,
            'idempotency_key' => 'asst-'.uniqid(),
        ]);
        $revision = BrandExperienceRevision::query()->create([
            'brand_experience_id' => $experience->id,
            'revision_number' => 1,
            'context_schema_version' => $context->schemaVersion,
            'context_snapshot' => $context->toArray(),
            'market_code' => 'DE',
            'channel' => BrandExperienceChannel::GoogleAds,
            'situation_summary' => $summary,
            'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed,
            'action_summary' => 'Action',
            'action_occurred_at' => now()->subDays(30),
            'outcome_summary' => 'Outcome',
            'outcome_observed_at' => now()->subDays(10),
            'outcome_clarity' => BrandExperienceOutcomeClarity::Favorable,
            'support_status' => $quality->supportStatus,
            'quality_assessment' => $quality->toArray(),
            'quality_policy_version' => $quality->policyVersion,
            'quality_assessed_at' => now(),
            'causality_status' => BrandExperienceCausalityStatus::CausalityNotEstablished,
        ]);
        $experience->forceFill(['current_revision_id' => $revision->id])->save();
    }
}
