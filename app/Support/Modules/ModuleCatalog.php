<?php

namespace App\Support\Modules;

/**
 * Product semantics for Module Registry rows.
 *
 * Modules are business/domain capabilities — not Integrations (providers)
 * and not Agents/Skills.
 */
final class ModuleCatalog
{
    /**
     * Operator-facing product capability modules (Module Registry UI).
     *
     * @var list<string>
     */
    public const PRODUCT_MODULE_IDS = [
        'website',
        'google-ads',
        'google-business-profile',
        'meta-ads',
    ];

    /**
     * Developer / modular packaging fixtures — retained for Composer discovery
     * and smoke tests, but hidden from normal operator Module Registry UI.
     *
     * @var list<string>
     */
    public const DEVELOPER_FIXTURE_MODULE_IDS = [
        'sample-module',
    ];

    /**
     * Integration provider keys that must never appear as Modules.
     *
     * @var list<string>
     */
    public const INTEGRATION_PROVIDER_KEYS_NOT_MODULES = [
        'openai',
        'dataforseo',
        'anthropic',
        'gemini',
        'openrouter',
        'google',
        'meta',
    ];

    public static function isDeveloperFixture(string $moduleId): bool
    {
        return in_array($moduleId, self::DEVELOPER_FIXTURE_MODULE_IDS, true);
    }

    public static function isOperatorVisible(string $moduleId): bool
    {
        return ! self::isDeveloperFixture($moduleId);
    }

    /**
     * @return list<string>
     */
    public static function operatorVisibleModuleIds(): array
    {
        return self::PRODUCT_MODULE_IDS;
    }
}
