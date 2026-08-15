<?php

namespace App\Services\RecurringReviews;

use App\Enums\EvidenceEligibilityStatus;
use App\Models\Evidence;
use App\Models\RecurringReviewRunItem;
use App\Models\Run;
use App\Models\User;
use App\Services\Evidence\EvidenceIdentityFingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Publishes bounded operator observation Evidence for a review check.
 * Zero provider calls. No confidential notes in payload.
 */
final class RecurringReviewEvidencePublisher
{
    public const string DEFINITION_ID = 'recurring_review.operator_observation';

    public const string SOURCE_MODULE = 'recurring-review';

    public function __construct(
        private readonly EvidenceIdentityFingerprint $fingerprints,
    ) {}

    public function publish(RecurringReviewRunItem $item, string $observationKind, ?User $actor = null): ?Evidence
    {
        unset($actor);

        $item->loadMissing('run');
        $run = $item->run;
        if ($run === null || $run->digital_asset_id === null) {
            return null;
        }

        $outcomeRevision = $item->updated_at?->timestamp !== null
            ? (string) $item->updated_at->timestamp
            : '1';

        $fingerprint = $this->fingerprints->make([
            'review_run_id' => (string) $run->id,
            'run_item_id' => (string) $item->id,
            'observation_kind' => $observationKind,
            'outcome_revision' => $outcomeRevision,
        ]);

        $collectionRun = $this->findOrCreateCollectionRun($run->digital_asset_id, $run->id, $item->id);

        $payload = [
            'outcome' => $observationKind,
            'check_definition_id' => $item->check_definition_id,
            'title_snapshot' => $item->title_snapshot,
            'schedule_id' => $run->schedule_id,
            'review_run_id' => $run->id,
            'run_item_id' => $item->id,
        ];

        $attributes = [
            'run_id' => $collectionRun->id,
            'digital_asset_id' => $run->digital_asset_id,
            'source_module' => self::SOURCE_MODULE,
            'type' => self::DEFINITION_ID,
            'definition_id' => self::DEFINITION_ID,
            'evidence_fingerprint' => $fingerprint,
            'is_canonical' => true,
            'eligibility_status' => EvidenceEligibilityStatus::Eligible->value,
            'is_derived' => false,
            'generated_by_ai' => false,
            'title' => 'Recurring review observation: '.$item->title_snapshot,
            'payload' => $payload,
            'observed_at' => now(),
        ];

        try {
            return DB::transaction(function () use ($run, $fingerprint, $attributes): Evidence {
                $existing = Evidence::query()
                    ->where('digital_asset_id', $run->digital_asset_id)
                    ->where('evidence_fingerprint', $fingerprint)
                    ->first();

                if ($existing instanceof Evidence) {
                    $existing->fill($attributes);
                    $existing->save();

                    return $existing->fresh() ?? $existing;
                }

                return Evidence::query()->create($attributes);
            });
        } catch (UniqueConstraintViolationException) {
            $existing = Evidence::query()
                ->where('digital_asset_id', $run->digital_asset_id)
                ->where('evidence_fingerprint', $fingerprint)
                ->firstOrFail();

            $existing->fill($attributes);
            $existing->save();

            return $existing->fresh() ?? $existing;
        }
    }

    private function findOrCreateCollectionRun(int $digitalAssetId, int $reviewRunId, int $runItemId): Run
    {
        $existing = Run::query()
            ->where('module_id', self::SOURCE_MODULE)
            ->where('digital_asset_id', $digitalAssetId)
            ->where('status', 'completed')
            ->where('metadata->review_run_id', $reviewRunId)
            ->where('metadata->run_item_id', $runItemId)
            ->first();

        if ($existing instanceof Run) {
            return $existing;
        }

        return Run::query()->create([
            'digital_asset_id' => $digitalAssetId,
            'module_id' => self::SOURCE_MODULE,
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [
                'review_run_id' => $reviewRunId,
                'run_item_id' => $runItemId,
                'source' => 'recurring_review_operator_observation',
            ],
        ]);
    }
}
