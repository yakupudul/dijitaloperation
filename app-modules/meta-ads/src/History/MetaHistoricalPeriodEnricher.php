<?php

namespace MoxDop\MetaAds\History;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaException;
use App\Support\Integrations\Meta\MetaApiConfig;
use MoxDop\MetaAds\Models\MetaAdsPeriodAggregate;

/**
 * Fills the exact-period aggregate cache for non-additive metrics (reach, frequency).
 * These are never derived by summing/averaging `meta_ads_daily_facts` rows — only an
 * exact provider request for the precise [date_from, date_to] range is trustworthy.
 */
final class MetaHistoricalPeriodEnricher
{
    public function __construct(
        private readonly MetaApiClient $client,
        private readonly MetaHistoricalUpserter $upserter,
    ) {}

    /**
     * Idempotent readiness check: if reach+frequency are already `ready` for this exact
     * range, does nothing. Otherwise marks both `pending` (creating the cache row(s) if
     * needed) and returns — the caller is responsible for enqueuing the actual fetch
     * (e.g. via fetchAndStoreExactPeriod(), typically from a queued job).
     *
     * @return array{status: 'ready'|'pending', enqueued: bool}
     */
    public function ensureExactPeriodMetrics(
        CoreIntegration $integration,
        CoreExternalResource $resource,
        string $entityType,
        string $entityId,
        string $from,
        string $to,
        string $attributionContext = MetaAdsPeriodAggregate::ATTRIBUTION_CONTEXT_UNIFIED,
    ): array {
        $existing = MetaAdsPeriodAggregate::query()
            ->where('core_external_resource_id', $resource->id)
            ->where('entity_type', $entityType)
            ->where('provider_external_id', $entityId)
            ->where('date_from', $from)
            ->where('date_to', $to)
            ->where('attribution_context', $attributionContext)
            ->whereIn('metric_key', [MetaAdsPeriodAggregate::METRIC_REACH, MetaAdsPeriodAggregate::METRIC_FREQUENCY])
            ->get()
            ->keyBy('metric_key');

        $ready = fn (string $metric): bool => $existing->get($metric)?->status === MetaAdsPeriodAggregate::STATUS_READY;

        if ($ready(MetaAdsPeriodAggregate::METRIC_REACH) && $ready(MetaAdsPeriodAggregate::METRIC_FREQUENCY)) {
            return ['status' => 'ready', 'enqueued' => false];
        }

        foreach ([MetaAdsPeriodAggregate::METRIC_REACH, MetaAdsPeriodAggregate::METRIC_FREQUENCY] as $metricKey) {
            if ($ready($metricKey)) {
                continue;
            }

            $this->upserter->upsertPeriodAggregate([
                'core_integration_id' => $integration->id,
                'core_external_resource_id' => $resource->id,
                'entity_type' => $entityType,
                'provider_external_id' => $entityId,
                'date_from' => $from,
                'date_to' => $to,
                'attribution_context' => $attributionContext,
                'metric_key' => $metricKey,
                'metric_value' => null,
                'status' => MetaAdsPeriodAggregate::STATUS_PENDING,
            ]);
        }

        return ['status' => 'pending', 'enqueued' => true];
    }

    /**
     * Synchronously fetches the exact [date_from, date_to] reach/frequency for one
     * entity from the Meta Marketing API and stores both as period aggregates.
     * GET-only; the entity's own `/insights` edge is queried directly (no `level`
     * parameter needed since the node itself scopes the request).
     *
     * @return array{status: 'ready'|'unavailable'|'failed', reach: ?float, frequency: ?float, error: ?string}
     */
    public function fetchAndStoreExactPeriod(
        CoreIntegration $integration,
        CoreExternalResource $resource,
        string $entityType,
        string $entityId,
        string $from,
        string $to,
        ?Run $run = null,
        string $attributionContext = MetaAdsPeriodAggregate::ATTRIBUTION_CONTEXT_UNIFIED,
    ): array {
        try {
            $payload = MetaHistoricalRetry::attempt(fn (): array => $this->client->get($integration, $entityId.'/insights', [
                'fields' => 'reach,frequency',
                'time_range' => json_encode(['since' => $from, 'until' => $to], JSON_THROW_ON_ERROR),
                'use_unified_attribution_setting' => 'true',
            ]));
        } catch (MetaException $exception) {
            $this->storeBoth($integration, $resource, $entityType, $entityId, $from, $to, $attributionContext, null, null, MetaAdsPeriodAggregate::STATUS_FAILED, $run, $exception->getMessage());

            return ['status' => 'failed', 'reach' => null, 'frequency' => null, 'error' => $exception->getMessage()];
        }

        $row = is_array($payload['data'][0] ?? null) ? $payload['data'][0] : [];
        $reach = isset($row['reach']) && is_numeric($row['reach']) ? (float) $row['reach'] : null;
        $frequency = isset($row['frequency']) && is_numeric($row['frequency']) ? (float) $row['frequency'] : null;

        $status = ($reach !== null || $frequency !== null) ? MetaAdsPeriodAggregate::STATUS_READY : MetaAdsPeriodAggregate::STATUS_UNAVAILABLE;

        $this->storeBoth($integration, $resource, $entityType, $entityId, $from, $to, $attributionContext, $reach, $frequency, $status, $run, null);

        return ['status' => $status, 'reach' => $reach, 'frequency' => $frequency, 'error' => null];
    }

    private function storeBoth(
        CoreIntegration $integration,
        CoreExternalResource $resource,
        string $entityType,
        string $entityId,
        string $from,
        string $to,
        string $attributionContext,
        ?float $reach,
        ?float $frequency,
        string $status,
        ?Run $run,
        ?string $error,
    ): void {
        $provenance = array_filter([
            'api_version' => MetaApiConfig::apiVersion(),
            'error' => $error,
        ], fn (mixed $value): bool => $value !== null);

        $this->upserter->upsertPeriodAggregate([
            'core_integration_id' => $integration->id,
            'core_external_resource_id' => $resource->id,
            'entity_type' => $entityType,
            'provider_external_id' => $entityId,
            'date_from' => $from,
            'date_to' => $to,
            'attribution_context' => $attributionContext,
            'metric_key' => MetaAdsPeriodAggregate::METRIC_REACH,
            'metric_value' => $reach,
            'status' => $reach !== null ? MetaAdsPeriodAggregate::STATUS_READY : $status,
            'provenance' => $provenance,
            'run_id' => $run?->id,
            'fetched_at' => now(),
        ]);

        $this->upserter->upsertPeriodAggregate([
            'core_integration_id' => $integration->id,
            'core_external_resource_id' => $resource->id,
            'entity_type' => $entityType,
            'provider_external_id' => $entityId,
            'date_from' => $from,
            'date_to' => $to,
            'attribution_context' => $attributionContext,
            'metric_key' => MetaAdsPeriodAggregate::METRIC_FREQUENCY,
            'metric_value' => $frequency,
            'status' => $frequency !== null ? MetaAdsPeriodAggregate::STATUS_READY : $status,
            'provenance' => $provenance,
            'run_id' => $run?->id,
            'fetched_at' => now(),
        ]);
    }
}
