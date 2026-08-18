<?php

namespace App\Services\RecurringReviews;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\FindingConditionState;
use App\Enums\FindingEligibilityDisposition;
use App\Enums\FindingLifecycleAction;
use App\Enums\FindingOrigin;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\FindingEvaluation;
use App\Models\RecurringReviewRunItem;
use App\Models\User;
use App\Services\DomainEvents\DomainEventEmitter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Operator Finding from a review check. Never resolves Findings. Never creates Tasks/Recommendations.
 */
final class CreateFindingFromReviewCheck
{
    public const string SOURCE_MODULE = 'recurring-review';

    public function __construct(
        private readonly RecurringReviewEvidencePublisher $evidencePublisher,
        private readonly DomainEventEmitter $domainEvents,
    ) {}

    /**
     * @return array{finding: Finding, evidence: Evidence}
     */
    public function __invoke(RecurringReviewRunItem $item, ?User $actor = null): array
    {
        return $this->create($item, $actor);
    }

    /**
     * @return array{finding: Finding, evidence: Evidence}
     */
    public function create(RecurringReviewRunItem $item, ?User $actor = null): array
    {
        $item->loadMissing('run');
        $run = $item->run;
        if ($run === null || $run->digital_asset_id === null) {
            throw new RecurringReviewValidationException('DIGITAL_ASSET_REQUIRED', 'Finding outcome requires a digital_asset on the review run.');
        }

        $evidence = $this->evidencePublisher->publish($item, 'finding', $actor);
        if (! $evidence instanceof Evidence) {
            throw new RecurringReviewValidationException('EVIDENCE_REQUIRED', 'Finding outcome requires published Evidence.');
        }

        $ruleId = filled($item->finding_rule_stable_id_snapshot)
            ? (string) $item->finding_rule_stable_id_snapshot
            : 'rr.check.'.$item->check_definition_id;

        // PER_DIGITAL_ASSET-style uniqueness: fingerprint equals stable rule id.
        $fingerprint = $ruleId;

        $evaluationFingerprint = hash('sha256', json_encode([
            'finding_fingerprint' => $fingerprint,
            'evidence_fingerprint' => $evidence->evidence_fingerprint,
            'run_item_id' => (string) $item->id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            $finding = DB::transaction(function () use ($item, $run, $evidence, $ruleId, $fingerprint, $evaluationFingerprint, $actor): Finding {
                $finding = Finding::query()
                    ->where('digital_asset_id', $run->digital_asset_id)
                    ->where('fingerprint', $fingerprint)
                    ->lockForUpdate()
                    ->first();

                $now = now();
                $created = false;

                if ($finding instanceof Finding) {
                    // Existing open (or any) finding: update last_seen_at only — never resolve, never duplicate.
                    $finding->forceFill([
                        'last_seen_at' => $now,
                        'last_run_id' => $evidence->run_id,
                    ])->save();
                } else {
                    $finding = Finding::query()->create([
                        'digital_asset_id' => $run->digital_asset_id,
                        'customer_id' => $run->customer_id,
                        'brand_id' => $run->brand_id,
                        'source_module' => self::SOURCE_MODULE,
                        'origin' => FindingOrigin::Operator->value,
                        'rule_id' => $ruleId,
                        'rule_version' => 1,
                        'fingerprint' => $fingerprint,
                        'semantic_fingerprint' => $fingerprint,
                        'subject_kind' => 'digital_asset',
                        'subject_id' => (string) $run->digital_asset_id,
                        'category' => 'recurring_review',
                        'severity' => 'medium',
                        'title' => $item->title_snapshot,
                        'summary' => $item->description_snapshot,
                        'confidence' => 1,
                        'status' => Finding::STATUS_OPEN,
                        'condition_state' => FindingConditionState::True->value,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'last_run_id' => $evidence->run_id,
                        'resolved_at' => null,
                    ]);
                    $created = true;
                }

                $this->ensureEvaluation($finding, $evidence, $ruleId, $evaluationFingerprint);
                $finding = $finding->fresh() ?? $finding;

                if ($created) {
                    $this->domainEvents->emit([
                        'event_type' => DomainEventType::FindingCreated,
                        'actor_kind' => DomainEventActorKind::InternalUser,
                        'actor_user_id' => $actor?->id,
                        'customer_id' => $finding->customer_id,
                        'brand_id' => $finding->brand_id,
                        'digital_asset_id' => $finding->digital_asset_id,
                        'subject_kind' => DomainEventSubjectKind::Finding,
                        'subject_id' => (int) $finding->id,
                        'payload' => [
                            'title' => (string) $finding->title,
                            'severity' => (string) $finding->severity,
                            'status' => (string) $finding->status,
                        ],
                    ]);
                }

                return $finding;
            });
        } catch (UniqueConstraintViolationException) {
            $finding = Finding::query()
                ->where('digital_asset_id', $run->digital_asset_id)
                ->where('fingerprint', $fingerprint)
                ->firstOrFail();

            $finding->forceFill([
                'last_seen_at' => now(),
                'last_run_id' => $evidence->run_id,
            ])->save();

            $this->ensureEvaluation($finding, $evidence, $ruleId, $evaluationFingerprint);

            $finding = $finding->fresh() ?? $finding;
        }

        return [
            'finding' => $finding,
            'evidence' => $evidence,
        ];
    }

    private function ensureEvaluation(
        Finding $finding,
        Evidence $evidence,
        string $ruleId,
        string $evaluationFingerprint,
    ): FindingEvaluation {
        $existing = FindingEvaluation::query()
            ->where('evaluation_fingerprint', $evaluationFingerprint)
            ->first();

        if ($existing instanceof FindingEvaluation) {
            if (! $existing->evidence()->where('evidence.id', $evidence->id)->exists()) {
                $existing->evidence()->attach($evidence->id, [
                    'evidence_observation_fingerprint' => (string) $evidence->evidence_fingerprint,
                ]);
            }

            return $existing;
        }

        try {
            $evaluation = FindingEvaluation::query()->create([
                'finding_id' => $finding->id,
                'rule_id' => $ruleId,
                'rule_version' => 1,
                'evaluation_fingerprint' => $evaluationFingerprint,
                'condition_result' => FindingConditionState::True->value,
                'eligibility_disposition' => FindingEligibilityDisposition::Eligible->value,
                'block_reason' => null,
                'evaluated_at' => now(),
                'operand_snapshot' => [
                    'source' => 'recurring_review_check',
                    'run_item_id' => null,
                ],
                'threshold_snapshot' => [],
                'freshness_state' => null,
                'integrity_state' => null,
                'completeness_state' => 'complete',
                'lifecycle_action' => FindingLifecycleAction::Created->value,
                'run_id' => $evidence->run_id,
            ]);

            $evaluation->evidence()->attach($evidence->id, [
                'evidence_observation_fingerprint' => (string) $evidence->evidence_fingerprint,
            ]);

            $finding->forceFill(['latest_evaluation_id' => $evaluation->id])->save();

            return $evaluation;
        } catch (UniqueConstraintViolationException) {
            return FindingEvaluation::query()
                ->where('evaluation_fingerprint', $evaluationFingerprint)
                ->firstOrFail();
        }
    }
}
