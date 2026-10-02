<?php

namespace App\Services\Analysis\Adapters;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\DigitalAsset;
use App\Services\Analysis\Support\CollectedFactsJson;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Support\Findings\RuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connector facts and connector ↔ published HTML parity rules.
 * No provider calls, AI inference, CVE claims, or automatic Tasks.
 */
final class WordPressCollectedFactsEvaluator
{
    public const string RULE_CORE_UPDATE = 'website.wordpress.core_update_available.v1';

    public const string RULE_PLUGIN_UPDATE = 'website.wordpress.plugin_update_available.v1';

    public const string RULE_THEME_UPDATE = 'website.wordpress.theme_update_available.v1';

    public const string RULE_REST_STATE = 'website.wordpress.rest_unhealthy.v1';

    public const string RULE_CRON_DISABLED = 'website.wordpress.cron_disabled.v1';

    public const string RULE_SEO_DESCRIPTION_PARITY = 'website.wordpress.seo_description_public_missing.v1';

    public const string RULE_SEO_TITLE_PARITY = 'website.wordpress.seo_title_public_missing.v1';

    public const string RULE_SEO_CANONICAL_PARITY = 'website.wordpress.seo_canonical_public_mismatch.v1';

    /**
     * @return array{
     *   evaluated:bool,
     *   evaluated_rule_ids:list<string>,
     *   matches:list<RuleMatch>,
     *   observed_at:?CarbonImmutable,
     *   provenance:array<string,mixed>,
     *   evidence:array<string,mixed>
     * }
     */
    public function evaluate(DigitalAsset $asset): array
    {
        $datasetRunIds = $this->completedConnectorDatasetRunIds($asset);
        if ($datasetRunIds === [] || ! $this->tablesReady()) {
            return $this->empty();
        }

        $site = DB::table('website_cms_site_snapshot')
            ->where('digital_asset_id', $asset->id)
            ->whereIn('last_dataset_run_id', $datasetRunIds)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
        if ($site === null) {
            return $this->empty();
        }

        $observedAt = CarbonImmutable::parse((string) $site->observed_at);
        $connectorCollectionRunId = (int) $site->last_collection_run_id;
        $evaluated = [self::RULE_REST_STATE, self::RULE_CRON_DISABLED];
        $matches = [];
        $siteMetadata = CollectedFactsJson::decode($site->metadata ?? null);

        $coreUpdateCheckedAt = trim((string) ($siteMetadata['core_update_checked_at'] ?? ''));
        if ($coreUpdateCheckedAt !== '') {
            $evaluated[] = self::RULE_CORE_UPDATE;
        }
        if ($coreUpdateCheckedAt !== '' && ($siteMetadata['core_update_available'] ?? false) === true) {
            $available = trim((string) ($siteMetadata['available_wordpress_version'] ?? ''));
            $matches[] = new RuleMatch(
                ruleId: self::RULE_CORE_UPDATE,
                fingerprint: self::RULE_CORE_UPDATE,
                category: 'technical',
                severity: 'high',
                title: 'WordPress core update is available',
                summary: 'The authenticated connector reports an available WordPress core update'.($available !== '' ? " ({$available})" : '').'. This is an update-state observation, not a vulnerability claim.',
                confidence: 0.99,
                recommendationTitle: 'Review and apply the WordPress core update',
                recommendationAction: 'Back up the site, validate compatibility in staging, then apply the reported WordPress core update.',
            );
        }

        $allExtensions = DB::table('website_cms_extension_snapshot')
            ->where('digital_asset_id', $asset->id)
            ->where('last_collection_run_id', $connectorCollectionRunId)
            ->whereIn('last_dataset_run_id', $datasetRunIds)
            ->get();
        $updateScope = [];
        foreach ($allExtensions as $extension) {
            $extensionMetadata = CollectedFactsJson::decode($extension->metadata ?? null);
            if (trim((string) ($extensionMetadata['update_checked_at'] ?? '')) !== '') {
                $updateScope[(string) $extension->extension_type] = true;
            }
        }
        if (isset($updateScope['plugin'])) {
            $evaluated[] = self::RULE_PLUGIN_UPDATE;
        }
        if (isset($updateScope['theme'])) {
            $evaluated[] = self::RULE_THEME_UPDATE;
        }
        $extensions = $allExtensions->filter(
            fn (object $extension): bool => (bool) $extension->update_available
                && isset($updateScope[(string) $extension->extension_type]),
        );
        foreach ($extensions as $extension) {
            $type = (string) $extension->extension_type;
            $ruleId = $type === 'theme' ? self::RULE_THEME_UPDATE : self::RULE_PLUGIN_UPDATE;
            $name = trim((string) ($extension->name ?: $extension->extension_id));
            $matches[] = new RuleMatch(
                ruleId: $ruleId,
                fingerprint: $ruleId.':'.hash('sha256', (string) $extension->extension_id),
                category: 'technical',
                severity: $type === 'theme' && (string) $extension->status === 'active' ? 'high' : 'medium',
                title: ucfirst($type).' update is available: '.$name,
                summary: 'The connector reports '.$name.' '.((string) $extension->version ?: 'unknown').' → '.((string) $extension->available_version ?: 'newer version').'. This does not assert a security vulnerability.',
                confidence: 0.99,
                recommendationTitle: 'Review the '.$name.' update',
                recommendationAction: 'Check compatibility and release notes, validate in staging, then update '.$name.' through the normal WordPress release process.',
            );
        }

        if (! in_array(strtolower((string) $site->rest_state), ['reachable', 'healthy'], true)) {
            $matches[] = new RuleMatch(
                ruleId: self::RULE_REST_STATE,
                fingerprint: self::RULE_REST_STATE,
                category: 'technical',
                severity: 'high',
                title: 'WordPress REST state is not healthy',
                summary: 'The authenticated connector reported REST state: '.((string) $site->rest_state ?: 'unknown').'.',
                confidence: 0.98,
                recommendationTitle: 'Restore WordPress REST availability',
                recommendationAction: 'Review WordPress, security plugin, cache, and web-server REST restrictions; then run the connector check again.',
            );
        }
        if (strtolower((string) $site->cron_state) === 'disabled') {
            $matches[] = new RuleMatch(
                ruleId: self::RULE_CRON_DISABLED,
                fingerprint: self::RULE_CRON_DISABLED,
                category: 'technical',
                severity: 'medium',
                title: 'WordPress built-in cron is disabled',
                summary: 'DISABLE_WP_CRON is active. This can be intentional, but a replacement system scheduler must be verified.',
                confidence: 0.99,
                recommendationTitle: 'Verify the external WordPress cron schedule',
                recommendationAction: 'Confirm a reliable system cron invokes wp-cron.php or WP-CLI on the intended cadence and monitor missed scheduled events.',
            );
        }

        $publicRunIds = $this->completedPublicDatasetRunIds($asset);
        if ($publicRunIds !== [] && Schema::hasTable('website_metadata_snapshot')) {
            $publicByUrl = $this->publicMetadataByUrl($asset, $publicRunIds);
            $seoRows = DB::table('website_cms_seo_snapshot')
                ->where('digital_asset_id', $asset->id)
                ->where('last_collection_run_id', $connectorCollectionRunId)
                ->whereIn('last_dataset_run_id', $datasetRunIds)
                ->get();
            $parityEvaluated = false;
            foreach ($seoRows as $seo) {
                $urlKey = $this->urlKey((string) $seo->permalink);
                $public = $publicByUrl[$urlKey] ?? null;
                if ($public === null) {
                    continue;
                }
                if (! $parityEvaluated) {
                    array_push($evaluated, self::RULE_SEO_DESCRIPTION_PARITY, self::RULE_SEO_TITLE_PARITY, self::RULE_SEO_CANONICAL_PARITY);
                    $parityEvaluated = true;
                }
                $meta = CollectedFactsJson::decode($public->metadata ?? null);
                $fingerprintSuffix = hash('sha256', (string) $seo->object_type.'|'.(string) $seo->object_id);
                if (trim((string) $seo->meta_description) !== '' && ! (bool) ($meta['meta_description_present'] ?? false)) {
                    $matches[] = $this->parityMatch(
                        self::RULE_SEO_DESCRIPTION_PARITY,
                        $fingerprintSuffix,
                        'WordPress meta description is not present in published HTML',
                        (string) $seo->permalink,
                        'Inspect the SEO plugin output, theme head hooks, cache, and template conditions; then verify the public HTML again.',
                    );
                }
                if (trim((string) $seo->seo_title) !== '' && ! (bool) ($meta['title_present'] ?? false)) {
                    $matches[] = $this->parityMatch(
                        self::RULE_SEO_TITLE_PARITY,
                        $fingerprintSuffix,
                        'WordPress SEO title is not present in published HTML',
                        (string) $seo->permalink,
                        'Inspect document title support, SEO plugin output, template head hooks, and cache; then verify the public HTML again.',
                    );
                }
                $configuredCanonical = $this->urlKey((string) $seo->canonical_url);
                $publicCanonicals = array_values(array_filter(array_map(
                    fn (mixed $url): string => $this->urlKey(is_string($url) ? $url : ''),
                    is_array($meta['canonical_hrefs'] ?? null) ? $meta['canonical_hrefs'] : [],
                )));
                if ($configuredCanonical !== '' && ! in_array($configuredCanonical, $publicCanonicals, true)) {
                    $matches[] = $this->parityMatch(
                        self::RULE_SEO_CANONICAL_PARITY,
                        $fingerprintSuffix,
                        'WordPress canonical setting differs from published HTML',
                        (string) $seo->permalink,
                        'Align the SEO plugin canonical field with the canonical link emitted by the active theme/template and clear relevant caches.',
                    );
                }
            }
        }

        return [
            'evaluated' => true,
            'evaluated_rule_ids' => array_values(array_unique($evaluated)),
            'matches' => $matches,
            'observed_at' => $observedAt,
            'provenance' => [
                'dataset_id' => 'website_cms_site_snapshot',
                'dataset_run_id' => $site->last_dataset_run_id,
                'collection_run_id' => $site->last_collection_run_id,
                'observed_at' => $observedAt->toIso8601String(),
                'provider_or_source' => 'WORDPRESS_SITE_CONNECTOR',
            ],
            'evidence' => [
                'wordpress_version' => $site->wordpress_version,
                'php_version' => $site->php_version,
                'active_theme' => $site->active_theme,
                'extensions_with_updates' => $extensions->count(),
                'connector_observed_at' => $observedAt->toIso8601String(),
                'generated_by_ai' => false,
            ],
        ];
    }

    private function parityMatch(string $ruleId, string $suffix, string $title, string $url, string $action): RuleMatch
    {
        return new RuleMatch(
            ruleId: $ruleId,
            fingerprint: $ruleId.':'.$suffix,
            category: 'seo',
            severity: 'medium',
            title: $title,
            summary: 'Connector configuration and Public Discovery disagree for '.$url.'. Both observations are required for this result.',
            confidence: 0.97,
            recommendationTitle: 'Align WordPress configuration and published HTML',
            recommendationAction: $action,
        );
    }

    /** @param list<int> $runIds @return array<string, object> */
    private function publicMetadataByUrl(DigitalAsset $asset, array $runIds): array
    {
        $indexed = [];
        $rows = DB::table('website_metadata_snapshot')
            ->where('digital_asset_id', $asset->id)
            ->whereIn('last_dataset_run_id', $runIds)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();
        foreach ($rows as $row) {
            $key = $this->urlKey((string) $row->url);
            if ($key !== '' && ! isset($indexed[$key])) {
                $indexed[$key] = $row;
            }
        }

        return $indexed;
    }

    /** @return list<int> */
    private function completedConnectorDatasetRunIds(DigitalAsset $asset): array
    {
        return $this->completedDatasetRunIds($asset, [WebsiteRequestFamilyCatalog::FAMILY_WP_REST]);
    }

    /** @return list<int> */
    private function completedPublicDatasetRunIds(DigitalAsset $asset): array
    {
        return $this->completedDatasetRunIds($asset, [
            WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL,
            WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS,
        ]);
    }

    /** @param list<string> $families @return list<int> */
    private function completedDatasetRunIds(DigitalAsset $asset, array $families): array
    {
        return CollectionDatasetRun::query()
            ->where('status', CollectionRunStatus::Completed)
            ->whereIn('request_family_id', $families)
            ->whereHas('resourceRun', function (EloquentBuilder $query) use ($asset): void {
                $query->where('digital_asset_id', $asset->id)->whereNull('external_resource_id');
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function tablesReady(): bool
    {
        foreach (['website_cms_site_snapshot', 'website_cms_extension_snapshot', 'website_cms_seo_snapshot'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function urlKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $port = isset($parts['port']) && ! (($scheme === 'https' && (int) $parts['port'] === 443) || ($scheme === 'http' && (int) $parts['port'] === 80))
            ? ':'.(int) $parts['port']
            : '';
        $path = (string) ($parts['path'] ?? '');
        $path = $path === '/' ? '' : rtrim($path, '/');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /** @return array{evaluated:bool,evaluated_rule_ids:array,matches:array,observed_at:null,provenance:array,evidence:array} */
    private function empty(): array
    {
        return ['evaluated' => false, 'evaluated_rule_ids' => [], 'matches' => [], 'observed_at' => null, 'provenance' => [], 'evidence' => []];
    }
}
