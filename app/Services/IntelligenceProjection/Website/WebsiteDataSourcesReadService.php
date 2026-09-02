<?php

namespace App\Services\IntelligenceProjection\Website;

use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\PageSpeedConnectionProbeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class WebsiteDataSourcesReadService
{
    public function __construct(
        private readonly WebsiteProjectionReadService $projection,
        private readonly GscSpecialistBindingResolver $gscBindings,
        private readonly Ga4SpecialistBindingResolver $ga4Bindings,
    ) {}

    /** @return array<string, mixed> */
    public function workspace(DigitalAsset $asset): array
    {
        $projection = $this->projection->summary($asset);
        $coverage = is_array($projection['coverage_state'] ?? null) ? $projection['coverage_state'] : [];
        $bindings = CoreAssetBinding::query()
            ->with('externalResource.integration')
            ->where('digital_asset_id', $asset->getKey())
            ->whereIn('capability', ['search_console', 'ga4'])
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->unique('capability')
            ->keyBy('capability');

        $sources = [
            $this->publicWebsiteSource($asset, $this->coverage($coverage, 'website')),
            $this->wordpressSource($asset, $this->coverage($coverage, 'wordpress')),
            $this->pageSpeedSource($asset),
            $this->gscSource($asset, $this->coverage($coverage, 'gsc'), $bindings->get('search_console')),
            $this->ga4Source($asset, $this->coverage($coverage, 'ga4'), $bindings->get('ga4')),
        ];

        $latestWatermark = $this->latestWatermark($sources);
        $latestCollection = $this->latestWebsiteCollection($asset);

        return [
            'available' => (bool) ($projection['available'] ?? false) || collect($sources)->contains(
                static fn (array $source): bool => $source['configured'] || $source['collected'],
            ),
            'projection' => $projection,
            'summary' => [
                'source_count' => count($sources),
                'ready_count' => collect($sources)->where('configured', true)->count(),
                'collected_count' => collect($sources)->where('collected', true)->count(),
                'attention_count' => collect($sources)->filter(
                    static fn (array $source): bool => in_array($source['state'], ['needs_attention', 'unavailable'], true),
                )->count(),
                'latest_watermark' => $latestWatermark,
                'latest_watermark_human' => $this->humanTime($latestWatermark),
            ],
            'groups' => [
                'site' => array_values(array_filter($sources, static fn (array $source): bool => $source['group'] === 'site')),
                'measurement' => array_values(array_filter($sources, static fn (array $source): bool => $source['group'] === 'measurement')),
            ],
            'collection' => $latestCollection,
        ];
    }

    /** @param array<string, mixed> $coverage @return array<string, mixed> */
    private function publicWebsiteSource(DigitalAsset $asset, array $coverage): array
    {
        $configured = filled($asset->primary_url) || filled($asset->domain);
        $dataState = $this->dataState($coverage);

        return $this->source(
            key: 'website',
            group: 'site',
            configured: $configured,
            connectionState: $configured ? 'ready' : 'not_configured',
            dataState: $dataState,
            displayName: $asset->primary_url ?: $asset->domain,
            watermark: $coverage['watermark'] ?? null,
            counts: $this->countsWhenCollected($dataState, [
                'pages' => $this->integerOrNull($coverage['page_count'] ?? null),
                'html' => $this->integerOrNull($coverage['html_snapshot_count'] ?? null),
            ]),
            contributes: ['page', 'entity'],
        );
    }

    /** @param array<string, mixed> $coverage @return array<string, mixed> */
    private function wordpressSource(DigitalAsset $asset, array $coverage): array
    {
        $connection = CoreConnection::query()
            ->where('digital_asset_id', $asset->getKey())
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->latest('id')
            ->first();
        $config = $connection instanceof CoreConnection && is_array($connection->config) ? $connection->config : [];
        $paired = ($config['pairing_state'] ?? null) === WordPressConnectorPairingService::PAIRED;
        $configured = $connection instanceof CoreConnection && $connection->enabled && $paired;
        $needsAttention = $connection instanceof CoreConnection
            && (! $connection->enabled || ! $paired || filled($connection->last_error));
        $connectionState = $needsAttention ? 'needs_attention' : ($configured ? 'connected' : 'not_configured');
        $dataState = $this->dataState($coverage);

        return $this->source(
            key: 'wordpress',
            group: 'site',
            configured: $configured,
            connectionState: $connectionState,
            dataState: $dataState,
            displayName: $config['site_url'] ?? $config['home_url'] ?? null,
            watermark: $coverage['watermark'] ?? $connection?->last_success_at?->toIso8601String(),
            counts: $this->countsWhenCollected($dataState, [
                'objects' => $this->integerOrNull($coverage['object_count'] ?? null),
                'extensions' => $this->integerOrNull($coverage['extension_count'] ?? null),
                'taxonomies' => $this->integerOrNull($coverage['taxonomy_count'] ?? null),
                'seo' => $this->integerOrNull($coverage['seo_record_count'] ?? null),
            ]),
            contributes: ['page', 'entity'],
        );
    }

    /** @return array<string, mixed> */
    private function pageSpeedSource(DigitalAsset $asset): array
    {
        $connection = CoreConnection::query()
            ->with('credential')
            ->where('digital_asset_id', $asset->getKey())
            ->where('type', PageSpeedConnectionProbeService::CONNECTION_TYPE)
            ->latest('id')
            ->first();
        $payload = $connection?->credential?->encrypted_payload;
        $configured = $connection instanceof CoreConnection
            && $connection->enabled
            && is_array($payload)
            && filled($payload['api_key'] ?? null);
        $needsAttention = $connection instanceof CoreConnection
            && (! $configured || filled($connection->last_error));

        $count = null;
        $watermark = $connection?->last_success_at?->toIso8601String();
        if (Schema::hasTable('website_performance_measurement')) {
            $query = DB::table('website_performance_measurement')->where('digital_asset_id', $asset->getKey());
            $count = (clone $query)->distinct()->count('url');
            $watermark = (clone $query)->max('last_collected_at') ?: $watermark;
        }
        $dataState = $count !== null && $count > 0 ? 'collected' : 'not_collected';

        return $this->source(
            key: 'pagespeed',
            group: 'site',
            configured: $configured,
            connectionState: $needsAttention ? 'needs_attention' : ($configured ? 'connected' : 'not_configured'),
            dataState: $dataState,
            displayName: null,
            watermark: $watermark,
            counts: $this->countsWhenCollected($dataState, ['pages' => $count]),
            contributes: ['technical_health'],
        );
    }

    /** @param array<string, mixed> $coverage */
    private function gscSource(DigitalAsset $asset, array $coverage, ?CoreAssetBinding $binding): array
    {
        $context = $this->gscBindings->resolve((string) $asset->getKey());
        $connectionState = match ($context->mode->value) {
            'REAL_BOUND' => 'connected',
            'ACTION_REQUIRED' => 'needs_attention',
            default => 'not_configured',
        };
        $dataState = $this->dataState($coverage);

        return $this->source(
            key: 'gsc',
            group: 'measurement',
            configured: $context->isReal(),
            connectionState: $connectionState,
            dataState: $dataState,
            displayName: $binding?->externalResource?->display_name ?: $context->siteUrl,
            watermark: $coverage['watermark'] ?? null,
            counts: $this->countsWhenCollected($dataState, [
                'pages' => $this->integerOrNull($coverage['page_count'] ?? null),
                'terms' => $this->integerOrNull($coverage['search_term_count'] ?? null),
                'relationships' => $this->integerOrNull($coverage['query_page_relationship_count'] ?? null),
            ]),
            contributes: ['page', 'search_term'],
        );
    }

    /** @param array<string, mixed> $coverage */
    private function ga4Source(DigitalAsset $asset, array $coverage, ?CoreAssetBinding $binding): array
    {
        $context = $this->ga4Bindings->resolve((string) $asset->getKey());
        $connectionState = match ($context->mode->value) {
            'REAL_BOUND' => 'connected',
            'ACTION_REQUIRED' => 'needs_attention',
            default => 'not_configured',
        };
        $dataState = $this->dataState($coverage);

        return $this->source(
            key: 'ga4',
            group: 'measurement',
            configured: $context->isReal(),
            connectionState: $connectionState,
            dataState: $dataState,
            displayName: $binding?->externalResource?->display_name
                ?: ($context->propertyId !== null ? 'GA4 '.$context->propertyId : null),
            watermark: $coverage['watermark'] ?? null,
            counts: $this->countsWhenCollected($dataState, [
                'pages' => $this->integerOrNull($coverage['page_count'] ?? null),
                'outcomes' => $this->integerOrNull($coverage['mapped_outcome_count'] ?? null),
            ]),
            contributes: ['page', 'outcome'],
        );
    }

    /**
     * @param array<string, int|null> $counts
     * @param list<string> $contributes
     * @return array<string, mixed>
     */
    private function source(
        string $key,
        string $group,
        bool $configured,
        string $connectionState,
        string $dataState,
        ?string $displayName,
        mixed $watermark,
        array $counts,
        array $contributes,
    ): array {
        $collected = $dataState === 'collected';
        $state = match (true) {
            $connectionState === 'needs_attention' => 'needs_attention',
            ! $configured && $collected => 'needs_attention',
            in_array($dataState, ['unavailable', 'projection_failed'], true) => 'unavailable',
            $collected => 'collected',
            ! $configured => 'not_configured',
            default => 'configured',
        };

        return [
            'key' => $key,
            'group' => $group,
            'state' => $state,
            'connection_state' => $connectionState,
            'data_state' => $dataState,
            'configured' => $configured,
            'collected' => $collected,
            'display_name' => is_string($displayName) && trim($displayName) !== '' ? trim($displayName) : null,
            'watermark' => is_string($watermark) && trim($watermark) !== '' ? trim($watermark) : null,
            'watermark_human' => $this->humanTime($watermark),
            'counts' => $counts,
            'contributes' => $contributes,
        ];
    }

    /** @param array<string, mixed> $coverage @return array<string, mixed> */
    private function coverage(array $coverage, string $source): array
    {
        return is_array($coverage[$source] ?? null) ? $coverage[$source] : [];
    }

    /** @param array<string, mixed> $coverage */
    private function dataState(array $coverage): string
    {
        $state = (string) ($coverage['state'] ?? 'not_collected');

        return in_array($state, ['collected', 'not_collected', 'not_configured', 'unavailable', 'projection_failed'], true)
            ? $state
            : 'not_collected';
    }

    /**
     * @param array<string, int|null> $counts
     * @return array<string, int|null>
     */
    private function countsWhenCollected(string $dataState, array $counts): array
    {
        if ($dataState !== 'collected') {
            return array_map(static fn (mixed $value): null => null, $counts);
        }

        return $counts;
    }

    /** @param list<array<string, mixed>> $sources */
    private function latestWatermark(array $sources): ?string
    {
        $latest = null;
        foreach ($sources as $source) {
            $value = $source['watermark'] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                $candidate = CarbonImmutable::parse($value);
            } catch (Throwable) {
                continue;
            }

            if ($latest === null || $candidate->isAfter($latest)) {
                $latest = $candidate;
            }
        }

        return $latest?->toIso8601String();
    }

    private function humanTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->diffForHumans();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function latestWebsiteCollection(DigitalAsset $asset): ?array
    {
        $run = CollectionRun::query()
            ->where('digital_asset_id', $asset->getKey())
            ->latest('id')
            ->limit(25)
            ->get()
            ->first(static fn (CollectionRun $candidate): bool => in_array(
                'WEBSITE_DIRECT',
                (array) data_get($candidate->request_context, 'provider_sources', []),
                true,
            ));
        if (! $run instanceof CollectionRun) {
            return null;
        }

        return [
            'id' => $run->getKey(),
            'state' => $run->status?->value,
            'datasets_total' => $run->datasets_total,
            'datasets_completed' => $run->datasets_completed,
            'datasets_failed' => $run->datasets_failed,
            'last_activity_at' => $run->last_activity_at?->toIso8601String() ?? $run->updated_at?->toIso8601String(),
            'last_activity_human' => ($run->last_activity_at ?? $run->updated_at)?->diffForHumans(),
        ];
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
