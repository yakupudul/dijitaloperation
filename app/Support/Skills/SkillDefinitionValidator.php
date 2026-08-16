<?php

namespace App\Support\Skills;

/**
 * Machine validation for production-ready Skill Definitions (no AI).
 */
final class SkillDefinitionValidator
{
    public function __construct(
        private readonly SkillEvidenceCatalog $evidenceCatalog,
    ) {}

    /**
     * @return list<string> validation error codes/messages (empty = valid)
     */
    public function validate(SkillDefinition $skill): array
    {
        $errors = [];

        if (trim($skill->purpose) === '') {
            $errors[] = 'purpose_empty';
        }

        if ($this->promisesBusinessOutcome($skill->purpose)) {
            $errors[] = 'purpose_promises_business_outcome';
        }

        if (trim($skill->whenToUse) === '') {
            $errors[] = 'when_to_use_empty';
        }

        if (trim($skill->doNotUseWhen) === '') {
            $errors[] = 'do_not_use_when_empty';
        }

        if (trim($skill->methodology) === '') {
            $errors[] = 'methodology_empty';
        }

        if ($skill->allowedConclusions === []) {
            $errors[] = 'allowed_conclusions_empty';
        }

        if ($skill->effectiveForbiddenClaims() === []) {
            $errors[] = 'forbidden_claims_empty';
        }

        if ($skill->successSignals === []) {
            $errors[] = 'success_signals_empty';
        }

        if ($skill->referenceSources === []) {
            $errors[] = 'references_empty';
        }

        if ($this->containsMagicScoreLanguage($skill)) {
            $errors[] = 'magic_score_language';
        }

        if ($this->containsExecutableMethodology($skill)) {
            $errors[] = 'methodology_executable';
        }

        $requiredKeys = [];
        foreach ($skill->requiredEvidenceRequirements as $requirement) {
            $requiredKeys[] = $requirement->key;
            $errors = array_merge($errors, $this->validateRequirement($requirement, required: true));
        }

        $optionalKeys = [];
        foreach ($skill->optionalEvidenceRequirements as $requirement) {
            $optionalKeys[] = $requirement->key;
            $errors = array_merge($errors, $this->validateRequirement($requirement, required: false));

            if ($requirement->missingBehavior !== SkillEvidenceRequirement::MISSING_CONTINUE) {
                $errors[] = 'optional_evidence_not_continue:'.$requirement->key;
            }
        }

        foreach (array_intersect($requiredKeys, $optionalKeys) as $dup) {
            $errors[] = 'evidence_both_required_and_optional:'.$dup;
        }

        if ($skill->isProductionReady()) {
            if ($skill->requiredEvidenceRequirements === [] && ! $this->allowsZeroEvidence($skill)) {
                $errors[] = 'required_evidence_empty_for_active_skill';
            }

            if ($skill->researchProvenance === [] && $this->requiresResearchProvenance($skill)) {
                $errors[] = 'research_provenance_missing';
            }

            if ($skill->abstentionRules === []) {
                $errors[] = 'abstention_rules_empty';
            }

            if (trim($skill->outputContract) === '') {
                $errors[] = 'output_contract_empty';
            }
        }

        foreach ($skill->methodologySteps as $step) {
            $type = strtoupper((string) ($step['type'] ?? ''));
            if ($type !== '' && ! in_array($type, ['CHECK', 'COMPARE', 'CLASSIFY', 'SYNTHESIZE', 'SUMMARIZE', 'PRIORITIZE_WITHOUT_SCORE', 'VALIDATE', 'ABSTAIN_GATE'], true)) {
                $errors[] = 'methodology_step_type_invalid:'.$type;
            }
        }

        return array_values(array_unique($errors));
    }

    public function assertValid(SkillDefinition $skill): void
    {
        $errors = $this->validate($skill);
        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'Skill definition invalid ['.$skill->stableKey().'@'.$skill->version.']: '.implode(', ', $errors)
            );
        }
    }

    /**
     * @return list<string>
     */
    private function validateRequirement(SkillEvidenceRequirement $requirement, bool $required): array
    {
        $errors = [];

        if ($requirement->key === '') {
            $errors[] = 'evidence_key_empty';
        }

        if (! in_array($requirement->kind, [
            SkillEvidenceRequirement::KIND_EVIDENCE_DEFINITION,
            SkillEvidenceRequirement::KIND_EVIDENCE_TYPE,
        ], true)) {
            $errors[] = 'evidence_kind_invalid:'.$requirement->key;
        }

        if (! $this->evidenceCatalog->isKnown($requirement->key, $requirement->kind)) {
            $errors[] = 'evidence_unknown:'.$requirement->key;
        }

        if ($required && $requirement->missingBehavior !== SkillEvidenceRequirement::MISSING_ABSTAIN) {
            $errors[] = 'required_evidence_missing_behavior:'.$requirement->key;
        }

        if ($requirement->expandsConclusions && $required === false && $requirement->purpose === '') {
            $errors[] = 'optional_evidence_purpose_empty:'.$requirement->key;
        }

        return $errors;
    }

    private function promisesBusinessOutcome(string $purpose): bool
    {
        return (bool) preg_match('/\b(increase rankings?|more leads|beat competitors|guarantee|grow revenue|rank #1)\b/i', $purpose);
    }

    private function containsMagicScoreLanguage(SkillDefinition $skill): bool
    {
        $haystack = strtolower(implode("\n", [
            $skill->purpose,
            $skill->methodology,
            $skill->outputContract,
            implode(' ', $skill->allowedConclusions),
            implode(' ', $skill->successSignals),
        ]));

        return (bool) preg_match('/\b(seo score|geo score|health score|eeat score|e-e-a-t score|ai visibility score|content score|authority score)\b/i', $haystack)
            || (bool) preg_match('/\bscore\s*[:=]\s*\d{1,3}\s*\/\s*100\b/i', $haystack);
    }

    private function containsExecutableMethodology(SkillDefinition $skill): bool
    {
        $haystack = $skill->methodology."\n".$skill->bodyMarkdown;

        return (bool) preg_match('/\b(eval\s*\(|shell_exec\s*\(|passthru\s*\(|proc_open\s*\(|curl\s*\|?\s*bash|npx\s+skills\s+add)\b/i', $haystack);
    }

    private function allowsZeroEvidence(SkillDefinition $skill): bool
    {
        // Framing / discovery skills may operate on Brand Context alone when explicitly documented.
        return in_array($skill->slug, ['recommendation-framing', 'brand-context-discovery'], true);
    }

    private function requiresResearchProvenance(SkillDefinition $skill): bool
    {
        // Prompt 48–derived normalized website Skills must retain candidate provenance.
        return $skill->module === 'website' && in_array($skill->slug, [
            'technical-seo-analysis',
            'indexability-analysis',
            'metadata-consistency',
            'gsc-search-demand-review',
            'keyword-opportunity-analysis',
            'ga4-measurement-quality',
            'search-console-analysis',
        ], true);
    }
}
