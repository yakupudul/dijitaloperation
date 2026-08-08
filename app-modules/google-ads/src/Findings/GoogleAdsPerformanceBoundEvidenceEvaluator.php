<?php

namespace MoxDop\GoogleAds\Findings;

use App\Contracts\Findings\EvaluatesBoundEvidence;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use DateTimeInterface;

/**
 * Deterministic Google Ads account + campaign performance rules from Binding Evidence.
 */
final class GoogleAdsPerformanceBoundEvidenceEvaluator implements EvaluatesBoundEvidence
{
    public function sourceModule(): string
    {
        return PerformanceFindingsCatalog::SOURCE_MODULE;
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

        $account = $this->usableEvidence($runs, 'google_ads_account_summary');
        if ($account !== null) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, PerformanceFindingsCatalog::ACCOUNT_RULE_IDS);
            $anchorRun = $account['run'];
            $observedAt = $account['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $this->evaluateAccount($account['evidence']));
        }

        $campaigns = $this->usableEvidence($runs, 'google_ads_campaign_performance');
        if ($campaigns !== null) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, PerformanceFindingsCatalog::CAMPAIGN_RULE_IDS);
            $anchorRun = $campaigns['run'];
            $observedAt = $campaigns['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $this->evaluateCampaigns($campaigns['evidence']));
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
            if ($run->status !== 'completed') {
                continue;
            }

            $run->loadMissing('evidence');
            $evidence = $run->evidence->first(
                fn (Evidence $row): bool => $row->type === $type
                    && data_get($row->payload, 'response_ok') === true
            );

            if ($evidence instanceof Evidence) {
                return ['run' => $run, 'evidence' => $evidence];
            }
        }

        return null;
    }

    /**
     * @return list<RuleMatch>
     */
    private function evaluateAccount(Evidence $evidence): array
    {
        $current = is_array($evidence->payload['current'] ?? null) ? $evidence->payload['current'] : [];
        $previous = is_array($evidence->payload['previous'] ?? null) ? $evidence->payload['previous'] : [];
        $deltas = is_array($evidence->payload['deltas'] ?? null) ? $evidence->payload['deltas'] : [];
        $matches = [];

        $prevConv = $this->floatOrNull($previous['conversions'] ?? null);
        $currConv = $this->floatOrNull($current['conversions'] ?? null);
        $absConv = $this->floatOrNull(data_get($deltas, 'conversions.absolute'));
        $pctConv = $this->floatOrNull(data_get($deltas, 'conversions.percent'));

        if (
            $prevConv !== null
            && $prevConv >= PerformanceFindingsCatalog::CONVERSIONS_PREV_MIN
            && $absConv !== null
            && $absConv <= -PerformanceFindingsCatalog::CONVERSIONS_ABS_DROP_MIN
            && $pctConv !== null
            && $pctConv <= -PerformanceFindingsCatalog::CONVERSIONS_PCT_DROP_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_CONVERSIONS_DECLINE,
                PerformanceFindingsCatalog::RULE_CONVERSIONS_DECLINE,
                'high',
                'Google Ads conversions declined',
                sprintf(
                    'Conversions fell from %s to %s (%s / %s%%) versus the prior comparable period.',
                    $this->fmt($prevConv),
                    $this->fmt($currConv),
                    $this->fmt($absConv),
                    $this->fmt($pctConv),
                ),
                'Inspect conversion tracking continuity, landing experience, and campaign efficiency using Ads Evidence.',
            );
        }

        $prevCost = $this->floatOrNull($previous['cost'] ?? null);
        $currCost = $this->floatOrNull($current['cost'] ?? null);
        $prevCpa = $this->cpa($prevCost, $prevConv);
        $currCpa = $this->cpa($currCost, $currConv);
        if (
            $prevConv !== null
            && $currConv !== null
            && $prevCost !== null
            && $prevConv >= PerformanceFindingsCatalog::CPA_PREV_CONVERSIONS_MIN
            && $currConv >= PerformanceFindingsCatalog::CPA_CURRENT_CONVERSIONS_MIN
            && $prevCost >= PerformanceFindingsCatalog::CPA_PREV_COST_MIN
            && $prevCpa !== null
            && $currCpa !== null
            && $prevCpa > 0
        ) {
            $cpaPct = (($currCpa - $prevCpa) / $prevCpa) * 100;
            if ($cpaPct >= PerformanceFindingsCatalog::CPA_PCT_INCREASE_MIN) {
                $matches[] = $this->match(
                    PerformanceFindingsCatalog::RULE_CPA_DETERIORATION,
                    PerformanceFindingsCatalog::RULE_CPA_DETERIORATION,
                    'high',
                    'Google Ads CPA deteriorated',
                    sprintf(
                        'CPA rose from %s to %s (%s%%) with prior cost %s and conversions %s → %s.',
                        $this->fmt($prevCpa),
                        $this->fmt($currCpa),
                        $this->fmt($cpaPct),
                        $this->fmt($prevCost),
                        $this->fmt($prevConv),
                        $this->fmt($currConv),
                    ),
                    'Review bid/budget pressure and inefficient campaigns; validate conversion volume quality before scaling spend.',
                );
            }
        }

        $pctCost = $this->floatOrNull(data_get($deltas, 'cost.percent'));
        if (
            $prevCost !== null
            && $prevCost >= PerformanceFindingsCatalog::SPEND_UP_PREV_COST_MIN
            && $pctCost !== null
            && $pctCost >= PerformanceFindingsCatalog::SPEND_UP_PCT_MIN
            && $pctConv !== null
            && $pctConv <= -PerformanceFindingsCatalog::SPEND_UP_CONVERSIONS_PCT_DROP_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_SPEND_UP_CONVERSIONS_DOWN,
                PerformanceFindingsCatalog::RULE_SPEND_UP_CONVERSIONS_DOWN,
                'critical',
                'Google Ads spend rose while conversions fell',
                sprintf(
                    'Cost changed %s%% (from %s to %s) while conversions changed %s%% (from %s to %s).',
                    $this->fmt($pctCost),
                    $this->fmt($prevCost),
                    $this->fmt($currCost),
                    $this->fmt($pctConv),
                    $this->fmt($prevConv),
                    $this->fmt($currConv),
                ),
                'Pause or constrain inefficient spend drivers and investigate conversion-path breakage before adding budget.',
            );
        }

        return $matches;
    }

    /**
     * @return list<RuleMatch>
     */
    private function evaluateCampaigns(Evidence $evidence): array
    {
        $rows = $evidence->payload['rows'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $campaignId = isset($row['campaign_id']) ? trim((string) $row['campaign_id']) : '';
            if ($campaignId === '') {
                continue;
            }

            $cost = $this->floatOrNull($row['cost'] ?? null) ?? 0.0;
            $clicks = $this->floatOrNull($row['clicks'] ?? null) ?? 0.0;
            $conversions = $this->floatOrNull($row['conversions'] ?? null) ?? 0.0;
            $name = is_string($row['campaign_name'] ?? null) ? $row['campaign_name'] : $campaignId;

            $enoughVolume = $cost >= PerformanceFindingsCatalog::CAMPAIGN_COST_MIN
                || $clicks >= PerformanceFindingsCatalog::CAMPAIGN_CLICKS_MIN;

            if (! $enoughVolume || $conversions > 0.0001) {
                continue;
            }

            $fingerprint = PerformanceFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS.':'.$campaignId;
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS,
                $fingerprint,
                'high',
                'Campaign spent with zero conversions',
                sprintf(
                    'Campaign "%s" (%s) recorded cost %s and clicks %s with zero conversions in the current period.',
                    $name,
                    $campaignId,
                    $this->fmt($cost),
                    $this->fmt($clicks),
                ),
                'Audit conversion tracking and landing relevance for this campaign; reduce spend until conversions resume.',
            );

            if (count($matches) >= PerformanceFindingsCatalog::CAMPAIGN_FINDINGS_MAX) {
                break;
            }
        }

        return $matches;
    }

    private function cpa(?float $cost, ?float $conversions): ?float
    {
        if ($cost === null || $conversions === null || $conversions <= 0) {
            return null;
        }

        return round($cost / $conversions, 4);
    }

    private function match(
        string $ruleId,
        string $fingerprint,
        string $severity,
        string $title,
        string $summary,
        string $recommendationAction,
    ): RuleMatch {
        return new RuleMatch(
            ruleId: $ruleId,
            fingerprint: $fingerprint,
            category: 'performance',
            severity: $severity,
            title: $title,
            summary: $summary,
            confidence: 0.84,
            recommendationTitle: 'Investigate: '.$title,
            recommendationAction: $recommendationAction,
        );
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function fmt(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'n/a';
        }

        $number = (float) $value;

        return abs($number - round($number)) < 0.0001
            ? (string) (int) round($number)
            : number_format($number, 2, '.', '');
    }
}
