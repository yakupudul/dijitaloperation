<?php

namespace MoxDop\MetaAds\Findings;

use App\Contracts\Findings\EvaluatesBoundEvidence;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use DateTimeInterface;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;

/**
 * Cautious Meta Ads Findings from bound Insights Evidence.
 * Missing/failed Evidence does not evaluate those rules (no false resolution).
 */
final class MetaAdsPerformanceBoundEvidenceEvaluator implements EvaluatesBoundEvidence
{
    public function sourceModule(): string
    {
        return MetaAdsFindingsCatalog::SOURCE_MODULE;
    }

    /**
     * @param  list<Run>  $runs
     */
    public function evaluate(DigitalAsset $asset, array $runs): RuleEvaluationResult
    {
        $matches = [];
        $evaluatedRuleIds = [];
        $anchorRun = null;
        $observedAt = now();

        $campaigns = $this->usableEvidence($runs, MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE);
        if ($campaigns !== null) {
            $campaignEvaluation = $this->evaluateCampaigns($campaigns['evidence']);
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, $campaignEvaluation['evaluated_rule_ids']);
            $anchorRun = $campaigns['run'];
            $observedAt = $campaigns['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $campaignEvaluation['matches']);
        }

        $evaluationSuccessful = $evaluatedRuleIds !== [];
        $anchorRun ??= $runs[array_key_last($runs)] ?? null;

        if (! $anchorRun instanceof Run) {
            $anchorRun = new Run([
                'digital_asset_id' => $asset->id,
                'module_id' => $this->sourceModule(),
                'status' => 'failed',
            ]);

            return new RuleEvaluationResult(
                asset: $asset,
                sourceModule: $this->sourceModule(),
                run: $anchorRun,
                evaluationSuccessful: false,
                evaluatedRuleIds: [],
                matches: [],
                observedAt: now(),
            );
        }

        return new RuleEvaluationResult(
            asset: $asset,
            sourceModule: $this->sourceModule(),
            run: $anchorRun,
            evaluationSuccessful: $evaluationSuccessful,
            evaluatedRuleIds: $evaluatedRuleIds,
            matches: $matches,
            observedAt: $observedAt instanceof DateTimeInterface ? $observedAt : now(),
        );
    }

    /**
     * @param  list<Run>  $runs
     * @return array{run: Run, evidence: Evidence}|null
     */
    private function usableEvidence(array $runs, string $type): ?array
    {
        for ($i = count($runs) - 1; $i >= 0; $i--) {
            $run = $runs[$i];
            if (! in_array($run->status, ['completed', 'partial'], true)) {
                continue;
            }

            $evidence = $run->relationLoaded('evidence')
                ? $run->evidence->firstWhere('type', $type)
                : Evidence::query()->where('run_id', $run->id)->where('type', $type)->first();

            if (! $evidence instanceof Evidence) {
                continue;
            }

            if (data_get($evidence->payload, 'response_ok') !== true) {
                continue;
            }

            return ['run' => $run, 'evidence' => $evidence];
        }

        return null;
    }

    /**
     * @return array{matches: list<RuleMatch>, evaluated_rule_ids: list<string>}
     */
    private function evaluateCampaigns(Evidence $evidence): array
    {
        $rows = data_get($evidence->payload, 'rows');
        if (! is_array($rows)) {
            return ['matches' => [], 'evaluated_rule_ids' => []];
        }

        $hasPrimaryResultStatus = false;
        $hasCampaignStatus = false;
        foreach ($rows as $probe) {
            if (! is_array($probe)) {
                continue;
            }
            $primary = is_array($probe['primary_result'] ?? null) ? $probe['primary_result'] : [];
            if (array_key_exists('status', $primary)) {
                $hasPrimaryResultStatus = true;
            }
            $status = strtoupper(trim((string) ($probe['effective_status'] ?? $probe['status'] ?? '')));
            if ($status !== '') {
                $hasCampaignStatus = true;
            }
        }

        $evaluatedRuleIds = [];
        if ($hasPrimaryResultStatus) {
            $evaluatedRuleIds[] = MetaAdsFindingsCatalog::RULE_SPEND_WITHOUT_PRIMARY_RESULT;
            $evaluatedRuleIds[] = MetaAdsFindingsCatalog::RULE_DELIVERY_WITHOUT_RESOLVED_RESULT;
        }
        if ($hasCampaignStatus) {
            $evaluatedRuleIds[] = MetaAdsFindingsCatalog::RULE_CAMPAIGN_INACTIVE_WITH_RECENT_SPEND;
        }

        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (count($matches) >= MetaAdsFindingsCatalog::CAMPAIGN_FINDINGS_MAX) {
                break;
            }

            $campaignId = (string) ($row['campaign_id'] ?? '');
            $campaignName = (string) ($row['campaign_name'] ?? $campaignId);
            $spend = is_numeric($row['spend'] ?? null) ? (float) $row['spend'] : null;
            $impressions = is_numeric($row['impressions'] ?? null) ? (float) $row['impressions'] : null;
            $clicks = is_numeric($row['clicks'] ?? null) ? (float) $row['clicks'] : null;
            $primary = is_array($row['primary_result'] ?? null) ? $row['primary_result'] : [];
            $status = strtoupper(trim((string) ($row['effective_status'] ?? $row['status'] ?? '')));

            $sampleOk = $spend !== null
                && $spend >= MetaAdsFindingsCatalog::SPEND_MIN
                && (
                    ($impressions !== null && $impressions >= MetaAdsFindingsCatalog::IMPRESSIONS_MIN)
                    || ($clicks !== null && $clicks >= MetaAdsFindingsCatalog::CLICKS_MIN)
                );

            if ($hasPrimaryResultStatus && $sampleOk && ($primary['status'] ?? null) === 'zero') {
                $matches[] = new RuleMatch(
                    ruleId: MetaAdsFindingsCatalog::RULE_SPEND_WITHOUT_PRIMARY_RESULT,
                    fingerprint: 'meta-ads:spend-without-primary-result:'.$campaignId,
                    category: 'meta_ads_performance',
                    severity: 'medium',
                    title: 'Spend without observed primary Meta result',
                    summary: sprintf(
                        'Campaign "%s" spent %s with delivery sample gates met, but the safely resolved primary Meta result count is zero. This is an investigation candidate — not proof the campaign is bad. Meta results are platform-attributed, not verified business outcomes.',
                        $campaignName,
                        number_format($spend, 2),
                    ),
                    confidence: 0.7,
                    recommendationTitle: 'Investigate measurement and offer/creative for zero-result spend',
                    recommendationAction: 'Review Meta Events Manager / Pixel / CRM linkage for this campaign objective, then decide externally whether to pause, revise creative, or fix tracking. Do not apply changes from MoxDOP.',
                );
            }

            if ($hasPrimaryResultStatus && $sampleOk && in_array($primary['status'] ?? null, ['unresolved', 'none'], true)) {
                $matches[] = new RuleMatch(
                    ruleId: MetaAdsFindingsCatalog::RULE_DELIVERY_WITHOUT_RESOLVED_RESULT,
                    fingerprint: 'meta-ads:delivery-without-resolved-result:'.$campaignId,
                    category: 'meta_ads_measurement',
                    severity: 'low',
                    title: 'Delivery without safely resolved Meta result signal',
                    summary: sprintf(
                        'Campaign "%s" has meaningful delivery/spend, but MoxDOP could not safely resolve a primary Meta result type from objective/optimization + observed actions. Treat Cost/Result as Mixed/Unresolved until measurement context is clearer.',
                        $campaignName,
                    ),
                    confidence: 0.65,
                    recommendationTitle: 'Clarify Meta result interpretation for this campaign',
                    recommendationAction: 'Confirm campaign objective, ad set optimization goal, and which Meta action types should represent success. Do not invent a primary result from the largest action count.',
                );
            }

            if (
                $hasCampaignStatus
                && $spend !== null
                && $spend >= MetaAdsFindingsCatalog::SPEND_MIN
                && in_array($status, ['PAUSED', 'CAMPAIGN_PAUSED', 'ARCHIVED'], true)
            ) {
                $matches[] = new RuleMatch(
                    ruleId: MetaAdsFindingsCatalog::RULE_CAMPAIGN_INACTIVE_WITH_RECENT_SPEND,
                    fingerprint: 'meta-ads:campaign-inactive-with-context:'.$campaignId,
                    category: 'meta_ads_delivery',
                    severity: 'low',
                    title: 'Inactive campaign with recent spend context',
                    summary: sprintf(
                        'Campaign "%s" shows effective status %s while the selected period still includes spend of %s. This is a configuration/context fact for the operator — not an automatic recommendation to re-enable.',
                        $campaignName,
                        $status !== '' ? $status : 'INACTIVE',
                        number_format($spend, 2),
                    ),
                    confidence: 0.8,
                    recommendationTitle: 'Confirm whether inactive status is intentional',
                    recommendationAction: 'Review why the campaign is paused/archived relative to the analysis period. Any status change must be made by a human in Meta Ads Manager.',
                );
            }
        }

        return ['matches' => $matches, 'evaluated_rule_ids' => $evaluatedRuleIds];
    }
}
