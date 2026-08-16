<?php

namespace App\Services\BrandExperiences;

use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceChannel;
use App\Enums\BrandExperienceEvidenceRole;
use App\Enums\BrandExperienceOrigin;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BrandExperienceEvidenceLink;
use App\Models\BrandExperienceGoal;
use App\Models\BrandExperienceOffering;
use App\Models\BrandExperienceRevision;
use App\Models\BrandGoal;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Support\BrandExperiences\BrandExperienceContextSnapshot;
use App\Support\BrandExperiences\Dto\BrandExperienceEvidenceQualityAssessment;
use App\Support\Options\CountryOptions;
use App\Support\Tasks\TaskStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Canonical Brand Experience write boundary.
 *
 * No provider calls, no AI calls, no auto-create from Task/Recommendation listeners,
 * no causality inference, no BusinessOutcome, no Sector aggregation.
 */
final class BrandExperienceService
{
    public function __construct(
        private readonly BrandExperienceEvidenceQualityEvaluator $qualityEvaluator,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function createDraft(array $input, ?User $actor = null, ?string $idempotencyKey = null): BrandExperience
    {
        return $this->persistNew($input, BrandExperienceStatus::Draft, $actor, $idempotencyKey);
    }

    /**
     * Create and confirm in one validated write when all confirmation rules pass.
     *
     * @param  array<string, mixed>  $input
     */
    public function createConfirmed(array $input, ?User $actor = null, ?string $idempotencyKey = null): BrandExperience
    {
        return $this->persistNew($input, BrandExperienceStatus::Confirmed, $actor, $idempotencyKey);
    }

    public function confirm(BrandExperience $experience, ?User $actor = null): BrandExperience
    {
        $experience = BrandExperience::query()->with('currentRevision.evidenceLinks')->findOrFail($experience->id);

        if ($experience->status === BrandExperienceStatus::Confirmed) {
            return $experience;
        }

        if (! in_array($experience->status, [BrandExperienceStatus::Draft], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft Experiences can be confirmed.',
            ]);
        }

        $revision = $experience->currentRevision;
        if (! $revision instanceof BrandExperienceRevision) {
            throw ValidationException::withMessages([
                'revision' => 'Experience has no current revision.',
            ]);
        }

        $this->assertConfirmationRules($experience, $revision);

        $experience->forceFill([
            'status' => BrandExperienceStatus::Confirmed,
            'recorded_by' => $experience->recorded_by ?? $actor?->id,
        ])->save();

        return $experience->fresh(['currentRevision']) ?? $experience;
    }

    /**
     * Material correction: new Experience that supersedes the prior confirmed one.
     *
     * @param  array<string, mixed>  $input
     */
    public function supersede(
        BrandExperience $prior,
        array $input,
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): BrandExperience {
        $prior = BrandExperience::query()->findOrFail($prior->id);

        if ($prior->status === BrandExperienceStatus::Invalidated) {
            throw ValidationException::withMessages([
                'status' => 'Invalidated Experiences cannot be superseded.',
            ]);
        }

        $input['customer_id'] = $prior->customer_id;
        $input['brand_id'] = $prior->brand_id;
        $input['supersedes_experience_id'] = $prior->id;

        $replacement = $this->persistNew(
            $input,
            BrandExperienceStatus::Confirmed,
            $actor,
            $idempotencyKey,
        );

        $prior->forceFill([
            'status' => BrandExperienceStatus::Superseded,
            'superseded_by_experience_id' => $replacement->id,
        ])->save();

        return $replacement;
    }

    public function invalidate(BrandExperience $experience, ?User $actor = null): BrandExperience
    {
        $experience = BrandExperience::query()->findOrFail($experience->id);

        if ($experience->status === BrandExperienceStatus::Invalidated) {
            return $experience;
        }

        $experience->forceFill([
            'status' => BrandExperienceStatus::Invalidated,
        ])->save();

        return $experience;
    }

    /**
     * Reject AI/agent direct trusted writes.
     */
    public function assertAiCannotWrite(): void
    {
        throw new InvalidArgumentException('AI cannot directly create or confirm trusted Brand Experience.');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function persistNew(
        array $input,
        BrandExperienceStatus $status,
        ?User $actor,
        ?string $idempotencyKey,
    ): BrandExperience {
        if ($idempotencyKey !== null) {
            $existing = BrandExperience::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof BrandExperience) {
                return $existing->load(['currentRevision']);
            }
        }

        $normalized = $this->normalizeAndValidate($input);

        if ($status === BrandExperienceStatus::Confirmed
            && ! $normalized['quality']->supportStatus->mayConfirm()) {
            throw ValidationException::withMessages([
                'evidence_quality' => 'Support status does not allow confirmation: '.$normalized['quality']->supportStatus->value,
            ]);
        }

        try {
            return DB::transaction(function () use ($normalized, $status, $actor, $idempotencyKey): BrandExperience {
                $experience = BrandExperience::query()->create([
                    'customer_id' => $normalized['customer_id'],
                    'brand_id' => $normalized['brand_id'],
                    'status' => $status,
                    'origin' => $normalized['origin'],
                    'recorded_by' => $actor?->id,
                    'idempotency_key' => $idempotencyKey,
                    'supersedes_experience_id' => $normalized['supersedes_experience_id'],
                ]);

                $revision = $this->insertRevision($experience, $normalized, 1, $actor, $idempotencyKey !== null ? $idempotencyKey.':rev1' : null);
                $experience->forceFill(['current_revision_id' => $revision->id])->save();

                return $experience->fresh(['currentRevision.goals', 'currentRevision.offerings', 'currentRevision.evidenceLinks']) ?? $experience;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = BrandExperience::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing instanceof BrandExperience) {
                    return $existing->load(['currentRevision']);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function insertRevision(
        BrandExperience $experience,
        array $normalized,
        int $revisionNumber,
        ?User $actor,
        ?string $revisionIdempotencyKey,
    ): BrandExperienceRevision {
        /** @var BrandExperienceEvidenceQualityAssessment $quality */
        $quality = $normalized['quality'];

        $revision = BrandExperienceRevision::query()->create([
            'brand_experience_id' => $experience->id,
            'revision_number' => $revisionNumber,
            'context_schema_version' => $normalized['context']->schemaVersion,
            'context_snapshot' => $normalized['context']->toArray(),
            'market_code' => $normalized['market_code'],
            'market_label' => $normalized['market_label'],
            'channel' => $normalized['channel']?->value,
            'digital_asset_id' => $normalized['digital_asset_id'],
            'situation_summary' => $normalized['situation_summary'],
            'situation_period_start' => $normalized['situation_period_start'],
            'situation_period_end' => $normalized['situation_period_end'],
            'situation_finding_id' => $normalized['situation_finding_id'],
            'situation_opportunity_id' => $normalized['situation_opportunity_id'],
            'action_kind' => $normalized['action_kind']->value,
            'action_summary' => $normalized['action_summary'],
            'action_occurred_at' => $normalized['action_occurred_at'],
            'action_task_id' => $normalized['action_task_id'],
            'action_recommendation_id' => $normalized['action_recommendation_id'],
            'outcome_summary' => $normalized['outcome_summary'],
            'outcome_observed_at' => $normalized['outcome_observed_at'],
            'outcome_period_start' => $normalized['outcome_period_start'],
            'outcome_period_end' => $normalized['outcome_period_end'],
            'outcome_clarity' => $normalized['outcome_clarity']->value,
            'support_status' => $quality->supportStatus->value,
            'quality_assessment' => $quality->toArray(),
            'quality_policy_version' => $quality->policyVersion,
            'quality_assessed_at' => now(),
            'causality_status' => BrandExperienceCausalityStatus::CausalityNotEstablished->value,
            'created_by' => $actor?->id,
            'idempotency_key' => $revisionIdempotencyKey,
        ]);

        foreach ($normalized['goal_rows'] as $row) {
            BrandExperienceGoal::query()->create([
                'brand_experience_revision_id' => $revision->id,
                'brand_goal_id' => $row['brand_goal_id'],
                'goal_label_snapshot' => $row['goal_label_snapshot'],
            ]);
        }

        foreach ($normalized['offering_rows'] as $row) {
            BrandExperienceOffering::query()->create([
                'brand_experience_revision_id' => $revision->id,
                'brand_offering_id' => $row['brand_offering_id'],
                'offering_label_snapshot' => $row['offering_label_snapshot'],
            ]);
        }

        foreach ($normalized['evidence_links'] as $link) {
            BrandExperienceEvidenceLink::query()->create([
                'brand_experience_revision_id' => $revision->id,
                'evidence_id' => $link['evidence_id'],
                'evidence_fingerprint' => $link['evidence_fingerprint'],
                'role' => $link['role'],
            ]);
        }

        return $revision;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeAndValidate(array $input): array
    {
        if (array_key_exists('action_caused_outcome', $input)) {
            throw ValidationException::withMessages([
                'causality' => 'action_caused_outcome is forbidden.',
            ]);
        }

        foreach (['quality_score', 'confidence_score', 'experience_score', 'learning_score'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw ValidationException::withMessages([
                    $forbidden => 'Magic numeric scores are forbidden.',
                ]);
            }
        }

        $brandId = (int) ($input['brand_id'] ?? 0);
        $brand = Brand::query()->find($brandId);
        if (! $brand instanceof Brand) {
            throw ValidationException::withMessages(['brand_id' => 'Brand not found.']);
        }

        $customerId = (int) ($brand->customer_id);
        if (isset($input['customer_id']) && (int) $input['customer_id'] !== $customerId) {
            throw ValidationException::withMessages(['customer_id' => 'Customer does not match Brand tenancy.']);
        }

        $digitalAssetId = isset($input['digital_asset_id']) ? (int) $input['digital_asset_id'] : null;
        if ($digitalAssetId !== null) {
            $asset = DigitalAsset::query()->find($digitalAssetId);
            if (! $asset instanceof DigitalAsset || (int) $asset->brand_id !== $brandId) {
                throw ValidationException::withMessages(['digital_asset_id' => 'DigitalAsset must belong to Brand.']);
            }
        }

        $channel = null;
        if (isset($input['channel']) && $input['channel'] !== null && $input['channel'] !== '') {
            $channel = BrandExperienceChannel::tryFrom((string) $input['channel']);
            if ($channel === null) {
                throw ValidationException::withMessages(['channel' => 'Channel must be a canonical DigitalAsset type key.']);
            }
        }

        $marketCode = isset($input['market_code']) && $input['market_code'] !== ''
            ? strtoupper((string) $input['market_code'])
            : null;
        $marketLabel = null;
        if ($marketCode !== null) {
            if (! CountryOptions::isValid($marketCode)) {
                throw ValidationException::withMessages(['market_code' => 'Market must be a canonical country code.']);
            }
            $marketLabel = CountryOptions::label($marketCode);
            if (isset($input['market_label']) && is_string($input['market_label']) && $input['market_label'] !== '') {
                $marketLabel = $input['market_label'];
            }
        }

        $contextInput = is_array($input['context'] ?? null) ? $input['context'] : [];
        $contextInput['brand_id'] = $brandId;
        $contextInput['customer_id'] = $customerId;
        if ($digitalAssetId !== null) {
            $contextInput['digital_asset_id'] = $digitalAssetId;
        }
        $context = BrandExperienceContextSnapshot::fromArray($contextInput);
        if ($context->brandId !== $brandId || $context->customerId !== $customerId) {
            throw ValidationException::withMessages(['context' => 'Context Brand/Customer mismatch.']);
        }

        $situationSummary = trim((string) ($input['situation_summary'] ?? ''));
        $actionSummary = trim((string) ($input['action_summary'] ?? ''));
        $outcomeSummary = trim((string) ($input['outcome_summary'] ?? ''));
        if ($situationSummary === '' || $actionSummary === '' || $outcomeSummary === '') {
            throw ValidationException::withMessages([
                'summaries' => 'Situation, Action, and Outcome summaries are required.',
            ]);
        }
        foreach (['situation_summary' => $situationSummary, 'action_summary' => $actionSummary, 'outcome_summary' => $outcomeSummary] as $field => $value) {
            if (mb_strlen($value) > 2000) {
                throw ValidationException::withMessages([$field => 'Summary exceeds 2000 characters.']);
            }
        }

        $actionKind = BrandExperienceActionKind::tryFrom((string) ($input['action_kind'] ?? ''));
        if ($actionKind === null) {
            throw ValidationException::withMessages(['action_kind' => 'Invalid action kind.']);
        }

        // Recommendation acceptance alone is never enough.
        if (($input['recommendation_accepted_as_action'] ?? false) === true) {
            throw ValidationException::withMessages([
                'action' => 'Recommendation acceptance alone does not prove Action execution.',
            ]);
        }

        $actionTaskId = isset($input['action_task_id']) ? (int) $input['action_task_id'] : null;
        $actionRecommendationId = isset($input['action_recommendation_id']) ? (int) $input['action_recommendation_id'] : null;
        $actionOccurredAt = $input['action_occurred_at'] ?? null;
        $taskCompleted = false;

        if ($actionKind === BrandExperienceActionKind::TaskCompleted) {
            if ($actionTaskId === null) {
                throw ValidationException::withMessages(['action_task_id' => 'Completed Task is required for task_completed actions.']);
            }
            $task = Task::query()->find($actionTaskId);
            if (! $task instanceof Task
                || (int) $task->brand_id !== $brandId
                || (int) $task->customer_id !== $customerId) {
                throw ValidationException::withMessages(['action_task_id' => 'Task must belong to Customer/Brand.']);
            }
            if ($task->status !== TaskStatus::COMPLETED) {
                throw ValidationException::withMessages(['action_task_id' => 'Open/incomplete Task cannot be Action provenance.']);
            }
            if ($task->completed_at === null) {
                throw ValidationException::withMessages(['action_task_id' => 'Completed Task must have completed_at.']);
            }
            $taskCompleted = true;
            $actionOccurredAt = $actionOccurredAt ?? $task->completed_at;
            if ($task->recommendation_id !== null && $actionRecommendationId === null) {
                $actionRecommendationId = (int) $task->recommendation_id;
            }
        } elseif ($actionKind === BrandExperienceActionKind::ExternalOperatorConfirmed) {
            if ($actionOccurredAt === null) {
                throw ValidationException::withMessages(['action_occurred_at' => 'External Action requires occurred_at.']);
            }
            if (($input['external_action_confirmed'] ?? false) !== true) {
                throw ValidationException::withMessages([
                    'external_action_confirmed' => 'External Action requires explicit operator confirmation flag.',
                ]);
            }
        }

        if ($actionRecommendationId !== null) {
            $recommendation = Recommendation::query()->find($actionRecommendationId);
            if (! $recommendation instanceof Recommendation) {
                throw ValidationException::withMessages(['action_recommendation_id' => 'Recommendation not found.']);
            }
            // Recommendation is provenance/context only — never proof of execution by itself.
            $recBrandId = $this->recommendationBrandId($recommendation);
            if ($recBrandId !== null && $recBrandId !== $brandId) {
                throw ValidationException::withMessages(['action_recommendation_id' => 'Recommendation Brand mismatch.']);
            }
        }

        if ($actionOccurredAt === null) {
            throw ValidationException::withMessages(['action_occurred_at' => 'Action occurred_at is required.']);
        }

        $outcomeObservedAt = $input['outcome_observed_at'] ?? null;
        if ($outcomeObservedAt === null) {
            throw ValidationException::withMessages(['outcome_observed_at' => 'Outcome observed_at is required.']);
        }

        $actionAt = Carbon::parse($actionOccurredAt);
        $outcomeAt = Carbon::parse($outcomeObservedAt);
        if ($outcomeAt->lessThanOrEqualTo($actionAt)) {
            throw ValidationException::withMessages([
                'outcome_observed_at' => 'Later Outcome must occur after Action.',
            ]);
        }

        // Missing follow-up must never become no_change.
        if (($input['infer_no_change_from_missing_follow_up'] ?? false) === true) {
            throw ValidationException::withMessages([
                'outcome' => 'Missing follow-up data must not become no_change.',
            ]);
        }

        $outcomeClarity = BrandExperienceOutcomeClarity::tryFrom((string) ($input['outcome_clarity'] ?? BrandExperienceOutcomeClarity::Unclear->value));
        if ($outcomeClarity === null) {
            throw ValidationException::withMessages(['outcome_clarity' => 'Invalid outcome clarity.']);
        }

        if (in_array($outcomeClarity, [BrandExperienceOutcomeClarity::Favorable, BrandExperienceOutcomeClarity::Unfavorable], true)
            && empty($input['goal_ids'])
            && ($input['desired_direction_declared'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'outcome_clarity' => 'Favorable/Unfavorable requires Goal or explicit desired direction declaration.',
            ]);
        }

        $findingId = isset($input['situation_finding_id']) ? (int) $input['situation_finding_id'] : null;
        if ($findingId !== null) {
            $finding = Finding::query()->find($findingId);
            if (! $finding instanceof Finding || (int) $finding->brand_id !== $brandId) {
                throw ValidationException::withMessages(['situation_finding_id' => 'Finding must belong to Brand.']);
            }
        }

        $opportunityId = isset($input['situation_opportunity_id']) ? (int) $input['situation_opportunity_id'] : null;
        if ($opportunityId !== null) {
            $opportunity = Opportunity::query()->find($opportunityId);
            if (! $opportunity instanceof Opportunity || (int) $opportunity->brand_id !== $brandId) {
                throw ValidationException::withMessages(['situation_opportunity_id' => 'Opportunity must belong to Brand.']);
            }
        }

        $goalRows = [];
        foreach (array_values(array_unique(array_map('intval', $input['goal_ids'] ?? []))) as $goalId) {
            if ($goalId <= 0) {
                continue;
            }
            $goal = BrandGoal::query()->find($goalId);
            if (! $goal instanceof BrandGoal || (int) $goal->brand_id !== $brandId) {
                throw ValidationException::withMessages(['goal_ids' => "Goal {$goalId} must belong to Brand."]);
            }
            $goalRows[] = [
                'brand_goal_id' => $goal->id,
                'goal_label_snapshot' => (string) $goal->label,
            ];
        }

        $offeringRows = [];
        foreach (array_values(array_unique(array_map('intval', $input['offering_ids'] ?? []))) as $offeringId) {
            if ($offeringId <= 0) {
                continue;
            }
            $offering = BrandOffering::query()->with('primaryName')->find($offeringId);
            if (! $offering instanceof BrandOffering || (int) $offering->brand_id !== $brandId) {
                throw ValidationException::withMessages(['offering_ids' => "Offering {$offeringId} must belong to Brand."]);
            }
            $label = $offering->primaryName?->raw_label ?? ('Offering #'.$offering->id);
            $offeringRows[] = [
                'brand_offering_id' => $offering->id,
                'offering_label_snapshot' => (string) $label,
            ];
        }

        $evidenceLinks = $this->normalizeEvidenceLinks($input['evidence_links'] ?? [], $brandId);

        $qualityHints = is_array($input['quality_hints'] ?? null) ? $input['quality_hints'] : [];
        $quality = $this->qualityEvaluator->evaluate([
            'action_kind' => $actionKind,
            'action_occurred_at' => $actionAt,
            'outcome_observed_at' => $outcomeAt,
            'outcome_period_start' => $input['outcome_period_start'] ?? null,
            'outcome_period_end' => $input['outcome_period_end'] ?? null,
            'situation_period_start' => $input['situation_period_start'] ?? null,
            'situation_period_end' => $input['situation_period_end'] ?? null,
            'has_action_task' => $actionTaskId !== null,
            'action_task_completed' => $taskCompleted,
            'operator_observation_only' => (bool) ($qualityHints['operator_observation_only'] ?? ($evidenceLinks === [])),
            'conflicting' => (bool) ($qualityHints['conflicting'] ?? false),
            'period_mismatch' => (bool) ($qualityHints['period_mismatch'] ?? false),
            'currency_mismatch' => (bool) ($qualityHints['currency_mismatch'] ?? false),
            'attribution_mismatch' => (bool) ($qualityHints['attribution_mismatch'] ?? false),
            'provider_limited' => (bool) ($qualityHints['provider_limited'] ?? false),
            'follow_up_incomplete' => (bool) ($qualityHints['follow_up_incomplete'] ?? false),
        ], $evidenceLinks);

        $origin = BrandExperienceOrigin::tryFrom((string) ($input['origin'] ?? BrandExperienceOrigin::OperatorCaptured->value))
            ?? BrandExperienceOrigin::OperatorCaptured;

        return [
            'customer_id' => $customerId,
            'brand_id' => $brandId,
            'origin' => $origin,
            'supersedes_experience_id' => isset($input['supersedes_experience_id']) ? (int) $input['supersedes_experience_id'] : null,
            'context' => $context,
            'market_code' => $marketCode,
            'market_label' => $marketLabel,
            'channel' => $channel,
            'digital_asset_id' => $digitalAssetId,
            'situation_summary' => $situationSummary,
            'situation_period_start' => $input['situation_period_start'] ?? null,
            'situation_period_end' => $input['situation_period_end'] ?? null,
            'situation_finding_id' => $findingId,
            'situation_opportunity_id' => $opportunityId,
            'action_kind' => $actionKind,
            'action_summary' => $actionSummary,
            'action_occurred_at' => $actionAt,
            'action_task_id' => $actionTaskId,
            'action_recommendation_id' => $actionRecommendationId,
            'outcome_summary' => $outcomeSummary,
            'outcome_observed_at' => $outcomeAt,
            'outcome_period_start' => $input['outcome_period_start'] ?? null,
            'outcome_period_end' => $input['outcome_period_end'] ?? null,
            'outcome_clarity' => $outcomeClarity,
            'goal_rows' => $goalRows,
            'offering_rows' => $offeringRows,
            'evidence_links' => $evidenceLinks,
            'quality' => $quality,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|mixed  $links
     * @return list<array{role: string, evidence_id: int, evidence_fingerprint: string}>
     */
    private function normalizeEvidenceLinks(mixed $links, int $brandId): array
    {
        if (! is_array($links)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($links as $link) {
            if (! is_array($link)) {
                continue;
            }
            $role = BrandExperienceEvidenceRole::tryFrom((string) ($link['role'] ?? ''));
            if ($role === null) {
                throw ValidationException::withMessages(['evidence_links' => 'Invalid Evidence role.']);
            }
            $evidenceId = (int) ($link['evidence_id'] ?? 0);
            $evidence = Evidence::query()->with('digitalAsset')->find($evidenceId);
            if (! $evidence instanceof Evidence) {
                throw ValidationException::withMessages(['evidence_links' => "Evidence {$evidenceId} not found."]);
            }
            if ((int) ($evidence->digitalAsset?->brand_id) !== $brandId) {
                throw ValidationException::withMessages(['evidence_links' => 'Cross-Brand Evidence is forbidden.']);
            }
            $fingerprint = (string) ($link['evidence_fingerprint'] ?? $evidence->evidence_fingerprint ?? '');
            if ($fingerprint === '') {
                throw ValidationException::withMessages(['evidence_links' => 'Evidence fingerprint pinning is required.']);
            }
            if (filled($evidence->evidence_fingerprint) && $fingerprint !== (string) $evidence->evidence_fingerprint) {
                throw ValidationException::withMessages([
                    'evidence_links' => 'Evidence fingerprint must match the pinned historical revision.',
                ]);
            }

            $key = $evidenceId.':'.$role->value;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = [
                'role' => $role->value,
                'evidence_id' => $evidenceId,
                'evidence_fingerprint' => $fingerprint,
            ];
        }

        return $normalized;
    }

    private function assertConfirmationRules(BrandExperience $experience, BrandExperienceRevision $revision): void
    {
        if ($revision->outcome_observed_at === null || $revision->action_occurred_at === null) {
            throw ValidationException::withMessages(['temporal' => 'Action and Outcome times required.']);
        }
        if ($revision->outcome_observed_at->lessThanOrEqualTo($revision->action_occurred_at)) {
            throw ValidationException::withMessages(['temporal' => 'Outcome must follow Action.']);
        }
        if (! $revision->support_status instanceof BrandExperienceSupportStatus
            || ! $revision->support_status->mayConfirm()) {
            throw ValidationException::withMessages([
                'evidence_quality' => 'Evidence Quality does not permit confirmation.',
            ]);
        }
        if ($revision->causality_status !== BrandExperienceCausalityStatus::CausalityNotEstablished) {
            throw ValidationException::withMessages(['causality' => 'Invalid causality status.']);
        }
    }

    private function recommendationBrandId(Recommendation $recommendation): ?int
    {
        if ($recommendation->digital_asset_id !== null) {
            $asset = DigitalAsset::query()->find($recommendation->digital_asset_id);

            return $asset instanceof DigitalAsset ? (int) $asset->brand_id : null;
        }

        return null;
    }
}
