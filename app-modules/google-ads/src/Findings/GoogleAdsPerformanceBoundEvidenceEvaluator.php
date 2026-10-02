<?php

namespace MoxDop\GoogleAds\Findings;

use App\Contracts\Findings\EvaluatesBoundEvidence;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use DateTimeInterface;
use MoxDop\GoogleAds\Collection\GoogleAdsBoundCollector;

/**
 * Deterministic Google Ads account + campaign + search-term + measurement rules from Binding Evidence.
 *
 * Search-term Findings are CANDIDATES for human investigation — never external writes.
 * Failed/unusable search-term Evidence does not evaluate those rules (old Findings stay open).
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

        $searchTerms = $this->usableEvidence($runs, GoogleAdsBoundCollector::EVIDENCE_TYPE_SEARCH_TERM_PERFORMANCE);
        if ($searchTerms !== null) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, PerformanceFindingsCatalog::SEARCH_TERM_RULE_IDS);
            $anchorRun = $searchTerms['run'];
            $observedAt = $searchTerms['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $this->evaluateSearchTerms($searchTerms['evidence']));
        }

        $measurement = $this->usableEvidence($runs, GoogleAdsBoundCollector::EVIDENCE_TYPE_CONVERSION_ACTIONS);
        if ($measurement !== null) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, PerformanceFindingsCatalog::MEASUREMENT_RULE_IDS);
            $anchorRun = $measurement['run'];
            $observedAt = $measurement['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $this->evaluateMeasurement($measurement['evidence']));
        }

        $landing = $this->usableLandingEvidence($runs);
        if ($landing !== null) {
            $evaluatedRuleIds = array_merge($evaluatedRuleIds, PerformanceFindingsCatalog::LANDING_RULE_IDS);
            $anchorRun = $landing['run'];
            $observedAt = $landing['evidence']->observed_at ?? $observedAt;
            $matches = array_merge($matches, $this->evaluateLanding($landing['evidence']));
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
            $typed = $run->evidence->filter(fn (Evidence $row): bool => $row->type === $type)->values();
            if ($typed->isEmpty()) {
                continue;
            }

            $usable = $typed->first(fn (Evidence $row): bool => data_get($row->payload, 'response_ok') === true);
            if ($usable instanceof Evidence) {
                return ['run' => $run, 'evidence' => $usable];
            }

            // Latest completed Run attempted this Evidence type but it was not usable —
            // do not fall back to older Runs (avoids false resolution after failed collection).
            return null;
        }

        return null;
    }

    /**
     * Landing Evidence historically used payload.ok (compat) — accept ok or response_ok.
     *
     * @param  list<Run>  $runs
     * @return array{run: Run, evidence: Evidence}|null
     */
    private function usableLandingEvidence(array $runs): ?array
    {
        for ($i = count($runs) - 1; $i >= 0; $i--) {
            $run = $runs[$i];
            if ($run->status !== 'completed') {
                continue;
            }

            $run->loadMissing('evidence');
            $evidence = $run->evidence->first(function (Evidence $row): bool {
                if ($row->type !== GoogleAdsBoundCollector::EVIDENCE_TYPE_LANDING_FINAL_URLS) {
                    return false;
                }

                return data_get($row->payload, 'response_ok') === true
                    || data_get($row->payload, 'ok') === true;
            });

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
                        'CPA rose from %s to %s (%s%%) with prior cost %s and conversions %s → %s. Platform CPA is not verified business profitability.',
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

            $cost = $this->floatOrNull($row['cost'] ?? null);
            $clicks = $this->floatOrNull($row['clicks'] ?? null);
            $conversions = $this->floatOrNull($row['conversions'] ?? null);
            if ($conversions === null || ($cost === null && $clicks === null)) {
                continue;
            }

            $cost = $cost ?? 0.0;
            $clicks = $clicks ?? 0.0;
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
                    $this->safeLabel($name),
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

    /**
     * @return list<RuleMatch>
     */
    private function evaluateSearchTerms(Evidence $evidence): array
    {
        $rows = $evidence->payload['rows'] ?? null;
        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $waste = [];
        $opportunity = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $term = trim((string) ($row['search_term'] ?? ''));
            if ($term === '') {
                continue;
            }

            $campaignId = trim((string) ($row['campaign_id'] ?? ''));
            $adGroupId = trim((string) ($row['ad_group_id'] ?? ''));
            $cost = $this->floatOrNull($row['cost'] ?? null) ?? 0.0;
            $clicks = $this->floatOrNull($row['clicks'] ?? null) ?? 0.0;
            $conversions = $this->floatOrNull($row['conversions'] ?? null) ?? 0.0;
            $status = strtoupper(trim((string) ($row['targeting_status'] ?? '')));
            $source = (string) ($row['source_report'] ?? '');
            $channel = (string) ($row['advertising_channel_type'] ?? '');

            $enoughWasteSample = $cost >= PerformanceFindingsCatalog::SEARCH_WASTE_COST_MIN
                || $clicks >= PerformanceFindingsCatalog::SEARCH_WASTE_CLICKS_MIN;

            if (
                $enoughWasteSample
                && $conversions <= 0.0001
                && $status !== 'EXCLUDED'
                && $status !== 'ADDED_EXCLUDED'
                && count($waste) < PerformanceFindingsCatalog::SEARCH_WASTE_FINDINGS_MAX
            ) {
                $fingerprint = PerformanceFindingsCatalog::RULE_SEARCH_TERM_WASTE_CANDIDATE
                    .':'.sha1(strtolower($term).'|'.$campaignId.'|'.$adGroupId.'|'.$source);
                $waste[] = $this->match(
                    PerformanceFindingsCatalog::RULE_SEARCH_TERM_WASTE_CANDIDATE,
                    $fingerprint,
                    'medium',
                    'Search term waste candidate',
                    sprintf(
                        'Search term (untrusted text) in campaign %s consumed cost %s / clicks %s with zero observed conversions in the analyzed period (source %s%s). Candidate for investigation — not an automatic negative keyword.',
                        $campaignId !== '' ? $campaignId : 'n/a',
                        $this->fmt($cost),
                        $this->fmt($clicks),
                        $source !== '' ? $source : 'unknown',
                        $channel !== '' ? ', channel '.$channel : '',
                    ),
                    'Review cited search-term Evidence for negative-keyword candidacy. Do not auto-apply negatives in Google Ads.',
                );
            }

            $statusKnownNone = $status === 'NONE';
            $statusUnknownPmax = $status === '' && $source === GoogleAdsBoundCollector::SOURCE_REPORT_CAMPAIGN_SEARCH_TERM_VIEW;

            if (
                $conversions >= PerformanceFindingsCatalog::SEARCH_OPP_CONVERSIONS_MIN
                && $clicks >= PerformanceFindingsCatalog::SEARCH_OPP_CLICKS_MIN
                && ($statusKnownNone || $statusUnknownPmax)
                && $status !== 'ADDED'
                && $status !== 'EXCLUDED'
                && $status !== 'ADDED_EXCLUDED'
                && count($opportunity) < PerformanceFindingsCatalog::SEARCH_OPP_FINDINGS_MAX
            ) {
                $fingerprint = PerformanceFindingsCatalog::RULE_SEARCH_TERM_OPPORTUNITY_CANDIDATE
                    .':'.sha1(strtolower($term).'|'.$campaignId.'|'.$adGroupId.'|'.$source);
                $opportunity[] = $this->match(
                    PerformanceFindingsCatalog::RULE_SEARCH_TERM_OPPORTUNITY_CANDIDATE,
                    $fingerprint,
                    'medium',
                    'Search term opportunity candidate',
                    sprintf(
                        'Search term (untrusted text) in campaign %s recorded conversions %s with clicks %s; targeting status %s (source %s). Candidate for keyword coverage review — not an automatic add.',
                        $campaignId !== '' ? $campaignId : 'n/a',
                        $this->fmt($conversions),
                        $this->fmt($clicks),
                        $status !== '' ? $status : 'unavailable',
                        $source !== '' ? $source : 'unknown',
                    ),
                    'Review cited search-term Evidence for keyword coverage. Do not auto-add keywords in Google Ads.',
                );
            }
        }

        return [...$waste, ...$opportunity];
    }

    /**
     * @return list<RuleMatch>
     */
    private function evaluateMeasurement(Evidence $evidence): array
    {
        $usable = (int) ($evidence->payload['usable_primary_or_included_count'] ?? 0);
        $enabled = (int) ($evidence->payload['enabled_count'] ?? 0);
        $actionCount = (int) ($evidence->payload['action_count'] ?? 0);

        if ($actionCount === 0 || ($enabled > 0 && $usable === 0) || $enabled === 0) {
            return [
                $this->match(
                    PerformanceFindingsCatalog::RULE_MEASUREMENT_CONFIG_RISK,
                    PerformanceFindingsCatalog::RULE_MEASUREMENT_CONFIG_RISK,
                    'high',
                    'Measurement configuration risk',
                    sprintf(
                        'Collected conversion-action configuration shows %s actions (%s enabled, %s primary/included). This is configuration Evidence only — it does not prove browser tags or CRM events work.',
                        $this->fmt($actionCount),
                        $this->fmt($enabled),
                        $this->fmt($usable),
                    ),
                    'Review Google Ads conversion action configuration and account/campaign goals. Do not claim tracking is broken without browser/CRM validation.',
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<RuleMatch>
     */
    private function evaluateLanding(Evidence $evidence): array
    {
        $count = (int) ($evidence->payload['final_url_count'] ?? count($evidence->payload['final_urls'] ?? []));
        if ($count > 0) {
            return [];
        }

        return [
            $this->match(
                PerformanceFindingsCatalog::RULE_LANDING_URL_COVERAGE_RISK,
                PerformanceFindingsCatalog::RULE_LANDING_URL_COVERAGE_RISK,
                'medium',
                'Landing URL coverage risk',
                'No final URLs were present in collected Google Ads landing Evidence for active ad_group_ad rows in this Run.',
                'Confirm ads are serving with final URLs and re-collect read-only Ads Evidence before judging landing alignment.',
            ),
        ];
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

    /**
     * Campaign/ad names are untrusted provider text — truncate for Finding summary only.
     */
    private function safeLabel(string $value): string
    {
        $trimmed = trim($value);
        if (mb_strlen($trimmed) <= 80) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, 77).'...';
    }
}
