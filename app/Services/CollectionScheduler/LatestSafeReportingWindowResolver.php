<?php

namespace App\Services\CollectionScheduler;

use App\Services\DataPool\Freshness\CollectableEndResolver;
use App\Services\DataPool\Freshness\DataFreshnessPolicyLoader;
use App\Support\CollectionScheduler\LatestSafeReportingWindow;
use DateTimeInterface;

/**
 * Dataset-specific latest-safe reporting frontier (Prompt 62).
 * Wraps Prompt 27 CollectableEndResolver — does not invent lag constants.
 */
final class LatestSafeReportingWindowResolver
{
    public function __construct(
        private readonly DataFreshnessPolicyLoader $policies,
        private readonly CollectableEndResolver $collectableEnd = new CollectableEndResolver,
    ) {}

    public function resolve(
        string $datasetId,
        ?string $reportingTimezone = null,
        ?DateTimeInterface $clockNow = null,
    ): LatestSafeReportingWindow {
        $policy = $this->policies->policy($datasetId);
        $policyVersion = $policy !== null
            ? (int) ($policy['policy_version'] ?? $this->policies->version())
            : $this->policies->version();

        if ($policy === null) {
            return new LatestSafeReportingWindow(
                status: 'POLICY_BLOCKED',
                latestSafeDate: null,
                providerLocalReportingDate: null,
                timezone: $reportingTimezone ?: 'UTC',
                policyVersion: $policyVersion,
                reason: 'POLICY_NOT_CONFIGURED',
            );
        }

        if (($policy['incremental_applicable'] ?? true) === false) {
            return new LatestSafeReportingWindow(
                status: 'UNSUPPORTED',
                latestSafeDate: null,
                providerLocalReportingDate: null,
                timezone: $reportingTimezone ?: 'UTC',
                policyVersion: $policyVersion,
                reason: (string) ($policy['non_applicable_reason'] ?? 'UNSUPPORTED'),
            );
        }

        if (($policy['safe_collection_lag_days'] ?? null) === null
            && ($policy['collection_mode'] ?? '') !== 'CURRENT_SNAPSHOT'
            && ($policy['collection_mode'] ?? '') !== 'current_snapshot') {
            // Lag must be explicit for daily watermark modes — never guess.
            $mode = (string) ($policy['collection_mode'] ?? '');
            if (in_array($mode, ['HISTORICAL_INCREMENTAL', 'PERIOD_OBSERVATION', 'historical_incremental', 'period_observation'], true)) {
                return new LatestSafeReportingWindow(
                    status: 'POLICY_BLOCKED',
                    latestSafeDate: null,
                    providerLocalReportingDate: null,
                    timezone: $reportingTimezone ?: 'UTC',
                    policyVersion: $policyVersion,
                    reason: 'POLICY_NOT_CONFIGURED',
                );
            }
        }

        $tz = $reportingTimezone;
        if ($tz === null || $tz === '') {
            $source = (string) ($policy['timezone_source'] ?? '');
            $tz = $source === 'gsc_reporting_date_semantics' ? 'America/Los_Angeles' : 'UTC';
        }

        $localToday = $this->collectableEnd->providerLocalReportingDate($policy, $tz, $clockNow);
        $end = $this->collectableEnd->resolve($policy, $tz, $clockNow);

        if ($end === null) {
            return new LatestSafeReportingWindow(
                status: 'NOT_YET_AVAILABLE',
                latestSafeDate: null,
                providerLocalReportingDate: $localToday,
                timezone: $tz,
                policyVersion: $policyVersion,
                reason: 'NO_SAFE_INTERVAL',
            );
        }

        return new LatestSafeReportingWindow(
            status: 'AVAILABLE',
            latestSafeDate: $end,
            providerLocalReportingDate: $localToday,
            timezone: $tz,
            policyVersion: $policyVersion,
            reason: null,
        );
    }
}
