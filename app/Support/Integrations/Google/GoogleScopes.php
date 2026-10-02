<?php

namespace App\Support\Integrations\Google;

use App\Services\Integrations\Google\GoogleScopeRegistry;

/**
 * OAuth scope URL constants + default requested set.
 *
 * Prefer GoogleScopeRegistry for Connector-aware planning.
 */
final class GoogleScopes
{
    public const string SEARCH_CONSOLE_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

    public const string ANALYTICS_READONLY = 'https://www.googleapis.com/auth/analytics.readonly';

    public const string ADWORDS = 'https://www.googleapis.com/auth/adwords';

    public const string BUSINESS_MANAGE = 'https://www.googleapis.com/auth/business.manage';

    /**
     * Default Connect scope union for frozen Google capabilities.
     *
     * @return list<string>
     */
    public static function requested(): array
    {
        return app(GoogleScopeRegistry::class)->scopesForCapabilities();
    }
}
