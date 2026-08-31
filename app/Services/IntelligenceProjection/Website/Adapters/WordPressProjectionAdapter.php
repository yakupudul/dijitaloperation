<?php

namespace App\Services\IntelligenceProjection\Website\Adapters;

use App\Contracts\IntelligenceCore\WebsiteProjectionSourceAdapter;
use App\Enums\IntelligenceCore\IdentityMatchMethod;
use App\Enums\IntelligenceCore\IntelligenceSourceClass;
use App\Models\CoreConnection;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
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
        return ['page'];
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

        if (! Schema::hasTable('website_cms_object_snapshot')) {
            return new WebsiteProjectionContribution(
                sourceId: $this->sourceId(),
                coverage: ['state' => $connected ? 'not_collected' : 'not_configured'],
            );
        }

        $objectRows = DB::table('website_cms_object_snapshot')
            ->where('digital_asset_id', $assetId)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();
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
        $watermarks[] = $site['last_collected_at'] ?? null;
        $watermark = $this->support->latestTimestamp(...$watermarks);

        return new WebsiteProjectionContribution(
            sourceId: $this->sourceId(),
            pages: $pages,
            coverage: [
                'state' => ! $connected ? 'not_configured' : ($objects === [] ? 'not_collected' : 'collected'),
                'connected' => $connected,
                'object_count' => count($objects),
                'page_profile_count' => count($pages),
                'extension_count' => $this->latestSnapshotCount('website_cms_extension_snapshot', $assetId),
                'taxonomy_count' => $this->latestSnapshotCount('website_cms_taxonomy_snapshot', $assetId),
                'seo_record_count' => count($seo),
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
            'rest_state' => $row->rest_state,
            'cron_state' => $row->cron_state,
            'site_health' => [
                'good' => $row->site_health_good_count,
                'recommended' => $row->site_health_recommended_count,
                'critical' => $row->site_health_critical_count,
            ],
            'observed_at' => (string) $row->observed_at,
            'last_collected_at' => (string) $row->last_collected_at,
            'source_record_id' => (int) $row->id,
        ];
    }

    private function latestSnapshotCount(string $table, int $assetId): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $latest = DB::table($table)->where('digital_asset_id', $assetId)->max('observed_at');
        if ($latest === null) {
            return 0;
        }

        return DB::table($table)->where('digital_asset_id', $assetId)->where('observed_at', $latest)->count();
    }
}
