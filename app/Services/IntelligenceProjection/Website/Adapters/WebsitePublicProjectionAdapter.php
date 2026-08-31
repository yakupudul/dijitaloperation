<?php

namespace App\Services\IntelligenceProjection\Website\Adapters;

use App\Contracts\IntelligenceCore\WebsiteProjectionSourceAdapter;
use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Services\IntelligenceCore\Identity\EntityIdentityResolver;
use App\Services\IntelligenceCore\Identity\PageIdentityResolver;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionAdapterSupport;
use App\Support\IntelligenceProjection\WebsiteProjectionContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WebsitePublicProjectionAdapter implements WebsiteProjectionSourceAdapter
{
    public function __construct(
        private readonly PageIdentityResolver $pages,
        private readonly EntityIdentityResolver $entities,
        private readonly WebsiteProjectionAdapterSupport $support,
    ) {}

    public function sourceId(): string
    {
        return 'website';
    }

    public function capabilityIds(): array
    {
        return ['website.public_observation.read', 'website.performance.read'];
    }

    public function profileIds(): array
    {
        return ['page', 'entity'];
    }

    public function metricIds(): array
    {
        return ['website.http_status', 'website.html_change'];
    }

    public function project(WebsiteProjectionContext $context): WebsiteProjectionContribution
    {
        $asset = $context->websiteAsset;
        $assetId = (int) $asset->getKey();
        $inventory = $this->inventory($assetId);
        $http = $this->latestSnapshots('website_http_snapshot', $assetId);
        $metadata = $this->latestSnapshots('website_metadata_snapshot', $assetId);
        $headings = $this->latestSnapshots('website_heading_snapshot', $assetId);
        $schema = $this->latestSnapshots('website_schema_snapshot', $assetId);
        $content = $this->latestSnapshots('website_content_stats', $assetId);
        $html = $this->latestSnapshots('website_html_snapshot', $assetId);
        $performance = $this->latestPerformance($assetId);
        $issues = $this->latestIssues($assetId, $http, $metadata);
        $links = $this->latestLinks($assetId, $http, $metadata);

        $urls = array_values(array_unique(array_filter([
            ...array_keys($inventory),
            ...array_keys($http),
            ...array_keys($metadata),
            ...array_keys($headings),
            ...array_keys($schema),
            ...array_keys($content),
            ...array_keys($html),
        ])));
        sort($urls);

        $pageContributions = [];
        $watermarks = [];
        foreach ($urls as $url) {
            $identityRow = $inventory[$url] ?? $html[$url] ?? $metadata[$url] ?? $http[$url] ?? null;
            $source = $this->support->source(
                provider: 'website',
                sourceClass: IntelligenceSourceClass::DirectObserved,
                semantic: 'public_page_observation',
                datasetId: isset($inventory[$url]) ? 'website_url' : $this->datasetFor($identityRow),
                row: $identityRow,
                fallbackAssetId: $assetId,
                recordKey: isset($identityRow->id) ? (string) $identityRow->id : hash('sha256', $url),
            );
            $observedAt = $this->support->latestTimestamp(
                $inventory[$url]->last_collected_at ?? null,
                $http[$url]->observed_at ?? null,
                $metadata[$url]->observed_at ?? null,
                $html[$url]->observed_at ?? null,
            );
            $time = $this->support->time(
                timezone: (string) ($identityRow->source_timezone ?? 'UTC'),
                observedAt: $observedAt,
                retrievedAt: $identityRow->last_collected_at ?? null,
                marketCode: $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null,
                languageCode: $asset->seo_market_language_code,
            );
            $identity = $this->pages->resolveObserved(
                websiteAsset: $asset,
                observedUrl: $url,
                source: $source,
                time: $time,
                aliasKind: 'public_observed_url',
            );

            $httpMeta = $this->support->json($http[$url]->metadata ?? null);
            $meta = $this->support->json($metadata[$url]->metadata ?? null);
            $heading = $this->support->json($headings[$url]->metadata ?? null);
            $schemaMeta = $this->support->json($schema[$url]->metadata ?? null);
            $contentMeta = $this->support->json($content[$url]->metadata ?? null);
            $statusCode = is_numeric($httpMeta['status_code'] ?? null)
                ? (int) $httpMeta['status_code']
                : (isset($html[$url]->status_code) ? (int) $html[$url]->status_code : null);
            $httpSource = $this->support->source(
                provider: 'website',
                sourceClass: IntelligenceSourceClass::DirectObserved,
                semantic: 'public_http_observation',
                datasetId: 'website_http_snapshot',
                row: $http[$url] ?? $html[$url] ?? $identityRow,
                fallbackAssetId: $assetId,
            );
            $httpTime = $this->support->time(
                timezone: (string) ($http[$url]->source_timezone ?? 'UTC'),
                observedAt: $http[$url]->observed_at ?? $html[$url]->observed_at ?? $observedAt,
                retrievedAt: $http[$url]->last_collected_at ?? $html[$url]->last_collected_at ?? null,
            );

            $metrics = [];
            if ($statusCode !== null) {
                $metrics[] = $this->support->metric(
                    metricId: 'website.http_status',
                    value: $statusCode,
                    grain: 'page_observation',
                    dimensions: ['page_identity_id' => (int) $identity->getKey()],
                    source: $httpSource,
                    time: $httpTime,
                );
            }
            if (isset($html[$url]) && trim((string) $html[$url]->change_state) !== '') {
                $htmlSource = $this->support->source(
                    provider: 'website',
                    sourceClass: IntelligenceSourceClass::DirectObserved,
                    semantic: 'public_html_observation',
                    datasetId: 'website_html_snapshot',
                    row: $html[$url],
                    fallbackAssetId: $assetId,
                    recordKey: hash('sha256', $url),
                );
                $htmlTime = $this->support->time(
                    timezone: (string) ($html[$url]->source_timezone ?? 'UTC'),
                    observedAt: $html[$url]->observed_at,
                    retrievedAt: $html[$url]->last_collected_at,
                );
                $metrics[] = $this->support->metric(
                    metricId: 'website.html_change',
                    value: (string) $html[$url]->change_state,
                    grain: 'page_observation',
                    dimensions: ['page_identity_id' => (int) $identity->getKey()],
                    source: $htmlSource,
                    time: $htmlTime,
                );
            }

            $sourceState = [
                'state' => 'collected',
                'url' => $url,
                'http' => $httpMeta === [] ? null : [
                    'status_code' => $statusCode,
                    'final_url' => $httpMeta['final_url'] ?? null,
                    'redirect_count' => $httpMeta['redirect_count'] ?? null,
                    'content_type' => $httpMeta['content_type'] ?? null,
                    'reachable' => $httpMeta['ok'] ?? null,
                    'error' => $httpMeta['error'] ?? null,
                    'observed_at' => $http[$url]->observed_at ?? null,
                ],
                'document_head' => isset($metadata[$url]) ? [
                    'title' => $meta['title'] ?? null,
                    'title_present' => $meta['title_present'] ?? null,
                    'meta_description' => $meta['meta_description'] ?? null,
                    'canonical_hrefs' => $meta['canonical_hrefs'] ?? [],
                    'robots' => $meta['meta_robots'] ?? null,
                    'observed_at' => $metadata[$url]->observed_at ?? null,
                ] : null,
                'headings' => isset($headings[$url]) ? [
                    'h1' => $heading['h1'] ?? null,
                    'h1_present' => $heading['h1_present'] ?? null,
                    'observed_at' => $headings[$url]->observed_at ?? null,
                ] : null,
                'structured_data' => isset($schema[$url]) ? [
                    'types' => $schemaMeta['types'] ?? [],
                    'block_count' => $schemaMeta['block_count'] ?? null,
                    'valid_blocks' => $schemaMeta['parse_ok_count'] ?? null,
                    'malformed_blocks' => $schemaMeta['malformed_count'] ?? null,
                    'observed_at' => $schema[$url]->observed_at ?? null,
                ] : null,
                'content' => isset($content[$url]) ? [
                    'word_count' => $contentMeta['word_count'] ?? null,
                    'paragraph_count' => $contentMeta['paragraph_count'] ?? null,
                    'visible_text_length' => $contentMeta['visible_text_length'] ?? null,
                    'language' => $contentMeta['language'] ?? null,
                    'thin_content_hint' => $contentMeta['thin_content_hint'] ?? null,
                    'observed_at' => $content[$url]->observed_at ?? null,
                ] : null,
                'html' => isset($html[$url]) ? [
                    'html_hash' => $html[$url]->html_hash,
                    'previous_html_hash' => $html[$url]->previous_html_hash,
                    'change_state' => $html[$url]->change_state,
                    'html_bytes' => (int) $html[$url]->html_bytes,
                    'raw_ingestion_object_id' => $html[$url]->raw_ingestion_object_id !== null ? (int) $html[$url]->raw_ingestion_object_id : null,
                    'observed_at' => $html[$url]->observed_at,
                ] : null,
                'performance' => $performance[$url] ?? [],
                'crawl_issues' => $issues[$url] ?? [],
                'links' => $links[$url] ?? ['internal' => 0, 'external' => 0],
                'metrics' => $metrics,
                'source_records' => $this->sourceRecords([
                    'website_url' => $inventory[$url] ?? null,
                    'website_http_snapshot' => $http[$url] ?? null,
                    'website_metadata_snapshot' => $metadata[$url] ?? null,
                    'website_heading_snapshot' => $headings[$url] ?? null,
                    'website_schema_snapshot' => $schema[$url] ?? null,
                    'website_content_stats' => $content[$url] ?? null,
                    'website_html_snapshot' => $html[$url] ?? null,
                ]),
            ];

            $pageContributions[] = [
                'identity_id' => (int) $identity->getKey(),
                'source_state' => $sourceState,
                'observed_at' => $observedAt,
            ];
            if ($observedAt !== null) {
                $watermarks[] = $observedAt;
            }
        }

        $entityContributions = $this->brandEntity($context);
        $watermark = $this->support->latestTimestamp(...$watermarks);

        return new WebsiteProjectionContribution(
            sourceId: $this->sourceId(),
            pages: $pageContributions,
            entities: $entityContributions,
            coverage: [
                'state' => $pageContributions === [] ? 'not_collected' : 'collected',
                'page_count' => count($pageContributions),
                'html_snapshot_count' => count($html),
                'period_policy' => 'latest_direct_observation_per_page',
                'watermark' => $watermark,
            ],
            watermark: $watermark,
        );
    }

    /** @return array<string, object> */
    private function inventory(int $assetId): array
    {
        if (! Schema::hasTable('website_url')) {
            return [];
        }

        return $this->support->latestBy(
            DB::table('website_url')->where('digital_asset_id', $assetId)->orderByDesc('last_collected_at')->orderByDesc('id')->get(),
            static fn (object $row): string => (string) $row->normalized_url,
        );
    }

    /** @return array<string, object> */
    private function latestSnapshots(string $table, int $assetId): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return $this->support->latestBy(
            DB::table($table)->where('digital_asset_id', $assetId)->orderByDesc('observed_at')->orderByDesc('id')->get(),
            static fn (object $row): string => (string) $row->url,
        );
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function latestPerformance(int $assetId): array
    {
        if (! Schema::hasTable('website_performance_measurement')) {
            return [];
        }

        $latest = $this->support->latestBy(
            DB::table('website_performance_measurement')->where('digital_asset_id', $assetId)->orderByDesc('observed_at')->orderByDesc('id')->get(),
            static fn (object $row): string => $row->url.'|'.$row->strategy,
        );
        $grouped = [];
        foreach ($latest as $row) {
            $grouped[(string) $row->url][] = [
                'strategy' => (string) $row->strategy,
                'observed_at' => (string) $row->observed_at,
                'measurements' => $this->support->json($row->metadata),
                'source_record_id' => (int) $row->id,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<string, object>  $http
     * @param  array<string, object>  $metadata
     * @return array<string, list<array<string, mixed>>>
     */
    private function latestIssues(int $assetId, array $http, array $metadata): array
    {
        if (! Schema::hasTable('website_crawl_issue_snapshot')) {
            return [];
        }

        $latest = $this->support->latestBy(
            DB::table('website_crawl_issue_snapshot')->where('digital_asset_id', $assetId)->orderByDesc('observed_at')->orderByDesc('id')->get(),
            static fn (object $row): string => $row->url.'|'.$row->issue_code,
        );
        $grouped = [];
        foreach ($latest as $row) {
            $url = (string) $row->url;
            $currentObservedAt = $metadata[$url]->observed_at ?? $http[$url]->observed_at ?? null;
            if ($currentObservedAt === null || (string) $row->observed_at !== (string) $currentObservedAt) {
                continue;
            }

            $grouped[$url][] = [
                'code' => (string) $row->issue_code,
                'severity' => (string) $row->severity,
                'message' => (string) $row->message,
                'observed_at' => (string) $row->observed_at,
                'source_record_id' => (int) $row->id,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<string, object>  $http
     * @param  array<string, object>  $metadata
     * @return array<string, array{internal:int, external:int, observed_at:?string}>
     */
    private function latestLinks(int $assetId, array $http, array $metadata): array
    {
        if (! Schema::hasTable('website_link_edge')) {
            return [];
        }

        $latestObservedAt = DB::table('website_link_edge')->where('digital_asset_id', $assetId)->max('observed_at');
        if ($latestObservedAt === null) {
            return [];
        }

        $links = [];
        foreach (DB::table('website_link_edge')->where('digital_asset_id', $assetId)->where('observed_at', $latestObservedAt)->get() as $row) {
            $url = (string) $row->source_url;
            $currentObservedAt = $metadata[$url]->observed_at ?? $http[$url]->observed_at ?? null;
            if ($currentObservedAt === null || (string) $row->observed_at !== (string) $currentObservedAt) {
                continue;
            }

            $links[$url] ??= ['internal' => 0, 'external' => 0, 'observed_at' => (string) $row->observed_at];
            $links[$url][$row->is_internal ? 'internal' : 'external']++;
        }

        return $links;
    }

    /** @return list<array{identity_id:int, source_state:array<string,mixed>, observed_at:?string}> */
    private function brandEntity(WebsiteProjectionContext $context): array
    {
        $asset = $context->websiteAsset;
        $brand = $asset->brand;
        if ($brand === null || trim((string) $brand->name) === '') {
            return [];
        }

        $source = $this->support->source(
            provider: 'website',
            sourceClass: IntelligenceSourceClass::OperatorMaintained,
            semantic: 'website_configured_brand',
            datasetId: 'digital_assets',
            row: null,
            fallbackAssetId: (int) $asset->getKey(),
            recordKey: (string) $asset->getKey(),
        );
        $time = $this->support->time(
            timezone: 'UTC',
            observedAt: $asset->updated_at,
            retrievedAt: now(),
            marketCode: $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null,
            languageCode: $asset->seo_market_language_code,
        );
        $identity = $this->entities->resolve(
            brand: $brand,
            entityType: 'organization',
            observedName: (string) $brand->name,
            source: $source,
            time: $time,
            aliasKind: 'operator_brand_name',
            externalEntityId: 'brand:'.$brand->getKey(),
            countryCode: is_array($asset->target_countries) ? ($asset->target_countries[0] ?? null) : null,
            matchMethod: IdentityMatchMethod::OperatorConfirmed,
            metadata: ['website_asset_id' => (int) $asset->getKey()],
        );

        $infra = null;
        if (Schema::hasTable('website_infra_snapshot')) {
            $infraRow = DB::table('website_infra_snapshot')
                ->where('digital_asset_id', $asset->getKey())
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->first();
            if ($infraRow !== null) {
                $infra = [
                    'observed_at' => (string) $infraRow->observed_at,
                    'facts' => $this->support->json($infraRow->metadata),
                    'source_record_id' => (int) $infraRow->id,
                ];
            }
        }

        return [[
            'identity_id' => (int) $identity->getKey(),
            'source_state' => [
                'state' => 'configured',
                'brand_id' => (int) $brand->getKey(),
                'website_asset_id' => (int) $asset->getKey(),
                'domain' => $asset->domain,
                'primary_url' => $asset->primary_url,
                'languages' => $asset->languages ?? [],
                'target_countries' => $asset->target_countries ?? [],
                'infra' => $infra,
                'source' => $source->toArray(),
                'time_context' => $time->toArray(),
            ],
            'observed_at' => $time->observedAt?->format(DATE_ATOM),
        ]];
    }

    /** @param array<string, object|null> $rows @return array<string, array<string, int|string|null>> */
    private function sourceRecords(array $rows): array
    {
        $records = [];
        foreach ($rows as $dataset => $row) {
            if ($row === null) {
                continue;
            }
            $records[$dataset] = [
                'id' => (int) $row->id,
                'collection_run_id' => $row->last_collection_run_id !== null ? (int) $row->last_collection_run_id : null,
                'dataset_run_id' => $row->last_dataset_run_id !== null ? (int) $row->last_dataset_run_id : null,
                'contract_version' => (int) $row->contract_version,
                'last_collected_at' => isset($row->last_collected_at) ? (string) $row->last_collected_at : null,
            ];
        }

        return $records;
    }

    private function datasetFor(?object $row): string
    {
        if ($row !== null && isset($row->html_hash)) {
            return 'website_html_snapshot';
        }
        if ($row !== null && isset($row->url)) {
            return 'website_metadata_snapshot';
        }

        return 'website_url';
    }
}
