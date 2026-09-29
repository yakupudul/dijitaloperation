<?php

namespace App\Support\Collection;

/**
 * MoxDOP v2 dataset catalogue (Faz 1). `config('moxdop-collection.datasets')` is the single truth for what is
 * collected; every collector asks this class before planning a request family.
 */
final class CollectionDatasetCatalog
{
    /** @return array<string, list<string>> provider => kept dataset ids */
    public static function all(): array
    {
        return array_map(
            static fn ($datasets): array => array_values(array_map('strval', (array) $datasets)),
            (array) config('moxdop-collection.datasets', []),
        );
    }

    /** @return list<string> */
    public static function kept(string $provider): array
    {
        return self::all()[$provider] ?? [];
    }

    /** Whether the provider's collection is governed by the catalogue (unlisted providers are not filtered). */
    public static function governs(string $provider): bool
    {
        return array_key_exists($provider, self::all());
    }

    /**
     * Whether a dataset is collected. A family without a durable dataset (provider metadata probes) is always
     * allowed; an ungoverned provider is not filtered here.
     */
    public static function keeps(string $provider, ?string $datasetId): bool
    {
        if ($datasetId === null || $datasetId === '' || ! self::governs($provider)) {
            return true;
        }

        return in_array($datasetId, self::kept($provider), true);
    }

    /** On-demand datasets (operator request only, never scheduled). */
    public static function isOnDemand(string $provider, string $datasetId): bool
    {
        return in_array($datasetId, (array) config('moxdop-collection.on_demand.'.$provider, []), true);
    }
}
