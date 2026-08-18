<?php

namespace App\Services\Evidence;

use App\Enums\EvidenceEligibilityStatus;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Evidence\EvidenceCandidate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Upserts canonical Evidence by (digital_asset_id, evidence_fingerprint).
 * Does not create Findings, Opportunities, Recommendations, Tasks, or AI calls.
 */
final class CanonicalEvidenceWriter
{
    public function __construct(
        private readonly EvidenceIdentityFingerprint $fingerprints,
    ) {}

    /**
     * @return array{evidence: Evidence, created: bool}
     */
    public function upsert(Run $run, EvidenceCandidate $candidate): array
    {
        $fingerprint = $this->fingerprints->make($candidate->fingerprintInputs);

        try {
            return DB::transaction(function () use ($run, $candidate, $fingerprint): array {
                $existing = Evidence::query()
                    ->where('digital_asset_id', $candidate->asset->id)
                    ->where('evidence_fingerprint', $fingerprint)
                    ->first();

                if ($existing instanceof Evidence) {
                    $existing->fill($this->attributes($run, $candidate, $fingerprint));
                    $existing->save();

                    return ['evidence' => $existing->fresh() ?? $existing, 'created' => false];
                }

                $evidence = Evidence::query()->create($this->attributes($run, $candidate, $fingerprint));

                return ['evidence' => $evidence, 'created' => true];
            });
        } catch (UniqueConstraintViolationException) {
            $existing = Evidence::query()
                ->where('digital_asset_id', $candidate->asset->id)
                ->where('evidence_fingerprint', $fingerprint)
                ->firstOrFail();

            $existing->fill($this->attributes($run, $candidate, $fingerprint));
            $existing->save();

            return ['evidence' => $existing->fresh() ?? $existing, 'created' => false];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Run $run, EvidenceCandidate $candidate, string $fingerprint): array
    {
        return [
            'run_id' => $run->id,
            'digital_asset_id' => $candidate->asset->id,
            'source_module' => $candidate->definition->sourceModule,
            'type' => $candidate->definition->id,
            'definition_id' => $candidate->definition->id,
            'evidence_fingerprint' => $fingerprint,
            'is_canonical' => true,
            'eligibility_status' => EvidenceEligibilityStatus::Eligible->value,
            'collection_run_id' => $candidate->collectionRunId,
            'brand_goal_id' => $candidate->brandGoalId,
            'brand_offering_id' => $candidate->brandOfferingId,
            'is_derived' => false,
            'generated_by_ai' => false,
            'title' => $candidate->title,
            'payload' => $candidate->payload,
            'observed_at' => $candidate->observedAt,
        ];
    }
}
