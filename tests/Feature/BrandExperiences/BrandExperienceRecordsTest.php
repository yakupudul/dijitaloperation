<?php

namespace Tests\Feature\BrandExperiences;

use App\Contracts\IntelligenceMemory\BrandMemoryContextProvider;
use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceChannel;
use App\Enums\BrandExperienceEvidenceRole;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Enums\GoalKind;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BrandGoal;
use App\Models\BrandOffering;
use App\Models\BrandOfferingName;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Task;
use App\Models\User;
use App\Services\BrandExperiences\BrandExperienceReadService;
use App\Services\BrandExperiences\BrandExperienceSectorContributionBuilder;
use App\Services\BrandExperiences\BrandExperienceService;
use App\Services\BrandIntelligence\BrandGoalService;
use App\Services\IntelligenceMemory\ExperienceBrandMemoryContextProvider;
use App\Support\IntelligenceMemory\Dto\BrandMemoryScope;
use App\Support\Tasks\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BrandExperienceRecordsTest extends TestCase
{
    use RefreshDatabase;

    private BrandExperienceService $service;

    private BrandExperienceReadService $read;

    private Customer $customer;

    private Brand $brand;

    private DigitalAsset $asset;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BrandExperienceService::class);
        $this->read = app(BrandExperienceReadService::class);
        $this->customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $this->customer->id, 'sector' => 'dental']);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'website']);
        $this->user = User::factory()->create();
    }

    public function test_create_confirmed_experience_with_completed_task_and_evidence(): void
    {
        $task = $this->completedTask();
        $situation = $this->evidenceForBrand('situation-fp-1');
        $outcome = $this->evidenceForBrand('outcome-fp-1');
        $goal = $this->goal('Grow consults');

        $experience = $this->service->createConfirmed([
            'brand_id' => $this->brand->id,
            'channel' => BrandExperienceChannel::Website->value,
            'market_code' => 'DE',
            'digital_asset_id' => $this->asset->id,
            'goal_ids' => [$goal->id],
            'context' => ['subject' => 'Landing page'],
            'situation_summary' => 'Conversion rate was below target for DE market.',
            'situation_period_start' => now()->subDays(30),
            'situation_period_end' => now()->subDays(15),
            'action_kind' => BrandExperienceActionKind::TaskCompleted->value,
            'action_summary' => 'Updated landing page CTA and form.',
            'action_task_id' => $task->id,
            'outcome_summary' => 'Higher conversion rate observed in follow-up period.',
            'outcome_observed_at' => now()->subDay(),
            'outcome_period_start' => now()->subDays(7),
            'outcome_period_end' => now()->subDay(),
            'outcome_clarity' => BrandExperienceOutcomeClarity::Favorable->value,
            'evidence_links' => [
                ['role' => BrandExperienceEvidenceRole::Situation->value, 'evidence_id' => $situation->id, 'evidence_fingerprint' => 'situation-fp-1'],
                ['role' => BrandExperienceEvidenceRole::Baseline->value, 'evidence_id' => $situation->id, 'evidence_fingerprint' => 'situation-fp-1'],
                ['role' => BrandExperienceEvidenceRole::Outcome->value, 'evidence_id' => $outcome->id, 'evidence_fingerprint' => 'outcome-fp-1'],
                ['role' => BrandExperienceEvidenceRole::FollowUp->value, 'evidence_id' => $outcome->id, 'evidence_fingerprint' => 'outcome-fp-1'],
            ],
        ], $this->user, 'idem-confirmed-1');

        $this->assertSame(BrandExperienceStatus::Confirmed, $experience->status);
        $this->assertSame((int) $this->customer->id, (int) $experience->customer_id);
        $this->assertSame((int) $this->brand->id, (int) $experience->brand_id);
        $this->assertNotNull($experience->currentRevision);
        $this->assertSame(BrandExperienceCausalityStatus::CausalityNotEstablished, $experience->currentRevision->causality_status);
        $this->assertContains($experience->currentRevision->support_status, [
            BrandExperienceSupportStatus::Sufficient,
            BrandExperienceSupportStatus::Partial,
        ]);
        $this->assertFalse(isset($experience->currentRevision->quality_assessment['score']));
        $this->assertSame(1, $experience->currentRevision->goals()->count());
    }

    public function test_idempotent_create_does_not_duplicate(): void
    {
        $input = $this->externalActionInput();
        $a = $this->service->createDraft($input, $this->user, 'idem-dup');
        $b = $this->service->createDraft($input, $this->user, 'idem-dup');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, BrandExperience::query()->count());
    }

    public function test_wrong_customer_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'customer_id' => Customer::factory()->create()->id,
        ]), $this->user);
    }

    public function test_cross_brand_goal_rejected(): void
    {
        $other = Brand::factory()->create(['customer_id' => $this->customer->id]);
        $foreignGoal = BrandGoal::query()->create([
            'brand_id' => $other->id,
            'kind' => GoalKind::Business,
            'label' => 'Other brand goal',
            'normalized_key' => 'other-brand-goal',
            'status' => 'active',
            'applicability_mode' => 'brand_wide',
            'sort_order' => 1,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'goal_ids' => [$foreignGoal->id],
        ]), $this->user);
    }

    public function test_cross_brand_evidence_rejected(): void
    {
        $otherBrand = Brand::factory()->create();
        $otherAsset = DigitalAsset::factory()->create(['brand_id' => $otherBrand->id]);
        $foreignEvidence = Evidence::factory()->create([
            'digital_asset_id' => $otherAsset->id,
            'evidence_fingerprint' => 'foreign-fp',
            'is_canonical' => true,
            'definition_id' => 'test.def',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'evidence_links' => [[
                'role' => BrandExperienceEvidenceRole::Situation->value,
                'evidence_id' => $foreignEvidence->id,
                'evidence_fingerprint' => 'foreign-fp',
            ]],
        ]), $this->user);
    }

    public function test_recommendation_accepted_alone_is_not_action(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'recommendation_accepted_as_action' => true,
        ]), $this->user);
    }

    public function test_open_task_cannot_be_action_provenance(): void
    {
        $task = Task::factory()->create([
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'status' => TaskStatus::OPEN,
            'completed_at' => null,
            'recommendation_id' => null,
            'source_kind' => 'direct',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'action_kind' => BrandExperienceActionKind::TaskCompleted->value,
            'action_task_id' => $task->id,
            'external_action_confirmed' => false,
        ]), $this->user);
    }

    public function test_outcome_before_action_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'action_occurred_at' => now()->subDay(),
            'outcome_observed_at' => now()->subDays(3),
        ]), $this->user);
    }

    public function test_missing_follow_up_cannot_become_no_change(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'infer_no_change_from_missing_follow_up' => true,
        ]), $this->user);
    }

    public function test_no_action_caused_outcome_field(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'action_caused_outcome' => true,
        ]), $this->user);
    }

    public function test_no_magic_quality_score_accepted(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'quality_score' => 87,
        ]), $this->user);
    }

    public function test_historical_market_preserved_when_current_changes(): void
    {
        $experience = $this->service->createConfirmed(array_merge($this->externalActionInput(), [
            'market_code' => 'DE',
        ]), $this->user);

        $this->brand->forceFill(['primary_country' => 'NL'])->save();

        $fresh = $this->read->getByIdForBrand(
            new BrandMemoryScope((int) $this->customer->id, (int) $this->brand->id),
            (int) $experience->id,
        );

        $this->assertSame('DE', $fresh?->currentRevision?->market_code);
        $this->assertSame('NL', $this->brand->fresh()->primary_country);
    }

    public function test_negative_outcome_can_be_confirmed(): void
    {
        $goal = $this->goal('Grow leads');
        $experience = $this->service->createConfirmed(array_merge($this->externalActionInput(), [
            'goal_ids' => [$goal->id],
            'outcome_clarity' => BrandExperienceOutcomeClarity::Unfavorable->value,
            'outcome_summary' => 'Lead volume declined in the follow-up window.',
        ]), $this->user);

        $this->assertSame(BrandExperienceStatus::Confirmed, $experience->status);
        $this->assertSame(BrandExperienceOutcomeClarity::Unfavorable, $experience->currentRevision->outcome_clarity);
    }

    public function test_brand_isolation_on_read_and_memory_provider(): void
    {
        $confirmed = $this->service->createConfirmed($this->externalActionInput(), $this->user, 'iso-a');

        $brandB = Brand::factory()->create(['customer_id' => $this->customer->id, 'sector' => 'dental']);
        $scopeA = new BrandMemoryScope((int) $this->customer->id, (int) $this->brand->id);
        $scopeB = new BrandMemoryScope((int) $this->customer->id, (int) $brandB->id);

        $this->assertNotNull($this->read->getByIdForBrand($scopeA, (int) $confirmed->id));
        $this->assertNull($this->read->getByIdForBrand($scopeB, (int) $confirmed->id));

        $provider = app(BrandMemoryContextProvider::class);
        $this->assertInstanceOf(ExperienceBrandMemoryContextProvider::class, $provider);
        $refsA = $provider->listApplicableReferences($scopeA);
        $refsB = $provider->listApplicableReferences($scopeB);
        $this->assertNotEmpty($refsA);
        $this->assertSame([], $refsB);
    }

    public function test_no_automatic_experience_from_task_completion_listeners(): void
    {
        $before = BrandExperience::query()->count();
        $this->completedTask();
        $this->assertSame($before, BrandExperience::query()->count());
    }

    public function test_ai_cannot_write_trusted_experience(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertAiCannotWrite();
    }

    public function test_supersession_retains_history(): void
    {
        $prior = $this->service->createConfirmed($this->externalActionInput(), $this->user, 'sup-1');
        $replacement = $this->service->supersede($prior, array_merge($this->externalActionInput(), [
            'situation_summary' => 'Corrected situation summary after review.',
            'action_summary' => 'Corrected action summary.',
            'outcome_summary' => 'Corrected later observation.',
        ]), $this->user, 'sup-2');

        $prior->refresh();
        $this->assertSame(BrandExperienceStatus::Superseded, $prior->status);
        $this->assertSame($replacement->id, $prior->superseded_by_experience_id);
        $this->assertSame($prior->id, $replacement->supersedes_experience_id);
        $this->assertSame(BrandExperienceStatus::Confirmed, $replacement->status);
    }

    public function test_prompt53_contribution_candidate_not_sector_usable(): void
    {
        $confirmed = $this->service->createConfirmed($this->externalActionInput(), $this->user);
        $draft = $this->service->createDraft($this->externalActionInput(), $this->user);

        $builder = app(BrandExperienceSectorContributionBuilder::class);
        $candidate = $builder->fromExperience($confirmed);
        $this->assertNotNull($candidate);
        $this->assertTrue($candidate->structurallyEligibleForConsideration);
        $this->assertFalse($candidate->toArray()['privacy_qualified']);
        $this->assertFalse($candidate->toArray()['sector_usable_now']);

        $safe = $candidate->toConsumerSafeArray();
        $this->assertArrayNotHasKey('contributor_brand_id_internal', $safe);
        $this->assertArrayNotHasKey('contributor_customer_id_internal', $safe);

        $draftCandidate = $builder->fromExperience($draft);
        $this->assertNotNull($draftCandidate);
        $this->assertFalse($draftCandidate->structurallyEligibleForConsideration);
    }

    public function test_invalidated_experience_not_in_memory_provider(): void
    {
        $experience = $this->service->createConfirmed($this->externalActionInput(), $this->user);
        $this->service->invalidate($experience);

        $refs = app(BrandMemoryContextProvider::class)->listApplicableReferences(
            new BrandMemoryScope((int) $this->customer->id, (int) $this->brand->id),
        );
        $this->assertSame([], $refs);
    }

    public function test_channel_rejects_free_text(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->createDraft(array_merge($this->externalActionInput(), [
            'channel' => 'SEO',
        ]), $this->user);
    }

    public function test_offering_stable_id_and_snapshot(): void
    {
        $offering = BrandOffering::query()->create([
            'brand_id' => $this->brand->id,
            'status' => 'active',
            'priority_rank' => 1,
        ]);
        BrandOfferingName::query()->create([
            'brand_id' => $this->brand->id,
            'brand_offering_id' => $offering->id,
            'raw_label' => 'Dental Implant',
            'normalized_key' => 'dental-implant',
            'is_primary' => true,
            'is_active' => true,
            'name_kind' => 'primary',
            'provenance' => 'primary_operator',
            'normalization_version' => 'v1',
        ]);

        $experience = $this->service->createConfirmed(array_merge($this->externalActionInput(), [
            'offering_ids' => [$offering->id],
        ]), $this->user);

        $row = $experience->currentRevision->offerings()->first();
        $this->assertSame($offering->id, $row?->brand_offering_id);
        $this->assertSame('Dental Implant', $row?->offering_label_snapshot);

        BrandOfferingName::query()->where('brand_offering_id', $offering->id)->update(['raw_label' => 'Implantology']);
        $experience->refresh()->load('currentRevision.offerings');
        $this->assertSame('Dental Implant', $experience->currentRevision->offerings()->first()?->offering_label_snapshot);
    }

    public function test_no_generic_memories_table_still(): void
    {
        $this->assertFalse(Schema::hasTable('memories'));
        $this->assertFalse(Schema::hasTable('memory_embeddings'));
        $this->assertTrue(Schema::hasTable('brand_experiences'));
        $this->assertTrue(Schema::hasTable('brand_experience_revisions'));
    }

    /**
     * @return array<string, mixed>
     */
    private function externalActionInput(): array
    {
        return [
            'brand_id' => $this->brand->id,
            'channel' => BrandExperienceChannel::Website->value,
            'digital_asset_id' => $this->asset->id,
            'context' => ['subject' => 'Campaign adjustment'],
            'situation_summary' => 'Paid search CPA was elevated versus prior period.',
            'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed->value,
            'action_summary' => 'Paused underperforming ad group and reallocated budget.',
            'action_occurred_at' => now()->subDays(10),
            'external_action_confirmed' => true,
            'outcome_summary' => 'CPA decreased during the defined follow-up period.',
            'outcome_observed_at' => now()->subDays(2),
            'outcome_clarity' => BrandExperienceOutcomeClarity::FactualState->value,
            'quality_hints' => [
                'operator_observation_only' => true,
            ],
        ];
    }

    private function completedTask(): Task
    {
        return Task::factory()->create([
            'customer_id' => $this->customer->id,
            'brand_id' => $this->brand->id,
            'digital_asset_id' => $this->asset->id,
            'status' => TaskStatus::COMPLETED,
            'completed_at' => now()->subDays(5),
            'completed_by_id' => $this->user->id,
            'recommendation_id' => null,
            'source_kind' => 'direct',
        ]);
    }

    private function evidenceForBrand(string $fingerprint): Evidence
    {
        return Evidence::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'evidence_fingerprint' => $fingerprint,
            'is_canonical' => true,
            'definition_id' => 'test.brand_experience',
            'observed_at' => now()->subDays(3),
        ]);
    }

    private function goal(string $label): BrandGoal
    {
        return app(BrandGoalService::class)->create(
            brand: $this->brand,
            kind: GoalKind::Business,
            label: $label,
            actor: $this->user,
            recordActivity: false,
        );
    }
}
