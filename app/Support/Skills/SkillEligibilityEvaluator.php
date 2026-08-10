<?php

namespace App\Support\Skills;

/**
 * Deterministic Skill eligibility against available Evidence types / context flags.
 *
 * Capability metadata is informational only in V1 — it never triggers external access.
 */
final class SkillEligibilityEvaluator
{
    public const string ELIGIBLE = 'eligible';

    public const string MISSING_REQUIRED_EVIDENCE = 'missing_required_evidence';

    public const string MISSING_REQUIRED_CONTEXT = 'missing_required_context';

    /**
     * @param  list<string>  $availableEvidenceTypes
     * @param  array<string, bool>  $contextFlags  e.g. ['brand_context' => true]
     * @return array{
     *     status: string,
     *     eligible: bool,
     *     missing_evidence: list<string>,
     *     missing_context: list<string>,
     *     required_capabilities: list<string>,
     *     optional_capabilities: list<string>
     * }
     */
    public function evaluate(
        SkillDefinition $skill,
        array $availableEvidenceTypes,
        array $contextFlags = [],
    ): array {
        $available = array_fill_keys($availableEvidenceTypes, true);
        $missingEvidence = [];

        foreach ($skill->requiredEvidence as $type) {
            if ($this->evidenceRequirementMet($type, $available)) {
                continue;
            }
            $missingEvidence[] = $type;
        }

        $missingContext = [];
        foreach ($skill->requiredContext as $flag) {
            if (($contextFlags[$flag] ?? false) !== true) {
                $missingContext[] = $flag;
            }
        }

        $status = self::ELIGIBLE;
        if ($missingEvidence !== []) {
            $status = self::MISSING_REQUIRED_EVIDENCE;
        } elseif ($missingContext !== []) {
            $status = self::MISSING_REQUIRED_CONTEXT;
        }

        return [
            'status' => $status,
            'eligible' => $status === self::ELIGIBLE,
            'missing_evidence' => $missingEvidence,
            'missing_context' => $missingContext,
            // Metadata only — Capability Router is NOT implemented.
            'required_capabilities' => $skill->requiredCapabilities,
            'optional_capabilities' => $skill->optionalCapabilities,
        ];
    }

    /**
     * @param  array<string, true>  $available
     */
    private function evidenceRequirementMet(string $requirement, array $available): bool
    {
        // Exact type.
        if (isset($available[$requirement])) {
            return true;
        }

        // Prefix groups, e.g. "gsc_*" or conceptual groups used in Skills.
        if (str_ends_with($requirement, '_*') || str_ends_with($requirement, '*')) {
            $prefix = rtrim($requirement, '*');
            foreach (array_keys($available) as $type) {
                if (str_starts_with($type, $prefix)) {
                    return true;
                }
            }
        }

        // Conceptual aliases.
        return match ($requirement) {
            'gsc_any' => $this->anyPrefix($available, 'gsc_'),
            'dataforseo_any' => $this->anyPrefix($available, 'dataforseo_'),
            'technical_any' => isset($available['page_html'])
                || $this->anyPrefix($available, 'pagespeed_')
                || $this->anyPrefix($available, 'lighthouse_'),
            default => false,
        };
    }

    /**
     * @param  array<string, true>  $available
     */
    private function anyPrefix(array $available, string $prefix): bool
    {
        foreach (array_keys($available) as $type) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
