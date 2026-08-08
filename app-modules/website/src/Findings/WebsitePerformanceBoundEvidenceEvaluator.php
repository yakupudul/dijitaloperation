<?php

namespace MoxDop\Website\Findings;

use App\Contracts\Findings\EvaluatesBoundEvidence;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use DateTimeInterface;

/**
 * Deterministic Search Console + GA4 performance rules from Binding-collected Evidence.
 */
final class WebsitePerformanceBoundEvidenceEvaluator implements EvaluatesBoundEvidence
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

        $gsc = $this->usableEvidence($runs, 'gsc_performance_summary');
        if ($gsc !== null) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, PerformanceFindingsCatalog::GSC_RULE_IDS);
            $anchorRun = $gsc['run'];
            $observedAt = $gsc['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $this->evaluateGsc($gsc['evidence']));
        }

        $ga4 = $this->usableEvidence($runs, 'ga4_performance_summary');
        if ($ga4 !== null) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, PerformanceFindingsCatalog::GA4_RULE_IDS);
            $anchorRun = $ga4['run'];
            $observedAt = $ga4['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $this->evaluateGa4($ga4['evidence']));
        }

        $evaluationSuccessful = $evaluatedRuleIds !== [];
        $anchorRun ??= $runs[array_key_last($runs)] ?? null;

        if (! $anchorRun instanceof Run) {
            // Orchestrator should not call with empty runs; still fail closed (no resolve).
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
    private function evaluateGsc(Evidence $evidence): array
    {
        $current = is_array($evidence->payload['current'] ?? null) ? $evidence->payload['current'] : [];
        $previous = is_array($evidence->payload['previous'] ?? null) ? $evidence->payload['previous'] : [];
        $deltas = is_array($evidence->payload['deltas'] ?? null) ? $evidence->payload['deltas'] : [];
        $matches = [];

        $prevClicks = $this->floatOrNull($previous['clicks'] ?? null);
        $absClicks = $this->floatOrNull(data_get($deltas, 'clicks.absolute'));
        $pctClicks = $this->floatOrNull(data_get($deltas, 'clicks.percent'));
        if (
            $prevClicks !== null
            && $prevClicks >= PerformanceFindingsCatalog::GSC_CLICKS_PREV_MIN
            && $absClicks !== null
            && $absClicks <= -PerformanceFindingsCatalog::GSC_CLICKS_ABS_DROP_MIN
            && $pctClicks !== null
            && $pctClicks <= -PerformanceFindingsCatalog::GSC_CLICKS_PCT_DROP_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_GSC_CLICKS_DECLINE,
                PerformanceFindingsCatalog::RULE_GSC_CLICKS_DECLINE,
                'high',
                'Search Console clicks declined',
                sprintf(
                    'Clicks fell from %s to %s (%s / %s%%) versus the prior comparable period.',
                    $this->fmt($prevClicks),
                    $this->fmt($current['clicks'] ?? null),
                    $this->fmt($absClicks),
                    $this->fmt($pctClicks),
                ),
                'Review query and page declines in Search Console Evidence, then prioritize content and technical fixes for the largest drop drivers.',
            );
        }

        $prevImpr = $this->floatOrNull($previous['impressions'] ?? null);
        $absImpr = $this->floatOrNull(data_get($deltas, 'impressions.absolute'));
        $pctImpr = $this->floatOrNull(data_get($deltas, 'impressions.percent'));
        if (
            $prevImpr !== null
            && $prevImpr >= PerformanceFindingsCatalog::GSC_IMPRESSIONS_PREV_MIN
            && $absImpr !== null
            && $absImpr <= -PerformanceFindingsCatalog::GSC_IMPRESSIONS_ABS_DROP_MIN
            && $pctImpr !== null
            && $pctImpr <= -PerformanceFindingsCatalog::GSC_IMPRESSIONS_PCT_DROP_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_GSC_IMPRESSIONS_DECLINE,
                PerformanceFindingsCatalog::RULE_GSC_IMPRESSIONS_DECLINE,
                'medium',
                'Search Console impressions declined',
                sprintf(
                    'Impressions fell from %s to %s (%s / %s%%) versus the prior comparable period.',
                    $this->fmt($prevImpr),
                    $this->fmt($current['impressions'] ?? null),
                    $this->fmt($absImpr),
                    $this->fmt($pctImpr),
                ),
                'Investigate indexing, coverage, and ranking losses using Search Console page/query Evidence.',
            );
        }

        $prevImprForCtr = $this->floatOrNull($previous['impressions'] ?? null);
        $absCtr = $this->floatOrNull(data_get($deltas, 'ctr.absolute'));
        if (
            $prevImprForCtr !== null
            && $prevImprForCtr >= PerformanceFindingsCatalog::GSC_CTR_PREV_IMPRESSIONS_MIN
            && $absCtr !== null
            && $absCtr <= -PerformanceFindingsCatalog::GSC_CTR_ABS_DROP_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_GSC_CTR_DECLINE,
                PerformanceFindingsCatalog::RULE_GSC_CTR_DECLINE,
                'medium',
                'Search Console CTR declined',
                sprintf(
                    'CTR fell from %s to %s (absolute change %s) with prior impressions %s.',
                    $this->fmtRatio($previous['ctr'] ?? null),
                    $this->fmtRatio($current['ctr'] ?? null),
                    $this->fmtRatio($absCtr),
                    $this->fmt($prevImprForCtr),
                ),
                'Compare titles/meta and SERP features for high-impression queries with weakened CTR.',
            );
        }

        $prevImprForPos = $this->floatOrNull($previous['impressions'] ?? null);
        $absPos = $this->floatOrNull(data_get($deltas, 'position.absolute'));
        if (
            $prevImprForPos !== null
            && $prevImprForPos >= PerformanceFindingsCatalog::GSC_POSITION_PREV_IMPRESSIONS_MIN
            && $absPos !== null
            && $absPos >= PerformanceFindingsCatalog::GSC_POSITION_WORSEN_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_GSC_POSITION_WORSEN,
                PerformanceFindingsCatalog::RULE_GSC_POSITION_WORSEN,
                'high',
                'Search Console average position worsened',
                sprintf(
                    'Average position moved from %s to %s (worsened by %s) with prior impressions %s.',
                    $this->fmt($previous['position'] ?? null),
                    $this->fmt($current['position'] ?? null),
                    $this->fmt($absPos),
                    $this->fmt($prevImprForPos),
                ),
                'Prioritize pages/queries with the largest position losses and validate technical + content competitiveness.',
            );
        }

        return $matches;
    }

    /**
     * @return list<RuleMatch>
     */
    private function evaluateGa4(Evidence $evidence): array
    {
        $current = is_array($evidence->payload['current'] ?? null) ? $evidence->payload['current'] : [];
        $previous = is_array($evidence->payload['previous'] ?? null) ? $evidence->payload['previous'] : [];
        $deltas = is_array($evidence->payload['deltas'] ?? null) ? $evidence->payload['deltas'] : [];
        $matches = [];

        $prevUsers = $this->floatOrNull($previous['totalUsers'] ?? null);
        $absUsers = $this->floatOrNull(data_get($deltas, 'totalUsers.absolute'));
        $pctUsers = $this->floatOrNull(data_get($deltas, 'totalUsers.percent'));
        if (
            $prevUsers !== null
            && $prevUsers >= PerformanceFindingsCatalog::GA4_USERS_PREV_MIN
            && $absUsers !== null
            && $absUsers <= -PerformanceFindingsCatalog::GA4_USERS_ABS_DROP_MIN
            && $pctUsers !== null
            && $pctUsers <= -PerformanceFindingsCatalog::GA4_USERS_PCT_DROP_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_GA4_USERS_DECLINE,
                PerformanceFindingsCatalog::RULE_GA4_USERS_DECLINE,
                'high',
                'GA4 users declined',
                sprintf(
                    'Total users fell from %s to %s (%s / %s%%) versus the prior comparable period.',
                    $this->fmt($prevUsers),
                    $this->fmt($current['totalUsers'] ?? null),
                    $this->fmt($absUsers),
                    $this->fmt($pctUsers),
                ),
                'Review GA4 acquisition and landing Evidence for channel/page contributors to the user drop.',
            );
        }

        $prevSessions = $this->floatOrNull($previous['sessions'] ?? null);
        $absSessions = $this->floatOrNull(data_get($deltas, 'sessions.absolute'));
        $pctSessions = $this->floatOrNull(data_get($deltas, 'sessions.percent'));
        if (
            $prevSessions !== null
            && $prevSessions >= PerformanceFindingsCatalog::GA4_SESSIONS_PREV_MIN
            && $absSessions !== null
            && $absSessions <= -PerformanceFindingsCatalog::GA4_SESSIONS_ABS_DROP_MIN
            && $pctSessions !== null
            && $pctSessions <= -PerformanceFindingsCatalog::GA4_SESSIONS_PCT_DROP_MIN
        ) {
            $matches[] = $this->match(
                PerformanceFindingsCatalog::RULE_GA4_SESSIONS_DECLINE,
                PerformanceFindingsCatalog::RULE_GA4_SESSIONS_DECLINE,
                'medium',
                'GA4 sessions declined',
                sprintf(
                    'Sessions fell from %s to %s (%s / %s%%) versus the prior comparable period.',
                    $this->fmt($prevSessions),
                    $this->fmt($current['sessions'] ?? null),
                    $this->fmt($absSessions),
                    $this->fmt($pctSessions),
                ),
                'Compare acquisition channels and landing pages for session losses; validate tracking continuity.',
            );
        }

        return $matches;
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
            confidence: 0.82,
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

    private function fmtRatio(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'n/a';
        }

        return number_format(((float) $value) * 100, 2, '.', '').'%';
    }
}
