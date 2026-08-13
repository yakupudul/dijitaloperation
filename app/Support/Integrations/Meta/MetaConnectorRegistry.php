<?php

namespace App\Support\Integrations\Meta;

/**
 * Meta product Connectors under one Meta Integration authorization plane.
 * Prompt 21 foundation: Meta Ads is the production advertising capability.
 * Instagram remains a registered capability id but is not a Prompt 21 production connector.
 */
final class MetaConnectorRegistry
{
    public const string META_ADS = 'meta_ads';

    /**
     * @return list<array{
     *   capability: string,
     *   slug: string,
     *   label: string,
     *   resource_type: string,
     *   resource_type_label: string,
     *   bindable: bool,
     *   production_foundation: bool,
     *   collection_status: string
     * }>
     */
    public static function connectors(): array
    {
        return [
            [
                'capability' => self::META_ADS,
                'slug' => 'meta-ads',
                'label' => 'Meta Ads',
                'resource_type' => MetaResourceType::META_AD_ACCOUNT,
                'resource_type_label' => MetaResourceType::label(MetaResourceType::META_AD_ACCOUNT),
                'bindable' => true,
                'production_foundation' => true,
                'collection_status' => 'NOT_YET',
                'collection_note' => 'Prompt 24 Meta Ads Production Collector',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function byCapability(string $capability): ?array
    {
        foreach (self::connectors() as $connector) {
            if ($connector['capability'] === $capability) {
                return $connector;
            }
        }

        return null;
    }
}
