<?php

namespace App\Support\Integrations\Google;

/**
 * Minimal OAuth scopes for read-oriented Google discovery.
 *
 * Notes from current official Google docs:
 * - Search Console: webmasters.readonly
 * - GA4 Admin: analytics.readonly
 * - Google Ads: only https://www.googleapis.com/auth/adwords (no separate readonly scope);
 *   DOP still never mutates Ads entities.
 * - GBP: business.manage is manage-level and optional via config.
 */
final class GoogleScopes
{
    public const string SEARCH_CONSOLE_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

    public const string ANALYTICS_READONLY = 'https://www.googleapis.com/auth/analytics.readonly';

    public const string ADWORDS = 'https://www.googleapis.com/auth/adwords';

    public const string BUSINESS_MANAGE = 'https://www.googleapis.com/auth/business.manage';

    /**
     * @return list<string>
     */
    public static function requested(): array
    {
        $scopes = [
            self::SEARCH_CONSOLE_READONLY,
            self::ANALYTICS_READONLY,
            self::ADWORDS,
        ];

        if ((bool) config('moxdop.google.include_gbp_scope', false)) {
            $scopes[] = self::BUSINESS_MANAGE;
        }

        return $scopes;
    }
}
