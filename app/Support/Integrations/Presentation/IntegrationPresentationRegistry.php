<?php

namespace App\Support\Integrations\Presentation;

use App\Support\Integrations\ProviderRegistry;

/**
 * Operator-facing presentation metadata for Settings → Integrations.
 * ProviderRegistry remains canonical identity; this does not invent providers.
 */
final class IntegrationPresentationRegistry
{
    public const string GROUP_DATA = 'data_platforms';

    public const string GROUP_AI = 'ai_providers';

    /**
     * Operator-ready providers only. Meta stays in ProviderRegistry but is not
     * shown until its Integration workspace exists.
     *
     * @return list<array{
     *     provider: string,
     *     label: string,
     *     description: string,
     *     group: string,
     *     group_label: string,
     *     icon: string,
     *     supports_resources: bool,
     *     capability_labels: list<string>
     * }>
     */
    public static function operatorReady(): array
    {
        return [
            [
                'provider' => ProviderRegistry::GOOGLE,
                'label' => ProviderRegistry::label(ProviderRegistry::GOOGLE),
                'description' => 'Analytics, search and advertising data',
                'group' => self::GROUP_DATA,
                'group_label' => 'Data & platforms',
                'icon' => 'google',
                'supports_resources' => true,
                'capability_labels' => [
                    'Search Console',
                    'GA4',
                    'Google Ads',
                    'GBP',
                ],
            ],
            [
                'provider' => ProviderRegistry::DATAFORSEO,
                'label' => ProviderRegistry::label(ProviderRegistry::DATAFORSEO),
                'description' => 'External SEO and keyword intelligence',
                'group' => self::GROUP_DATA,
                'group_label' => 'Data & platforms',
                'icon' => 'seo',
                'supports_resources' => false,
                'capability_labels' => [
                    'SEO intelligence',
                ],
            ],
            [
                'provider' => ProviderRegistry::OPENAI,
                'label' => ProviderRegistry::label(ProviderRegistry::OPENAI),
                'description' => 'AI reasoning and recommendation intelligence',
                'group' => self::GROUP_AI,
                'group_label' => 'AI providers',
                'icon' => 'ai',
                'supports_resources' => false,
                'capability_labels' => [
                    'AI guidance',
                ],
            ],
            [
                'provider' => ProviderRegistry::ANTHROPIC,
                'label' => ProviderRegistry::label(ProviderRegistry::ANTHROPIC),
                'description' => 'Claude reasoning and analysis',
                'group' => self::GROUP_AI,
                'group_label' => 'AI providers',
                'icon' => 'ai',
                'supports_resources' => false,
                'capability_labels' => [
                    'AI guidance',
                ],
            ],
            [
                'provider' => ProviderRegistry::GEMINI,
                'label' => ProviderRegistry::label(ProviderRegistry::GEMINI),
                'description' => 'Google AI reasoning and multimodal intelligence',
                'group' => self::GROUP_AI,
                'group_label' => 'AI providers',
                'icon' => 'ai',
                'supports_resources' => false,
                'capability_labels' => [
                    'AI guidance',
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     provider: string,
     *     label: string,
     *     description: string,
     *     group: string,
     *     group_label: string,
     *     icon: string,
     *     supports_resources: bool,
     *     capability_labels: list<string>
     * }|null
     */
    public static function for(string $provider): ?array
    {
        foreach (self::operatorReady() as $meta) {
            if ($meta['provider'] === $provider) {
                return $meta;
            }
        }

        return null;
    }

    public static function isOperatorReady(string $provider): bool
    {
        return self::for($provider) !== null;
    }

    /**
     * @return list<string>
     */
    public static function groupOrder(): array
    {
        return [self::GROUP_DATA, self::GROUP_AI];
    }
}
