<?php

namespace App\Services\IntelligenceScheduling;

use App\Enums\AutomaticIntelligencePolicyStatus;
use App\Enums\Intelligence\AnalyzerEligibilityDisposition;
use App\Enums\Intelligence\AnalyzerKind;
use App\Enums\Intelligence\IntelligenceTriggerSource;
use App\Models\AutomaticIntelligencePolicy;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use Carbon\CarbonImmutable;

/**
 * Analyzer eligibility without numeric scores (Prompt 63).
 */
final class AnalyzerEligibilityResolver
{
    /**
     * @param  array<string, mixed>  $analyzer
     * @param  list<string>  $presentDefinitionIds
     * @param  list<array<string, mixed>>  $evidenceRefs
     * @return array{disposition: AnalyzerEligibilityDisposition, reason: string}
     */
    public function resolve(
        array $analyzer,
        DigitalAsset $asset,
        array $presentDefinitionIds,
        array $evidenceRefs,
        ?AutomaticIntelligencePolicy $policy = null,
        ?IntelligenceTriggerSource $triggerSource = null,
    ): array {
        $kind = AnalyzerKind::tryFrom((string) ($analyzer['kind'] ?? ''));
        if ($kind === null) {
            return $this->blocked(AnalyzerEligibilityDisposition::ScopeNotApplicable, 'UNKNOWN_ANALYZER_KIND');
        }

        if ($kind === AnalyzerKind::FindingRule || $kind === AnalyzerKind::OpportunityRule) {
            $deps = $analyzer['evidence_definition_ids'] ?? [];
            if (is_array($deps) && $deps !== [] && ! $this->intersects($deps, $presentDefinitionIds)
                && $kind === AnalyzerKind::FindingRule) {
                // Finding with no overlapping present evidence still may evaluate blocked/indeterminate.
                // Eligibility for scheduling remains when dependency matched upstream.
            }

            return [
                'disposition' => AnalyzerEligibilityDisposition::Eligible,
                'reason' => 'DETERMINISTIC_RULE_ELIGIBLE',
            ];
        }

        // AI_SKILL
        if ($policy === null || ! $policy->isActive()) {
            return $this->blocked(AnalyzerEligibilityDisposition::AutomationDisabled, 'AUTOMATION_DISABLED');
        }

        if ($triggerSource !== null) {
            $allowed = is_array($policy->allowed_trigger_kinds) ? $policy->allowed_trigger_kinds : [];
            if ($allowed !== [] && ! in_array($triggerSource->value, $allowed, true)) {
                return $this->blocked(AnalyzerEligibilityDisposition::AutomationDisabled, 'TRIGGER_KIND_NOT_ALLOWED');
            }
        }

        if ($policy->digital_asset_id !== null && (int) $policy->digital_asset_id !== (int) $asset->id) {
            return $this->blocked(AnalyzerEligibilityDisposition::ScopeNotApplicable, 'ASSET_SCOPE_MISMATCH');
        }

        if ((int) $policy->brand_id !== (int) $asset->brand_id) {
            return $this->blocked(AnalyzerEligibilityDisposition::ScopeNotApplicable, 'BRAND_SCOPE_MISMATCH');
        }

        $required = array_map('strval', $analyzer['required_evidence'] ?? []);
        foreach ($required as $definitionId) {
            if (! in_array($definitionId, $presentDefinitionIds, true)) {
                return $this->blocked(AnalyzerEligibilityDisposition::RequiredEvidenceMissing, 'REQUIRED_EVIDENCE_MISSING');
            }
            $ref = $this->refForDefinition($evidenceRefs, $definitionId);
            if ($ref === null) {
                return $this->blocked(AnalyzerEligibilityDisposition::RequiredEvidenceMissing, 'REQUIRED_EVIDENCE_MISSING');
            }
            if ($this->isStale($ref)) {
                return $this->blocked(AnalyzerEligibilityDisposition::EvidenceStale, 'REQUIRED_EVIDENCE_STALE');
            }
            if ($this->isIntegrityBlocked($ref)) {
                return $this->blocked(AnalyzerEligibilityDisposition::IntegrityBlocked, 'INTEGRITY_BLOCKED');
            }
        }

        if (! $this->withinBudget($policy)) {
            return $this->blocked(AnalyzerEligibilityDisposition::AiBudgetBlocked, 'AI_BUDGET_BLOCKED');
        }

        return [
            'disposition' => AnalyzerEligibilityDisposition::Eligible,
            'reason' => 'AI_SKILL_ELIGIBLE',
        ];
    }

    /**
     * @return array{disposition: AnalyzerEligibilityDisposition, reason: string}
     */
    private function blocked(AnalyzerEligibilityDisposition $disposition, string $reason): array
    {
        return ['disposition' => $disposition, 'reason' => $reason];
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     */
    private function intersects(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $refs
     * @return array<string, mixed>|null
     */
    private function refForDefinition(array $refs, string $definitionId): ?array
    {
        foreach ($refs as $ref) {
            if ((string) ($ref['definition_id'] ?? '') === $definitionId) {
                return $ref;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function isStale(array $ref): bool
    {
        $observation = is_array($ref['observation'] ?? null) ? $ref['observation'] : [];
        $freshUntil = $observation['fresh_until'] ?? null;
        if (! is_string($freshUntil) || $freshUntil === '') {
            return false;
        }

        return CarbonImmutable::parse($freshUntil)->lessThan(CarbonImmutable::now('UTC'));
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function isIntegrityBlocked(array $ref): bool
    {
        $observation = is_array($ref['observation'] ?? null) ? $ref['observation'] : [];
        $integrity = $observation['integrity'] ?? null;
        if (is_string($integrity) && in_array(strtolower($integrity), ['blocked', 'integrity_blocked', 'failed'], true)) {
            return true;
        }
        $eligibility = (string) ($observation['eligibility_status'] ?? '');

        return $eligibility === 'integrity_blocked';
    }

    private function withinBudget(AutomaticIntelligencePolicy $policy): bool
    {
        if ($policy->status !== AutomaticIntelligencePolicyStatus::Active) {
            return false;
        }

        $now = CarbonImmutable::now('UTC');
        if ($policy->last_automatic_run_at !== null) {
            $minInterval = max(1, (int) $policy->min_interval_minutes);
            if ($policy->last_automatic_run_at->greaterThan($now->subMinutes($minInterval))) {
                return false;
            }
        }

        $windowStarted = $policy->window_started_at;
        $windowMinutes = max(1, (int) $policy->window_minutes);
        if ($windowStarted === null || $windowStarted->lessThan($now->subMinutes($windowMinutes))) {
            return true;
        }

        return (int) $policy->runs_in_window < (int) $policy->max_automatic_runs_per_window;
    }
}
