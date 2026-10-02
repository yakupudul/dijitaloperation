<?php

namespace App\Support\Skills;

/**
 * Deterministic Skill eligibility against available Evidence types / context flags.
 *
 * Capability metadata is informational only in V1 — it never triggers external access.
 * Prompt 49 defines abstention reason codes; Prompt 50 enforces runtime.
 */
final class SkillEligibilityEvaluator
{
    public const string ELIGIBLE = 'eligible';

    public const string MISSING_REQUIRED_EVIDENCE = 'missing_required_evidence';

    public const string MISSING_REQUIRED_CONTEXT = 'missing_required_context';

    public const string REQUIRED_EVIDENCE_STALE = 'required_evidence_stale';

    public const string INTEGRITY_BLOCKED = 'integrity_blocked';

    public const string COVERAGE_INSUFFICIENT = 'coverage_insufficient';

    public const string PROVIDER_LIMITED = 'provider_limited';

    public const string METHODOLOGY_NOT_APPLICABLE = 'methodology_not_applicable';

    public const string UNSUPPORTED_QUESTION = 'unsupported_question';

    /**
     * @param  list<string>  $availableEvidenceTypes
     * @param  array<string, bool>  $contextFlags  e.g. ['brand_context' => true]
     * @param  array<string, string>  $evidenceStates  key => eligible|stale|integrity_blocked|insufficient|provider_limited
     * @return array{
     *     status: string,
     *     eligible: bool,
     *     abstain: bool,
     *     reason_code: string|null,
     *     missing_evidence: list<string>,
     *     missing_context: list<string>,
     *     blocked_evidence: list<string>,
     *     optional_evidence_present: list<string>,
     *     optional_evidence_absent: list<string>,
     *     required_capabilities: list<string>,
     *     optional_capabilities: list<string>
     * }
     */
    public function evaluate(
        SkillDefinition $skill,
        array $availableEvidenceTypes,
        array $contextFlags = [],
        array $evidenceStates = [],
    ): array {
        $available = array_fill_keys($availableEvidenceTypes, true);
        $missingEvidence = [];
        $blockedEvidence = [];

        foreach ($skill->requiredEvidence as $type) {
            if (! $this->evidenceRequirementMet($type, $available)) {
                $missingEvidence[] = $type;

                continue;
            }

            $state = $evidenceStates[$type] ?? 'eligible';
            if (in_array($state, ['stale', 'integrity_blocked', 'insufficient', 'provider_limited'], true)) {
                $blockedEvidence[] = $type;
            }
        }

        $missingContext = [];
        foreach ($skill->requiredContext as $flag) {
            if (($contextFlags[$flag] ?? false) !== true) {
                $missingContext[] = $flag;
            }
        }

        $optionalPresent = [];
        $optionalAbsent = [];
        foreach ($skill->optionalEvidence as $type) {
            if ($this->evidenceRequirementMet($type, $available)) {
                $optionalPresent[] = $type;
            } else {
                $optionalAbsent[] = $type;
            }
        }

        $status = self::ELIGIBLE;
        $reasonCode = null;

        if ($missingEvidence !== []) {
            $status = self::MISSING_REQUIRED_EVIDENCE;
            $reasonCode = self::MISSING_REQUIRED_EVIDENCE;
        } elseif ($blockedEvidence !== []) {
            $first = $blockedEvidence[0];
            $state = $evidenceStates[$first] ?? 'integrity_blocked';
            $status = match ($state) {
                'stale' => self::REQUIRED_EVIDENCE_STALE,
                'insufficient' => self::COVERAGE_INSUFFICIENT,
                'provider_limited' => self::PROVIDER_LIMITED,
                default => self::INTEGRITY_BLOCKED,
            };
            $reasonCode = $status;
        } elseif ($missingContext !== []) {
            $status = self::MISSING_REQUIRED_CONTEXT;
            $reasonCode = self::MISSING_REQUIRED_CONTEXT;
        }

        $eligible = $status === self::ELIGIBLE;

        return [
            'status' => $status,
            'eligible' => $eligible,
            'abstain' => ! $eligible,
            'reason_code' => $reasonCode,
            'missing_evidence' => $missingEvidence,
            'missing_context' => $missingContext,
            'blocked_evidence' => $blockedEvidence,
            'optional_evidence_present' => $optionalPresent,
            'optional_evidence_absent' => $optionalAbsent,
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

        // Evidence Definition IDs may be satisfied by a mapped observational type alias.
        if ($requirement === 'gsc.property.period_comparison') {
            return isset($available['search_console_performance']) || $this->anyPrefix($available, 'gsc_');
        }
        if ($requirement === 'ga4.property.period_comparison') {
            return isset($available['ga4_events']) || $this->anyPrefix($available, 'ga4_');
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
            'gsc_any' => $this->anyPrefix($available, 'gsc_') || isset($available['search_console_performance']),
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
