<?php

namespace App\Support\Integrations;

/**
 * Canonical agency providers and their discoverable capabilities.
 * Not a marketplace — finite product list only.
 */
final class ProviderRegistry
{
    public const string GOOGLE = 'google';

    public const string META = 'meta';

    public const string DATAFORSEO = 'dataforseo';

    public const string OPENAI = 'openai';

    /**
     * @return array<string, array{label: string, capabilities: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::GOOGLE => [
                'label' => 'Google',
                'capabilities' => [
                    'search_console',
                    'ga4',
                    'google_ads',
                    'google_business_profile',
                ],
            ],
            self::META => [
                'label' => 'Meta',
                'capabilities' => [
                    'meta_ads',
                    'instagram',
                ],
            ],
            self::DATAFORSEO => [
                'label' => 'DataForSEO',
                'capabilities' => [
                    'seo_data',
                ],
            ],
            self::OPENAI => [
                'label' => 'OpenAI',
                'capabilities' => [
                    'ai',
                ],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::all())
            ->mapWithKeys(fn (array $meta, string $key): array => [$key => $meta['label']])
            ->all();
    }

    public static function label(string $provider): string
    {
        return self::all()[$provider]['label'] ?? str($provider)->replace('_', ' ')->title()->toString();
    }

    public static function isValid(string $provider): bool
    {
        return array_key_exists($provider, self::all());
    }

    /**
     * @return list<string>
     */
    public static function capabilities(string $provider): array
    {
        return self::all()[$provider]['capabilities'] ?? [];
    }

    public static function defaultName(string $provider): string
    {
        return self::label($provider);
    }

    public static function capabilityLabel(string $capability): string
    {
        return match ($capability) {
            'search_console' => 'Search Console',
            'ga4' => 'GA4',
            'google_ads' => 'Google Ads',
            'google_business_profile' => 'Google Business Profile',
            'meta_ads' => 'Meta Ads',
            'instagram' => 'Instagram',
            'seo_data' => 'SEO data',
            'ai' => 'AI',
            default => str($capability)->replace('_', ' ')->title()->toString(),
        };
    }
}
