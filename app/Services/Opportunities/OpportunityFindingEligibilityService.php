<?php

namespace App\Services\Opportunities;

use App\Enums\OpportunityEligibilityDisposition;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Support\Evidence\Dto\CanonicalEvidenceDto;
use App\Support\Opportunities\OpportunityEligibilityReport;
use App\Support\Opportunities\OpportunityRule;

/**
 * Rule Finding eligibility. An Opportunity Rule never promotes every Finding — it only reads
 * the Findings its rule explicitly names via finding_rule_stable_ids. Does not mutate Findings.
 *
 * "Missing is not cleared": if the named Finding rule has never fired on this Digital Asset
 * (no Finding row exists at all), the rule is blocked (MissingFinding) rather than treated as
 * proof of absence. Once a Finding row exists, its current status feeds FINDING_PRESENT /
 * FINDING_ABSENT_WITH_PROOF condition evaluation — a resolved Finding is proof of absence,
 * not a missing input.
 */
final class OpportunityFindingEligibilityService
{
    /**
     * @param  list<CanonicalEvidenceDto>  $eligibleEvidence
     */
    public function evaluate(
        OpportunityRule $rule,
        DigitalAsset $asset,
        array $eligibleEvidence,
    ): OpportunityEligibilityReport {
        if ($rule->findingRuleStableIds === []) {
            return new OpportunityEligibilityReport(
                OpportunityEligibilityDisposition::MissingFinding,
                $eligibleEvidence,
                [],
                ['reason' => 'rule_declares_no_finding_rule_stable_ids'],
            );
        }

        $findings = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('rule_id', $rule->findingRuleStableIds)
            ->orderByDesc('id')
            ->get()
            ->all();

        if ($findings === []) {
            return new OpportunityEligibilityReport(
                OpportunityEligibilityDisposition::MissingFinding,
                $eligibleEvidence,
                [],
                ['finding_rule_stable_ids' => $rule->findingRuleStableIds],
            );
        }

        return new OpportunityEligibilityReport(
            OpportunityEligibilityDisposition::Eligible,
            $eligibleEvidence,
            $findings,
        );
    }
}
