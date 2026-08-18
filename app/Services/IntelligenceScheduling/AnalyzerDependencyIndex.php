<?php

namespace App\Services\IntelligenceScheduling;

use App\Enums\Intelligence\AnalyzerKind;
use App\Services\Findings\FindingRuleRegistry;
use App\Services\Opportunities\OpportunityRuleRegistry;
use App\Support\Findings\FindingRule;
use App\Support\Opportunities\OpportunityRule;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillRegistry;

/**
 * Deterministic analyzer dependency index (Prompt 63).
 * No fuzzy matching, embeddings, or LLM classification.
 */
final class AnalyzerDependencyIndex
{
    public function __construct(
        private readonly FindingRuleRegistry $findingRules,
        private readonly OpportunityRuleRegistry $opportunityRules,
        private readonly ?SkillRegistry $skills = null,
    ) {}

    /**
     * @param  list<string>  $changedEvidenceDefinitionIds
     * @return list<array{kind: string, analyzer_id: string, stable_id: string, version: int, evidence_definition_ids: list<string>}>
     */
    public function findingRulesForEvidenceDefinitions(array $changedEvidenceDefinitionIds): array
    {
        $changed = array_fill_keys($changedEvidenceDefinitionIds, true);
        $out = [];
        foreach ($this->findingRules->enabled() as $rule) {
            if ($this->intersects($rule->evidenceDefinitionIds, $changed)) {
                $out[] = $this->findingEntry($rule);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $changedEvidenceDefinitionIds
     * @param  list<string>  $changedFindingRuleStableIds
     * @return list<array{kind: string, analyzer_id: string, stable_id: string, version: int, evidence_definition_ids: list<string>, finding_rule_stable_ids: list<string>}>
     */
    public function opportunityRulesForChanges(
        array $changedEvidenceDefinitionIds = [],
        array $changedFindingRuleStableIds = [],
    ): array {
        $evidenceChanged = array_fill_keys($changedEvidenceDefinitionIds, true);
        $findingChanged = array_fill_keys($changedFindingRuleStableIds, true);
        $out = [];
        foreach ($this->opportunityRules->enabled() as $rule) {
            $evidenceHit = $this->intersects($rule->evidenceDefinitionIds, $evidenceChanged);
            $findingHit = $this->intersects($rule->findingRuleStableIds, $findingChanged);
            if ($evidenceHit || $findingHit) {
                $out[] = $this->opportunityEntry($rule);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $changedEvidenceDefinitionIds
     * @return list<array{kind: string, analyzer_id: string, skill_signature: string, version: string, required_evidence: list<string>, optional_evidence: list<string>}>
     */
    public function skillsForRequiredEvidenceChanges(array $changedEvidenceDefinitionIds): array
    {
        if ($this->skills === null) {
            return [];
        }

        $changed = array_fill_keys($changedEvidenceDefinitionIds, true);
        $out = [];
        foreach ($this->skills->all() as $skill) {
            if (! $skill instanceof SkillDefinition) {
                continue;
            }
            if ($this->intersects($skill->requiredEvidence, $changed)) {
                $out[] = $this->skillEntry($skill);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $changedEvidenceDefinitionIds
     * @return list<array{kind: string, analyzer_id: string, skill_signature: string, version: string, required_evidence: list<string>, optional_evidence: list<string>}>
     */
    public function skillsForOptionalEvidenceChanges(array $changedEvidenceDefinitionIds): array
    {
        if ($this->skills === null) {
            return [];
        }

        $changed = array_fill_keys($changedEvidenceDefinitionIds, true);
        $out = [];
        foreach ($this->skills->all() as $skill) {
            if (! $skill instanceof SkillDefinition) {
                continue;
            }
            if ($this->intersects($skill->optionalEvidence, $changed)
                && ! $this->intersects($skill->requiredEvidence, $changed)) {
                $out[] = $this->skillEntry($skill);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $haystack
     * @param  array<string, true>  $needles
     */
    private function intersects(array $haystack, array $needles): bool
    {
        if ($needles === [] || $haystack === []) {
            return false;
        }
        foreach ($haystack as $item) {
            if (isset($needles[(string) $item])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{kind: string, analyzer_id: string, stable_id: string, version: int, evidence_definition_ids: list<string>}
     */
    private function findingEntry(FindingRule $rule): array
    {
        return [
            'kind' => AnalyzerKind::FindingRule->value,
            'analyzer_id' => $rule->id,
            'stable_id' => $rule->stableId,
            'version' => $rule->version,
            'evidence_definition_ids' => $rule->evidenceDefinitionIds,
        ];
    }

    /**
     * @return array{kind: string, analyzer_id: string, stable_id: string, version: int, evidence_definition_ids: list<string>, finding_rule_stable_ids: list<string>}
     */
    private function opportunityEntry(OpportunityRule $rule): array
    {
        return [
            'kind' => AnalyzerKind::OpportunityRule->value,
            'analyzer_id' => $rule->id,
            'stable_id' => $rule->stableId,
            'version' => $rule->version,
            'evidence_definition_ids' => $rule->evidenceDefinitionIds,
            'finding_rule_stable_ids' => $rule->findingRuleStableIds,
        ];
    }

    /**
     * @return array{kind: string, analyzer_id: string, skill_signature: string, version: string, required_evidence: list<string>, optional_evidence: list<string>}
     */
    private function skillEntry(SkillDefinition $skill): array
    {
        return [
            'kind' => AnalyzerKind::AiSkill->value,
            'analyzer_id' => $skill->signature(),
            'skill_signature' => $skill->signature(),
            'version' => (string) $skill->version,
            'required_evidence' => $skill->requiredEvidence,
            'optional_evidence' => $skill->optionalEvidence,
        ];
    }
}
