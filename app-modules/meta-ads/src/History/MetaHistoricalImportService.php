<?php

namespace MoxDop\MetaAds\History;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaException;
use App\Support\Integrations\ComparisonPeriod;
use App\Support\Integrations\Meta\MetaApiConfig;
use App\Support\Integrations\ProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use MoxDop\MetaAds\Normalization\MetaActionNormalizer;
use RuntimeException;
use Throwable;

/**
 * High-level orchestration for importing Meta Ads history into the historical store.
 * GET-only (MetaApiClient never exposes write verbs). Anchored on Integration +
 * External Resource — never requires a Digital Asset binding.
 */
final class MetaHistoricalImportService
{
    public const string RESOURCE_TYPE = 'meta_ads';

    public function __construct(
        private readonly MetaApiClient $client,
        private readonly MetaHistoricalUpserter $upserter,
        private readonly MetaHistoricalPeriodEnricher $periodEnricher,
        private readonly AsyncOperationService $asyncOperations,
    ) {}

    /**
     * Meta Ad Accounts available for history import under this Integration.
     * Discovery only — never auto-binds a Digital Asset.
     *
     * @return Collection<int, CoreExternalResource>
     */
    public function discoverAccountsForImport(CoreIntegration $integration): Collection
    {
        $this->assertMeta($integration);

        return CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', self::RESOURCE_TYPE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->orderBy('display_name')
            ->get();
    }

    public function ensureCoverageRows(CoreIntegration $integration, CoreExternalResource $resource): void
    {
        foreach ([
            MetaAdsHistoryCoverage::LAYER_ENTITIES,
            MetaAdsHistoryCoverage::LAYER_DAILY_FACTS,
            MetaAdsHistoryCoverage::LAYER_DAILY_ACTIONS,
            MetaAdsHistoryCoverage::LAYER_PERIOD_AGGREGATES,
        ] as $layer) {
            MetaAdsHistoryCoverage::query()->firstOrCreate(
                [
                    'core_external_resource_id' => $resource->id,
                    'data_layer' => $layer,
                    'granularity' => 'day',
                ],
                [
                    'core_integration_id' => $integration->id,
                    'status' => MetaAdsHistoryCoverage::STATUS_NOT_IMPORTED,
                ],
            );
        }
    }

    /**
     * Imports one Ad Account's history: entity metadata + daily facts/actions in
     * chunks, plus exact period aggregates for common windows. One account's failure
     * never aborts the whole import — errors are collected and returned as `partial`.
     *
     * @param  array{
     *     from?: string,
     *     to?: string,
     *     include_period_aggregates?: bool,
     *     on_progress?: callable(array<string, mixed>, Run): void,
     * }  $options
     * @return array{ok: bool, status: string, date_from: string, date_to: string, counts: array<string, int>, errors: list<string>}
     */
    public function importAccountHistory(CoreIntegration $integration, CoreExternalResource $resource, Run $parentRun, array $options = []): array
    {
        $this->assertMeta($integration);
        $this->assertOwnership($integration, $resource);

        $this->ensureCoverageRows($integration, $resource);
        $this->markImporting($resource);

        $actId = $this->normalizeActId((string) $resource->external_id);
        [$from, $to] = $this->resolveWindow($options);
        $onProgress = $options['on_progress'] ?? null;
        $includePeriodAggregates = $options['include_period_aggregates'] ?? true;

        $this->reportProgress($parentRun, $onProgress, [
            'current_account' => $resource->display_name,
            'current_phase' => 'starting',
        ]);

        $counts = ['entities' => 0, 'facts' => 0, 'actions' => 0];
        $errors = [];

        $this->upsertAccountEntity($integration, $resource, $actId);
        $counts['entities']++;

        $chunks = $this->chunkDateRange($from, $to, MetaHistoricalConfig::CHUNK_DAYS);
        $chunksTotal = count($chunks);
        $seenIds = ['campaign' => [], 'adset' => [], 'ad' => []];

        foreach ($chunks as $index => $chunk) {
            $this->reportProgress($parentRun, $onProgress, [
                'current_phase' => 'importing_daily_facts',
                'chunks_done' => $index,
                'chunks_total' => $chunksTotal,
            ]);

            foreach (['account', 'campaign', 'adset', 'ad'] as $level) {
                try {
                    $rows = $this->fetchDailyInsights($integration, $actId, $level, $chunk);
                } catch (MetaException $exception) {
                    $errors[] = "{$level}@{$chunk['start']}..{$chunk['end']}: {$exception->getMessage()}";

                    continue;
                }

                foreach ($rows as $row) {
                    $providerId = $level === 'account' ? $actId : $this->entityIdFromRow($row, $level);
                    if ($providerId === null || $providerId === '') {
                        continue;
                    }
                    if ($level !== 'account') {
                        $seenIds[$level][$providerId] = true;
                    }

                    $this->ingestRow($integration, $resource, $level, $providerId, $this->parentIdFromRow($row, $level, $actId), $row, $counts);
                }
            }
        }

        $this->reportProgress($parentRun, $onProgress, [
            'current_phase' => 'importing_entities',
            'chunks_done' => $chunksTotal,
            'chunks_total' => $chunksTotal,
        ]);

        $entityResult = $this->importEntityMetadata($integration, $resource, $actId, $seenIds);
        $counts['entities'] += $entityResult['count'];
        $errors = [...$errors, ...$entityResult['errors']];

        $periodErrors = [];
        if ($includePeriodAggregates) {
            $this->reportProgress($parentRun, $onProgress, ['current_phase' => 'importing_period_aggregates']);
            $periodErrors = $this->importCommonPeriodAggregates($integration, $resource, $actId, array_keys($seenIds['campaign']), $parentRun);
            $errors = [...$errors, ...$periodErrors];
        }

        $status = $this->finalizeCoverage($integration, $resource, $from, $to, $parentRun, $counts, $errors, $entityResult['errors']);

        $this->reportProgress($parentRun, $onProgress, ['current_phase' => $status]);

        return [
            'ok' => $status !== 'failed',
            'status' => $status,
            'date_from' => $from,
            'date_to' => $to,
            'counts' => $counts,
            'errors' => $errors,
        ];
    }

    /**
     * Imports history for several accounts under one Integration. One account's
     * failure never aborts the others.
     *
     * @param  iterable<CoreExternalResource>  $resources
     * @param  array<string, mixed>  $options
     * @return array{ok: bool, accounts: array<string, array<string, mixed>>}
     */
    public function importHistoryForAccounts(CoreIntegration $integration, iterable $resources, Run $parentRun, array $options = []): array
    {
        $resources = collect($resources)->values();
        $onProgress = $options['on_progress'] ?? null;
        $results = [];
        $ok = true;

        $this->reportProgress($parentRun, $onProgress, [
            'accounts_total' => $resources->count(),
            'accounts_done' => 0,
        ]);

        foreach ($resources as $index => $resource) {
            $this->reportProgress($parentRun, $onProgress, [
                'current_account' => $resource->display_name,
                'accounts_done' => $index,
            ]);

            try {
                $results[$resource->external_id] = $this->importAccountHistory($integration, $resource, $parentRun, $options);
            } catch (Throwable $exception) {
                $ok = false;
                $results[$resource->external_id] = [
                    'ok' => false,
                    'status' => 'failed',
                    'errors' => [$exception->getMessage()],
                ];
            }

            if (($results[$resource->external_id]['ok'] ?? false) !== true) {
                $ok = false;
            }
        }

        $this->reportProgress($parentRun, $onProgress, [
            'accounts_done' => $resources->count(),
            'current_phase' => $ok ? 'complete' : 'partial',
        ]);

        return ['ok' => $ok, 'accounts' => $results];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{entities: int, facts: int, actions: int}  $counts
     */
    private function ingestRow(
        CoreIntegration $integration,
        CoreExternalResource $resource,
        string $level,
        string $providerId,
        ?string $parentId,
        array $row,
        array &$counts,
    ): void {
        $normalized = $this->normalizeRow($row);
        if ($normalized['date'] === '') {
            return;
        }

        $this->upserter->upsertDailyFact([
            'core_integration_id' => $integration->id,
            'core_external_resource_id' => $resource->id,
            'entity_type' => $level,
            'provider_external_id' => $providerId,
            'parent_provider_external_id' => $parentId,
            'date' => $normalized['date'],
            'spend' => $normalized['spend'],
            'impressions' => $normalized['impressions'],
            'clicks' => $normalized['clicks'],
            'link_clicks' => $normalized['link_clicks'],
            'outbound_clicks' => $normalized['outbound_clicks'],
            'reach' => $normalized['reach'],
            'frequency' => $normalized['frequency'],
            'cpc' => $normalized['cpc'],
            'cpm' => $normalized['cpm'],
            'ctr' => $normalized['ctr'],
            'link_ctr' => $normalized['link_ctr'],
            'currency' => $normalized['currency'],
            'attribution_setting' => $normalized['attribution_setting'],
            'provenance' => ['api_version' => MetaApiConfig::apiVersion()],
        ]);
        $counts['facts']++;

        $actions = MetaActionNormalizer::normalize($row['actions'] ?? [], $row['action_values'] ?? null);
        foreach ($actions as $action) {
            $this->upserter->upsertDailyAction([
                'core_integration_id' => $integration->id,
                'core_external_resource_id' => $resource->id,
                'entity_type' => $level,
                'provider_external_id' => $providerId,
                'date' => $normalized['date'],
                'raw_action_type' => $action['raw_action_type'],
                'normalized_family' => $action['normalized_result_type'],
                'value' => $action['count'],
                'action_value' => $action['value'],
                'attribution_window' => $normalized['attribution_setting'] ?? '',
                'provenance' => ['source' => $action['source']],
            ]);
            $counts['actions']++;
        }
    }

    /**
     * @param  array{start: string, end: string}  $chunk
     * @return list<array<string, mixed>>
     *
     * TODO(meta-async-insights): For very large daily-insight windows Meta recommends
     * the asynchronous Insights report flow — POST /{ad-account}/insights to create a
     * report_run, poll GET /{report_run_id} until `async_status` is
     * "Job Completed", then page GET /{report_run_id}/insights. That path avoids the
     * synchronous timeout/#100 pagination limits on huge accounts. It is intentionally
     * NOT implemented here because it requires a POST (write verb): MetaApiClient is
     * GET-only by design (ADR — no external write actions), and enabling POST would
     * expand the surface and permissions beyond the read-only import contract. The
     * per-account chunking + retry below keeps synchronous GET pagination correct for
     * the supported history window. Revisit only if we introduce a vetted, read-scoped
     * async-report client that still honors the no-write constraint.
     */
    private function fetchDailyInsights(CoreIntegration $integration, string $actId, string $level, array $chunk): array
    {
        $query = [
            'fields' => MetaHistoricalConfig::INSIGHT_FIELDS,
            'level' => $level,
            'limit' => 500,
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $chunk['start'], 'until' => $chunk['end']], JSON_THROW_ON_ERROR),
            'use_unified_attribution_setting' => 'true',
        ];

        return $this->paginate($integration, $actId.'/insights', $query, MetaHistoricalConfig::historyMaxPages());
    }

    /**
     * @param  array{campaign: array<string, bool>, adset: array<string, bool>, ad: array<string, bool>}  $seenIds
     * @return array{errors: list<string>, count: int}
     */
    private function importEntityMetadata(CoreIntegration $integration, CoreExternalResource $resource, string $actId, array $seenIds): array
    {
        $errors = [];
        $count = 0;

        $campaignMeta = $this->fetchMetaByIds($integration, $actId.'/campaigns', array_keys($seenIds['campaign']), 'id,name,status,effective_status,objective,buying_type');
        foreach ($campaignMeta['by_id'] as $id => $meta) {
            $this->upserter->upsertEntity($integration, $resource, [
                'entity_type' => 'campaign',
                'provider_external_id' => $id,
                'parent_provider_external_id' => $actId,
                'name' => isset($meta['name']) ? (string) $meta['name'] : null,
                'status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : (isset($meta['status']) ? (string) $meta['status'] : null),
                'objective' => isset($meta['objective']) ? (string) $meta['objective'] : null,
            ]);
            $count++;
        }
        if ($campaignMeta['error'] !== null) {
            $errors[] = 'campaign_metadata: '.$campaignMeta['error'];
        }

        $adsetMeta = $this->fetchMetaByIds($integration, $actId.'/adsets', array_keys($seenIds['adset']), 'id,name,campaign_id,status,effective_status,optimization_goal,destination_type');
        foreach ($adsetMeta['by_id'] as $id => $meta) {
            $this->upserter->upsertEntity($integration, $resource, [
                'entity_type' => 'adset',
                'provider_external_id' => $id,
                'parent_provider_external_id' => isset($meta['campaign_id']) ? (string) $meta['campaign_id'] : null,
                'name' => isset($meta['name']) ? (string) $meta['name'] : null,
                'status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : (isset($meta['status']) ? (string) $meta['status'] : null),
                'optimization_goal' => isset($meta['optimization_goal']) ? (string) $meta['optimization_goal'] : null,
                'destination_type' => isset($meta['destination_type']) ? (string) $meta['destination_type'] : null,
            ]);
            $count++;
        }
        if ($adsetMeta['error'] !== null) {
            $errors[] = 'adset_metadata: '.$adsetMeta['error'];
        }

        $adMeta = $this->fetchMetaByIds($integration, $actId.'/ads', array_keys($seenIds['ad']), 'id,name,adset_id,campaign_id,status,effective_status,creative{id}');
        $creativeIds = [];
        foreach ($adMeta['by_id'] as $id => $meta) {
            $creativeId = is_array($meta['creative'] ?? null) && isset($meta['creative']['id']) ? (string) $meta['creative']['id'] : null;
            if ($creativeId !== null) {
                $creativeIds[$creativeId] = true;
            }
            $this->upserter->upsertEntity($integration, $resource, [
                'entity_type' => 'ad',
                'provider_external_id' => $id,
                'parent_provider_external_id' => isset($meta['adset_id']) ? (string) $meta['adset_id'] : null,
                'name' => isset($meta['name']) ? (string) $meta['name'] : null,
                'status' => isset($meta['effective_status']) ? (string) $meta['effective_status'] : (isset($meta['status']) ? (string) $meta['status'] : null),
                'creative_provider_id' => $creativeId,
            ]);
            $count++;
        }
        if ($adMeta['error'] !== null) {
            $errors[] = 'ad_metadata: '.$adMeta['error'];
        }

        foreach (array_keys($creativeIds) as $creativeId) {
            try {
                $payload = MetaHistoricalRetry::attempt(fn (): array => $this->client->get($integration, $creativeId, ['fields' => 'id,name']));
                $this->upserter->upsertEntity($integration, $resource, [
                    'entity_type' => 'creative',
                    'provider_external_id' => $creativeId,
                    'name' => isset($payload['name']) ? (string) $payload['name'] : null,
                ]);
                $count++;
            } catch (MetaException $exception) {
                $errors[] = "creative_metadata[{$creativeId}]: {$exception->getMessage()}";
            }
        }

        return ['errors' => $errors, 'count' => $count];
    }

    /**
     * @param  list<string>  $ids
     * @return array{by_id: array<string, array<string, mixed>>, error: ?string}
     */
    private function fetchMetaByIds(CoreIntegration $integration, string $path, array $ids, string $fields): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return ['by_id' => [], 'error' => null];
        }

        $byId = [];
        $errors = [];

        // Account /adsets edge listing can fail for large catalogs; individual node
        // GETs remain reliable. Prefer per-id reads for entity metadata enrichment.
        foreach (array_chunk($ids, 25) as $chunk) {
            foreach ($chunk as $id) {
                try {
                    $row = $this->client->get($integration, $id, ['fields' => $fields]);
                    if (is_array($row) && isset($row['id'])) {
                        $byId[(string) $row['id']] = $row;
                    }
                } catch (MetaException $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        return [
            'by_id' => $byId,
            'error' => $byId === [] && $errors !== [] ? implode('; ', array_slice($errors, 0, 3)) : null,
        ];
    }

    /**
     * @param  list<string>  $campaignIds
     * @return list<string>
     */
    private function importCommonPeriodAggregates(CoreIntegration $integration, CoreExternalResource $resource, string $actId, array $campaignIds, Run $run): array
    {
        $errors = [];
        $presets = [
            ComparisonPeriod::PRESET_LAST_7,
            ComparisonPeriod::PRESET_LAST_14,
            ComparisonPeriod::PRESET_LAST_28,
            ComparisonPeriod::PRESET_LAST_30,
            ComparisonPeriod::PRESET_THIS_MONTH,
            ComparisonPeriod::PRESET_LAST_MONTH,
        ];

        foreach ($presets as $preset) {
            $window = ComparisonPeriod::forPreset($preset, compare: false)['current'];

            $result = $this->periodEnricher->fetchAndStoreExactPeriod($integration, $resource, 'account', $actId, $window['start'], $window['end'], $run);
            if ($result['status'] === 'failed') {
                $errors[] = "period_aggregate[account][{$preset}]: ".($result['error'] ?? 'failed');
            }

            foreach ($campaignIds as $campaignId) {
                $result = $this->periodEnricher->fetchAndStoreExactPeriod($integration, $resource, 'campaign', $campaignId, $window['start'], $window['end'], $run);
                if ($result['status'] === 'failed') {
                    $errors[] = "period_aggregate[campaign:{$campaignId}][{$preset}]: ".($result['error'] ?? 'failed');
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array{entities: int, facts: int, actions: int}  $counts
     * @param  list<string>  $errors
     * @param  list<string>  $entityErrors
     */
    private function finalizeCoverage(
        CoreIntegration $integration,
        CoreExternalResource $resource,
        string $from,
        string $to,
        Run $run,
        array $counts,
        array $errors,
        array $entityErrors,
    ): string {
        $status = $errors === []
            ? MetaAdsHistoryCoverage::STATUS_COMPLETE
            : ($counts['facts'] > 0 ? MetaAdsHistoryCoverage::STATUS_PARTIAL : 'failed');

        $factStatus = $status === 'failed' ? MetaAdsHistoryCoverage::STATUS_PARTIAL : $status;

        $this->upserter->updateCoverage($integration, $resource, MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, [
            'status' => $factStatus,
            'start_date' => $from,
            'end_date' => $to,
            'last_successful_sync_at' => now(),
            'import_run_id' => $run->id,
            'summary' => ['facts' => $counts['facts'], 'errors' => $errors],
        ]);

        $this->upserter->updateCoverage($integration, $resource, MetaAdsHistoryCoverage::LAYER_DAILY_ACTIONS, [
            'status' => $factStatus,
            'start_date' => $from,
            'end_date' => $to,
            'last_successful_sync_at' => now(),
            'import_run_id' => $run->id,
            'summary' => ['actions' => $counts['actions']],
        ]);

        $this->upserter->updateCoverage($integration, $resource, MetaAdsHistoryCoverage::LAYER_ENTITIES, [
            'status' => $entityErrors === [] ? MetaAdsHistoryCoverage::STATUS_COMPLETE : MetaAdsHistoryCoverage::STATUS_PARTIAL,
            'last_successful_sync_at' => now(),
            'import_run_id' => $run->id,
            'summary' => ['entities' => $counts['entities']],
        ]);

        return $status;
    }

    private function markImporting(CoreExternalResource $resource): void
    {
        MetaAdsHistoryCoverage::query()
            ->where('core_external_resource_id', $resource->id)
            ->whereIn('data_layer', [MetaAdsHistoryCoverage::LAYER_DAILY_FACTS, MetaAdsHistoryCoverage::LAYER_DAILY_ACTIONS])
            ->update(['status' => MetaAdsHistoryCoverage::STATUS_IMPORTING]);
    }

    private function upsertAccountEntity(CoreIntegration $integration, CoreExternalResource $resource, string $actId): void
    {
        $meta = is_array($resource->metadata) ? $resource->metadata : [];

        $this->upserter->upsertEntity($integration, $resource, [
            'entity_type' => 'account',
            'provider_external_id' => $actId,
            'name' => $resource->display_name,
            'currency' => isset($meta['currency']) ? (string) $meta['currency'] : null,
            'metadata' => $meta,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(CoreIntegration $integration, string $path, array $query, int $maxPages): array
    {
        $rows = [];

        $payload = MetaHistoricalRetry::attempt(fn (): array => $this->client->get($integration, $path, $query));
        $rows = [...$rows, ...(is_array($payload['data'] ?? null) ? $payload['data'] : [])];
        $next = data_get($payload, 'paging.next');
        $page = 1;

        while (is_string($next) && $next !== '' && $page < $maxPages) {
            $payload = MetaHistoricalRetry::attempt(fn (): array => $this->client->getAbsolute($integration, $next));
            $rows = [...$rows, ...(is_array($payload['data'] ?? null) ? $payload['data'] : [])];
            $next = data_get($payload, 'paging.next');
            $page++;
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function normalizeRow(array $row): array
    {
        return [
            'date' => (string) ($row['date_start'] ?? $row['date_stop'] ?? ''),
            'spend' => $this->toFloat($row['spend'] ?? null),
            'impressions' => $this->toInt($row['impressions'] ?? null),
            'clicks' => $this->toInt($row['clicks'] ?? null),
            'link_clicks' => $this->toInt($row['inline_link_clicks'] ?? null),
            'outbound_clicks' => $this->toInt($this->extractActionListMetric($row['outbound_clicks'] ?? null, 'outbound_click')),
            'reach' => $this->toInt($row['reach'] ?? null),
            'frequency' => $this->toFloat($row['frequency'] ?? null),
            'cpc' => $this->toFloat($row['cpc'] ?? null),
            'cpm' => $this->toFloat($row['cpm'] ?? null),
            'ctr' => $this->toFloat($row['ctr'] ?? null),
            'link_ctr' => $this->toFloat($row['inline_link_click_ctr'] ?? null),
            'currency' => isset($row['account_currency']) ? (string) $row['account_currency'] : null,
            'attribution_setting' => isset($row['attribution_setting']) ? (string) $row['attribution_setting'] : null,
        ];
    }

    private function entityIdFromRow(array $row, string $level): ?string
    {
        $key = match ($level) {
            'campaign' => 'campaign_id',
            'adset' => 'adset_id',
            'ad' => 'ad_id',
            default => null,
        };

        if ($key === null || ! isset($row[$key])) {
            return null;
        }

        $id = (string) $row[$key];

        return $id !== '' ? $id : null;
    }

    private function parentIdFromRow(array $row, string $level, string $actId): ?string
    {
        return match ($level) {
            'campaign' => $actId,
            'adset' => isset($row['campaign_id']) ? (string) $row['campaign_id'] : null,
            'ad' => isset($row['adset_id']) ? (string) $row['adset_id'] : null,
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveWindow(array $options): array
    {
        $earliestAllowed = CarbonImmutable::now('UTC')->subMonths(MetaHistoricalConfig::HISTORY_MONTHS)->startOfDay();

        $to = isset($options['to']) && is_string($options['to'])
            ? CarbonImmutable::parse($options['to'])->startOfDay()
            : CarbonImmutable::now('UTC')->subDay()->startOfDay();

        $from = isset($options['from']) && is_string($options['from'])
            ? CarbonImmutable::parse($options['from'])->startOfDay()
            : $earliestAllowed;

        if ($from->lt($earliestAllowed)) {
            $from = $earliestAllowed;
        }
        if ($to->lt($from)) {
            $to = $from;
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * @return list<array{start: string, end: string}>
     */
    private function chunkDateRange(string $from, string $to, int $chunkDays): array
    {
        $chunks = [];
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);

        while ($cursor->lte($end)) {
            $chunkEnd = $cursor->addDays($chunkDays - 1);
            if ($chunkEnd->gt($end)) {
                $chunkEnd = $end;
            }

            $chunks[] = ['start' => $cursor->toDateString(), 'end' => $chunkEnd->toDateString()];
            $cursor = $chunkEnd->addDay();
        }

        return $chunks;
    }

    /**
     * Meta returns list-of-{action_type,value} shapes for some metrics
     * (outbound_clicks) identical to `actions`, not scalars.
     */
    private function extractActionListMetric(mixed $rows, string $actionType): mixed
    {
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['action_type'] ?? null) === $actionType) {
                return $row['value'] ?? null;
            }
        }

        return null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 6);
    }

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function reportProgress(Run $run, mixed $onProgress, array $progress): void
    {
        $run->refresh();
        $existing = data_get($run->metadata, 'meta_history_progress');
        $merged = [
            ...(is_array($existing) ? $existing : []),
            ...$progress,
            'updated_at' => now()->toIso8601String(),
        ];

        $run->update([
            'metadata' => [...($run->metadata ?? []), 'meta_history_progress' => $merged],
        ]);

        if (isset($progress['current_phase'])) {
            $this->asyncOperations->setPhase($run, (string) $progress['current_phase'], $this->phaseLabel((string) $progress['current_phase']));
        }

        if (is_callable($onProgress)) {
            $onProgress($merged, $run);
        }
    }

    private function phaseLabel(string $phase): string
    {
        return match ($phase) {
            'starting' => 'Starting Meta history import',
            'importing_daily_facts' => 'Importing daily facts',
            'importing_entities' => 'Importing entity metadata',
            'importing_period_aggregates' => 'Fetching exact period metrics',
            MetaAdsHistoryCoverage::STATUS_COMPLETE => 'Meta history import complete',
            MetaAdsHistoryCoverage::STATUS_PARTIAL => 'Meta history import finished with gaps',
            'failed' => 'Meta history import failed',
            default => ucfirst(str_replace('_', ' ', $phase)),
        };
    }

    private function normalizeActId(string $externalId): string
    {
        $externalId = trim($externalId);
        if ($externalId === '') {
            throw new RuntimeException('Meta Ads External Resource has no Ad Account ID.');
        }

        return str_starts_with($externalId, 'act_') ? $externalId : 'act_'.$externalId;
    }

    private function assertMeta(CoreIntegration $integration): void
    {
        if ($integration->provider !== ProviderRegistry::META) {
            throw new RuntimeException('Meta history import requires a Meta Integration.');
        }
    }

    private function assertOwnership(CoreIntegration $integration, CoreExternalResource $resource): void
    {
        if ((int) $resource->integration_id !== (int) $integration->id) {
            throw new RuntimeException('External Resource does not belong to this Integration.');
        }

        if ($resource->resource_type !== self::RESOURCE_TYPE) {
            throw new RuntimeException('External Resource is not a Meta Ad Account.');
        }
    }
}
