<?php

namespace App\Services\Observability;

use App\Enums\DataPool\FreshnessState;
use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\Observability\WorkerHeartbeat;
use App\Models\ResourceAutomation;
use App\Services\Async\AsyncWorkerHealth;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Services\Integrations\ResourceAutomationService;
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
        private readonly QueueWaitMonitor $queueWaits,
        private readonly AlertSubjects $subjects,
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
        $opened += $this->queueWaits->evaluate($this->lifecycle);
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
                title: 'Kuyrukta bekleyen iş birikti',
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
        // A deploy restarts every worker at once; a short gap is not an outage. Fire only when the newest heartbeat
        // is older than the alert threshold (default 10 min), and resolve as soon as heartbeats resume.
        $lastSeen = WorkerHeartbeat::query()->max('last_seen_at');
        $silentFor = $lastSeen !== null ? (int) abs(now()->diffInSeconds(CarbonImmutable::parse((string) $lastSeen))) : PHP_INT_MAX;
        $alertAfter = max((int) $snap['stale_seconds'], (int) config('moxdop-observability.worker.alert_after_seconds', 600));

        if ($expected !== [] && $snap['status']->value === 'UNHEALTHY' && $silentFor >= $alertAfter) {
            $this->lifecycle->observeCondition(
                ruleKey: 'worker_heartbeat_missing',
                ruleVersion: 1,
                ruleType: OperationalAlertRuleType::QueueWorkerUnavailable,
                family: OperationalSignalFamily::Worker,
                severity: OperationalAlertSeverity::Critical,
                scopeType: 'WORKER',
                scopeKey: $scope,
                title: 'Arka plan işçileri çalışmıyor',
                summary: $snap['message'],
                observed: [
                    'expected_supervisors' => $expected,
                    'fresh_heartbeats' => $snap['fresh_heartbeats'],
                    'stale_seconds' => $snap['stale_seconds'],
                ],
            );

            return 1;
        }

        // Heartbeats are back (all supervisors, or some of them — a partial gap is not "workers not running").
        if ($expected !== [] && in_array($snap['status']->value, ['HEALTHY', 'DEGRADED'], true)) {
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
                title: 'Takılı kalan veri çekimi var',
                summary: count($candidates).' veri çekimi uzun süredir ilerlemiyor.',
                observed: [
                    'candidate_count' => count($candidates),
                    'sample_run_ids' => array_slice(array_column($candidates, 'collection_run_id'), 0, 10),
                ] + $this->affectedOf($this->runRows(array_column($candidates, 'collection_run_id'), array_column($candidates, 'digital_asset_id', 'collection_run_id'), false)),
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
                title: 'Veri çekimleri tekrar tekrar başarısız',
                summary: sprintf('Son %d dakikada %d veri çekimi başarısız oldu; Arka plan işleri sayfasından inceleyin.', intdiv($window, 60), $failures->count()),
                observed: [
                    'failure_count' => $failures->count(),
                    'window_seconds' => $window,
                    'min_failures' => $min,
                    'sample_uuids' => $failures->take(5)->pluck('uuid')->all(),
                ] + $this->affectedOf($this->runRows($failures->pluck('id')->all(), $failures->pluck('digital_asset_id', 'id')->all(), true)),
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
                    title: 'Sağlayıcı istek sınırına takıldı',
                    summary: sprintf('%s: %d isteğin %d tanesi istek sınırına takıldı.', $provider, $attempts, $summary['rate_limits']),
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
                    title: 'Sağlayıcıdan çok fazla hata dönüyor',
                    summary: sprintf('%s: %d isteğin %d tanesi hata döndü.', $provider, $attempts, $summary['numerator_errors']),
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
                    title: 'Entegrasyon yeniden bağlanmalı',
                    summary: $integration->provider.' bağlantısı #'.$integration->id.' yeniden bağlanmalı.',
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
                    title: 'Entegrasyon yetkisi yakında bitiyor',
                    summary: $integration->provider.' bağlantısı #'.$integration->id.' izni '.$expiresAt->toDateString().' tarihinde bitiyor.',
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
        // Use Prompt27 due query — never max stored date / full history scan. Idle / dormant accounts are collected
        // weekly with the light set only: the due query judges them by that set with the weekly interval as grace.
        // Never activity_gate here: a gate pass is a planning record (savings log, provider change check), not a status read.
        try {
            $items = $this->dueCollections->query([
                'include_action_required' => true,
                'activity_tiers' => true,
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

        // Only accounts that serve an operational brand's asset page the operator (operator decision 2026-11-17: an
        // account not bound to a brand is never shown outside Marka adayları).
        $boundAssets = DigitalAsset::query()->whereIn('id', array_values(array_unique(array_filter(array_map(fn ($item) => $item->digitalAssetId, $staleOrBlocked)))))
            ->whereNotNull('brand_id')->whereHas('brand', fn ($q) => $q->operational())->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $staleOrBlocked = array_values(array_filter($staleOrBlocked, fn ($item): bool => $item->digitalAssetId !== null && $boundAssets->has((int) $item->digitalAssetId)));
        // Accounts not collected on purpose are not late.
        $parked = $this->parkedResources(array_map(fn ($item): ?int => $item->externalResourceId, $staleOrBlocked));
        $staleOrBlocked = array_values(array_filter($staleOrBlocked, fn ($item): bool => $item->externalResourceId === null || ! isset($parked[(int) $item->externalResourceId])));

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
                title: 'Bazı veriler güncel değil ya da çekilemiyor',
                summary: $staleCount.' hesap / veri kaynağında veri güncel değil.',
                observed: [
                    'stale_or_blocked_count' => $staleCount,
                    'hold_seconds' => $hold,
                    'freshness_source' => 'Prompt27 DueCollectionQueryService',
                ] + $this->affectedOf(array_map(fn ($item): array => [
                    'asset_id' => $item->digitalAssetId,
                    'resource_id' => $item->externalResourceId,
                    'dataset' => $item->datasetId,
                    'state' => $item->freshnessState->value,
                ], $staleOrBlocked)),
            );

            return 1;
        }

        $this->lifecycle->resolveIfActive('dataset_stale', 'SYSTEM', $scope);

        return 0;
    }

    /**
     * Accounts whose automatic collection does not run on purpose (ResourceAutomationService::isParked): switched off
     * by the operator, or parked by admission (Google Ads manager account, account reported not enabled). An account
     * that needs reconnecting is not parked: its data really stopped.
     *
     * @param  list<int|null>  $resourceIds
     * @return array<int, true> external resource id => true
     */
    private function parkedResources(array $resourceIds): array
    {
        $ids = array_values(array_unique(array_filter($resourceIds, fn (?int $id): bool => $id !== null)));
        if ($ids === []) {
            return [];
        }
        $readiness = app(ResourceAutomationService::class);
        $parked = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (ResourceAutomation::query()->with('resource.integration')->whereIn('external_resource_id', $chunk)->get() as $automation) {
                if ($readiness->isParked($automation)) {
                    $parked[(int) $automation->external_resource_id] = true;
                }
            }
        }

        return $parked;
    }

    /**
     * The named accounts / assets behind an aggregated alert (AlertSubjects), for "which accounts, which data, why".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{affected?: list<array<string, mixed>>, affected_total?: int}
     */
    private function affectedOf(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        try {
            $keys = array_unique(array_map(fn (array $row): string => ($row['resource_id'] ?? null) !== null ? 'r'.$row['resource_id'] : 'a'.($row['asset_id'] ?? 0), $rows));

            return ['affected' => $this->subjects->describe($rows), 'affected_total' => count($keys)];
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /**
     * Rows (asset, account, dataset, error) of collection runs: from their dataset runs, or the run's asset when a run
     * has none.
     *
     * @param  list<int>  $runIds
     * @param  array<int, int|null>  $assetByRun
     * @return list<array<string, mixed>>
     */
    private function runRows(array $runIds, array $assetByRun, bool $failedOnly): array
    {
        if ($runIds === []) {
            return [];
        }
        $rows = [];
        $seen = [];
        try {
            $datasets = CollectionDatasetRun::query()->with('resourceRun:id,external_resource_id,digital_asset_id')
                ->whereIn('collection_run_id', $runIds)
                ->when($failedOnly, fn ($q) => $q->whereNotNull('error_category'))
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'collection_run_id', 'collection_resource_run_id', 'dataset_contract_id', 'error_category']);
            foreach ($datasets as $dataset) {
                $category = $dataset->error_category;
                $rows[] = [
                    'asset_id' => $dataset->resourceRun?->digital_asset_id ?? ($assetByRun[$dataset->collection_run_id] ?? null),
                    'resource_id' => $dataset->resourceRun?->external_resource_id,
                    'dataset' => $dataset->dataset_contract_id,
                    'error_category' => $category instanceof \BackedEnum ? (string) $category->value : ($category !== null ? (string) $category : null),
                ];
                $seen[(int) $dataset->collection_run_id] = true;
            }
        } catch (Throwable $error) {
            report($error);
        }
        foreach ($runIds as $runId) {
            if (! isset($seen[(int) $runId]) && ($assetByRun[$runId] ?? null) !== null) {
                $rows[] = ['asset_id' => (int) $assetByRun[$runId]];
            }
        }

        return $rows;
    }
}
