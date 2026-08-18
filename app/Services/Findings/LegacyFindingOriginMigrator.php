<?php

namespace App\Services\Findings;

use App\Enums\FindingOrigin;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Support\Findings\FindingRule;

/**
 * Idempotent legacy Finding origin classification. Does not invent rule provenance.
 * Does not migrate Demo fixtures. Does not delete Findings or break Recommendation FKs.
 */
final class LegacyFindingOriginMigrator
{
    public function __construct(
        private readonly FindingRuleRegistry $rules,
    ) {}

    /**
     * @return array{mapped: int, unverified: int, skipped: int}
     */
    public function migrate(): array
    {
        $stats = ['mapped' => 0, 'unverified' => 0, 'skipped' => 0];
        $known = [];
        foreach ($this->rules->all() as $rule) {
            $known[$rule->stableId] = $rule;
        }

        Finding::query()->orderBy('id')->each(function (Finding $finding) use ($known, &$stats): void {
            if (in_array($finding->origin, [FindingOrigin::RuleEngine->value, FindingOrigin::Operator->value], true)
                && filled($finding->rule_id)) {
                $stats['skipped']++;

                return;
            }

            $stableId = $this->stableIdFromFingerprint((string) $finding->fingerprint, $known);
            if ($stableId !== null) {
                $rule = $known[$stableId];
                $finding->forceFill([
                    'origin' => FindingOrigin::RuleEngine->value,
                    'rule_id' => $rule->stableId,
                    'rule_version' => $finding->rule_version ?? $rule->version,
                ])->save();
                $stats['mapped']++;

                return;
            }

            if ($finding->origin !== FindingOrigin::LegacyUnverified->value) {
                $finding->forceFill(['origin' => FindingOrigin::LegacyUnverified->value])->save();
            }
            $stats['unverified']++;
        });

        Recommendation::query()->whereNotNull('finding_id')->count();

        return $stats;
    }

    /**
     * @param  array<string, FindingRule>  $known
     */
    private function stableIdFromFingerprint(string $fingerprint, array $known): ?string
    {
        if (isset($known[$fingerprint])) {
            return $fingerprint;
        }

        foreach (array_keys($known) as $stableId) {
            if ($fingerprint === $stableId || str_starts_with($fingerprint, $stableId.':')) {
                return $stableId;
            }
        }

        return null;
    }
}
