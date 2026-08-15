<?php

namespace App\Services\RecurringReviews;

use App\Enums\OpportunityDetectionState;
use App\Enums\OpportunityOrigin;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\Evidence;
use App\Models\Opportunity;
use App\Models\RecurringReviewRunItem;
use App\Models\User;
use App\Services\Opportunities\OpportunityFingerprintBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Operator Opportunity from a review check. Never closes Opportunities. Never creates Tasks/Recommendations.
 * No magic score.
 */
final class CreateOpportunityFromReviewCheck
{
    public function __construct(
        private readonly RecurringReviewEvidencePublisher $evidencePublisher,
    ) {}

    /**
     * @return array{opportunity: Opportunity, evidence: Evidence}
     */
    public function __invoke(RecurringReviewRunItem $item, ?User $actor = null): array
    {
        return $this->create($item, $actor);
    }

    /**
     * @return array{opportunity: Opportunity, evidence: Evidence}
     */
    public function create(RecurringReviewRunItem $item, ?User $actor = null): array
    {
        $item->loadMissing('run');
        $run = $item->run;
        if ($run === null || $run->digital_asset_id === null) {
            throw new RecurringReviewValidationException('DIGITAL_ASSET_REQUIRED', 'Opportunity outcome requires a digital_asset on the review run.');
        }

        $evidence = $this->evidencePublisher->publish($item, 'opportunity', $actor);
        if (! $evidence instanceof Evidence) {
            throw new RecurringReviewValidationException('EVIDENCE_REQUIRED', 'Opportunity outcome requires published Evidence.');
        }

        $ruleId = filled($item->opportunity_rule_stable_id_snapshot)
            ? (string) $item->opportunity_rule_stable_id_snapshot
            : 'rr.check.'.$item->check_definition_id;

        $semantic = $this->semanticFingerprint(
            $ruleId,
            (int) $run->customer_id,
            $run->brand_id !== null ? (int) $run->brand_id : null,
            (int) $run->digital_asset_id,
            (int) $item->check_definition_id,
        );
        $fingerprint = $ruleId.':'.$semantic;

        try {
            $opportunity = DB::transaction(function () use ($item, $run, $ruleId, $semantic, $fingerprint): Opportunity {
                $opportunity = Opportunity::query()
                    ->where('fingerprint', $fingerprint)
                    ->lockForUpdate()
                    ->first();

                $now = now();

                if ($opportunity instanceof Opportunity) {
                    $opportunity->forceFill([
                        'last_detected_at' => $now,
                        'detection_state' => OpportunityDetectionState::Detected->value,
                    ])->save();

                    return $opportunity->fresh() ?? $opportunity;
                }

                return Opportunity::query()->create([
                    'customer_id' => $run->customer_id,
                    'brand_id' => $run->brand_id,
                    'digital_asset_id' => $run->digital_asset_id,
                    'origin' => OpportunityOrigin::Operator->value,
                    'rule_id' => $ruleId,
                    'rule_version' => 1,
                    'fingerprint' => $fingerprint,
                    'semantic_fingerprint' => $semantic,
                    'subject_kind' => 'digital_asset',
                    'subject_id' => (string) $run->digital_asset_id,
                    'category' => 'recurring_review',
                    'status' => Opportunity::STATUS_OPEN,
                    'detection_state' => OpportunityDetectionState::Detected->value,
                    'qualitative_priority' => 'medium',
                    'service_definition_code' => null,
                    'commercial_scope_state' => null,
                    'title' => $item->title_snapshot,
                    'description' => $item->description_snapshot,
                    'brand_goal_id' => null,
                    'brand_offering_id' => null,
                    'market_location' => null,
                    'market_language' => null,
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'closed_at' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $opportunity = Opportunity::query()->where('fingerprint', $fingerprint)->firstOrFail();
            $opportunity->forceFill([
                'last_detected_at' => now(),
                'detection_state' => OpportunityDetectionState::Detected->value,
            ])->save();

            $opportunity = $opportunity->fresh() ?? $opportunity;
        }

        return [
            'opportunity' => $opportunity,
            'evidence' => $evidence,
        ];
    }

    private function semanticFingerprint(
        string $ruleId,
        int $customerId,
        ?int $brandId,
        int $digitalAssetId,
        int $checkDefinitionId,
    ): string {
        $inputs = [
            'stable_rule_id' => $ruleId,
            'customer_id' => (string) $customerId,
            'brand_id' => (string) ($brandId ?? ''),
            'digital_asset_id' => (string) $digitalAssetId,
            'subject_kind' => 'digital_asset',
            'subject_id' => (string) $digitalAssetId,
            'check_definition_id' => (string) $checkDefinitionId,
        ];
        ksort($inputs);

        return hash('sha256', json_encode([
            'version' => OpportunityFingerprintBuilder::VERSION,
            'inputs' => $inputs,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
