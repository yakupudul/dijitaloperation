<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsiteEntityProfile;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Illuminate\Support\Collection;

final class WebsiteInfrastructureReadService
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly WebsiteProjectionReadService $projection,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(
        DigitalAsset $asset,
        string $search = '',
        string $filter = 'all',
        int $page = 1,
    ): array {
        $projection = $this->projection->summary($asset);
        $entities = $this->projection->entities($asset)->get(['source_states', 'last_observed_at']);
        $wordpress = $this->sourceState($entities, 'wordpress');
        $website = $this->sourceState($entities, 'website');
        $site = $this->presentSite(is_array($wordpress['site'] ?? null) ? $wordpress['site'] : []);
        $extensions = is_array($wordpress['extensions'] ?? null) ? $wordpress['extensions'] : [];
        $taxonomies = is_array($wordpress['taxonomies'] ?? null) ? $wordpress['taxonomies'] : [];
        $records = collect(is_array($extensions['records'] ?? null) ? $extensions['records'] : [])
            ->filter(static fn (mixed $record): bool => is_array($record))
            ->map(fn (array $record): array => $this->presentExtension($record));
        $filtered = $this->filterExtensions($records, $search, $filter);
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $connection = $this->connection($asset);
        $wordpressCoverage = data_get($projection, 'coverage_state.wordpress', []);

        return [
            'available' => $site['available'] || $connection['exists'] || $website !== [],
            'projection' => $projection,
            'coverage' => [
                'state' => (string) data_get($wordpressCoverage, 'state', 'not_collected'),
                'watermark' => data_get($wordpressCoverage, 'watermark'),
                'object_count' => $this->integerOrNull(data_get($wordpressCoverage, 'object_count')),
                'extension_count' => $this->integerOrNull(data_get($wordpressCoverage, 'extension_count')),
                'taxonomy_count' => $this->integerOrNull(data_get($wordpressCoverage, 'taxonomy_count')),
                'seo_record_count' => $this->integerOrNull(data_get($wordpressCoverage, 'seo_record_count')),
            ],
            'asset' => [
                'domain' => $asset->domain,
                'primary_url' => $asset->primary_url,
                'cms' => $asset->cms,
                'hosting_context' => $asset->hosting_context,
                'languages' => is_array($asset->languages) ? $asset->languages : [],
                'target_countries' => is_array($asset->target_countries) ? $asset->target_countries : [],
            ],
            'connection' => $connection,
            'site' => $site,
            'extensions' => [
                'available' => $extensions !== [],
                'count' => $this->integerOrNull($extensions['count'] ?? null),
                'plugin_count' => $this->integerOrNull($extensions['plugin_count'] ?? null),
                'theme_count' => $this->integerOrNull($extensions['theme_count'] ?? null),
                'active_count' => $this->integerOrNull($extensions['active_count'] ?? null),
                'inactive_count' => $this->integerOrNull($extensions['inactive_count'] ?? null),
                'update_count' => $this->integerOrNull($extensions['update_count'] ?? null),
                'auto_update_count' => $this->integerOrNull($extensions['auto_update_count'] ?? null),
                'observed_at' => $extensions['observed_at'] ?? null,
                'rows' => $filtered->forPage($page, self::PER_PAGE)->values()->all(),
                'pagination' => [
                    'page' => $page,
                    'per_page' => self::PER_PAGE,
                    'total' => $total,
                    'last_page' => $lastPage,
                    'from' => $total === 0 ? 0 : (($page - 1) * self::PER_PAGE) + 1,
                    'to' => min($total, $page * self::PER_PAGE),
                ],
            ],
            'taxonomies' => [
                'available' => $taxonomies !== [],
                'count' => $this->integerOrNull($taxonomies['count'] ?? null),
                'content_count' => $this->integerOrNull($taxonomies['content_count'] ?? null),
                'by_taxonomy' => is_array($taxonomies['by_taxonomy'] ?? null) ? $taxonomies['by_taxonomy'] : [],
                'by_language' => is_array($taxonomies['by_language'] ?? null) ? $taxonomies['by_language'] : [],
                'observed_at' => $taxonomies['observed_at'] ?? null,
            ],
            'seo_providers' => collect(is_array($wordpress['seo_providers'] ?? null) ? $wordpress['seo_providers'] : [])
                ->filter(static fn (mixed $provider): bool => is_string($provider) && trim($provider) !== '')
                ->values()
                ->all(),
            'public_infrastructure' => $this->publicInfrastructure($website),
            'filters' => [
                'search' => trim($search),
                'filter' => $filter,
            ],
        ];
    }

    /** @param Collection<int, WebsiteEntityProfile> $entities @return array<string, mixed> */
    private function sourceState(Collection $entities, string $source): array
    {
        foreach ($entities as $entity) {
            $state = data_get($entity->source_states, $source);
            if (is_array($state)) {
                return $state;
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function connection(DigitalAsset $asset): array
    {
        $connection = CoreConnection::query()
            ->where('digital_asset_id', $asset->getKey())
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->first();
        if (! $connection instanceof CoreConnection) {
            return [
                'exists' => false,
                'paired' => false,
                'enabled' => false,
                'state' => 'not_configured',
                'plugin_version' => null,
                'paired_at' => null,
                'site_url' => null,
                'home_url' => null,
                'last_success_at' => null,
                'last_success_human' => null,
                'last_error' => null,
            ];
        }

        $config = is_array($connection->config) ? $connection->config : [];
        $state = (string) ($config['pairing_state'] ?? 'not_configured');

        return [
            'exists' => true,
            'paired' => $state === WordPressConnectorPairingService::PAIRED,
            'enabled' => (bool) $connection->enabled,
            'state' => $state,
            'plugin_version' => $config['plugin_version'] ?? null,
            'paired_at' => $config['paired_at'] ?? null,
            'site_url' => $config['site_url'] ?? null,
            'home_url' => $config['home_url'] ?? null,
            'last_success_at' => $connection->last_success_at?->toIso8601String(),
            'last_success_human' => $connection->last_success_at?->diffForHumans(),
            'last_error' => $connection->last_error,
        ];
    }

    /** @param array<string, mixed> $site @return array<string, mixed> */
    private function presentSite(array $site): array
    {
        $settings = is_array($site['settings'] ?? null) ? $site['settings'] : [];
        $features = is_array($site['features'] ?? null) ? $site['features'] : [];
        $health = is_array($site['site_health'] ?? null) ? $site['site_health'] : [];

        return [
            'available' => $site !== [],
            'cms' => $site['cms'] ?? null,
            'site_key' => $site['site_key'] ?? null,
            'site_url' => $site['site_url'] ?? null,
            'home_url' => $site['home_url'] ?? null,
            'wordpress_version' => $site['wordpress_version'] ?? null,
            'php_version' => $site['php_version'] ?? null,
            'locale' => $site['locale'] ?? null,
            'timezone' => $site['timezone'] ?? null,
            'active_theme' => $site['active_theme'] ?? null,
            'active_theme_name' => $site['active_theme_name'] ?? null,
            'active_theme_version' => $site['active_theme_version'] ?? null,
            'is_multisite' => $this->booleanOrNull($site['is_multisite'] ?? null),
            'rest_state' => $site['rest_state'] ?? null,
            'cron_state' => $site['cron_state'] ?? null,
            'core_update_available' => $this->booleanOrNull($site['core_update_available'] ?? null),
            'available_wordpress_version' => $site['available_wordpress_version'] ?? null,
            'core_update_checked_at' => $site['core_update_checked_at'] ?? null,
            'site_health' => [
                'good' => $this->integerOrNull($health['good'] ?? null),
                'recommended' => $this->integerOrNull($health['recommended'] ?? null),
                'critical' => $this->integerOrNull($health['critical'] ?? null),
            ],
            'settings' => [
                'blog_public' => $this->booleanOrNull($settings['blog_public'] ?? null),
                'permalink_structure' => $settings['permalink_structure'] ?? null,
                'show_on_front' => $settings['show_on_front'] ?? null,
                'page_on_front' => $this->integerOrNull($settings['page_on_front'] ?? null),
                'page_for_posts' => $this->integerOrNull($settings['page_for_posts'] ?? null),
                'posts_per_page' => $this->integerOrNull($settings['posts_per_page'] ?? null),
                'uploads_use_yearmonth_folders' => $this->booleanOrNull($settings['uploads_use_yearmonth_folders'] ?? null),
                'memory_limit' => $settings['memory_limit'] ?? null,
                'max_upload_size' => $this->integerOrNull($settings['max_upload_size'] ?? null),
            ],
            'features' => [
                'polylang' => $this->booleanOrNull($features['polylang'] ?? null),
                'litespeed_cache' => $this->booleanOrNull($features['litespeed_cache'] ?? null),
            ],
            'observed_at' => $site['observed_at'] ?? null,
            'last_collected_at' => $site['last_collected_at'] ?? null,
            'source_record_id' => $this->integerOrNull($site['source_record_id'] ?? null),
            'collection_run_id' => $this->integerOrNull($site['last_collection_run_id'] ?? null),
            'dataset_run_id' => $this->integerOrNull($site['last_dataset_run_id'] ?? null),
            'contract_version' => $this->integerOrNull($site['contract_version'] ?? null),
        ];
    }

    /** @param array<string, mixed> $record @return array<string, mixed> */
    private function presentExtension(array $record): array
    {
        return [
            'type' => (string) ($record['type'] ?? 'plugin'),
            'id' => (string) ($record['id'] ?? ''),
            'name' => is_string($record['name'] ?? null) && trim($record['name']) !== '' ? trim($record['name']) : null,
            'version' => $record['version'] ?? null,
            'status' => $record['status'] ?? null,
            'update_available' => $this->booleanOrNull($record['update_available'] ?? null),
            'available_version' => $record['available_version'] ?? null,
            'auto_update' => $this->booleanOrNull($record['auto_update'] ?? null),
            'update_checked_at' => $record['update_checked_at'] ?? null,
            'source_record_id' => $this->integerOrNull($record['source_record_id'] ?? null),
            'collection_run_id' => $this->integerOrNull($record['collection_run_id'] ?? null),
            'dataset_run_id' => $this->integerOrNull($record['dataset_run_id'] ?? null),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $records @return Collection<int, array<string, mixed>> */
    private function filterExtensions(Collection $records, string $search, string $filter): Collection
    {
        $search = mb_strtolower(trim($search));
        if ($search !== '') {
            $records = $records->filter(static function (array $record) use ($search): bool {
                return str_contains(mb_strtolower((string) ($record['name'] ?? '')), $search)
                    || str_contains(mb_strtolower((string) ($record['id'] ?? '')), $search)
                    || str_contains(mb_strtolower((string) ($record['version'] ?? '')), $search);
            });
        }

        return $records->filter(static fn (array $record): bool => match ($filter) {
            'plugin' => $record['type'] === 'plugin',
            'theme' => $record['type'] === 'theme',
            'active' => $record['status'] === 'active',
            'inactive' => $record['status'] === 'inactive',
            'updates' => $record['update_available'] === true,
            default => true,
        })->sort(static function (array $left, array $right): int {
            return [
                $left['update_available'] === true ? 0 : 1,
                $left['type'],
                mb_strtolower((string) ($left['name'] ?? $left['id'])),
            ] <=> [
                $right['update_available'] === true ? 0 : 1,
                $right['type'],
                mb_strtolower((string) ($right['name'] ?? $right['id'])),
            ];
        })->values();
    }

    /** @param array<string, mixed> $website @return array<string, mixed> */
    private function publicInfrastructure(array $website): array
    {
        $infra = is_array($website['infra'] ?? null) ? $website['infra'] : [];
        $facts = is_array($infra['facts'] ?? null) ? $infra['facts'] : [];
        $tls = is_array($facts['tls'] ?? null) ? $facts['tls'] : [];

        return [
            'available' => $facts !== [],
            'host' => $facts['host'] ?? null,
            'tls_present' => $this->booleanOrNull($facts['present'] ?? null),
            'issuer' => $tls['issuer_common_name'] ?? null,
            'valid_from' => $tls['valid_from'] ?? null,
            'valid_to' => $tls['valid_to'] ?? null,
            'error' => $tls['error_class'] ?? null,
            'observed_at' => $infra['observed_at'] ?? null,
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function booleanOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
