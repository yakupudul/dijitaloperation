<?php

namespace App\Services\Findings;

use App\Models\DigitalAsset;
use App\Support\Findings\FindingRule;

/**
 * Semantic Finding identity. Excludes Evidence IDs, values, CollectionRun, DatasetRun, title, severity.
 */
final class FindingFingerprintBuilder
{
    public const string VERSION = 'v1';

    public function make(
        FindingRule $rule,
        DigitalAsset $asset,
        string $subjectKind,
        string $subjectId,
        ?int $brandGoalId = null,
        ?int $brandOfferingId = null,
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
     * Persisted unique `findings.fingerprint` for PER_DIGITAL_ASSET rules matches the frozen catalog stable ID
     * so legacy rows converge instead of duplicating.
     */
    public function persistenceKey(FindingRule $rule, string $semanticFingerprint): string
    {
        if (($rule->subject['grain'] ?? '') === 'PER_DIGITAL_ASSET') {
            return $rule->stableId;
        }

        return $rule->stableId.':'.$semanticFingerprint;
    }
}
