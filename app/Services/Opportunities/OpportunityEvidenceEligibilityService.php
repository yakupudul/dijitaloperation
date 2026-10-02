<?php

namespace App\Services\Opportunities;

use App\Enums\DataPool\FreshnessState;
use App\Enums\OpportunityEligibilityDisposition;
use App\Models\DigitalAsset;
use App\Services\Formulas\Support\FormulaResult;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Opportunities\OpportunityEligibilityReport;
use App\Support\Opportunities\OpportunityRule;

/**
 * Rule Evidence eligibility. Missing/stale/partial/integrity-blocked ≠ condition false.
 * Mirrors FindingEvidenceEligibilityService for Opportunity rules.
 */
final class OpportunityEvidenceEligibilityService
{
    /**
     * @param  list<CanonicalEvidenceDto>  $frozenEvidence
     */
    public function evaluate(
        OpportunityRule $rule,
        DigitalAsset $asset,
        array $frozenEvidence,
    ): OpportunityEligibilityReport {
        if (! $rule->enabled) {
            return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::RuleDisabled, []);
        }

        $matched = [];
        foreach ($rule->evidenceDefinitionIds as $definitionId) {
            $row = $this->currentForDefinition($frozenEvidence, $definitionId);
            if ($row === null) {
                return new OpportunityEligibilityReport(
                    OpportunityEligibilityDisposition::MissingEvidence,
                    [],
                    [],
                    ['definition_id' => $definitionId],
                );
            }
            $matched[] = $row;
        }

        if (count($matched) > 1) {
            $compat = $this->compatibility($rule, $matched);
            if ($compat !== null) {
                return new OpportunityEligibilityReport($compat, $matched);
            }
        }

        foreach ($matched as $row) {
            if ($row->digitalAssetId !== $asset->id) {
                return new OpportunityEligibilityReport(
                    OpportunityEligibilityDisposition::ScopeMismatch,
                    $matched,
                    [],
                    ['reason' => 'cross_asset_evidence'],
                );
            }

            $integrity = strtolower((string) ($row->integrityStatus ?? ''));
            if (in_array($integrity, ['fail', 'failed', 'blocked', 'integrity_blocked'], true)) {
                return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::BlockedIntegrity, $matched);
            }

            $freshness = FreshnessState::tryFrom((string) ($row->freshnessState ?? ''));
            if ($freshness === FreshnessState::IntegrityBlocked) {
                return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::BlockedIntegrity, $matched);
            }
            if ($freshness === FreshnessState::Partial) {
                return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::BlockedPartial, $matched);
            }
            if ($freshness === FreshnessState::ProviderLimited) {
                return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::BlockedProviderLimited, $matched);
            }
            if ($freshness === FreshnessState::Stale || $freshness === FreshnessState::Unknown) {
                return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::BlockedStale, $matched);
            }
            if ($freshness === FreshnessState::ActionRequired) {
                return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::BlockedUnverified, $matched);
            }

            if ($rule->freshnessRequirement === 'fresh_or_fresh_with_limitation') {
                if ($freshness !== null && ! $freshness->trustedFresh()) {
                    return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::BlockedStale, $matched);
                }
            }

            foreach ($rule->requiredOperandStates as $requirement) {
                $path = (string) ($requirement['path'] ?? '');
                $expected = (string) ($requirement['equals'] ?? FormulaResult::STATE_VALUE);
                $actual = data_get($row->payload, $path);
                if ((string) $actual !== $expected) {
                    return new OpportunityEligibilityReport(
                        OpportunityEligibilityDisposition::IncompleteOperands,
                        $matched,
                        [],
                        ['path' => $path, 'expected' => $expected, 'actual' => $actual],
                    );
                }
            }
        }

        return new OpportunityEligibilityReport(OpportunityEligibilityDisposition::Eligible, $matched);
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $frozenEvidence
     */
    private function currentForDefinition(array $frozenEvidence, string $definitionId): ?CanonicalEvidenceDto
    {
        $matches = array_values(array_filter(
            $frozenEvidence,
            static fn (CanonicalEvidenceDto $row): bool => $row->definitionId === $definitionId,
        ));

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn (CanonicalEvidenceDto $a, CanonicalEvidenceDto $b): int => $b->id <=> $a->id,
        );

        return $matches[0];
    }

    /**
     * @param  list<CanonicalEvidenceDto>  $matched
     */
    private function compatibility(OpportunityRule $rule, array $matched): ?OpportunityEligibilityDisposition
    {
        $first = $matched[0];
        foreach (array_slice($matched, 1) as $row) {
            if ($row->digitalAssetId !== $first->digitalAssetId) {
                return OpportunityEligibilityDisposition::ScopeMismatch;
            }
            $periodA = $first->payload['period'] ?? null;
            $periodB = $row->payload['period'] ?? null;
            if ($periodA !== $periodB) {
                return OpportunityEligibilityDisposition::PeriodMismatch;
            }
            $currencyA = data_get($first->payload, 'currency');
            $currencyB = data_get($row->payload, 'currency');
            if ($rule->currencyPolicy !== 'not_applicable' && $currencyA !== $currencyB) {
                return OpportunityEligibilityDisposition::CurrencyMismatch;
            }
            $attrA = data_get($first->payload, 'attribution');
            $attrB = data_get($row->payload, 'attribution');
            if ($attrA !== null && $attrB !== null && $attrA !== $attrB) {
                return OpportunityEligibilityDisposition::AttributionMismatch;
            }
        }

        return null;
    }
}
