<?php

namespace App\Services\IntelligenceScheduling;

use App\Enums\AutomaticIntelligencePolicyStatus;
use App\Enums\Intelligence\AnalyzerEligibilityDisposition;
use App\Enums\Intelligence\AnalyzerKind;
use App\Enums\Intelligence\IntelligencePlanPhase;
use App\Enums\Intelligence\IntelligencePlanStatus;
use App\Enums\Intelligence\IntelligenceTriggerSource;
use App\Enums\Intelligence\IntelligenceTriggerStatus;
use App\Models\AutomaticIntelligencePolicy;
use App\Models\DigitalAsset;
use App\Models\IntelligenceExecutionPlan;
use App\Models\IntelligenceTrigger;
use Illuminate\Support\Facades\DB;

/**
 * Builds immutable, finite Intelligence Execution Plans (Prompt 63).
 * No Agent swarm. No LLM fan-out. Phases fixed before AI calls.
 */
final class IntelligenceSchedulingPlanner
{
    public function __construct(
        private readonly AnalyzerDependencyIndex $dependencies,
        private readonly AnalyzerEligibilityResolver $eligibility,
    ) {}

    public function planForTrigger(IntelligenceTrigger $trigger): IntelligenceExecutionPlan
    {
        $asset = DigitalAsset::query()->with('brand')->findOrFail($trigger->digital_asset_id);
        $refs = is_array($trigger->changed_evidence_refs) ? $trigger->changed_evidence_refs : [];
        $definitionIds = array_values(array_unique(array_map(
            static fn (array $r): string => (string) ($r['definition_id'] ?? ''),
            $refs,
        )));
        $definitionIds = array_values(array_filter($definitionIds, static fn (string $id): bool => $id !== ''));
        if ($definitionIds === [] && is_array($trigger->metadata['definition_ids'] ?? null)) {
            $definitionIds = array_map('strval', $trigger->metadata['definition_ids']);
        }

        $analyzers = [];
        $skipped = [];

        if ($trigger->source_kind === IntelligenceTriggerSource::FindingStateChanged) {
            $findingStable = (string) ($trigger->metadata['finding_rule_stable_id'] ?? '');
            $oppCandidates = $this->dependencies->opportunityRulesForChanges([], $findingStable !== '' ? [$findingStable] : []);
            foreach ($oppCandidates as $candidate) {
                $analyzers[] = $this->withEligibility($candidate, $asset, $definitionIds, $refs, null, $trigger->source_kind);
            }
        } else {
            foreach ($this->dependencies->findingRulesForEvidenceDefinitions($definitionIds) as $candidate) {
                $analyzers[] = $this->withEligibility($candidate, $asset, $definitionIds, $refs, null, $trigger->source_kind);
            }
            foreach ($this->dependencies->opportunityRulesForChanges($definitionIds, []) as $candidate) {
                $analyzers[] = $this->withEligibility($candidate, $asset, $definitionIds, $refs, null, $trigger->source_kind);
            }

            $policies = $this->activePoliciesForAsset($asset);
            $maxFanout = (int) config('moxdop-intelligence-scheduling.max_ai_fanout_per_plan', 3);
            $aiAdded = 0;
            foreach ($policies as $policy) {
                if ($aiAdded >= min($maxFanout, (int) $policy->max_fanout_per_plan)) {
                    $skipped[] = [
                        'kind' => AnalyzerKind::AiSkill->value,
                        'disposition' => AnalyzerEligibilityDisposition::AiBudgetBlocked->value,
                        'reason' => 'MAX_FANOUT',
                    ];
                    break;
                }

                $skillCandidates = [];
                if ($policy->trigger_on_required_evidence_change) {
                    $skillCandidates = array_merge(
                        $skillCandidates,
                        $this->dependencies->skillsForRequiredEvidenceChanges($definitionIds),
                    );
                }
                if ($policy->trigger_on_optional_evidence_change) {
                    $skillCandidates = array_merge(
                        $skillCandidates,
                        $this->dependencies->skillsForOptionalEvidenceChanges($definitionIds),
                    );
                }

                foreach ($skillCandidates as $candidate) {
                    if ((string) ($candidate['skill_signature'] ?? '') !== (string) $policy->skill_signature) {
                        continue;
                    }
                    $entry = $this->withEligibility($candidate, $asset, $definitionIds, $refs, $policy, $trigger->source_kind);
                    $entry['agent_slug'] = $policy->agent_slug;
                    $entry['agent_version'] = $policy->agent_version;
                    $entry['route_key'] = $policy->route_key;
                    $entry['route_signature'] = $policy->route_signature;
                    $entry['policy_id'] = (int) $policy->id;
                    $entry['policy_fingerprint'] = $policy->policy_fingerprint;
                    $entry['policy_version'] = (int) $policy->policy_version;
                    $analyzers[] = $entry;
                    if (($entry['disposition'] ?? null) === AnalyzerEligibilityDisposition::Eligible->value) {
                        $aiAdded++;
                    }
                }
            }
        }

        $eligible = array_values(array_filter(
            $analyzers,
            static fn (array $a): bool => ($a['disposition'] ?? null) === AnalyzerEligibilityDisposition::Eligible->value,
        ));
        $skipped = array_merge($skipped, array_values(array_filter(
            $analyzers,
            static fn (array $a): bool => ($a['disposition'] ?? null) !== AnalyzerEligibilityDisposition::Eligible->value,
        )));

        $phases = [
            IntelligencePlanPhase::FindingRules->value => array_values(array_filter(
                $eligible,
                static fn (array $a): bool => ($a['kind'] ?? null) === AnalyzerKind::FindingRule->value,
            )),
            IntelligencePlanPhase::OpportunityRules->value => array_values(array_filter(
                $eligible,
                static fn (array $a): bool => ($a['kind'] ?? null) === AnalyzerKind::OpportunityRule->value,
            )),
            IntelligencePlanPhase::AiSkills->value => array_values(array_filter(
                $eligible,
                static fn (array $a): bool => ($a['kind'] ?? null) === AnalyzerKind::AiSkill->value,
            )),
        ];

        if ($eligible === []) {
            $status = IntelligencePlanStatus::NoRelevantAnalyzer;
        } else {
            $status = IntelligencePlanStatus::Planned;
        }

        $planPayload = [
            'trigger_id' => (int) $trigger->id,
            'asset_id' => (int) $asset->id,
            'source_revision' => $trigger->source_revision_fingerprint,
            'phases' => $phases,
        ];
        $fingerprint = 'iplan:'.hash('sha256', json_encode($planPayload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($trigger, $asset, $fingerprint, $status, $phases, $skipped, $refs): IntelligenceExecutionPlan {
            $existing = IntelligenceExecutionPlan::query()->where('plan_fingerprint', $fingerprint)->first();
            if ($existing !== null) {
                if ($trigger->status === IntelligenceTriggerStatus::Pending) {
                    $trigger->status = IntelligenceTriggerStatus::Planned;
                    $trigger->planned_at = now();
                    $trigger->save();
                }

                return $existing;
            }

            // Coalesce pending plans for same asset (current-state analyzers).
            $pending = IntelligenceExecutionPlan::query()
                ->where('digital_asset_id', $asset->id)
                ->where('status', IntelligencePlanStatus::Planned)
                ->orderByDesc('id')
                ->get();
            foreach ($pending as $prior) {
                $prior->status = IntelligencePlanStatus::Superseded;
                $prior->finished_at = now();
                $prior->metadata = array_merge($prior->metadata ?? [], [
                    'superseded_by_fingerprint' => $fingerprint,
                ]);
                $prior->save();
                if ($prior->intelligence_trigger_id) {
                    IntelligenceTrigger::query()->whereKey($prior->intelligence_trigger_id)->update([
                        'status' => IntelligenceTriggerStatus::Superseded,
                        'completed_at' => now(),
                    ]);
                }
            }

            $plan = IntelligenceExecutionPlan::query()->create([
                'customer_id' => (int) $trigger->customer_id,
                'brand_id' => (int) $trigger->brand_id,
                'digital_asset_id' => (int) $asset->id,
                'intelligence_trigger_id' => (int) $trigger->id,
                'plan_fingerprint' => $fingerprint,
                'status' => $status,
                'current_phase' => null,
                'trigger_ids' => [(int) $trigger->id],
                'evidence_input_fingerprints' => array_map(
                    static fn (array $r): string => (string) ($r['analytical_fingerprint'] ?? ''),
                    $refs,
                ),
                'analyzers' => [
                    'phases' => $phases,
                    'skipped' => $skipped,
                    'finite_before_ai' => true,
                    'swarm' => false,
                ],
                'phase_results' => [],
                'metadata' => [
                    'source_kind' => $trigger->source_kind->value,
                    'source_revision_fingerprint' => $trigger->source_revision_fingerprint,
                ],
                'created_at' => now(),
            ]);

            $trigger->status = IntelligenceTriggerStatus::Planned;
            $trigger->planned_at = now();
            $trigger->save();

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<string>  $definitionIds
     * @param  list<array<string, mixed>>  $refs
     * @return array<string, mixed>
     */
    private function withEligibility(
        array $candidate,
        DigitalAsset $asset,
        array $definitionIds,
        array $refs,
        ?AutomaticIntelligencePolicy $policy,
        IntelligenceTriggerSource $source,
    ): array {
        $result = $this->eligibility->resolve($candidate, $asset, $definitionIds, $refs, $policy, $source);

        return array_merge($candidate, [
            'disposition' => $result['disposition']->value,
            'eligibility_reason' => $result['reason'],
        ]);
    }

    /**
     * @return list<AutomaticIntelligencePolicy>
     */
    private function activePoliciesForAsset(DigitalAsset $asset): array
    {
        return AutomaticIntelligencePolicy::query()
            ->where('brand_id', $asset->brand_id)
            ->where('status', AutomaticIntelligencePolicyStatus::Active)
            ->where(function ($q) use ($asset): void {
                $q->whereNull('digital_asset_id')->orWhere('digital_asset_id', $asset->id);
            })
            ->orderBy('id')
            ->get()
            ->all();
    }
}
