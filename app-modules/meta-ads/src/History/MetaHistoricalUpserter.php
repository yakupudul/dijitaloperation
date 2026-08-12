<?php

namespace MoxDop\MetaAds\History;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use Illuminate\Support\Arr;
use MoxDop\MetaAds\Models\MetaAdsDailyAction;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsEntity;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Models\MetaAdsPeriodAggregate;

/**
 * Idempotent writers for the Meta Ads historical store. Every method upserts by the
 * table's natural (provider-identity) unique key — repeated calls with the same
 * identity never create duplicates.
 *
 * Only explicitly provided keys are persisted: omitting a key preserves whatever was
 * already stored, while passing `null` explicitly records "provider returned no value
 * this time" (missing != zero either way — no column is ever coerced to 0).
 */
final class MetaHistoricalUpserter
{
    /**
     * @param  array{
     *     entity_type: string,
     *     provider_external_id: string,
     *     parent_provider_external_id?: ?string,
     *     name?: ?string,
     *     status?: ?string,
     *     objective?: ?string,
     *     optimization_goal?: ?string,
     *     destination_type?: ?string,
     *     creative_provider_id?: ?string,
     *     currency?: ?string,
     *     metadata?: ?array<string, mixed>,
     * }  $attributes
     */
    public function upsertEntity(CoreIntegration $integration, CoreExternalResource $resource, array $attributes): MetaAdsEntity
    {
        $now = now();

        /** @var MetaAdsEntity $entity */
        $entity = MetaAdsEntity::query()->firstOrNew([
            'core_external_resource_id' => $resource->id,
            'entity_type' => $attributes['entity_type'],
            'provider_external_id' => $attributes['provider_external_id'],
        ]);

        $entity->core_integration_id = $integration->id;
        $entity->fill(Arr::only($attributes, [
            'parent_provider_external_id',
            'name',
            'status',
            'objective',
            'optimization_goal',
            'destination_type',
            'creative_provider_id',
            'currency',
            'metadata',
        ]));

        if (! $entity->exists) {
            $entity->first_seen_at = $now;
        }
        $entity->last_seen_at = $now;
        $entity->save();

        return $entity;
    }

    /**
     * @param  array{
     *     core_integration_id: int,
     *     core_external_resource_id: int,
     *     entity_type: string,
     *     provider_external_id: string,
     *     date: string,
     * }  $row
     */
    public function upsertDailyFact(array $row): MetaAdsDailyFact
    {
        $key = Arr::only($row, ['core_external_resource_id', 'entity_type', 'provider_external_id', 'date']);
        $values = Arr::except($row, ['core_external_resource_id', 'entity_type', 'provider_external_id', 'date']);

        /** @var MetaAdsDailyFact $fact */
        $fact = MetaAdsDailyFact::query()->updateOrCreate($key, $values);

        return $fact;
    }

    /**
     * @param  list<array{
     *     core_integration_id: int,
     *     core_external_resource_id: int,
     *     entity_type: string,
     *     provider_external_id: string,
     *     date: string,
     *     raw_action_type: string,
     * }>  $rows
     * @return list<MetaAdsDailyAction>
     */
    public function upsertDailyActions(array $rows): array
    {
        return array_map(fn (array $row): MetaAdsDailyAction => $this->upsertDailyAction($row), $rows);
    }

    /**
     * @param  array{
     *     core_integration_id: int,
     *     core_external_resource_id: int,
     *     entity_type: string,
     *     provider_external_id: string,
     *     date: string,
     *     raw_action_type: string,
     *     attribution_window?: ?string,
     * }  $row
     */
    public function upsertDailyAction(array $row): MetaAdsDailyAction
    {
        $row['attribution_window'] = (string) ($row['attribution_window'] ?? '');

        $key = Arr::only($row, [
            'core_external_resource_id',
            'entity_type',
            'provider_external_id',
            'date',
            'raw_action_type',
            'attribution_window',
        ]);
        $values = Arr::except($row, array_keys($key));

        /** @var MetaAdsDailyAction $action */
        $action = MetaAdsDailyAction::query()->updateOrCreate($key, $values);

        return $action;
    }

    /**
     * @param  array{
     *     core_integration_id: int,
     *     core_external_resource_id: int,
     *     entity_type: string,
     *     provider_external_id: string,
     *     date_from: string,
     *     date_to: string,
     *     metric_key: string,
     *     attribution_context?: string,
     * }  $row
     */
    public function upsertPeriodAggregate(array $row): MetaAdsPeriodAggregate
    {
        $row['attribution_context'] = (string) ($row['attribution_context'] ?? MetaAdsPeriodAggregate::ATTRIBUTION_CONTEXT_UNIFIED);

        $key = Arr::only($row, [
            'core_external_resource_id',
            'entity_type',
            'provider_external_id',
            'date_from',
            'date_to',
            'attribution_context',
            'metric_key',
        ]);
        $values = Arr::except($row, array_keys($key));

        /** @var MetaAdsPeriodAggregate $aggregate */
        $aggregate = MetaAdsPeriodAggregate::query()->updateOrCreate($key, $values);

        return $aggregate;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateCoverage(
        CoreIntegration $integration,
        CoreExternalResource $resource,
        string $dataLayer,
        array $attributes,
        string $granularity = 'day',
    ): MetaAdsHistoryCoverage {
        /** @var MetaAdsHistoryCoverage $coverage */
        $coverage = MetaAdsHistoryCoverage::query()->firstOrNew([
            'core_external_resource_id' => $resource->id,
            'data_layer' => $dataLayer,
            'granularity' => $granularity,
        ]);

        $coverage->core_integration_id = $integration->id;
        $coverage->fill(Arr::only($attributes, [
            'start_date',
            'end_date',
            'status',
            'last_successful_sync_at',
            'earliest_provider_date',
            'latest_provider_date',
            'gaps',
            'import_run_id',
            'summary',
        ]));

        if (! $coverage->status) {
            $coverage->status = MetaAdsHistoryCoverage::STATUS_NOT_IMPORTED;
        }

        $coverage->save();

        return $coverage;
    }
}
