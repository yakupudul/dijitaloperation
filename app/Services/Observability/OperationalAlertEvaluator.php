<?php

namespace App\Services\Observability;

use App\Enums\DataPool\FreshnessState;
use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Services\Async\AsyncWorkerHealth;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Deterministic Alert evaluation — no AI.
 * Reuses Prompt27 freshness, Prompt64 credentials, queue/worker signals.
 */
final class OperationalAlertEvaluator
{
    public function __construct(
        private readonly OperationalAlertLifecycleService $lifecycle,
        private readonly StuckCollectionDetector $stuck,
        private readonly WorkerHeartbeatService $workers,
        private readonly ProviderApiTelemetryService $providerApi,
        private readonly AsyncWorkerHealth $queueHealth,
        private readonly DueCollectionQueryService $dueCollections,
    ) {}

    /**
     * @return array{opened: int, resolved: int, updated: int}
     */
    public function evaluate(): array
    {
        $opened = 0;
        $resolved = 0;
        $updated = 0;

        $opened += $this->evaluateQueueBacklog();
        $opened += $this->evaluateWorkerHealth();
        $opened += $this->evaluateStuckCollections();
        $opened += $this->evaluateCollectionFailures();
        $opened += $this->evaluateProviderRates();
        $opened += $this->evaluateCredentials();
        $opened += $this->evaluateStaleDatasets();

        // Resolutions counted inside helpers via lifecycle; approximate from returns.
        return [
            'opened' => $opened,
            'resolved' => $resolved,
            'updated' => $updated,
        ];
    }

    private function evaluateQueueBacklog(): int
    {
        $snap = $this->queueHealth->snapshot();
        $age = $snap['oldest_queued_job_age_seconds'];
        $threshold = (int) config('moxdop-observability.queue.interactive_oldest_age_alert_seconds', 300);
        $hold = (int) config('moxdop-observability.queue.hold_duration_seconds', 120);
        $scope = 'queue:default';

        if ($age !== null && $age >= ($threshold + $hold) && (int) $snap['pending_jobs'] > 0) {
            $this->lifecycle->observeCondition(
                ruleKey: 'queue_interactive_backlog',
                ruleVersion: 1,
                ruleType: OperationalAlertRuleType::QueueBacklog,
                family: OperationalSignalFamily::Queue,
                severity: OperationalAlertSeverity::Warning,
                scopeType: 'QUEUE',
                scopeKey: $scope,
                title: 'Queue backlog: oldest waiting job exceeds policy',
                summary: $snap['message'],
                observed: [
                    'pending_jobs' => $snap['pending_jobs'],
                    'oldest_queued_job_age_seconds' => $age,
                    'threshold_seconds' => $threshold,
                    'hold_seconds' => $hold,
                ],
            );

            return 1;
        }

        $this->lifecycle->resolveIfActive('queue_interactive_backlog', 'QUEUE', $scope);

        return 0;
    }

    private function evaluateWorkerHealth(): int
    {
        $snap = $this->workers->snapshot();
        $scope = 'worker:system';
        $expected = $snap['expected_supervisors'];

        if ($expected !== [] && $snap['status']->value === 'UNHEALTHY') {
            $this->lifecycle->observeCondition(
                ruleKey: 'worker_heartbeat_missing',
                ruleVersion: 1,
                ruleType: OperationalAlertRuleType::QueueWorkerUnavailable,
                family: OperationalSignalFamily::Worker,
                severity: OperationalAlertSeverity::Critical,
                scopeType: 'WORKER',
                scopeKey: $scope,
                title: 'Expected workers unavailable',
                summary: $snap['message'],
                observed: [
                    'expected_supervisors' => $expected,
                    'fresh_heartbeats' => $snap['fresh_heartbeats'],
                    'stale_seconds' => $snap['stale_seconds'],
                ],
            );

            return 1;
        }

        if ($expected !== [] && $snap['status']->value === 'HEALTHY') {
            $this->lifecycle->resolveIfActive('worker_heartbeat_missing', 'WORKER', $scope);
        }

        return 0;
    }

    private function evaluateStuckCollections(): int
    {
        $candidates = $this->stuck->candidates();
        $scope = 'collection:stuck';

        if ($candidates !== []) {
            $this->lifecycle->observeCondition(
                ruleKey: 'collection_stuck',
                ruleVersion: 1,
                ruleType: OperationalAlertRuleType::CollectionStuck,
                family: OperationalSignalFamily::Collection,
                severity: OperationalAlertSeverity::Warning,
                scopeType: 'SYSTEM',
                scopeKey: $scope,
                title: 'Stuck collection run(s) detected',
                summary: count($candidates).' running collection(s) exceed workload-aware no-progress policy',
                observed: [
                    'candidate_count' => count($candidates),
                    'sample_run_ids' => array_slice(array_column($candidates, 'collection_run_id'), 0, 10),
                ],
            );

            return 1;
        }

        $this->lifecycle->resolveIfActive('collection_stuck', 'SYSTEM', $scope);

        return 0;
    }

    private function evaluateCollectionFailures(): int
    {
        $window = 3600;
        $min = 3;
        $rule = collect(config('moxdop-observability.rules', []))
            ->firstWhere('key', 'collection_repeated_failure');
        if (is_array($rule)) {
            $window = (int) ($rule['window_seconds'] ?? 3600);
            $min = (int) ($rule['min_failures'] ?? 3);
        }

        $failures = $this->stuck->recentFailures($window);
        $scope = 'collection:failures';

        if ($failures->count() >= $min) {
            $this->lifecycle->observeCondition(
                ruleKey: 'collection_repeated_failure',
                ruleVersion: 1,
                ruleType: OperationalAlertRuleType::CollectionRepeatedFailure,
                family: OperationalSignalFamily::Collection,
                severity: OperationalAlertSeverity::Warning,
                scopeType: 'SYSTEM',
                scopeKey: $scope,
                title: 'Repeated collection failures',
                summary: $failures->count().' failed CollectionRun(s) in the last '.$window.'s',
                observed: [
                    'failure_count' => $failures->count(),
                    'window_seconds' => $window,
                    'min_failures' => $min,
                    'sample_uuids' => $failures->take(5)->pluck('uuid')->all(),
                ],
            );

            return 1;
        }

        $this->lifecycle->resolveIfActive('collection_repeated_failure', 'SYSTEM', $scope);

        return 0;
    }

    private function evaluateProviderRates(): int
    {
        $opened = 0;
        $providers = [
            ProviderRegistry::GOOGLE,
            ProviderRegistry::META,
            'openai',
            'dataforseo',
        ];
        $operation = 'http';
        $minAttempts = (int) config('moxdop-observability.provider_api.error_rate_minimum_attempts', 20);
        $errorThreshold = (float) config('moxdop-observability.provider_api.error_rate_threshold', 0.35);
        $rlMin = (int) config('moxdop-observability.provider_api.rate_limit_minimum_attempts', 10);
        $rlThreshold = (float) config('moxdop-observability.provider_api.rate_limit_threshold', 0.25);

        foreach ($providers as $provider) {
            $summary = $this->providerApi->rateSummary($provider, $operation);
            $scope = 'provider:'.$provider;

            $attempts = $summary['denominator_attempts'];
            $errorRate = $summary['error_rate'];
            $rlRate = $summary['rate_limit_rate'];

            if ($attempts >= $rlMin && $rlRate !== null && $rlRate >= $rlThreshold) {
                $this->lifecycle->observeCondition(
                    ruleKey: 'provider_rate_limited',
                    ruleVersion: 1,
                    ruleType: OperationalAlertRuleType::ProviderRateLimited,
                    family: OperationalSignalFamily::ProviderApi,
                    severity: OperationalAlertSeverity::Warning,
                    scopeType: 'PROVIDER',
                    scopeKey: $scope,
                    title: 'Provider rate limited',
                    summary: strtoupper($provider).' rate_limit_rate='.$rlRate.' ('.$summary['rate_limits'].'/'.$attempts.')',
                    observed: $summary,
                );
                $opened++;
            } else {
                $this->lifecycle->resolveIfActive('provider_rate_limited', 'PROVIDER', $scope);
            }

            if ($attempts >= $minAttempts && $errorRate !== null && $errorRate >= $errorThreshold) {
                $this->lifecycle->observeCondition(
                    ruleKey: 'provider_error_rate',
                    ruleVersion: 1,
                    ruleType: OperationalAlertRuleType::ProviderErrorRate,
                    family: OperationalSignalFamily::ProviderApi,
                    severity: OperationalAlertSeverity::Warning,
                    scopeType: 'PROVIDER',
                    scopeKey: $scope,
                    title: 'Provider error rate above policy',
                    summary: strtoupper($provider).' error_rate='.$errorRate.' ('.$summary['numerator_errors'].'/'.$attempts.')',
                    observed: $summary,
                );
                $opened++;
            } else {
                $this->lifecycle->resolveIfActive('provider_error_rate', 'PROVIDER', $scope);
            }
        }

        return $opened;
    }

    private function evaluateCredentials(): int
    {
        $opened = 0;
        try {
            $integrations = CoreIntegration::query()
                ->whereIn('provider', [ProviderRegistry::GOOGLE, ProviderRegistry::META])
                ->limit(200)
                ->get();
        } catch (Throwable) {
            return 0;
        }

        foreach ($integrations as $integration) {
            $config = is_array($integration->config) ? $integration->config : [];
            $status = strtoupper((string) ($config['auth_status'] ?? $config['status'] ?? ''));
            $scope = 'integration:'.(int) $integration->id;

            // Google: refresh_required / revoked; Meta: reauth_required / permission_required or a dead token.
            $reconnect = in_array($status, [
                'RECONNECT_REQUIRED',
                'REFRESH_REQUIRED',
                'REVOKED',
                'EXPIRED',
                'REAUTH_REQUIRED',
                'PERMISSION_REQUIRED',
            ], true) || in_array(strtolower((string) ($config['credential_status'] ?? '')), ['expired', 'revoked', 'invalid', 'wrong_app'], true);

            if ($reconnect) {
                $this->lifecycle->observeCondition(
                    ruleKey: 'credential_reconnect_required',
                    ruleVersion: 1,
                    ruleType: OperationalAlertRuleType::ProviderAuthFailure,
                    family: OperationalSignalFamily::Credential,
                    severity: OperationalAlertSeverity::Critical,
                    scopeType: 'INTEGRATION',
                    scopeKey: $scope,
                    title: 'Integration reconnect required',
                    summary: 'Provider '.$integration->provider.' integration #'.$integration->id.' status='.$status,
                    observed: [
                        'integration_id' => (int) $integration->id,
                        'provider' => (string) $integration->provider,
                        'auth_status' => $status,
                    ],
                );
                $opened++;
            } else {
                $this->lifecycle->resolveIfActive('credential_reconnect_required', 'INTEGRATION', $scope);
            }

            $expiresAt = $reconnect ? null : $this->credentialExpiry($integration, $config);
            $warnDays = (int) config('moxdop-observability.credential_expiry_warning_days', 7);
            if ($expiresAt !== null && $expiresAt->isFuture() && $expiresAt->lte(now()->addDays($warnDays))) {
                $this->lifecycle->observeCondition(
                    ruleKey: 'credential_expiring',
                    ruleVersion: 1,
                    ruleType: OperationalAlertRuleType::ProviderAuthFailure,
                    family: OperationalSignalFamily::Credential,
                    severity: OperationalAlertSeverity::Warning,
                    scopeType: 'INTEGRATION',
                    scopeKey: $scope,
                    title: 'Integration authorization expires soon',
                    summary: 'Provider '.$integration->provider.' integration #'.$integration->id.' expires_at='.$expiresAt->toIso8601String(),
                    observed: [
                        'integration_id' => (int) $integration->id,
                        'provider' => (string) $integration->provider,
                        'expires_at' => $expiresAt->toIso8601String(),
                    ],
                );
                $opened++;
            } else {
                $this->lifecycle->resolveIfActive('credential_expiring', 'INTEGRATION', $scope);
            }
        }

        return $opened;
    }

    /**
     * When the stored authorization stops working without a reconnect: Google refresh tokens of apps in
     * testing expire (refresh_token_expires_at); Meta long-lived user tokens expire (authorization credential
     * expires_at). Google access-token expiry is not used: it is refreshed automatically.
     *
     * @param  array<string, mixed>  $config
     */
    private function credentialExpiry(CoreIntegration $integration, array $config): ?CarbonImmutable
    {
        try {
            if ($integration->provider === ProviderRegistry::GOOGLE) {
                $value = $config['refresh_token_expires_at'] ?? null;

                return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
            }
            $expires = CoreIntegrationCredential::query()->where('integration_id', $integration->id)
                ->where('credential_type', CoreIntegrationCredential::TYPE_AUTHORIZATION)->value('expires_at');

            return $expires !== null ? CarbonImmutable::parse((string) $expires) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function evaluateStaleDatasets(): int
    {
        // Use Prompt27 due query — never max stored date / full history scan.
        try {
            $items = $this->dueCollections->query([
                'include_action_required' => true,
            ]);
        } catch (Throwable) {
            return 0;
        }

        $staleOrBlocked = [];
        foreach ($items as $item) {
            $state = $item->freshnessState;
            if (in_array($state, [
                FreshnessState::Stale,
                FreshnessState::IntegrityBlocked,
                FreshnessState::ActionRequired,
            ], true)) {
                // PROVIDER_LIMITED is not a system failure — exclude from this alert.
                $staleOrBlocked[] = $item;
            }
        }

        $staleCount = count($staleOrBlocked);
        $scope = 'dataset:stale';
        $hold = (int) config('moxdop-observability.dataset.stale_hold_seconds', 1800);

        if ($staleCount > 0) {
            $this->lifecycle->observeCondition(
                ruleKey: 'dataset_stale',
                ruleVersion: 1,
                ruleType: OperationalAlertRuleType::DatasetStale,
                family: OperationalSignalFamily::Dataset,
                severity: OperationalAlertSeverity::Warning,
                scopeType: 'SYSTEM',
                scopeKey: $scope,
                title: 'Datasets reported STALE / BLOCKED by Prompt27',
                summary: $staleCount.' Resource×Dataset row(s) require attention (hold_seconds='.$hold.')',
                observed: [
                    'stale_or_blocked_count' => $staleCount,
                    'hold_seconds' => $hold,
                    'freshness_source' => 'Prompt27 DueCollectionQueryService',
                ],
            );

            return 1;
        }

        $this->lifecycle->resolveIfActive('dataset_stale', 'SYSTEM', $scope);

        return 0;
    }
}
