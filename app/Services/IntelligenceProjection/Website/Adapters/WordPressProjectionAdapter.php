<?php

namespace App\Services\IntelligenceProjection\Website\Adapters;

use App\Contracts\IntelligenceCore\WebsiteProjectionSourceAdapter;
use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Models\CoreConnection;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\IntelligenceCore\Identity\EntityIdentityResolver;
use App\Services\IntelligenceCore\Identity\PageIdentityResolver;
use App\Services\IntelligenceProjection\Website\WebsiteProjectionAdapterSupport;
use App\Support\IntelligenceProjection\WebsiteProjectionContext;
use App\Support\IntelligenceProjection\WebsiteProjectionContribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WordPressProjectionAdapter implements WebsiteProjectionSourceAdapter
{
    public function __construct(
        private readonly PageIdentityResolver $pages,
        private readonly EntityIdentityResolver $entities,
        private readonly WebsiteProjectionAdapterSupport $support,
    ) {}

    public function sourceId(): string
    {
        return 'wordpress';
    }

    public function capabilityIds(): array
    {
        return ['website.cms_snapshot.read'];
    }

    public function profileIds(): array
    {
        return ['page', 'entity'];
    }

    public function metricIds(): array
    {
        return [];
    }

    public function project(WebsiteProjectionContext $context): WebsiteProjectionContribution
    {
        $asset = $context->websiteAsset;
        $assetId = (int) $asset->getKey();
        $connected = CoreConnection::query()
            ->where('digital_asset_id', $assetId)
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)
            ->where('enabled', true)
            ->exists();

        $objectRows = Schema::hasTable('website_cms_object_snapshot')
            ? DB::table('website_cms_object_snapshot')
                ->where('digital_asset_id', $assetId)
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->get()
            : collect();
        $objects = $this->support->latestBy(
            $objectRows,
            static fn (object $row): string => $row->cms.'|'.$row->object_type.'|'.$row->object_id,
        );
        $seoRows = Schema::hasTable('website_cms_seo_snapshot')
            ? DB::table('website_cms_seo_snapshot')->where('digital_asset_id', $assetId)->orderByDesc('observed_at')->orderByDesc('id')->get()
            : collect();
        $seo = $this->support->latestBy(
            $seoRows,
            static fn (object $row): string => $row->cms.'|'.$row->object_type.'|'.$row->object_id,
        );

        $pages = [];
        $watermarks = [];
        foreach ($objects as $key => $row) {
            $permalink = trim((string) ($row->permalink ?? ''));
            if ($permalink === '') {
                continue;
            }

            $source = $this->support->source(
                provider: 'wordpress',
                sourceClass: IntelligenceSourceClass::CmsAuthenticated,
                semantic: 'cms_object_permalink',
                datasetId: 'website_cms_object_snapshot',
                row: $row,
                fallbackAssetId: $assetId,
                recordKey: $row->cms.'|'.$row->object_type.'|'.$row->object_id,
            );
            $time = $this->support->time(
                timezone: (string) ($row->source_timezone ?? 'UTC'),
                observedAt: $row->observed_at,
                retrievedAt: $row->last_collected_at,
                marketCode: $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null,
                languageCode: $this->support->json($row->metadata)['language'] ?? $asset->seo_market_language_code,
            );
            $identity = $this->pages->resolveObserved(
                websiteAsset: $asset,
                observedUrl: $permalink,
                source: $source,
                time: $time,
                aliasKind: 'cms_permalink',
            );
            $this->pages->attachEvidenceAlias(
                identity: $identity,
                observedUrl: $permalink,
                source: $source,
                time: $time,
                aliasKind: 'cms_permalink',
                matchMethod: IdentityMatchMethod::CmsPermalinkEvidence,
                metadata: ['cms_object_type' => (string) $row->object_type, 'cms_object_id' => (string) $row->object_id],
            );

            $rowMeta = $this->support->json($row->metadata);
            $seoRow = $seo[$key] ?? null;
            $pages[] = [
                'identity_id' => (int) $identity->getKey(),
                'source_state' => [
                    'state' => 'collected',
                    'cms' => (string) $row->cms,
                    'object' => [
                        'type' => (string) $row->object_type,
                        'id' => (string) $row->object_id,
                        'status' => $row->status,
                        'slug' => $row->slug,
                        'permalink' => $row->permalink,
                        'title' => $row->title,
                        'published_at' => $row->published_at,
                        'modified_at' => $row->modified_at,
                        'parent_id' => $row->parent_id,
                        'template' => $row->template,
                        'featured_media_id' => $row->featured_media_id,
                        'language' => $rowMeta['language'] ?? null,
                        'translations' => $rowMeta['translations'] ?? [],
                    ],
                    'content' => [
                        'content_hash' => $rowMeta['content_hash'] ?? null,
                        'content_length' => $rowMeta['content_length'] ?? null,
                        'builder_provider' => $rowMeta['builder_provider'] ?? null,
                        'builder_content_hash' => $rowMeta['builder_content_hash'] ?? null,
                        'builder_content_length' => $rowMeta['builder_content_length'] ?? null,
                        'raw_html_retained_in_private_ingestion' => true,
                    ],
                    'seo' => $seoRow !== null ? [
                        'provider' => $seoRow->seo_provider,
                        'title' => $seoRow->seo_title,
                        'meta_description' => $seoRow->meta_description,
                        'canonical_url' => $seoRow->canonical_url,
                        'robots' => $seoRow->robots,
                        'language' => $seoRow->language,
                        'observed_at' => (string) $seoRow->observed_at,
                        'source_record_id' => (int) $seoRow->id,
                    ] : null,
                    'source' => $source->toArray(),
                    'time_context' => $time->toArray(),
                ],
                'observed_at' => $time->observedAt?->format(DATE_ATOM),
            ];
            $watermarks[] = $row->last_collected_at;
        }

        $site = $this->currentSite($assetId);
        $extensions = $this->currentExtensions($assetId);
        $taxonomies = $this->currentTaxonomies($assetId);
        $seoProviders = collect($seo)
            ->map(static fn (object $row): string => trim((string) ($row->seo_provider ?? '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $watermarks[] = $site['last_collected_at'] ?? null;
        $watermarks[] = $extensions['last_collected_at'] ?? null;
        $watermarks[] = $taxonomies['last_collected_at'] ?? null;
        $watermark = $this->support->latestTimestamp(...$watermarks);
        $collected = $objects !== [] || $seo !== [] || $site !== null || $extensions !== null || $taxonomies !== null;

        return new WebsiteProjectionContribution(
            sourceId: $this->sourceId(),
            pages: $pages,
            entities: $this->siteEntity($context, $site, $extensions, $taxonomies, $seoProviders),
            coverage: [
                'state' => ! $connected ? 'not_configured' : ($collected ? 'collected' : 'not_collected'),
                'connected' => $connected,
                'object_count' => count($objects),
                'page_profile_count' => count($pages),
                'extension_count' => $extensions['count'] ?? null,
                'extension_update_count' => $extensions['update_count'] ?? null,
                'taxonomy_count' => $taxonomies['count'] ?? null,
                'seo_record_count' => count($seo),
                'seo_providers' => $seoProviders,
                'site' => $site,
                'watermark' => $watermark,
                'raw_content_policy' => 'private_ingestion_object_reference',
            ],
            watermark: $watermark,
        );
    }

    /** @return array<string, mixed>|null */
    private function currentSite(int $assetId): ?array
    {
        if (! Schema::hasTable('website_cms_site_snapshot')) {
            return null;
        }

        $row = DB::table('website_cms_site_snapshot')
            ->where('digital_asset_id', $assetId)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
        if ($row === null) {
            return null;
        }
        $metadata = $this->support->json($row->metadata);

        return [
            'cms' => $row->cms,
            'site_key' => $row->site_key,
            'site_url' => $row->site_url,
            'home_url' => $row->home_url,
            'wordpress_version' => $row->wordpress_version,
            'php_version' => $row->php_version,
            'locale' => $row->locale,
            'timezone' => $row->timezone,
            'active_theme' => $row->active_theme,
            'active_theme_name' => $metadata['active_theme_name'] ?? null,
            'active_theme_version' => $metadata['active_theme_version'] ?? null,
            'is_multisite' => (bool) $row->is_multisite,
            'rest_state' => $row->rest_state,
            'cron_state' => $row->cron_state,
            'core_update_available' => is_bool($metadata['core_update_available'] ?? null)
                ? $metadata['core_update_available']
                : null,
            'available_wordpress_version' => $metadata['available_wordpress_version'] ?? null,
            'core_update_checked_at' => $metadata['core_update_checked_at'] ?? null,
            'settings' => is_array($metadata['settings'] ?? null) ? $metadata['settings'] : [],
            'features' => is_array($metadata['features'] ?? null) ? $metadata['features'] : [],
            'site_health' => [
                'good' => $row->site_health_good_count,
                'recommended' => $row->site_health_recommended_count,
                'critical' => $row->site_health_critical_count,
            ],
            'observed_at' => (string) $row->observed_at,
            'last_collected_at' => (string) $row->last_collected_at,
            'source_record_id' => (int) $row->id,
            'external_resource_id' => $row->external_resource_id !== null ? (int) $row->external_resource_id : null,
            'last_collection_run_id' => $row->last_collection_run_id !== null ? (int) $row->last_collection_run_id : null,
            'last_dataset_run_id' => $row->last_dataset_run_id !== null ? (int) $row->last_dataset_run_id : null,
            'contract_version' => (int) $row->contract_version,
            'source_timezone' => $row->source_timezone,
        ];
    }

    /**
     * @return array{count:int,plugin_count:int,theme_count:int,active_count:int,inactive_count:int,update_count:int,auto_update_count:int,records:list<array<string,mixed>>,observed_at:string,last_collected_at:string}|null
     */
    private function currentExtensions(int $assetId): ?array
    {
        if (! Schema::hasTable('website_cms_extension_snapshot')) {
            return null;
        }

        $latest = DB::table('website_cms_extension_snapshot')->where('digital_asset_id', $assetId)->max('observed_at');
        if (! is_string($latest) || $latest === '') {
            return null;
        }

        $rows = DB::table('website_cms_extension_snapshot')
            ->where('digital_asset_id', $assetId)
            ->where('observed_at', $latest)
            ->orderBy('extension_type')
            ->orderBy('name')
            ->get();

        return [
            'count' => $rows->count(),
            'plugin_count' => $rows->where('extension_type', 'plugin')->count(),
            'theme_count' => $rows->where('extension_type', 'theme')->count(),
            'active_count' => $rows->where('status', 'active')->count(),
            'inactive_count' => $rows->where('status', 'inactive')->count(),
            'update_count' => $rows->where('update_available', true)->count(),
            'auto_update_count' => $rows->where('auto_update', true)->count(),
            'records' => $rows->map(static function (object $row): array {
                $metadata = is_string($row->metadata ?? null) ? json_decode($row->metadata, true) : $row->metadata;

                return [
                    'type' => (string) $row->extension_type,
                    'id' => (string) $row->extension_id,
                    'name' => $row->name,
                    'version' => $row->version,
                    'status' => $row->status,
                    'update_available' => (bool) $row->update_available,
                    'available_version' => $row->available_version,
                    'auto_update' => $row->auto_update !== null ? (bool) $row->auto_update : null,
                    'update_checked_at' => is_array($metadata) ? ($metadata['update_checked_at'] ?? null) : null,
                    'source_record_id' => (int) $row->id,
                    'collection_run_id' => $row->last_collection_run_id !== null ? (int) $row->last_collection_run_id : null,
                    'dataset_run_id' => $row->last_dataset_run_id !== null ? (int) $row->last_dataset_run_id : null,
                ];
            })->values()->all(),
            'observed_at' => (string) $latest,
            'last_collected_at' => (string) $rows->max('last_collected_at'),
        ];
    }

    /** @return array{count:int,content_count:int,by_taxonomy:array<string,int>,by_language:array<string,int>,observed_at:string,last_collected_at:string}|null */
    private function currentTaxonomies(int $assetId): ?array
    {
        if (! Schema::hasTable('website_cms_taxonomy_snapshot')) {
            return null;
        }

        $latest = DB::table('website_cms_taxonomy_snapshot')->where('digital_asset_id', $assetId)->max('observed_at');
        if (! is_string($latest) || $latest === '') {
            return null;
        }

        $rows = DB::table('website_cms_taxonomy_snapshot')
            ->where('digital_asset_id', $assetId)
            ->where('observed_at', $latest)
            ->get();
        $byTaxonomy = $rows->groupBy('taxonomy')->map->count()->sortDesc()->all();
        $byLanguage = $rows
            ->filter(static fn (object $row): bool => filled($row->language))
            ->groupBy('language')
            ->map->count()
            ->sortDesc()
            ->all();

        return [
            'count' => $rows->count(),
            'content_count' => (int) $rows->sum(static fn (object $row): int => is_numeric($row->content_count) ? (int) $row->content_count : 0),
            'by_taxonomy' => $byTaxonomy,
            'by_language' => $byLanguage,
            'observed_at' => (string) $latest,
            'last_collected_at' => (string) $rows->max('last_collected_at'),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $site
     * @param  array<string,mixed>|null  $extensions
     * @param  array<string,mixed>|null  $taxonomies
     * @param  list<string>  $seoProviders
     * @return list<array{identity_id:int,source_state:array<string,mixed>,observed_at:?string}>
     */
    private function siteEntity(
        WebsiteProjectionContext $context,
        ?array $site,
        ?array $extensions,
        ?array $taxonomies,
        array $seoProviders,
    ): array {
        $asset = $context->websiteAsset;
        $brand = $asset->brand;
        if ($site === null || $brand === null || trim((string) $brand->name) === '') {
            return [];
        }

        $source = $this->support->source(
            provider: 'wordpress',
            sourceClass: IntelligenceSourceClass::CmsAuthenticated,
            semantic: 'cms_site_configuration',
            datasetId: 'website_cms_site_snapshot',
            row: $site,
            fallbackAssetId: (int) $asset->getKey(),
            recordKey: (string) $site['site_key'],
        );
        $time = $this->support->time(
            timezone: (string) (($site['source_timezone'] ?? null) ?: 'UTC'),
            observedAt: $site['observed_at'],
            retrievedAt: $site['last_collected_at'],
            marketCode: $asset->seo_market_location_code !== null ? (string) $asset->seo_market_location_code : null,
            languageCode: $asset->seo_market_language_code,
        );
        $identity = $this->entities->resolve(
            brand: $brand,
            entityType: 'organization',
            observedName: (string) $brand->name,
            source: $source,
            time: $time,
            aliasKind: 'authenticated_cms_site',
            externalEntityId: 'brand:'.$brand->getKey(),
            countryCode: is_array($asset->target_countries) ? ($asset->target_countries[0] ?? null) : null,
            matchMethod: IdentityMatchMethod::OperatorConfirmed,
            metadata: ['website_asset_id' => (int) $asset->getKey(), 'site_key' => $site['site_key']],
        );

        return [[
            'identity_id' => (int) $identity->getKey(),
            'source_state' => [
                'state' => 'collected',
                'site' => $site,
                'extensions' => $extensions,
                'taxonomies' => $taxonomies,
                'seo_providers' => $seoProviders,
                'source' => $source->toArray(),
                'time_context' => $time->toArray(),
            ],
            'observed_at' => $time->observedAt?->format(DATE_ATOM),
        ]];
    }
}
