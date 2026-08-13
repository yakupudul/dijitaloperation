<?php

namespace App\Services\Collection\Providers\SearchConsole;

/**
 * Centralized official Search Console provider capability constants.
 * Verification date: 2026-08-13 against Google Search Analytics query docs.
 */
final class SearchConsoleProviderCapabilities
{
    public const string VERIFICATION_DATE = '2026-08-13';

    public const string SEARCH_ANALYTICS_DOCS = 'https://developers.google.com/webmaster-tools/v1/searchanalytics/query';

    public const string SITEMAPS_DOCS = 'https://developers.google.com/webmaster-tools/v1/sitemaps';

    public const string URL_INSPECTION_DOCS = 'https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect';

    /** Official maximum rowLimit for searchanalytics.query */
    public const int MAX_ROW_LIMIT = 25000;

    public const string REPORTING_TIMEZONE = 'America/Los_Angeles';

    public const string PROVIDER_COMPLETENESS = 'PROVIDER_TOP_ROWS_LIMITED';

    public const string EXECUTION_COMPLETENESS = 'REQUEST_EXECUTION_COMPLETE';
}
