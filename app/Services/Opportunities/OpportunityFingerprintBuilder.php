<?php

namespace App\Services\Opportunities;

use App\Models\DigitalAsset;
use App\Support\Opportunities\OpportunityRule;

/**
 * Semantic Opportunity identity. Excludes Evidence IDs, Finding row IDs, values, CollectionRun,
 * title, and current Service Scope status. Always globally unique — an Opportunity fingerprint
 * is persisted as-is (unlike Finding, which may collapse PER_DIGITAL_ASSET rules to the stable ID).
 */
final class OpportunityFingerprintBuilder
{
    public const string VERSION = 'v1';

    public function make(
        OpportunityRule $rule,
        DigitalAsset $asset,
        string $subjectKind,
        string $subjectId,
        ?int $brandGoalId = null,
        ?int $brandOfferingId = null,
        ?string $marketIdentity = null,
        ?string $periodIdentity = null,
    ): string {
        $inputs = [
            'stable_rule_id' => $rule->stableId,
            'customer_id' => (string) ($asset->brand?->customer_id ?? ''),
            'brand_id' => (string) ($asset->brand_id ?? ''),
            'digital_asset_id' => (string) $asset->id,
            'subject_kind' => $subjectKind,
            'subject_id' => $subjectId,
        ];

        if ($rule->includeGoalInFingerprint) {
            $inputs['brand_goal_id'] = (string) ($brandGoalId ?? '');
        }
        if ($rule->includeOfferingInFingerprint) {
            $inputs['brand_offering_id'] = (string) ($brandOfferingId ?? '');
        }
        if ($rule->includeMarketInFingerprint) {
            $inputs['market_identity'] = (string) ($marketIdentity ?? '');
        }
        if ($rule->includePeriodInFingerprint) {
            $inputs['period'] = (string) ($periodIdentity ?? '');
        }

        ksort($inputs);

        return hash('sha256', json_encode([
            'version' => self::VERSION,
            'inputs' => $inputs,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Persisted `opportunities.fingerprint` is always the full semantic fingerprint —
     * Opportunity identity must stay globally unique across every Digital Asset and rule.
     */
    public function persistenceKey(OpportunityRule $rule, string $semanticFingerprint): string
    {
        return $rule->stableId.':'.$semanticFingerprint;
    }
}
