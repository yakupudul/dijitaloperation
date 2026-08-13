<?php

namespace App\Services\Collection\Monitoring;

use App\Services\Collection\DataContractRegistryLoader;
use Illuminate\Support\Str;

/**
 * Friendly dataset labels from Data Contract metadata — does not mutate contracts.
 */
final class CollectionDatasetLabelResolver
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly DataContractRegistryLoader $registry,
    ) {}

    public function label(string $datasetContractId): string
    {
        if (isset($this->cache[$datasetContractId])) {
            return $this->cache[$datasetContractId];
        }

        try {
            $dataset = $this->registry->dataset($datasetContractId);
            if ($dataset !== null) {
                $description = trim((string) ($dataset['description'] ?? ''));
                if ($description !== '') {
                    return $this->cache[$datasetContractId] = Str::limit($description, 80, '');
                }
            }
        } catch (\Throwable) {
            // Fall through to humanized id.
        }

        return $this->cache[$datasetContractId] = Str::of($datasetContractId)
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    public function providerLabel(string $providerOrSource): string
    {
        return match (strtoupper($providerOrSource)) {
            'GA4' => __('operator.collection.providers.ga4'),
            'SEARCH_CONSOLE', 'GSC' => __('operator.collection.providers.gsc'),
            'GOOGLE_ADS' => __('operator.collection.providers.google_ads'),
            'META_ADS', 'META' => __('operator.collection.providers.meta'),
            'WEBSITE_DIRECT', 'WEBSITE' => __('operator.collection.providers.website'),
            'DATAFORSEO' => __('operator.collection.providers.dataforseo'),
            'PAGESPEED_TECHNICAL' => __('operator.collection.providers.pagespeed'),
            'WORDPRESS_SITE_CONNECTOR' => __('operator.collection.providers.wordpress'),
            default => Str::of($providerOrSource)->replace('_', ' ')->title()->toString(),
        };
    }
}
