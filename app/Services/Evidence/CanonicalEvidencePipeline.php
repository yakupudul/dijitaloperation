<?php

namespace App\Services\Evidence;

use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Support\Evidence\CanonicalEvidencePipelineResult;
use App\Support\Evidence\EvidencePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Canonical Evidence pipeline:
 * Provider/Website fact → normalized fact → Definition → eligibility → candidate → fingerprint → Evidence.
 *
 * Collection completion does not invoke this. DatasetExecutors must not call it.
 */
final class CanonicalEvidencePipeline
{
    public const string MODULE_ID = 'evidence-canonicalization';

    public function __construct(
        private readonly EvidenceDefinitionRegistry $definitions,
        private readonly EvidenceEligibilityService $eligibility,
        private readonly EvidenceCandidateBuilder $candidates,
        private readonly CanonicalEvidenceWriter $writer,
    ) {}

    /**
     * @param  list<string>|null  $definitionIds
     */
    public function canonicalizeAsset(
        DigitalAsset $asset,
        ?User $actor = null,
        ?EvidencePeriod $period = null,
        ?int $brandGoalId = null,
        ?int $brandOfferingId = null,
        ?array $definitionIds = null,
    ): CanonicalEvidencePipelineResult {
        $asset = DigitalAsset::query()->with('brand')->findOrFail($asset->id);
        if ($asset->brand === null) {
            throw ValidationException::withMessages(['asset' => 'Digital Asset must belong to a Brand.']);
        }

        $findingsBefore = Finding::query()->count();
        $recommendationsBefore = Recommendation::query()->count();
        $tasksBefore = Task::query()->count();

        $result = DB::transaction(function () use (
            $asset,
            $actor,
            $period,
            $brandGoalId,
            $brandOfferingId,
            $definitionIds,
        ): CanonicalEvidencePipelineResult {
            $run = Run::query()->create([
                'digital_asset_id' => $asset->id,
                'module_id' => self::MODULE_ID,
                'status' => 'running',
                'started_at' => now(),
                'metadata' => [
                    'actor_user_id' => $actor?->id,
                    'brand_goal_id' => $brandGoalId,
                    'brand_offering_id' => $brandOfferingId,
                    'pipeline' => 'canonical_evidence',
                    'generated_by_ai' => false,
                ],
            ]);

            $written = [];
            $ineligible = [];
            $created = 0;
            $updated = 0;

            foreach ($this->definitions->all() as $definition) {
                if ($definitionIds !== null && ! in_array($definition->id, $definitionIds, true)) {
                    continue;
                }

                $effectivePeriod = $period;
                if ($effectivePeriod === null) {
                    $mat = DatasetMaterialization::query()
                        ->where('dataset_id', $definition->datasetId)
                        ->where('digital_asset_id', $asset->id)
                        ->first();
                    $end = $mat?->coverage_end_date?->toDateString();
                    $effectivePeriod = $this->eligibility->periodFromCoverageEnd($definition, $end);
                    if ($effectivePeriod === null) {
                        $ineligible[] = [
                            'definition_id' => $definition->id,
                            'report' => [
                                'status' => 'ineligible_period',
                                'reason' => 'coverage_end_unknown',
                            ],
                        ];

                        continue;
                    }
                }

                $report = $this->eligibility->evaluate(
                    $asset,
                    $definition,
                    $effectivePeriod,
                    $brandGoalId,
                    $brandOfferingId,
                );

                if (! $report->isEligible()) {
                    $ineligible[] = [
                        'definition_id' => $definition->id,
                        'report' => $report->toArray(),
                    ];

                    continue;
                }

                $candidate = $this->candidates->build(
                    $asset,
                    $definition,
                    $effectivePeriod,
                    $report,
                    $brandGoalId,
                    $brandOfferingId,
                );

                $write = $this->writer->upsert($run, $candidate);
                $written[] = $write['evidence'];
                if ($write['created']) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $run->status = 'completed';
            $run->finished_at = now();
            $run->metadata = array_merge($run->metadata ?? [], [
                'created' => $created,
                'updated' => $updated,
                'ineligible' => count($ineligible),
            ]);
            $run->save();

            return new CanonicalEvidencePipelineResult($run, $written, $ineligible, $created, $updated);
        });

        if (
            Finding::query()->count() !== $findingsBefore
            || Recommendation::query()->count() !== $recommendationsBefore
            || Task::query()->count() !== $tasksBefore
        ) {
            throw new \RuntimeException('Canonical Evidence pipeline must not create Findings, Recommendations, or Tasks.');
        }

        return $result;
    }
}
