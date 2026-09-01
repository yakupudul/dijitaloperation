<?php

namespace App\Services\IntelligenceProjection\Website\Adapters;

use App\Contracts\IntelligenceCore\WebsiteProjectionSourceAdapter;
use App\Enums\IntelligenceCore\BusinessActionSignalClass;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Models\IntelligenceCore\IntelligenceBusinessActionAlias;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\IntelligenceCore\Identity\PageIdentityResolver;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionAdapterSupport;
use App\Support\IntelligenceCore\IntelligenceSourceReference;
use App\Support\IntelligenceCore\IntelligenceTimeContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Ga4ProjectionAdapter implements WebsiteProjectionSourceAdapter
{
    public function __construct(
        private readonly Ga4SpecialistBindingResolver $bindings,
        private readonly PageIdentityResolver $pages,
        private readonly WebsiteProjectionAdapterSupport $support,
    ) {}

    public function sourceId(): string
    {
        return 'ga4';
    }

    public function capabilityIds(): array
    {
        return ['analytics.behavior.read'];
    }

    public function profileIds(): array
    {
        return ['page', 'outcome'];
    }

    public function metricIds(): array
    {
        return ['ga4.sessions', 'ga4.engaged_sessions', 'ga4.key_events'];
    }

    public function project(WebsiteProjectionContext $context): WebsiteProjectionContribution
    {
        $asset = $context->websiteAsset;
        $binding = $this->bindings->resolve((string) $asset->getKey());
        if (! $binding->isReal() || $binding->externalResourceId === null || $binding->propertyId === null) {
            return new WebsiteProjectionContribution(
                sourceId: $this->sourceId(),
                coverage: [
                    'state' => $binding->externalResourceId === null ? 'not_configured' : 'unavailable',
                    'reason' => $binding->reason,
                ],
            );
        }

        $start = $context->periodStart->toDateString();
        $end = $context->periodEnd->toDateString();
        $timezone = $binding->timezone ?: 'UTC';
        $landing = Schema::hasTable('ga4_landing_page_daily')
            ? $this->landingAggregates($binding->externalResourceId, $binding->propertyId, $start, $end)
            : [];
        $content = Schema::hasTable('ga4_page_content_daily')
            ? $this->contentAggregates($binding->externalResourceId, $binding->propertyId, $start, $end)
            : [];

        $pageStates = [];
        foreach ($landing as $observedUrl => $aggregate) {
            $absolute = $this->support->absolutePageUrl($asset, $observedUrl);
            if ($absolute === null) {
                continue;
            }
            $identityId = $this->resolvePage(
                $context,
                $absolute,
                'ga4_landing_page_daily',
                $aggregate,
                $binding->externalResourceId,
                $timezone,
                $start,
                $end,
                'ga4_landing_page',
            );
            $source = $this->source('ga4_landing_page_daily', $aggregate, $binding->externalResourceId, IntelligenceSourceClass::FirstPartyMeasured);
            $attributedSource = $this->source('ga4_landing_page_daily', $aggregate, $binding->externalResourceId, IntelligenceSourceClass::ProviderAttributed);
            $time = $this->time($aggregate, $timezone, $start, $end, $context);
            $metrics = [
                $this->support->metric('ga4.sessions', $aggregate['sessions'], 'landing_page_period', ['page_identity_id' => $identityId], $source, $time, metadata: $this->runProvenance($aggregate)),
                $this->support->metric('ga4.engaged_sessions', $aggregate['engaged_sessions'], 'landing_page_period', ['page_identity_id' => $identityId], $source, $time, metadata: $this->runProvenance($aggregate)),
                $this->support->metric('ga4.key_events', $aggregate['key_events'], 'landing_page_period', ['page_identity_id' => $identityId], $attributedSource, $time, metadata: $this->runProvenance($aggregate)),
            ];
            $pageStates[$identityId] = [
                'identity_id' => $identityId,
                'source_state' => [
                    'state' => 'collected',
                    'period' => ['start' => $start, 'end' => $end],
                    'property_id' => $binding->propertyId,
                    'landing_page' => $observedUrl,
                    'metrics' => $metrics,
                    'engagement_rate' => $aggregate['sessions'] > 0
                        ? $aggregate['engaged_sessions'] / $aggregate['sessions']
                        : null,
                    'page_content' => null,
                    'data_quality' => $this->dataQuality(),
                    'source' => $source->toArray(),
                    'time_context' => $time->toArray(),
                ],
                'observed_at' => $aggregate['last_collected_at'],
            ];
        }

        foreach ($content as $observedUrl => $aggregate) {
            $absolute = $this->contentUrl($asset, $aggregate, $observedUrl);
            if ($absolute === null) {
                continue;
            }
            $identityId = $this->resolvePage(
                $context,
                $absolute,
                'ga4_page_content_daily',
                $aggregate,
                $binding->externalResourceId,
                $timezone,
                $start,
                $end,
                'ga4_page_content',
            );
            $source = $this->source('ga4_page_content_daily', $aggregate, $binding->externalResourceId, IntelligenceSourceClass::FirstPartyMeasured);
            $time = $this->time($aggregate, $timezone, $start, $end, $context);
            $existing = $pageStates[$identityId] ?? [
                'identity_id' => $identityId,
                'source_state' => [
                    'state' => 'collected',
                    'period' => ['start' => $start, 'end' => $end],
                    'property_id' => $binding->propertyId,
                    'landing_page' => null,
                    'metrics' => [],
                    'engagement_rate' => null,
                    'page_content' => null,
                    'data_quality' => $this->dataQuality(),
                    'source' => $source->toArray(),
                    'time_context' => $time->toArray(),
                ],
                'observed_at' => $aggregate['last_collected_at'],
            ];
            $existing['source_state']['page_content'] = [
                'path' => $observedUrl,
                'host_name' => $aggregate['host_name'],
                'titles' => array_values(array_keys($aggregate['titles'])),
                'screen_page_views' => $aggregate['screen_page_views'],
                'active_users' => $aggregate['active_users'],
                'total_users' => $aggregate['total_users'],
                'event_count' => $aggregate['event_count'],
                'scrolled_users' => $aggregate['scrolled_users'],
                'user_engagement_duration' => $aggregate['user_engagement_duration'],
                'key_events' => $aggregate['key_events'],
            ];
            $existing['observed_at'] = $this->support->latestTimestamp($existing['observed_at'], $aggregate['last_collected_at']);
            $pageStates[$identityId] = $existing;
        }

        $outcomes = $this->outcomes($context, $binding->externalResourceId, $binding->propertyId, $timezone, $start, $end);
        $watermarks = [
            ...array_column($landing, 'last_collected_at'),
            ...array_column($content, 'last_collected_at'),
            ...array_column($outcomes, 'observed_at'),
        ];
        $watermark = $this->support->latestTimestamp(...$watermarks);

        return new WebsiteProjectionContribution(
            sourceId: $this->sourceId(),
            pages: array_values($pageStates),
            outcomes: $outcomes,
            coverage: [
                'state' => $pageStates === [] && $outcomes === [] ? 'not_collected' : 'collected',
                'external_resource_id' => $binding->externalResourceId,
                'property_id' => $binding->propertyId,
                'requested_period' => ['start' => $start, 'end' => $end],
                'page_count' => count($pageStates),
                'mapped_outcome_count' => count($outcomes),
                'watermark' => $watermark,
                'data_quality' => $this->dataQuality(),
            ],
            watermark: $watermark,
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function landingAggregates(int $resourceId, string $propertyId, string $start, string $end): array
    {
        $aggregates = [];
        DB::table('ga4_landing_page_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->orderBy('reporting_date')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$aggregates): void {
                foreach ($rows as $row) {
                    $page = trim((string) ($row->landingPagePlusQueryString ?? $row->landingPage ?? ''));
                    if ($page === '' || in_array($page, ['(not set)', '(not provided)'], true)) {
                        continue;
                    }
                    $aggregate = $aggregates[$page] ?? $this->emptyLandingAggregate($page);
                    $aggregate['sessions'] += (int) ($row->sessions ?? 0);
                    $aggregate['engaged_sessions'] += (int) ($row->engagedSessions ?? 0);
                    $aggregate['key_events'] = $this->nullableSum($aggregate['key_events'], $row->keyEvents ?? null);
                    $aggregate['last_collected_at'] = $this->support->latestTimestamp($aggregate['last_collected_at'], $row->last_collected_at ?? null);
                    $aggregate['latest_row'] = $row;
                    $this->trackRunProvenance($aggregate, $row);
                    $aggregates[$page] = $aggregate;
                }
            });

        return $aggregates;
    }

    /** @return array<string,array<string,mixed>> */
    private function contentAggregates(int $resourceId, string $propertyId, string $start, string $end): array
    {
        $aggregates = [];
        DB::table('ga4_page_content_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->orderBy('reporting_date')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$aggregates): void {
                foreach ($rows as $row) {
                    $path = trim((string) ($row->pagePathPlusQueryString ?? ''));
                    if ($path === '') {
                        continue;
                    }
                    $host = strtolower(trim((string) ($row->hostName ?? '')));
                    $key = $host.'|'.$path;
                    $aggregate = $aggregates[$key] ?? $this->emptyContentAggregate($host, $key);
                    $title = trim((string) ($row->pageTitle ?? ''));
                    if ($title !== '') {
                        $aggregate['titles'][$title] = true;
                    }
                    foreach ([
                        'screen_page_views' => 'screenPageViews',
                        'active_users' => 'activeUsers',
                        'total_users' => 'totalUsers',
                        'event_count' => 'eventCount',
                        'scrolled_users' => 'scrolledUsers',
                        'user_engagement_duration' => 'userEngagementDuration',
                    ] as $target => $column) {
                        $aggregate[$target] += (int) ($row->{$column} ?? 0);
                    }
                    $aggregate['key_events'] = $this->nullableSum($aggregate['key_events'], $row->keyEvents ?? null);
                    $aggregate['last_collected_at'] = $this->support->latestTimestamp($aggregate['last_collected_at'], $row->last_collected_at ?? null);
                    $aggregate['latest_row'] = $row;
                    $this->trackRunProvenance($aggregate, $row);
                    $aggregates[$key] = $aggregate;
                }
            });

        $byPath = [];
        foreach ($aggregates as $key => $aggregate) {
            $path = str_contains($key, '|') ? substr($key, strpos($key, '|') + 1) : $key;
            $byPath[$path] = $aggregate;
        }

        return $byPath;
    }

    /** @return list<array{identity_id:int,source_state:array<string,mixed>,observed_at:?string}> */
    private function outcomes(
        WebsiteProjectionContext $context,
        int $resourceId,
        string $propertyId,
        string $timezone,
        string $start,
        string $end,
    ): array {
        $aliases = IntelligenceBusinessActionAlias::query()
            ->with('businessActionIdentity')
            ->where('provider_or_source', 'ga4')
            ->where('external_resource_id', $resourceId)
            ->get();
        if ($aliases->isEmpty()) {
            return [];
        }

        $events = $this->keyEventAggregates($resourceId, $propertyId, $start, $end);
        $outcomes = [];
        foreach ($aliases->groupBy('business_action_identity_id') as $identityId => $actionAliases) {
            $identity = $actionAliases->first()?->businessActionIdentity;
            if ($identity === null || (int) $identity->brand_id !== (int) $context->websiteAsset->brand_id) {
                continue;
            }
            $names = $actionAliases->flatMap(static fn (IntelligenceBusinessActionAlias $alias): array => array_filter([
                $alias->provider_action_id,
                $alias->observed_name,
            ]))->map(static fn (mixed $value): string => trim((string) $value))->filter()->unique()->values();
            $value = null;
            $latestRow = null;
            $watermark = null;
            $inputCollectionRunIds = [];
            $inputDatasetRunIds = [];
            foreach ($names as $name) {
                if (! isset($events[$name])) {
                    continue;
                }
                $value = ($value ?? 0.0) + (float) $events[$name]['key_events'];
                $latestRow = $events[$name]['latest_row'];
                $watermark = $this->support->latestTimestamp($watermark, $events[$name]['last_collected_at']);
                $inputCollectionRunIds += $events[$name]['collection_run_ids'];
                $inputDatasetRunIds += $events[$name]['dataset_run_ids'];
            }
            $source = $this->support->source(
                provider: 'ga4',
                sourceClass: IntelligenceSourceClass::ProviderAttributed,
                semantic: 'analytics_key_event',
                datasetId: 'ga4_key_event_daily',
                row: $latestRow,
                fallbackResourceId: $resourceId,
                recordKey: 'business_action:'.$identityId,
            );
            $time = $this->support->time(
                timezone: $timezone,
                periodStart: $start,
                periodEnd: $end,
                observedAt: $watermark,
                retrievedAt: $watermark,
                marketCode: $context->websiteAsset->seo_market_location_code !== null ? (string) $context->websiteAsset->seo_market_location_code : null,
                languageCode: $context->websiteAsset->seo_market_language_code,
            );
            $hasOperatorVerifiedAlias = $actionAliases->contains(
                static fn (IntelligenceBusinessActionAlias $alias): bool => $alias->signal_class === BusinessActionSignalClass::OperatorVerifiedOutcome,
            );
            $outcomes[] = [
                'identity_id' => (int) $identityId,
                'source_state' => [
                    'state' => $value === null ? 'not_collected' : 'collected',
                    'period' => ['start' => $start, 'end' => $end],
                    'mapped_event_names' => $names->all(),
                    'metric' => $this->support->metric(
                        metricId: 'ga4.key_events',
                        value: $value,
                        grain: 'event_period',
                        dimensions: ['business_action_identity_id' => (int) $identityId],
                        source: $source,
                        time: $time,
                        metadata: $this->runProvenance([
                            'collection_run_ids' => $inputCollectionRunIds,
                            'dataset_run_ids' => $inputDatasetRunIds,
                        ]),
                    ),
                    'signal_class' => IntelligenceSourceClass::ProviderAttributed->value,
                    'verified_business_outcome' => false,
                    'action_has_operator_verified_outcome_alias' => $hasOperatorVerifiedAlias,
                    'source' => $source->toArray(),
                    'time_context' => $time->toArray(),
                ],
                'observed_at' => $watermark,
            ];
        }

        return $outcomes;
    }

    /** @return array<string,array<string,mixed>> */
    private function keyEventAggregates(int $resourceId, string $propertyId, string $start, string $end): array
    {
        if (! Schema::hasTable('ga4_key_event_daily')) {
            return [];
        }
        $events = [];
        foreach (DB::table('ga4_key_event_daily')
            ->where('external_resource_id', $resourceId)
            ->where('property_id', $propertyId)
            ->whereBetween('reporting_date', [$start, $end])
            ->get() as $row) {
            $name = trim((string) $row->eventName);
            if ($name === '') {
                continue;
            }
            $event = $events[$name] ?? [
                'key_events' => 0.0,
                'last_collected_at' => null,
                'latest_row' => null,
                'collection_run_ids' => [],
                'dataset_run_ids' => [],
            ];
            $event['key_events'] += (float) ($row->keyEvents ?? 0);
            $event['last_collected_at'] = $this->support->latestTimestamp($event['last_collected_at'], $row->last_collected_at ?? null);
            $event['latest_row'] = $row;
            $this->trackRunProvenance($event, $row);
            $events[$name] = $event;
        }

        return $events;
    }

    /** @param array<string,mixed> $aggregate */
    private function resolvePage(
        WebsiteProjectionContext $context,
        string $url,
        string $dataset,
        array $aggregate,
        int $resourceId,
        string $timezone,
        string $start,
        string $end,
        string $aliasKind,
    ): int {
        $source = $this->source($dataset, $aggregate, $resourceId, IntelligenceSourceClass::FirstPartyMeasured);
        $time = $this->time($aggregate, $timezone, $start, $end, $context);

        return (int) $this->pages->resolveObserved(
            websiteAsset: $context->websiteAsset,
            observedUrl: $url,
            source: $source,
            time: $time,
            aliasKind: $aliasKind,
        )->getKey();
    }

    /** @param array<string,mixed> $aggregate */
    private function source(
        string $dataset,
        array $aggregate,
        int $resourceId,
        IntelligenceSourceClass $sourceClass,
    ): IntelligenceSourceReference {
        return $this->support->source(
            provider: 'ga4',
            sourceClass: $sourceClass,
            semantic: str_replace('ga4_', '', $dataset),
            datasetId: $dataset,
            row: $aggregate['latest_row'] ?? null,
            fallbackResourceId: $resourceId,
            recordKey: $dataset.'|'.($aggregate['identity_key'] ?? 'aggregate'),
        );
    }

    /** @param array<string,mixed> $aggregate */
    private function time(
        array $aggregate,
        string $timezone,
        string $start,
        string $end,
        WebsiteProjectionContext $context,
    ): IntelligenceTimeContext {
        return $this->support->time(
            timezone: $timezone,
            periodStart: $start,
            periodEnd: $end,
            observedAt: $aggregate['last_collected_at'] ?? null,
            retrievedAt: $aggregate['last_collected_at'] ?? null,
            marketCode: $context->websiteAsset->seo_market_location_code !== null ? (string) $context->websiteAsset->seo_market_location_code : null,
            languageCode: $context->websiteAsset->seo_market_language_code,
        );
    }

    /** @param array<string,mixed> $aggregate */
    private function contentUrl(\App\Models\DigitalAsset $asset, array $aggregate, string $path): ?string
    {
        $host = trim((string) ($aggregate['host_name'] ?? ''));
        if ($host === '') {
            return $this->support->absolutePageUrl($asset, $path);
        }
        $scheme = 'https';
        $baseParts = parse_url((string) $asset->primary_url);
        if (is_array($baseParts) && isset($baseParts['scheme'])) {
            $scheme = strtolower((string) $baseParts['scheme']);
        }

        return $scheme.'://'.strtolower($host).(str_starts_with($path, '/') ? $path : '/'.$path);
    }

    /** @return array<string,mixed> */
    private function emptyLandingAggregate(string $identityKey): array
    {
        return [
            'identity_key' => $identityKey,
            'sessions' => 0,
            'engaged_sessions' => 0,
            'key_events' => null,
            'last_collected_at' => null,
            'latest_row' => null,
            'collection_run_ids' => [],
            'dataset_run_ids' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function emptyContentAggregate(string $host, string $identityKey): array
    {
        return [
            'identity_key' => $identityKey,
            'host_name' => $host,
            'titles' => [],
            'screen_page_views' => 0,
            'active_users' => 0,
            'total_users' => 0,
            'event_count' => 0,
            'scrolled_users' => 0,
            'user_engagement_duration' => 0,
            'key_events' => null,
            'last_collected_at' => null,
            'latest_row' => null,
            'collection_run_ids' => [],
            'dataset_run_ids' => [],
        ];
    }

    /** @param array<string,mixed> $aggregate */
    private function trackRunProvenance(array &$aggregate, object $row): void
    {
        if (($row->last_collection_run_id ?? null) !== null) {
            $aggregate['collection_run_ids'][(int) $row->last_collection_run_id] = true;
        }
        if (($row->last_dataset_run_id ?? null) !== null) {
            $aggregate['dataset_run_ids'][(int) $row->last_dataset_run_id] = true;
        }
    }

    /** @param array<string,mixed> $aggregate @return array<string,list<int>> */
    private function runProvenance(array $aggregate): array
    {
        return [
            'input_collection_run_ids' => array_map('intval', array_keys($aggregate['collection_run_ids'] ?? [])),
            'input_dataset_run_ids' => array_map('intval', array_keys($aggregate['dataset_run_ids'] ?? [])),
        ];
    }

    private function nullableSum(int|float|null $current, mixed $value): int|float|null
    {
        return is_numeric($value) ? ($current ?? 0) + (float) $value : $current;
    }

    /** @return array<string,mixed> */
    private function dataQuality(): array
    {
        return [
            'provider_attribution_applies' => true,
            'landing_page_is_session_entry_dimension' => true,
            'page_content_is_not_landing_page' => true,
            'key_event_is_not_verified_business_outcome' => true,
            'missing_optional_metric_is_not_zero' => true,
        ];
    }
}
